<?php

return [
    /*
     * Proyektin SDK token-i — Zanbug panel-dən kopyalanır.
     * Əlavə et: ZANBUG_TOKEN=zbg_xxxxxxxxxxxxxxxx
     */
    'token' => env('ZANBUG_TOKEN'),

    /*
     * Zanbug backend URL-i (sonda slash olmadan).
     * Əlavə et: ZANBUG_HOST=http://localhost:3001
     */
    'host' => env('ZANBUG_HOST', 'http://localhost:3001'),

    /*
     * false edərək SDK-nı müvəqqəti söndürə bilərsiniz.
     */
    'enabled' => env('ZANBUG_ENABLED', true),

    /*
     * Slow query həddi (millisaniyə). 0 = söndürülmüş.
     * Default: 500ms
     */
    'slow_query_ms' => (int) env('ZANBUG_SLOW_QUERY_MS', 500),

    /*
     * Validation xətalarını warning olaraq göndər.
     * Bu, istifadəçilərin göndərdiyi yanlış fieldləri izləmək üçündür.
     */
    'capture_validation' => env('ZANBUG_CAPTURE_VALIDATION', true),

    /*
     * Http::get/post/... ilə edilən xarici API çağırışlarında
     * 4xx (warning) və 5xx (error) cavabları avtomatik göndər.
     * ZANBUG_CAPTURE_HTTP=false ilə söndürmək olar.
     */
    'capture_http_errors' => env('ZANBUG_CAPTURE_HTTP', true),

    /*
     * Bu exception siniflərini Zanbug-a göndərmə.
     */
    'skip_exceptions' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Http\Exceptions\ThrottleRequestsException::class,
        \Illuminate\Session\TokenMismatchException::class,
    ],
];
