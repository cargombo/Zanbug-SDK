<?php

namespace Zanbug\Laravel;

use Throwable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ZanbugClient
{
    private const SDK_NAME = 'zanbug/laravel';
    private const SDK_VERSION = '1.0.0';

    public function __construct(
        private readonly string $token,
        private readonly string $host,
    ) {}

    public function captureException(Throwable $e): void
    {
        $level = $this->resolveLevel($e);
        $message = $this->resolveMessage($e);

        $frames = $this->buildStackFrames($e);

        $context = $this->buildRequestContext();

        if ($e instanceof ValidationException) {
            $context['validation_errors'] = $e->errors();
        } else {
            $context['http_status'] = $this->resolveStatusCode($e);
        }

        $this->send([
            'level'           => $level,
            'message'         => $message,
            'occurred_at'     => now()->toIso8601String(),
            'exception_class' => get_class($e),
            'file'            => $e->getFile(),
            'line'            => $e->getLine(),
            'stack'           => $frames,
            'context'         => $context ?: null,
            'environment'     => config('app.env', 'production'),
            'release'         => config('app.version') ?: null,
            'server_name'     => gethostname() ?: null,
            'sdk'             => ['name' => self::SDK_NAME, 'version' => self::SDK_VERSION],
        ]);
    }

    public function captureHttpFailure($response): void
    {
        $uri    = (string) $response->effectiveUri();
        $parsed = parse_url($uri);
        // Query parametrləri çıxarılır — token/key ola bilər
        $safeUrl = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '') . ($parsed['path'] ?? '');

        $status = $response->status();
        $level  = $status >= 500 ? 'error' : 'warning';

        $context = array_merge($this->buildRequestContext(), [
            'http_client' => [
                'url'    => $safeUrl,
                'status' => $status,
                'body'   => $this->truncate($response->body(), 500),
            ],
        ]);

        $this->send([
            'level'           => $level,
            'message'         => "HTTP {$status}: {$safeUrl}",
            'occurred_at'     => now()->toIso8601String(),
            'exception_class' => 'HttpClientError',
            'context'         => $context,
            'environment'     => config('app.env', 'production'),
            'server_name'     => gethostname() ?: null,
            'sdk'             => ['name' => self::SDK_NAME, 'version' => self::SDK_VERSION],
        ]);
    }

    public function captureSlowQuery(string $sql, float $timeMs): void
    {
        $this->send([
            'level'           => 'warning',
            'message'         => sprintf('Slow query (%.0fms): %s', $timeMs, $this->truncate($sql, 300)),
            'occurred_at'     => now()->toIso8601String(),
            'exception_class' => 'SlowQuery',
            'context'         => [
                'query' => [
                    'sql'     => $this->truncate($sql, 2000),
                    'time_ms' => $timeMs,
                ],
            ],
            'environment' => config('app.env', 'production'),
            'server_name' => gethostname() ?: null,
            'sdk'         => ['name' => self::SDK_NAME, 'version' => self::SDK_VERSION],
        ]);
    }

    // ─────────────────────────────────────────────

    private function resolveStatusCode(Throwable $e): int
    {
        if ($e instanceof HttpException) {
            return $e->getStatusCode();
        }
        if (method_exists($e, 'getStatusCode')) {
            return (int) $e->getStatusCode();
        }
        return 500;
    }

    private function resolveLevel(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return 'warning';
        }

        if ($e instanceof HttpException) {
            return $e->getStatusCode() >= 500 ? 'error' : 'warning';
        }

        // PHP Error subclasses (TypeError, ParseError, etc.) → fatal
        if ($e instanceof \Error) {
            return 'fatal';
        }

        return 'error';
    }

    private function resolveMessage(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            $first = collect($e->errors())->flatten()->first();
            return 'Validation failed: ' . ($first ?? 'unknown field');
        }

        return $e->getMessage() ?: get_class($e);
    }

    private function buildStackFrames(Throwable $e): array
    {
        $basePath = base_path() . DIRECTORY_SEPARATOR;
        $frames = [];

        foreach ($e->getTrace() as $f) {
            $file = $f['file'] ?? null;
            $frames[] = array_filter([
                'file'     => $file,
                'line'     => $f['line'] ?? 0,
                'function' => $f['function'] ?? null,
                'class'    => $f['class'] ?? null,
                'inApp'    => $file !== null
                    ? (str_starts_with($file, $basePath)
                        && !str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR))
                    : false,
            ], fn($v) => $v !== null);
        }

        return $frames;
    }

    private function buildRequestContext(): array
    {
        $context = [];

        if (app()->runningInConsole()) {
            // Artisan/cron command context
            $argv = $_SERVER['argv'] ?? [];
            // Drop the script name (artisan), keep command + args
            $command = implode(' ', array_slice($argv, 1));
            $context['console'] = [
                'command' => $command ?: 'unknown',
            ];
        } else {
            try {
                $req = request();
                $context['request'] = [
                    'url'        => $req->fullUrl(),
                    'method'     => $req->method(),
                    'ip'         => $req->ip(),
                    'user_agent' => $req->userAgent(),
                ];

                if (auth()->check()) {
                    $context['user'] = ['id' => auth()->id()];
                }
            } catch (\Throwable) {
                // Request context could be unavailable in some contexts
            }
        }

        return $context;
    }

    private function send(array $payload): void
    {
        try {
            $ch = curl_init("{$this->host}/ingest/errors");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    "Authorization: Bearer {$this->token}",
                ],
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable) {
            // SDK xətaları heç vaxt tətbiqi dayandırmamalıdır
        }
    }

    private function truncate(string $str, int $max): string
    {
        return mb_strlen($str) > $max ? mb_substr($str, 0, $max) . '…' : $str;
    }
}
