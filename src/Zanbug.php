<?php

namespace Zanbug\Laravel;

/**
 * Əl ilə çağırış üçün qısa giriş nöqtəsi.
 *
 *   \Zanbug\Laravel\Zanbug::capture($e);
 *
 * Laravel 5.5+ paket aşkarlaması `Zanbug` alias-ını da qeydiyyatdan keçirir,
 * yəni orada sadəcə `\Zanbug::capture($e)` yazmaq kifayətdir.
 *
 * İki yerdə lazım olur:
 *   - Laravel 5–7: reportable() olmadığı üçün Handler::report() əl ilə override edilir
 *   - Hər versiyada: try/catch içində tutulan xəta (cron, artisan, queue)
 *
 * MİNİMUM PHP: 5.6 — bax ZanbugClient-in başlığındakı qadağa siyahısı.
 */
class Zanbug
{
    /**
     * @param \Exception|\Throwable $e
     *
     * Tip elanı yoxdur: PHP 5.6-da \Throwable mövcud deyil, PHP 7+-də isə
     * handler \Throwable ötürür. Tipsiz imza hər ikisini qəbul edir.
     */
    public static function capture($e)
    {
        $client = self::client();
        if ($client !== null) {
            $client->captureException($e);
        }
    }

    /**
     * Konteynerdən klienti alır. Konfiqurasiya söndürülübsə və ya Laravel
     * hələ qalxmayıbsa null qaytarır — çağıran tərəfdə try/catch lazım deyil.
     *
     * @return ZanbugClient|null
     */
    public static function client()
    {
        try {
            if (!function_exists('app') || !function_exists('config')) {
                return null;
            }
            if (!config('zanbug.enabled', true)) {
                return null;
            }

            return app('Zanbug\Laravel\ZanbugClient');
        } catch (\Exception $x) {
            return null;
        } catch (\Throwable $x) {
            return null;
        }
    }
}
