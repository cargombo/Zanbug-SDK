<?php

namespace Zanbug\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * MİNİMUM PHP: 5.6 — bax ZanbugClient-in başlığındakı qadağa siyahısı.
 * MİNİMUM LARAVEL: 5.0
 *
 * Versiya fərqləri burada bir yerdə toplanıb:
 *
 *   reportable()                  → Laravel 8+  (5–7: əl ilə report() override)
 *   Http::globalResponseMiddleware() → Laravel 11+
 *   paket avtomatik aşkarlanması  → Laravel 5.5+ (aşağıda config/app.php-ə əl ilə)
 */
class ZanbugServiceProvider extends ServiceProvider
{
    /** ExceptionHandler kontraktı — sinif sabiti köhnə Laravel-də də var. */
    const HANDLER_CONTRACT = 'Illuminate\Contracts\Debug\ExceptionHandler';

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/zanbug.php', 'zanbug');

        $this->app->singleton('Zanbug\Laravel\ZanbugClient', function ($app) {
            $config = $app['config'];

            return new ZanbugClient(
                (string) $config->get('zanbug.token', ''),
                (string) $config->get('zanbug.host', '')
            );
        });
    }

    public function boot()
    {
        $this->publishes(array(
            __DIR__ . '/../config/zanbug.php' => config_path('zanbug.php'),
        ), 'zanbug-config');

        if (!config('zanbug.enabled') || !config('zanbug.token') || !config('zanbug.host')) {
            return;
        }

        $this->registerExceptionReporting();
        $this->registerSlowQueryListener();
        $this->registerHttpClientListener();
    }

    /**
     * Laravel-in exception handler-inə qoşulur.
     *
     * callAfterResolving() ServiceProvider-ə sonradan əlavə olunub, ona görə
     * birbaşa konteynerin afterResolving()-i işlədilir — o, Laravel 5.0-dan var.
     * Handler artıq həll olunubsa afterResolving bir daha işləməyəcək, ona görə
     * resolved() ilə həmin hal ayrıca tutulur.
     */
    private function registerExceptionReporting()
    {
        $skipClasses       = config('zanbug.skip_exceptions', array());
        $captureValidation = config('zanbug.capture_validation', true);
        $app               = $this->app;

        $attach = function ($handler) use ($skipClasses, $captureValidation, $app) {
            // reportable() Laravel 8-dən var. Laravel 5–7-də, həmçinin tətbiq
            // ExceptionHandler kontraktını öz sinfi ilə əvəz edibsə, yoxdur —
            // yoxlamasaq boot-da fatal error alırıq.
            if (!is_object($handler) || !method_exists($handler, 'reportable')) {
                return;
            }

            $handler->reportable(function ($e) use ($skipClasses, $captureValidation, $app) {
                // İstifadəçi tərəfindən söndürülmüş exception sinifləri
                foreach ($skipClasses as $class) {
                    if ($e instanceof $class) {
                        return false;
                    }
                }

                $isValidation = $e instanceof \Illuminate\Validation\ValidationException;

                // Validation xətaları — ayrıca konfiqurasiya ilə idarə olunur
                if ($isValidation && !$captureValidation) {
                    return false;
                }

                // 4xx HTTP xətaları — server problemi deyil, keç
                if (!$isValidation
                    && $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
                    && $e->getStatusCode() < 500
                ) {
                    return false;
                }

                $app->make('Zanbug\Laravel\ZanbugClient')->captureException($e);

                return false; // false = Laravel öz log-unu davam etdirsin
            });
        };

        if (method_exists($this->app, 'resolved') && $this->app->resolved(self::HANDLER_CONTRACT)) {
            $attach($this->app->make(self::HANDLER_CONTRACT));
        }

        $this->app->afterResolving(self::HANDLER_CONTRACT, $attach);
    }

    private function registerHttpClientListener()
    {
        if (!config('zanbug.capture_http_errors', true)) {
            return;
        }

        /**
         * globalResponseMiddleware() Laravel 11-də əlavə olunub.
         *
         * Laravel 5–10-da Http facade MÖVCUD ola bilər, amma bu metod yoxdur:
         * çağırış PendingRequest-ə ötürülür, o da BadMethodCallException atır —
         * provider boot-da olduğu üçün BÜTÜN TƏTBİQ ÇÖKÜR.
         * class_exists() bunu tutmur, metodun özü yoxlanmalıdır.
         *
         * method_exists() sinif adı ilə işləyir: sinif yoxdursa (Laravel 5–6)
         * sadəcə false qaytarır, avtoyükləmə tetiklənmir.
         */
        if (!method_exists('Illuminate\Http\Client\Factory', 'globalResponseMiddleware')) {
            return;
        }

        $client = $this->app->make('Zanbug\Laravel\ZanbugClient');

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

    private function registerSlowQueryListener()
    {
        $thresholdMs = (float) config('zanbug.slow_query_ms', 500);

        if ($thresholdMs <= 0 || !isset($this->app['db'])) {
            return;
        }

        $app = $this->app;

        $this->app['db']->listen(function ($query) use ($thresholdMs, $app) {
            // Laravel 5.1-dən əvvəl listen() ($sql, $bindings, $time) ötürürdü;
            // obyekt gəlmirsə sadəcə keçirik.
            if (!is_object($query) || !isset($query->time) || !isset($query->sql)) {
                return;
            }

            if ((float) $query->time >= $thresholdMs) {
                $app->make('Zanbug\Laravel\ZanbugClient')
                    ->captureSlowQuery($query->sql, (float) $query->time);
            }
        });
    }
}
