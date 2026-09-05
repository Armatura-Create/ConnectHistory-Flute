<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Services;

/**
 * Форматирование значений, общее для экранов и виджетов.
 *
 * Вынесено отдельно, чтобы виджет и админ-экран показывали одно и то же число
 * одинаково: расхождение здесь выглядит как расхождение в данных.
 */
final class Format
{
    /** «2 ч 14 мин» вместо «8040». */
    public static function duration(mixed $seconds): string
    {
        if (!is_numeric($seconds)) {
            return '—';
        }

        $total = (int) $seconds;

        if ($total <= 0) {
            return '—';
        }

        $days = intdiv($total, 86400);
        $hours = intdiv($total % 86400, 3600);
        $minutes = intdiv($total % 3600, 60);

        if ($days > 0) {
            return $days . __('connecthistory.units.d') . ' ' . $hours . __('connecthistory.units.h');
        }

        if ($hours > 0) {
            return $hours . __('connecthistory.units.h') . ' ' . $minutes . __('connecthistory.units.m');
        }

        return max(1, $minutes) . __('connecthistory.units.m');
    }

    /**
     * Крупные числа с разделителем разрядов: «12 480» читается, «12480» — нет.
     * Неразрывный пробел, чтобы число не переносилось по строке.
     */
    public static function number(mixed $value): string
    {
        return number_format((float) (is_numeric($value) ? $value : 0), 0, ',', "\u{00A0}");
    }

    /** Секунды в часы — для метрик вида «наиграно всего». */
    public static function hours(mixed $seconds): string
    {
        return self::number((int) round((is_numeric($seconds) ? (float) $seconds : 0.0) / 3600));
    }

    public static function percent(mixed $value): string
    {
        return (is_numeric($value) ? (float) $value : 0.0) . '%';
    }
}
