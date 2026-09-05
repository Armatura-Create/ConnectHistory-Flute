<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Нормализованный набор фильтров экрана.
 *
 * Класс намеренно НЕ знает про Flute: на вход приходит обычный массив (обычно
 * request()->query->all()), на выходе — значения, уже приведённые к безопасным
 * типам и границам. Благодаря этому вся логика фильтрации проверяется юнит-тестами
 * без поднятия CMS.
 *
 * Главное свойство: окно выборки ограничено ВСЕГДА. Предыдущий модуль позволял
 * запросить 360 дней и тянул всю историю в память (docs/AUTOPSY.md №1).
 */
final class SessionFilter
{
    public const STATE_ALL = 'all';
    public const STATE_ONLINE = 'online';
    public const STATE_CLOSED = 'closed';
    public const STATE_CRASHED = 'crashed';

    public const GROUP_NONE = 'none';
    public const GROUP_PLAYER = 'player';
    public const GROUP_MAP = 'map';
    public const GROUP_COUNTRY = 'country';
    public const GROUP_DAY = 'day';
    public const GROUP_REASON = 'reason';

    /**
     * Символы, зарезервированные PSR-6 в ключах кеша. Ключ с любым из них
     * не кешируется, а бросает исключение.
     */
    public const CACHE_RESERVED_CHARACTERS = '{}()/\\@:';

    /** Значение end_kind для аварийно оборванной сессии. */
    public const END_KIND_STALE = 5;

    public const STATES = [
        self::STATE_ALL,
        self::STATE_ONLINE,
        self::STATE_CLOSED,
        self::STATE_CRASHED,
    ];

    public const GROUPS = [
        self::GROUP_NONE,
        self::GROUP_PLAYER,
        self::GROUP_MAP,
        self::GROUP_COUNTRY,
        self::GROUP_DAY,
        self::GROUP_REASON,
    ];

    /** Варианты периода в интерфейсе: ключ -> число дней. */
    public const PERIODS = [
        '1d' => 1,
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
        '180d' => 180,
        '365d' => 365,
    ];

    private function __construct(
        public readonly ?int $serverId,
        public readonly int $periodDays,
        public readonly ?string $dateFrom,
        public readonly ?string $dateTo,
        public readonly string $state,
        public readonly string $groupBy,
        public readonly ?string $map,
        public readonly ?string $country,
        public readonly ?string $reason,
        public readonly ?string $search,
        public readonly ?int $minDuration,
        public readonly ?int $maxDuration,
        public readonly bool $onlyNew,
        public readonly int $minSessionSeconds,
    ) {
    }

    /**
     * @param array<string, mixed> $input   Обычно request()->query->all()
     * @param array<string, mixed> $options max_period_days, default_period_days, short_session_seconds
     */
    public static function fromArray(array $input, array $options = []): self
    {
        $maxDays = max(1, (int) ($options['max_period_days'] ?? 365));
        $defaultDays = self::clamp((int) ($options['default_period_days'] ?? 7), 1, $maxDays);
        $shortSeconds = max(0, (int) ($options['short_session_seconds'] ?? 60));

        $dateFrom = self::date($input['date_from'] ?? null);
        $dateTo = self::date($input['date_to'] ?? null);

        // Перевёрнутый диапазон — почти всегда опечатка, а не намерение
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return new self(
            serverId: self::positiveIntOrNull($input['server'] ?? null),
            periodDays: self::period($input['period'] ?? null, $defaultDays, $maxDays),
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            state: self::oneOf($input['state'] ?? null, self::STATES, self::STATE_ALL),
            groupBy: self::oneOf($input['group'] ?? null, self::GROUPS, self::GROUP_NONE),
            map: self::text($input['map'] ?? null, 64),
            country: self::country($input['country'] ?? null),
            reason: self::text($input['reason'] ?? null, 64),
            search: self::text($input['search'] ?? null, 128),
            minDuration: self::minutesToSeconds($input['min_minutes'] ?? null),
            maxDuration: self::minutesToSeconds($input['max_minutes'] ?? null),
            onlyNew: self::bool($input['only_new'] ?? null),
            minSessionSeconds: self::bool($input['skip_short'] ?? null) ? $shortSeconds : 0,
        );
    }

