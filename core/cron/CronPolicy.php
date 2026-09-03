<?php

declare(strict_types=1);

/**
 * Regras centrais de intervalo para cron global, cron por loja e integração cron-job.org (entrada).
 */
final class CronPolicy
{
    public const INTERVAL_MIN_MINUTES = 1;
    public const INTERVAL_MAX_MINUTES = 720;

    public static function intervalMinMinutes(): int
    {
        return self::INTERVAL_MIN_MINUTES;
    }

    public static function intervalMaxMinutes(): int
    {
        return self::INTERVAL_MAX_MINUTES;
    }

    /**
     * Normaliza minutos para o intervalo permitido pelo sistema de cron.
     */
    public static function normalizeInterval(int $minutes): int
    {
        return max(self::INTERVAL_MIN_MINUTES, min(self::INTERVAL_MAX_MINUTES, $minutes));
    }

    /**
     * Intervalo em minutos → passo K em horas no :00 (alinhado à lógica cron-job.org no backend).
     *
     * @return array<int, int>
     */
    public static function intervaloMinutosParaKHorasMap(): array
    {
        return [
            60 => 1,
            120 => 2,
            180 => 3,
            240 => 4,
            360 => 6,
            480 => 8,
            self::intervalMaxMinutes() => 12,
        ];
    }

    /**
     * JSON seguro para embutir em <script>: config do cron para o admin (window.CRON_CONFIG).
     */
    public static function adminScriptConfigJson(): string
    {
        return json_encode(
            [
                'maxInterval' => self::intervalMaxMinutes(),
                'kPorIv' => self::intervaloMinutosParaKHorasMap(),
            ],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
        );
    }
}
