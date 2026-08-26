<?php

namespace Zanbug\Laravel;

use Throwable;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ZanbugServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/zanbug.php', 'zanbug');

        $this->app->singleton(ZanbugClient::class, function ($app) {
            return new ZanbugClient(
                token: (string) ($app['config']['zanbug.token'] ?? ''),
                host: rtrim((string) ($app['config']['zanbug.host'] ?? ''), '/'),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/zanbug.php' => config_path('zanbug.php'),
        ], 'zanbug-config');

        if (!config('zanbug.enabled') || !config('zanbug.token') || !config('zanbug.host')) {
            return;
        }

        $this->registerExceptionReporting();
        $this->registerSlowQueryListener();
        $this->registerHttpClientListener();
    }

    private function registerExceptionReporting(): void
    {
        $skipClasses = config('zanbug.skip_exceptions', []);
        $captureValidation = config('zanbug.capture_validation', true);

        $this->callAfterResolving(ExceptionHandler::class, function ($handler) use ($skipClasses, $captureValidation) {
            $handler->reportable(function (Throwable $e) use ($skipClasses, $captureValidation) {
                // İstifadəçi tərəfindən söndürülmüş exception sinifləri
                foreach ($skipClasses as $class) {
                    if ($e instanceof $class) {
                        return false;
                    }
                }

                // Validation xətaları — ayrıca konfiqurasiya ilə idarə olunur
                if ($e instanceof ValidationException) {
                    if (!$captureValidation) {
                        return false;
                    }
                    app(ZanbugClient::class)->captureException($e);
                    return false;
                }

                // 4xx HTTP xətaları — server problemi deyil, keç
                if ($e instanceof HttpException && $e->getStatusCode() < 500) {
                    return false;
                }

                app(ZanbugClient::class)->captureException($e);

                return false; // default reporting-ı dayandırma
            });
        });
    }

    private function registerHttpClientListener(): void
    {
        // Http facade yalnız Laravel-in tam versiyasında mövcuddur
        if (!class_exists(\Illuminate\Support\Facades\Http::class)) {
            return;
        }

        if (!config('zanbug.capture_http_errors', true)) {
            return;
        }

        $client = $this->app->make(ZanbugClient::class);

        \Illuminate\Support\Facades\Http::globalResponseMiddleware(
            function ($response) use ($client) {
                // 4xx → warning (token bitib, forbidden...), 5xx → error
                if ($response->failed()) {
                    $client->captureHttpFailure($response);
                }
                return $response;
            }
        );
    }

    private function registerSlowQueryListener(): void
    {
        $thresholdMs = (float) config('zanbug.slow_query_ms', 500);

        if ($thresholdMs <= 0) {
            return;
        }

        if (!isset($this->app['db'])) {
            return;
        }

        $this->app['db']->listen(function ($query) use ($thresholdMs) {
            if ($query->time >= $thresholdMs) {
                app(ZanbugClient::class)->captureSlowQuery($query->sql, $query->time);
            }
        });
    }
}
