<?php

namespace Zanbug\Laravel;

/**
 * MİNİMUM PHP: 5.6 — Laravel 5.x proyektləri köhnə PHP-də işləyir.
 *
 * Ona görə burada QƏSDƏN istifadə olunmur:
 *   - konstruktor promotion, readonly, adlandırılmış arqumentlər  (8.0 / 8.1)
 *   - `catch (\Throwable)` dəyişənsiz                              (8.0)
 *   - str_starts_with / str_contains                               (8.0)
 *   - arrow function `fn () =>`                                    (7.4)
 *   - tipli property-lər                                           (7.4)
 *   - `private const`                                              (7.1)
 *   - skalyar parametr/qaytarma tipləri                            (7.0)
 *
 * Bu faylı dəyişəndə həmin siyahını pozma — əks halda paket Laravel 5–7
 * proyektlərində fatal error verir.
 */
class ZanbugClient
{
    const SDK_NAME    = 'zanbug/laravel';
    const SDK_VERSION = '1.4.0';

    private $token;
    private $host;

    /**
     * Bu prosesdə artıq göndərilmiş exception obyektləri.
     *
     * Eyni xəta iki yoldan gələ bilər: paket özü handler-ə qoşulur, üstəlik
     * istifadəçi əl ilə Zanbug::capture() çağırmış ola bilər. Obyekt eyniliyi
     * (===) ilə yoxlanır — spl_object_hash azad olunmuş obyektdən sonra təkrar
     * istifadə oluna bilər və yanlış "artıq gördüm" verər.
     */
    private $seen = array();

    public function __construct($token, $host)
    {
        $this->token = (string) $token;
        $this->host  = rtrim((string) $host, '/');
    }

    /**
     * @param bool $handled Xəta tutuldumu, yoxsa sorğunu çökdürdü?
     *   Çərçivənin handler-i çağıranda false — sorğu 500 ilə bitir.
     *   Öz try/catch blokundan çağıranda true (bax: Zanbug::notify()).
     */
    public function captureException($e, $handled = false)
    {
        if (!$this->isThrowable($e) || $this->alreadySent($e)) {
            return;
        }

        try {
            $context = $this->buildRequestContext();

            if ($this->isValidation($e)) {
                $context['validation_errors'] = $this->validationErrors($e);
            } else {
                $context['http_status'] = $this->resolveStatusCode($e);
            }

            $payload = array(
                'level'           => $this->resolveLevel($e),
                // Validasiya xətası 422 qaytarır, proqram çökmür — həmişə handled.
                'handled'         => $this->isValidation($e) ? true : (bool) $handled,
                'message'         => $this->resolveMessage($e),
                'occurred_at'     => $this->now(),
                'exception_class' => get_class($e),
                'file'            => $e->getFile(),
                'line'            => $e->getLine(),
                'stack'           => $this->buildStackFrames($e),
                'context'         => $context ? $context : null,
                'environment'     => $this->conf('app.env', 'production'),
                'release'         => $this->conf('app.version', null),
                'server_name'     => gethostname() ? gethostname() : null,
                'sdk'             => array('name' => self::SDK_NAME, 'version' => self::SDK_VERSION),
            );

            /**
             * Laravel PHP xəbərdarlıqlarını ErrorException-a çevirib atır, ona
             * görə bizə "error" kimi gəlir. Əsl səviyyə E_* kodundadır.
             *
             * Backend bunu səviyyəyə çevirir (levelFromPhpSeverity), amma biz
             * göndərmədiyimiz üçün o məntiq indiyə qədər ölü kod idi.
             */
            if ($e instanceof \ErrorException) {
                $severity = $e->getSeverity();
                if (is_int($severity) && $severity > 0) {
                    $payload['severity'] = $severity;
                }
            }

            $this->send($payload);
        } catch (\Exception $x) {
            // SDK xətası heç vaxt tətbiqi dayandırmamalıdır
        } catch (\Throwable $x) {
        }
    }