    /**
     * Нижняя граница окна в UTC.
     *
     * Явная дата важнее периода, но период всё равно ограничивает окно сверху:
     * date_from вне разрешённого окна подтягивается к его началу.
     */
    public function from(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $windowStart = $now->sub(new DateInterval('P' . $this->periodDays . 'D'));

        if ($this->dateFrom === null) {
            return $windowStart;
        }

        $explicit = new DateTimeImmutable($this->dateFrom . ' 00:00:00', new DateTimeZone('UTC'));

        return $explicit < $windowStart ? $windowStart : $explicit;
    }

    /** Верхняя граница окна в UTC (включительно, до конца суток). */
    public function to(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($this->dateTo === null) {
            return $now;
        }

        $explicit = new DateTimeImmutable($this->dateTo . ' 23:59:59', new DateTimeZone('UTC'));

        return $explicit > $now ? $now : $explicit;
    }

    /** Сколько суток реально попадает в окно — для выбора шага графика. */
    public function spanDays(?DateTimeImmutable $now = null): int
    {
        $seconds = $this->to($now)->getTimestamp() - $this->from($now)->getTimestamp();

        return max(1, (int) ceil($seconds / 86400));
    }

    /** На коротком окне осмысленна разбивка по часам, на длинном — по дням. */
    public function useHourlyBuckets(?DateTimeImmutable $now = null): bool
    {
        return $this->spanDays($now) <= 2;
    }

    /**
     * Ключ кеша: одинаковые фильтры дают одинаковый результат.
     *
     * Разделитель — точка, а НЕ двоеточие: в PSR-6 символы {}()/\@: зарезервированы,
     * и Symfony Cache бросает InvalidArgumentException на таком ключе. Двоеточие
     * в ключе означало не «медленнее», а «кеша нет вовсе» — исключение проглатывалось,
     * и каждый заход шёл в базу. Проверяется тестом.
     */
    public function cacheKey(string $scope): string
    {
        return 'connecthistory.' . $scope . '.' . substr(sha1(serialize([
            $this->serverId,
            $this->periodDays,
            $this->dateFrom,
            $this->dateTo,
            $this->state,
            $this->groupBy,
            $this->map,
            $this->country,
            $this->reason,
            $this->search,
            $this->minDuration,
            $this->maxDuration,
            $this->onlyNew,
            $this->minSessionSeconds,
        ])), 0, 16);
    }

    /** Активен ли хоть один фильтр помимо периода — для подсветки кнопки сброса. */
    public function hasNarrowing(): bool
    {
        return $this->state !== self::STATE_ALL
            || $this->map !== null
            || $this->country !== null
            || $this->reason !== null
            || $this->search !== null
            || $this->minDuration !== null
            || $this->maxDuration !== null
            || $this->onlyNew
            || $this->minSessionSeconds > 0
            || $this->dateFrom !== null
            || $this->dateTo !== null;
    }

    /**
     * Похожа ли строка поиска на SteamID64, а не на ник.
     *
     * Везде в классе якорь \z вместо $: в PCRE $ совпадает и перед завершающим
     * переводом строки, из-за чего "76561198000000001\n" считался бы валидным.
     */
    public function searchIsSteamId(): bool
    {
        return $this->search !== null && preg_match('/^7656119\d{10}\z/', $this->search) === 1;
    }

    // --- нормализация ------------------------------------------------------

    private static function period(mixed $value, int $default, int $max): int
    {
        if (is_string($value) && isset(self::PERIODS[$value])) {
            return min(self::PERIODS[$value], $max);
        }

        if (is_numeric($value)) {
            return self::clamp((int) $value, 1, $max);
        }

        return $default;
    }

    private static function oneOf(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }

    private static function text(mixed $value, int $maxLength): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $maxLength);
    }

    /**
     * Код страны проверяется ДО обрезки по длине: иначе "RUS" молча превратился бы
     * в "RU", то есть модуль угадал бы страну за пользователя. Не двухбуквенный
     * код — это не код страны, и фильтр просто не применяется.
     */
    private static function country(mixed $value): ?string
    {
        $text = self::text($value, 16);

        return $text !== null && preg_match('/^[A-Za-z]{2}\z/', $text) === 1 ? strtoupper($text) : null;
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function minutesToSeconds(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $minutes = (int) $value;

        return $minutes > 0 ? $minutes * 60 : null;
    }

    private static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
