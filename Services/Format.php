<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Services;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Форматирование значений, общее для экранов и виджетов.
 *
 * Вынесено отдельно, чтобы виджет и админ-экран показывали одно и то же число
 * одинаково: расхождение здесь выглядит как расхождение в данных.
 */
final class Format
{
    /**
     * Время из базы (UTC) в поясе панели.
     *
     * В базе всё лежит в UTC — это соглашение плагина. Единственное место,
     * где UTC покидает данные, — вывод, и оно должно быть одно на модуль,
     * иначе экраны и вьюхи начнут показывать разное время для одной строки.
     */
    public static function time(mixed $utc, string $format = 'd.m.Y H:i'): string
    {
        if (!is_scalar($utc) || (string) $utc === '') {
            return '—';
        }

        try {
            return (new DateTimeImmutable((string) $utc, new DateTimeZone('UTC')))
                ->setTimezone(self::panelTimezone())
                ->format($format);
        } catch (Throwable) {
            return '—';
        }
    }

    public static function panelTimezone(): DateTimeZone
    {
        try {
            return new DateTimeZone((string) config('app.timezone', 'UTC'));
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * Ссылка на аватар, пригодная для атрибута src.
     *
     * Источники разные: Steam отдаёт абсолютный URL, а у пользователя сайта
     * в базе лежит ОТНОСИТЕЛЬНЫЙ путь вида assets/... — его обязан развернуть
     * asset(). Именно поэтому аватары показывались «не всегда»: у найденных
     * в Steam всё работало, у зарегистрированных на сайте — нет.
     *
     * Пусто — путь к аватару по умолчанию, а не пустой src: пустой атрибут
     * браузер трактует как ссылку на саму страницу и грузит её повторно.
     */
    public static function avatar(mixed $avatar): string
    {
        $value = is_scalar($avatar) ? trim((string) $avatar) : '';

        if ($value === '') {
            $value = (string) config('profile.default_avatar', 'assets/img/no_avatar.webp');
        }

        // Абсолютный URL (Steam) и protocol-relative отдаём как есть
        if (preg_match('#^(https?:)?//#i', $value) === 1) {
            return $value;
        }

        try {
            return (string) asset($value);
        } catch (Throwable) {
            return $value;
        }
    }

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