    /** Yalnız Laravel 11+-də çağırılır — Http::globalResponseMiddleware oradan var. */
    public function captureHttpFailure($response)
    {
        try {
            $uri    = (string) $response->effectiveUri();
            $parsed = parse_url($uri);

            // Query parametrləri çıxarılır — token/key ola bilər
            $scheme  = isset($parsed['scheme']) ? $parsed['scheme'] : 'http';
            $hostPart = isset($parsed['host']) ? $parsed['host'] : '';
            $path    = isset($parsed['path']) ? $parsed['path'] : '';
            $safeUrl = $scheme . '://' . $hostPart . $path;

            $status = $response->status();

            $context = array_merge($this->buildRequestContext(), array(
                'http_client' => array(
                    'url'    => $safeUrl,
                    'status' => $status,
                    'body'   => $this->truncate($response->body(), 500),
                ),
            ));

            $this->send(array(
                'level'           => $status >= 500 ? 'error' : 'warning',
                // Xarici API cavab verdi (pis cavab olsa da) — tətbiq çökmədi.
                'handled'         => true,
                'message'         => 'HTTP ' . $status . ': ' . $safeUrl,
                'occurred_at'     => $this->now(),
                'exception_class' => 'HttpClientError',
                'context'         => $context,
                'environment'     => $this->conf('app.env', 'production'),
                'server_name'     => gethostname() ? gethostname() : null,
                'sdk'             => array('name' => self::SDK_NAME, 'version' => self::SDK_VERSION),
            ));
        } catch (\Exception $x) {
        } catch (\Throwable $x) {
        }
    }

    public function captureSlowQuery($sql, $timeMs)
    {
        try {
            $timeMs = (float) $timeMs;

            $this->send(array(
                'level'           => 'warning',
                // Sorğu uğurla bitdi, sadəcə yavaş idi.
                'handled'         => true,
                'message'         => sprintf('Slow query (%.0fms): %s', $timeMs, $this->truncate($sql, 300)),
                'occurred_at'     => $this->now(),
                'exception_class' => 'SlowQuery',
                'context'         => array(
                    'query' => array(
                        'sql'     => $this->truncate($sql, 2000),
                        'time_ms' => $timeMs,
                    ),
                ),
                'environment' => $this->conf('app.env', 'production'),
                'server_name' => gethostname() ? gethostname() : null,
                'sdk'         => array('name' => self::SDK_NAME, 'version' => self::SDK_VERSION),
            ));
        } catch (\Exception $x) {
        } catch (\Throwable $x) {
        }
    }

    // ─────────────────────────────────────────────

    /** PHP 5.6-da \Throwable yoxdur — orada yalnız \Exception qalır. */
    private function isThrowable($e)
    {
        return $e instanceof \Exception || $e instanceof \Throwable;
    }

    private function alreadySent($e)
    {
        if (in_array($e, $this->seen, true)) {
            return true;
        }

        $this->seen[] = $e;
        if (count($this->seen) > 20) {
            array_shift($this->seen); // queue worker-də şişməsin
        }

        return false;
    }

    /**
     * Sinif adı ilə yoxlanır: `instanceof` mövcud olmayan sinifə də təhlükəsizdir,
     * amma köhnə Laravel-də ValidationException başqa namespace-də ola bilər.
     */
    private function isValidation($e)
    {
        return $e instanceof \Illuminate\Validation\ValidationException
            || (method_exists($e, 'errors') && method_exists($e, 'validator'));
    }

    private function validationErrors($e)
    {
        return method_exists($e, 'errors') ? (array) $e->errors() : array();
    }

    private function isHttpException($e)
    {
        return $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException;
    }

    private function resolveStatusCode($e)
    {
        if ($this->isHttpException($e)) {
            return $e->getStatusCode();
        }
        if (method_exists($e, 'getStatusCode')) {
            return (int) $e->getStatusCode();
        }
        return 500;
    }

    private function resolveLevel($e)
    {
        if ($this->isValidation($e)) {
            return 'warning';
        }
        if ($this->isHttpException($e)) {
            return $e->getStatusCode() >= 500 ? 'error' : 'warning';
        }
        // PHP \Error sinifləri (TypeError, ParseError…). PHP 5.6-da \Error
        // yoxdur — instanceof sadəcə false verir, xəta atmır.
        if ($e instanceof \Error) {
            return 'fatal';
        }
        return 'error';
    }

    private function resolveMessage($e)
    {
        if ($this->isValidation($e)) {
            foreach ($this->validationErrors($e) as $messages) {
                if (is_array($messages)) {
                    $first = isset($messages[0]) ? $messages[0] : '';
                } else {
                    $first = (string) $messages;
                }
                if ($first) {
                    return 'Validation failed: ' . $first;
                }
            }
            return 'Validation failed: unknown field';
        }

        return $e->getMessage() ? $e->getMessage() : get_class($e);
    }

    private function buildStackFrames($e)
    {
        $base   = function_exists('base_path') ? base_path() . DIRECTORY_SEPARATOR : null;
        $vendor = DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;
        $frames = array();

        foreach ($e->getTrace() as $f) {
            $file = isset($f['file']) ? $f['file'] : null;

            // inApp backend-də fingerprint üçün istifadə olunur: vendor freymləri
            // qruplaşmaya girmir. Bilinmirsə ümumiyyətlə yazılmır.
            $inApp = null;
            if ($file !== null && $base !== null) {
                $inApp = strpos($file, $base) === 0 && strpos($file, $vendor) === false;
            }

            $frame = array('file' => $file, 'line' => isset($f['line']) ? $f['line'] : 0);
            if (isset($f['function'])) $frame['function'] = $f['function'];
            if (isset($f['class']))    $frame['class']    = $f['class'];
            if ($inApp !== null)       $frame['inApp']    = $inApp;

            $frames[] = $frame;
        }

        return $frames;
    }

    private function buildRequestContext()
    {
        $context = array();

        try {
            $app = function_exists('app') ? app() : null;

            if ($app && method_exists($app, 'runningInConsole') && $app->runningInConsole()) {
                $argv    = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
                $command = implode(' ', array_slice($argv, 1)); // skript adını at
                $context['console'] = array('command' => $command ? $command : 'unknown');

                return $context;
            }

            if ($app && $app->bound('request')) {
                $req = $app->make('request');
                $context['request'] = array(
                    'url'        => $req->fullUrl(),
                    'method'     => $req->method(),
                    'ip'         => $req->ip(),
                    'user_agent' => $req->userAgent(),
                );
            }

            if (function_exists('auth') && auth()->check()) {
                $context['user'] = array('id' => auth()->id());
            }
        } catch (\Exception $x) {
            // Bəzi kontekstlərdə request/auth mövcud olmur — kontekstsiz göndəririk
        } catch (\Throwable $x) {
        }

        return $context;
    }

    /** config() helper-i provider boot-dan əvvəl mövcud olmaya bilər. */
    private function conf($key, $default)
    {
        if (!function_exists('config')) {
            return $default;
        }
        $value = config($key, $default);
        return $value ? $value : $default;
    }

    private function now()
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private function truncate($str, $max)
    {
        $str = (string) $str;

        // mbstring köhnə serverlərdə həmişə qurulu olmur.
        if (function_exists('mb_strlen')) {
            return mb_strlen($str) > $max ? mb_substr($str, 0, $max) . '…' : $str;
        }
        return strlen($str) > $max ? substr($str, 0, $max) . '...' : $str;
    }

    private function send($payload)
    {
        if (!$this->token || !$this->host || !function_exists('curl_init')) {
            return;
        }

        try {
            $ch = curl_init($this->host . '/ingest/errors');
            curl_setopt_array($ch, array(
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPHEADER     => array(
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $this->token,
                ),
            ));
            curl_exec($ch);
            curl_close($ch);
        } catch (\Exception $x) {
            // SDK xətaları heç vaxt tətbiqi dayandırmamalıdır
        } catch (\Throwable $x) {
        }
    }
}
