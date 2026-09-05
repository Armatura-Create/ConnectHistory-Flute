<?php

declare(strict_types=1);

namespace Flute\Modules\ConnectHistory\Services;

use Cycle\Database\Injection\Fragment;
use Cycle\Database\Query\SelectQuery;
use DateTimeImmutable;
use Flute\Core\Database\Entities\DatabaseConnection;
use Flute\Modules\ConnectHistory\Admin\Drivers\ConnectHistoryModDriver;
use Throwable;

/**
 * Весь SQL модуля. Только чтение чужой базы, которую наполняет игровой плагин.
 *
 * Два правила, из которых следует всё остальное:
 *
 * 1. Агрегат считается в SQL. Метод не возвращает сырые строки ради того, чтобы
 *    посчитать их в PHP — стоимость страницы не должна зависеть от объёма истории
 *    (docs/AUTOPSY.md №1).
 * 2. Права определяют список SELECT. Персональные колонки не «прячутся в вёрстке»,
 *    их просто нет в запросе, если нет права (docs/AUTOPSY.md №3).
 *
 * Время в базе — UTC. Сравнение с «сейчас» всегда через UTC_TIMESTAMP(),
 * NOW() отдал бы время в поясе сессии MySQL.
 */
final class HistoryRepository
{
    /** Колонки сессии, доступные всем. */
    public const SESSION_COLUMNS = [
        'id', 'steamid64', 'account_id', 'server_id', 'nickname',
        'started_at', 'ended_at', 'duration_seconds', 'spectator_seconds', 'end_kind',
        'connect_map', 'disconnect_map', 'disconnect_reason_name',
        'players_online', 'max_players', 'client_lang',
        'country_iso', 'country_name',
        'kills', 'deaths', 'assists', 'headshots', 'damage', 'mvp', 'score',
        'rounds_played', 'team_final', 'team_changes',
        'ping_avg', 'ping_min', 'ping_max', 'ping_samples',
        'plugin_version',
    ];

    /** Колонки за правом admin.connecthistory.pii. */
    public const PII_COLUMNS = ['player_ip', 'ip_hash', 'ip_subnet', 'city'];

    /**
     * @param string $database Имя подключения в конфиге панели
     * @param string $prefix   Префикс таблиц плагина (уже проверен белым списком)
     * @param int    $serverId server_id плагина; 0 — все серверы этой базы
     */
    public function __construct(
        private readonly string $database,
        private readonly string $prefix,
        private readonly int $serverId,
    ) {
    }

    // =====================================================================
    // Привязки «сервер панели -> сервер плагина»
    // =====================================================================

    /**
     * Все подключения с модом ConnectHistory.
     *
     * Читаем сущность DatabaseConnection напрямую, а не через DatabaseService:
     * его методы возвращают массивы разной формы (getServersByMode отдаёт
     * ['server', 'dbname'], getConnectionInfoByServerId — ['server', 'connection']),
     * и опечатка в ключе массива не ловится ни тестом, ни статическим анализом —
     * раздел просто молча считает себя ненастроенным. Свойства сущности типизованы,
     * и PHPStan проверяет их против настоящего класса Flute.
     *
     * @return array<int, array{server: object, database: string, prefix: string, server_id: int}>
     */
    public static function bindings(): array
    {
        try {
            $connections = DatabaseConnection::query()
                ->with('server')
                ->where('mod', ConnectHistoryModDriver::MOD_KEY)
                ->fetchAll();
        } catch (Throwable $e) {
            logs()->warning('[ConnectHistory] Не удалось получить список подключений: ' . $e->getMessage());

            return [];
        }

        $result = [];

        foreach ($connections as $connection) {
            $server = $connection->server;

            // Подключение может остаться без сервера, если сервер удалили
            if ($server === null) {
                continue;
            }

            // Мод объявлен, но самого подключения нет в конфиге панели
            if (!config("database.databases.{$connection->dbname}")) {
                logs()->warning(
                    "[ConnectHistory] Подключение «{$connection->dbname}» указано у сервера "
                    . "«{$server->name}», но отсутствует в config/database.php"
                );

                continue;
            }

            $additional = ServerBinding::readAdditional($connection->additional);

            $result[(int) $server->id] = [
                'server' => $server,
                'database' => (string) $connection->dbname,
                'prefix' => $additional['prefix'],
                'server_id' => $additional['server_id'],
            ];
        }

        return $result;
    }

    /**
     * Почему раздел считает себя ненастроенным.
     *
     * Существует ради одного сценария: человек прошёл все шаги инструкции, а экран
     * всё равно говорит «не настроено». Без этого метода единственный способ
     * разобраться — читать логи панели.
     *
     * Вызывается только на пустом экране, поэтому лишний запрос не важен.
     *
     * @return array{total: int, usable: int, problems: array<int, string>}
     */
    public static function diagnostics(): array
    {
        try {
            $connections = DatabaseConnection::query()
                ->with('server')
                ->where('mod', ConnectHistoryModDriver::MOD_KEY)
                ->fetchAll();
        } catch (Throwable $e) {
            return ['total' => 0, 'usable' => 0, 'problems' => [$e->getMessage()]];
        }

        $problems = [];
        $usable = 0;

        foreach ($connections as $connection) {
            $name = (string) $connection->dbname;

            if ($connection->server === null) {
                $problems[] = __('connecthistory.setup.problem_no_server', ['database' => $name]);

                continue;
            }

            if (!config("database.databases.{$name}")) {
                $problems[] = __('connecthistory.setup.problem_no_database', ['database' => $name]);

                continue;
            }

            ++$usable;
        }

        return [
            'total' => count($connections),
            'usable' => $usable,
            'problems' => $problems,
        ];
    }

    /** Опции для фильтра «Сервер»: id сервера панели -> название. */
    public static function serverOptions(): array
    {
        $options = [];

        foreach (self::bindings() as $id => $binding) {
            $options[$id] = (string) ($binding['server']->name ?? ('#' . $id));
        }

        return $options;
    }

    /**
     * Репозиторий для выбранного сервера панели.
     *
     * Одна база плагина обслуживает несколько игровых серверов, они различаются
     * колонкой server_id. Поэтому «ничего не выбрано» НЕ означает «показать всё»:
     * когда привязка одна, статистика считается по её серверу. Иначе в разделе
     * смешались бы данные соседних серверов, писавших в ту же базу, — а панель
     * о них ничего не знает и показать их отдельно всё равно не может.
     *
     * Явное «все серверы» доступно, только когда привязок несколько: тогда
     * сравнение между ними — осмысленный вопрос.
     *
     * server_id = 0 в привязке (поле не заполнено) снимает фильтр: пустой раздел
     * хуже, чем раздел со всеми данными, и о проблеме скажет экран «Серверы».
     */
    public static function for(?int $fluteServerId): ?self
    {
        $bindings = self::bindings();

        if ($bindings === []) {
            return null;
        }

        if ($fluteServerId !== null && isset($bindings[$fluteServerId])) {
            $binding = $bindings[$fluteServerId];

            return new self($binding['database'], $binding['prefix'], $binding['server_id']);
        }

        $first = reset($bindings);

        // Одна привязка — «по умолчанию» и «этот сервер» совпадают
        return new self(
            $first['database'],
            $first['prefix'],
            count($bindings) === 1 ? $first['server_id'] : 0
        );
    }

    public function isConfigured(): bool
    {
        return $this->database !== '';
    }

    // =====================================================================
    // Таблицы (отдаются в LayoutFactory::table)
    // =====================================================================

    /**
     * Сессии построчно.
     *
     * Возвращается SelectQuery, а не массив: пагинацию, сортировку и LIMIT делает
     * платформа на стороне SQL. В PHP приезжает ровно одна страница.
     */
    public function sessionsQuery(SessionFilter $filter, bool $withPii): SelectQuery
    {
        $columns = self::SESSION_COLUMNS;

        if ($withPii) {
            $columns = array_merge($columns, self::PII_COLUMNS);
        }

        $query = $this->db()->select($columns)->from($this->table('sessions'));

        return $this->applyFilter($query, $filter);
    }

    /**
     * Свод по выбранному измерению.
     *
     * Здесь возвращается массив, а не SelectQuery, сознательно: Cycle SelectQuery::count()
     * игнорирует GROUP BY, поэтому пагинатор платформы посчитал бы число сессий вместо
     * числа групп и показал бы неверное количество страниц.
     *
     * Память ограничена не объёмом истории, а $limit: в PHP приезжает не более
     * $limit агрегированных строк независимо от того, сколько сессий в них свернулось.
     *
     * @return array<int, array<string, mixed>>
     */
    public function groupedSessions(SessionFilter $filter, int $limit = 500): array
    {
        $dimension = match ($filter->groupBy) {
            SessionFilter::GROUP_PLAYER => ['`steamid64`', '`steamid64`'],
            SessionFilter::GROUP_MAP => ['`connect_map`', '`connect_map`'],
            SessionFilter::GROUP_COUNTRY => ['`country_iso`', '`country_iso`'],
            SessionFilter::GROUP_DAY => ['DATE(`started_at`)', 'DATE(`started_at`)'],
            SessionFilter::GROUP_REASON => ['`disconnect_reason_name`', '`disconnect_reason_name`'],
            default => null,
        };

        if ($dimension === null) {
            return [];
        }

        [$select, $groupBy] = $dimension;
        [$where, $params] = $this->conditions($filter);

        // MAX(nickname) — не «последний ник», а любой из встреченных: он нужен только
        // как подпись группы, точную историю ников отдаёт ch_nicknames.
        $sql = "SELECT {$select} AS `bucket`,
                       COUNT(*) AS `sessions`,
                       COUNT(DISTINCT `steamid64`) AS `players`,
                       COALESCE(SUM(`duration_seconds`), 0) AS `total_seconds`,
                       COALESCE(ROUND(AVG(`duration_seconds`)), 0) AS `avg_seconds`,
                       COALESCE(SUM(`kills`), 0) AS `kills`,
                       COALESCE(SUM(`deaths`), 0) AS `deaths`,
                       MAX(`nickname`) AS `nickname`,
                       MAX(`started_at`) AS `last_seen`
                FROM `{$this->table('sessions')}`
                WHERE {$where}
                GROUP BY {$groupBy}
                ORDER BY `sessions` DESC
                LIMIT {$this->int($limit, 1, 5000)}";

        return $this->fetch($sql, $params);
    }

    /** Агрегаты игроков. Отдаётся SelectQuery — таблица ch_players без GROUP BY. */
    public function playersQuery(SessionFilter $filter): SelectQuery
    {
        $query = $this->db()
            ->select([
                'steamid64', 'account_id', 'first_seen', 'last_seen',
                'sessions_count', 'total_seconds', 'last_nickname',
                'last_country', 'last_server_id',
            ])
            ->from($this->table('players'));

        if ($this->serverId > 0) {
            $query->where('last_server_id', $this->serverId);
        }

        $query->where('last_seen', '>=', $this->utc($filter->from()));

        if ($filter->onlyNew) {
            $query->where('first_seen', '>=', $this->utc($filter->from()));
        }

        if ($filter->country !== null) {
            $query->where('last_country', $filter->country);
        }

        if ($filter->minDuration !== null) {
            $query->where('total_seconds', '>=', $filter->minDuration);
        }

        if ($filter->search !== null) {
            $filter->searchIsSteamId()
                ? $query->where('steamid64', $filter->search)
                : $query->where('last_nickname', 'LIKE', '%' . $this->escapeLike($filter->search) . '%');
        }

        return $query;
    }

    // =====================================================================
    // Обзор
    // =====================================================================

    /** @return array<string, int|float> */
    public function overviewMetrics(SessionFilter $filter): array
    {
        [$where, $params] = $this->conditions($filter);

        $totals = $this->fetchOne(
            'SELECT COUNT(*) AS `sessions`,
                    COUNT(DISTINCT `steamid64`) AS `players`,
                    COALESCE(ROUND(AVG(`duration_seconds`)), 0) AS `avg_seconds`,
                    COALESCE(SUM(`duration_seconds`), 0) AS `total_seconds`,
                    SUM(CASE WHEN `end_kind` = ' . SessionFilter::END_KIND_STALE . " THEN 1 ELSE 0 END) AS `crashed`
             FROM `{$this->table('sessions')}`
             WHERE {$where}",
            $params
        );

        [$snapWhere, $snapParams] = $this->snapshotConditions($filter);

        $peak = $this->fetchOne(
            "SELECT COALESCE(MAX(`players`), 0) AS `peak`,
                    COALESCE(ROUND(AVG(`players`), 1), 0) AS `avg_online`
             FROM `{$this->table('online_snapshots')}`
             WHERE {$snapWhere}",
            $snapParams
        );

        $newcomers = $this->fetchOne(
            "SELECT COUNT(*) AS `newcomers`,
                    SUM(CASE WHEN `sessions_count` > 1 THEN 1 ELSE 0 END) AS `returned`
             FROM `{$this->table('players')}`
             WHERE `first_seen` >= ? AND `first_seen` <= ?"
            . ($this->serverId > 0 ? ' AND `last_server_id` = ?' : ''),
            $this->serverId > 0
                ? [$this->utc($filter->from()), $this->utc($filter->to()), $this->serverId]
                : [$this->utc($filter->from()), $this->utc($filter->to())]
        );

        $newcomerCount = (int) ($newcomers['newcomers'] ?? 0);
        $returnedCount = (int) ($newcomers['returned'] ?? 0);

        return [
            'sessions' => (int) ($totals['sessions'] ?? 0),
            'players' => (int) ($totals['players'] ?? 0),
            'avg_seconds' => (int) ($totals['avg_seconds'] ?? 0),
            'total_seconds' => (int) ($totals['total_seconds'] ?? 0),
            'crashed' => (int) ($totals['crashed'] ?? 0),
            'peak_online' => (int) ($peak['peak'] ?? 0),
            'avg_online' => (float) ($peak['avg_online'] ?? 0),
            'newcomers' => $newcomerCount,
            'returned' => $returnedCount,
            'retention' => $newcomerCount > 0 ? round($returnedCount / $newcomerCount * 100, 1) : 0.0,
        ];
    }

    /** Динамика онлайна: средний и пиковый по корзинам времени. */
    public function onlineTimeline(SessionFilter $filter): array
    {
        [$where, $params] = $this->snapshotConditions($filter);
        $bucket = $filter->useHourlyBuckets()
            ? "DATE_FORMAT(`taken_at`, '%Y-%m-%d %H:00')"
            : 'DATE(`taken_at`)';

        return $this->fetch(
            "SELECT {$bucket} AS `bucket`,
                    ROUND(AVG(`players`), 1) AS `avg_players`,
                    MAX(`players`) AS `peak_players`,
                    ROUND(AVG(`bots`), 1) AS `avg_bots`
             FROM `{$this->table('online_snapshots')}`
             WHERE {$where}
             GROUP BY `bucket`
             ORDER BY `bucket`",
            $params
        );
    }

    /** Заходы по корзинам времени: сколько сессий началось. */
    public function joins(SessionFilter $filter): array
    {
        [$where, $params] = $this->conditions($filter);
        $bucket = $filter->useHourlyBuckets()
            ? "DATE_FORMAT(`started_at`, '%Y-%m-%d %H:00')"
            : 'DATE(`started_at`)';

        return $this->fetch(
            "SELECT {$bucket} AS `bucket`,
                    COUNT(*) AS `sessions`,
                    COUNT(DISTINCT `steamid64`) AS `players`
             FROM `{$this->table('sessions')}`
             WHERE {$where}
             GROUP BY `bucket`
             ORDER BY `bucket`",
            $params
        );
    }

    /**
     * Тепловая карта «час суток x день недели».
     *
     * Считается по снимкам онлайна, а не по заходам: вопрос «когда сервер живой»
     * — это про количество людей на сервере, а не про частоту подключений.
     * WEEKDAY() отдаёт 0 = понедельник.
     */
    public function activityHeatmap(SessionFilter $filter): array
    {
        [$where, $params] = $this->snapshotConditions($filter);

        // Час и день недели считаются в поясе ПАНЕЛИ, а не в UTC.
        //
        // Иначе карта отвечает на вопрос «когда сервер живой по Гринвичу»,
        // что для Москвы сдвигает всю картину на три часа — а именно по ней
        // выбирают время ивентов.
        //
        // Сдвиг добавляется прямо в SQL, а не через CONVERT_TZ: та требует
        // загруженных таблиц часовых поясов MySQL, которых на типичном хостинге
        // нет. Смещение берётся на «сейчас», поэтому переход на летнее время
        // внутри длинного периода даёт погрешность в час — для карты
        // типичной активности это приемлемо.
        $shifted = "(`taken_at` + INTERVAL {$this->offsetMinutes()} MINUTE)";

        return $this->fetch(
            "SELECT WEEKDAY({$shifted}) AS `weekday`,
                    HOUR({$shifted}) AS `hour`,
                    ROUND(AVG(`players`), 1) AS `avg_players`,
                    MAX(`players`) AS `peak_players`
             FROM `{$this->table('online_snapshots')}`
             WHERE {$where}
             GROUP BY `weekday`, `hour`
             ORDER BY `weekday`, `hour`",
            $params
        );
    }

    /**
     * Смещение пояса панели от UTC в минутах, пригодное для подстановки в SQL.
     *
     * Значение вычисляется, а не приходит извне, и всё равно клампится:
     * оно попадает в текст запроса, где параметризовать INTERVAL нельзя.
     * Реальные пояса укладываются в +/-14 часов.
     */
    private function offsetMinutes(): int
    {
        try {
            $offset = Format::panelTimezone()->getOffset(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (Throwable) {
            return 0;
        }

        return $this->int(intdiv($offset, 60), -840, 840);
    }

    /** Новички по дням и сколько из них вернулось хотя бы раз. */
    public function newcomers(SessionFilter $filter): array
    {
        $params = [$this->utc($filter->from()), $this->utc($filter->to())];
        $serverCondition = '';

        if ($this->serverId > 0) {
            $serverCondition = ' AND `last_server_id` = ?';
            $params[] = $this->serverId;
        }

        return $this->fetch(
            "SELECT DATE(`first_seen`) AS `bucket`,
                    COUNT(*) AS `newcomers`,
                    SUM(CASE WHEN `sessions_count` > 1 THEN 1 ELSE 0 END) AS `returned`
             FROM `{$this->table('players')}`
             WHERE `first_seen` >= ? AND `first_seen` <= ?{$serverCondition}
             GROUP BY `bucket`
             ORDER BY `bucket`",
            $params
        );
    }

    /** Карты по наигранному времени. */
    public function topMaps(SessionFilter $filter, int $limit = 20): array
    {
        [$where, $params] = $this->conditions($filter);

        return $this->fetch(
            "SELECT `connect_map` AS `bucket`,
                    COUNT(*) AS `sessions`,
                    COUNT(DISTINCT `steamid64`) AS `players`,
                    COALESCE(ROUND(SUM(`duration_seconds`) / 3600, 1), 0) AS `hours`,
                    COALESCE(ROUND(AVG(`duration_seconds`) / 60, 1), 0) AS `avg_minutes`
             FROM `{$this->table('sessions')}`
             WHERE {$where} AND `connect_map` <> ''
             GROUP BY `connect_map`
             ORDER BY `hours` DESC
             LIMIT {$this->int($limit, 1, 100)}",
            $params
        );
    }

    /** Причины выхода. */
    public function disconnectReasons(SessionFilter $filter): array
    {
        [$where, $params] = $this->conditions($filter);

        return $this->fetch(
            "SELECT COALESCE(`disconnect_reason_name`, 'UNKNOWN') AS `bucket`,
                    COUNT(*) AS `sessions`
             FROM `{$this->table('sessions')}`
             WHERE {$where} AND `ended_at` IS NOT NULL
             GROUP BY `bucket`
             ORDER BY `sessions` DESC
             LIMIT 20",
            $params
        );
    }

    /** География: игроки и часы по странам. */
    public function geography(SessionFilter $filter, int $limit = 25): array
    {
        [$where, $params] = $this->conditions($filter);

        return $this->fetch(
            "SELECT `country_iso` AS `bucket`,
                    MAX(`country_name`) AS `country_name`,
                    COUNT(DISTINCT `steamid64`) AS `players`,
                    COUNT(*) AS `sessions`,
                    COALESCE(ROUND(SUM(`duration_seconds`) / 3600), 0) AS `hours`
             FROM `{$this->table('sessions')}`
             WHERE {$where} AND `country_iso` IS NOT NULL
             GROUP BY `country_iso`
             ORDER BY `players` DESC
             LIMIT {$this->int($limit, 1, 100)}",
            $params
        );
    }

    /**
     * Качество связи по странам.
     *
     * HAVING sessions > 20 — отсечка от шума: по трём сессиям средний пинг
     * страны ничего не значит.
     */
    public function pingByCountry(SessionFilter $filter, int $limit = 25): array
    {
        [$where, $params] = $this->conditions($filter);

        return $this->fetch(
            "SELECT `country_iso` AS `bucket`,
                    ROUND(AVG(`ping_avg`)) AS `avg_ping`,
                    MIN(`ping_min`) AS `min_ping`,
                    MAX(`ping_max`) AS `max_ping`,
                    COUNT(*) AS `sessions`
             FROM `{$this->table('sessions')}`
             WHERE {$where} AND `ping_samples` > 0 AND `country_iso` IS NOT NULL
             GROUP BY `country_iso`
             HAVING `sessions` > 20
             ORDER BY `avg_ping`
             LIMIT {$this->int($limit, 1, 100)}",
            $params
        );
    }

    /**
     * Карта падений сервера.
     *
     * end_kind = 5 (stale) — это не мусор, а факт: процесс сервера завершился
     * во время сессии, и плагин при следующем старте пометил её как оборванную.
     */
    public function crashes(SessionFilter $filter, int $limit = 50): array
    {
        [$where, $params] = $this->conditions($filter, ignoreState: true);

        return $this->fetch(
            "SELECT DATE(`started_at`) AS `bucket`,
                    `connect_map`,
                    COUNT(*) AS `interrupted_sessions`,
                    COUNT(DISTINCT `steamid64`) AS `players`
             FROM `{$this->table('sessions')}`
             WHERE {$where} AND `end_kind` = " . SessionFilter::END_KIND_STALE . "
             GROUP BY `bucket`, `connect_map`
             ORDER BY `bucket` DESC, `interrupted_sessions` DESC
             LIMIT {$this->int($limit, 1, 200)}",
            $params
        );
    }

    // =====================================================================
    // Карточка игрока
    // =====================================================================

    /** @return array<string, mixed>|null */
    public function player(string $steamid64): ?array
    {
        $row = $this->fetchOne(
            "SELECT `steamid64`, `account_id`, `first_seen`, `last_seen`,
                    `sessions_count`, `total_seconds`, `last_nickname`,
                    `last_country`, `last_server_id`
             FROM `{$this->table('players')}`
             WHERE `steamid64` = ?",
            [$steamid64]
        );

        return $row === [] ? null : $row;
    }

    public function playerNicknames(string $steamid64): array
    {
        return $this->fetch(
            "SELECT `nickname`, `first_seen`, `last_seen`, `times_seen`
             FROM `{$this->table('nicknames')}`
             WHERE `steamid64` = ?
             ORDER BY `last_seen` DESC
             LIMIT 50",
            [$steamid64]
        );
    }

    public function playerSessionsQuery(string $steamid64, bool $withPii): SelectQuery
    {
        $columns = self::SESSION_COLUMNS;

        if ($withPii) {
            $columns = array_merge($columns, self::PII_COLUMNS);
        }

        $query = $this->db()
            ->select($columns)
            ->from($this->table('sessions'))
            ->where('steamid64', $steamid64);

        if ($this->serverId > 0) {
            $query->where('server_id', $this->serverId);
        }

        return $query;
    }

    /** Активность игрока по дням — для спарклайна в карточке. */
    public function playerActivity(string $steamid64, int $days = 90): array
    {
        return $this->fetch(
            "SELECT DATE(`started_at`) AS `bucket`,
                    COUNT(*) AS `sessions`,
                    COALESCE(ROUND(SUM(`duration_seconds`) / 60), 0) AS `minutes`
             FROM `{$this->table('sessions')}`
             WHERE `steamid64` = ? AND `started_at` >= UTC_TIMESTAMP() - INTERVAL {$this->int($days, 1, 730)} DAY
             GROUP BY `bucket`
             ORDER BY `bucket`",
            [$steamid64]
        );
    }

    /**
     * Сводка по игроку за всё время: бой, связь, язык.
     *
     * Отдельным запросом, а не из ch_players: там лежат только время и число
     * заходов — всё остальное живёт в сессиях.
     *
     * @return array<string, mixed>
     */
    public function playerSummary(string $steamid64): array
    {
        return $this->fetchOne(
            'SELECT COALESCE(SUM(`kills`), 0) AS `kills`,
                    COALESCE(SUM(`deaths`), 0) AS `deaths`,
                    COALESCE(SUM(`assists`), 0) AS `assists`,
                    COALESCE(SUM(`headshots`), 0) AS `headshots`,
                    COALESCE(SUM(`damage`), 0) AS `damage`,
                    COALESCE(SUM(`mvp`), 0) AS `mvp`,
                    COALESCE(SUM(`rounds_played`), 0) AS `rounds`,
                    ROUND(AVG(NULLIF(`ping_avg`, 0))) AS `ping_avg`,
                    MIN(NULLIF(`ping_min`, 0)) AS `ping_min`,
                    MAX(`ping_max`) AS `ping_max`,
                    MAX(`client_lang`) AS `client_lang`,
                    COUNT(DISTINCT `country_iso`) AS `countries`,
                    SUM(CASE WHEN `end_kind` = ' . SessionFilter::END_KIND_STALE . " THEN 1 ELSE 0 END) AS `crashed`,
                    COALESCE(MAX(`players_online`), 0) AS `busiest`,
                    COALESCE(SUM(`spectator_seconds`), 0) AS `spectator_seconds`
             FROM `{$this->table('sessions')}`
             WHERE `steamid64` = ?",
            [$steamid64]
        );
    }

    /**
     * По каким серверам игрок ходил.
     *
     * server_id соединяется со справочником, чтобы показать имя, а не номер:
     * номер сервера плагина ничего не говорит человеку, смотрящему панель.
     */
    public function playerServers(string $steamid64): array
    {
        return $this->fetch(
            "SELECT s.`server_id`,
                    COALESCE(NULLIF(srv.`hostname`, ''), CONCAT('#', s.`server_id`)) AS `server_name`,
                    COUNT(*) AS `sessions`,
                    COALESCE(SUM(s.`duration_seconds`), 0) AS `total_seconds`,
                    MIN(s.`started_at`) AS `first_seen`,
                    MAX(s.`started_at`) AS `last_seen`
             FROM `{$this->table('sessions')}` s
             LEFT JOIN `{$this->table('servers')}` srv ON srv.`id` = s.`server_id`
             WHERE s.`steamid64` = ?
             GROUP BY s.`server_id`, `server_name`
             ORDER BY `total_seconds` DESC
             LIMIT 20",
            [$steamid64]
        );
    }

    /** Любимые карты игрока по наигранному времени. */
    public function playerMaps(string $steamid64, int $limit = 10): array
    {
        return $this->fetch(
            "SELECT `connect_map` AS `bucket`,
                    COUNT(*) AS `sessions`,
                    COALESCE(SUM(`duration_seconds`), 0) AS `total_seconds`
             FROM `{$this->table('sessions')}`
             WHERE `steamid64` = ? AND `connect_map` <> ''
             GROUP BY `connect_map`
             ORDER BY `total_seconds` DESC
             LIMIT {$this->int($limit, 1, 50)}",
            [$steamid64]
        );
    }

    /** Как игрок обычно покидает сервер: вышел сам, кикнут, потерял связь. */
    public function playerReasons(string $steamid64, int $limit = 10): array
    {
        return $this->fetch(
            "SELECT COALESCE(`disconnect_reason_name`, 'UNKNOWN') AS `bucket`,
                    COUNT(*) AS `sessions`,
                    MAX(`started_at`) AS `last_seen`
             FROM `{$this->table('sessions')}`
             WHERE `steamid64` = ? AND `ended_at` IS NOT NULL
             GROUP BY `bucket`
             ORDER BY `sessions` DESC
             LIMIT {$this->int($limit, 1, 50)}",
            [$steamid64]
        );
    }

    /**
     * История адресов игрока. ПЕРСОНАЛЬНЫЕ ДАННЫЕ.
     *
     * Вызывается только при праве admin.connecthistory.pii — как и колонки
     * player_ip в таблицах, запрос без права просто не выполняется.
     *
     * Группировка по адресу, а не по сессии: список из трёхсот строк с одним
     * и тем же IP не отвечает ни на один вопрос, а «с этого адреса заходил
     * тогда-то и столько-то раз» — отвечает.
     */
    public function playerIpHistory(string $steamid64, int $limit = 50): array
    {
        return $this->fetch(
            "SELECT `player_ip`, `ip_subnet`, `ip_hash`,
                    MAX(`country_iso`) AS `country_iso`,
                    MAX(`country_name`) AS `country_name`,
                    MAX(`city`) AS `city`,
                    COUNT(*) AS `sessions`,
                    MIN(`started_at`) AS `first_seen`,
                    MAX(`started_at`) AS `last_seen`
             FROM `{$this->table('sessions')}`
             WHERE `steamid64` = ? AND (`player_ip` IS NOT NULL OR `ip_hash` IS NOT NULL)
             GROUP BY `player_ip`, `ip_subnet`, `ip_hash`
             ORDER BY `last_seen` DESC
             LIMIT {$this->int($limit, 1, 200)}",
            [$steamid64]
        );
    }

    /**
     * Возможные мультиаккаунты: другие SteamID с того же хеша IP или подсети.
     *
     * Персональные данные — метод вызывается только при праве admin.connecthistory.pii.
     * Сам IP не выбирается: для ответа на вопрос «тот же ли это человек» достаточно
     * совпадения хеша.
     */
    public function possibleAlts(string $steamid64, int $days = 90, int $limit = 50): array
    {
        $window = $this->int($days, 1, 730);

        return $this->fetch(
            "SELECT s2.`steamid64`,
                    MAX(s2.`nickname`) AS `nickname`,
                    COUNT(*) AS `sessions`,
                    MAX(s2.`started_at`) AS `last_seen`,
                    COUNT(DISTINCT s2.`ip_hash`) AS `shared_hashes`
             FROM `{$this->table('sessions')}` s1
             JOIN `{$this->table('sessions')}` s2
               ON s2.`ip_hash` = s1.`ip_hash` AND s2.`steamid64` <> s1.`steamid64`
             WHERE s1.`steamid64` = ?
               AND s1.`ip_hash` IS NOT NULL
               AND s1.`started_at` >= UTC_TIMESTAMP() - INTERVAL {$window} DAY
               AND s2.`started_at` >= UTC_TIMESTAMP() - INTERVAL {$window} DAY
             GROUP BY s2.`steamid64`
             ORDER BY `sessions` DESC
             LIMIT {$this->int($limit, 1, 200)}",
            [$steamid64]
        );
    }

    // =====================================================================
    // Серверы
    // =====================================================================

    /**
     * Справочник серверов плагина + текущее состояние.
     *
     * address пустой или начинающийся с 0.0.0.0 означает, что плагин не смог
     * определить публичный адрес: ConVar ip отдаёт адрес привязки сокета.
     * Лечится настройкой Server.PublicAddress в конфиге плагина.
     */
    public function servers(): array
    {
        return $this->fetch(
            "SELECT srv.`id`, srv.`address`, srv.`hostname`, srv.`first_seen`, srv.`last_seen`,
                    (SELECT COUNT(*) FROM `{$this->table('sessions')}` s
                      WHERE s.`server_id` = srv.`id` AND s.`ended_at` IS NULL AND s.`end_kind` = 0) AS `online_now`,
                    (SELECT MAX(sn.`taken_at`) FROM `{$this->table('online_snapshots')}` sn
                      WHERE sn.`server_id` = srv.`id`) AS `last_snapshot`,
                    (SELECT COUNT(*) FROM `{$this->table('sessions')}` s
                      WHERE s.`server_id` = srv.`id`
                        AND s.`end_kind` = " . SessionFilter::END_KIND_STALE . "
                        AND s.`started_at` >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS `crashes_30d`
             FROM `{$this->table('servers')}` srv
             ORDER BY srv.`id`",
            []
        );
    }

    /** Сколько игроков сейчас на каждом сервере. Дёшево, но кешируется на 15 секунд. */
    public function onlineCounts(): array
    {
        $rows = $this->fetch(
            "SELECT `server_id`, COUNT(*) AS `online`
             FROM `{$this->table('sessions')}`
             WHERE `ended_at` IS NULL AND `end_kind` = 0
             GROUP BY `server_id`",
            []
        );

        return array_column($rows, 'online', 'server_id');
    }

    // =====================================================================
    // Опции фильтров
    // =====================================================================

    /** @return array<string, string> */
    public function mapOptions(SessionFilter $filter): array
    {
        [$where, $params] = $this->conditions($filter, ignoreNarrowing: true);

        $rows = $this->fetch(
            "SELECT `connect_map` AS `value`, COUNT(*) AS `n`
             FROM `{$this->table('sessions')}`
             WHERE {$where} AND `connect_map` <> ''
             GROUP BY `connect_map` ORDER BY `n` DESC LIMIT 100",
            $params
        );

        return array_combine(array_column($rows, 'value'), array_column($rows, 'value')) ?: [];
    }

    /** @return array<string, string> */
    public function countryOptions(SessionFilter $filter): array
    {
        [$where, $params] = $this->conditions($filter, ignoreNarrowing: true);

        $rows = $this->fetch(
            "SELECT `country_iso` AS `value`, MAX(`country_name`) AS `label`, COUNT(*) AS `n`
             FROM `{$this->table('sessions')}`
             WHERE {$where} AND `country_iso` IS NOT NULL
             GROUP BY `country_iso` ORDER BY `n` DESC LIMIT 100",
            $params
        );

        $options = [];

        foreach ($rows as $row) {
            $options[(string) $row['value']] = ((string) ($row['label'] ?? '')) !== ''
                ? $row['label'] . ' (' . $row['value'] . ')'
                : (string) $row['value'];
        }

        return $options;
    }

    /** @return array<string, string> */
    public function reasonOptions(SessionFilter $filter): array
    {
        [$where, $params] = $this->conditions($filter, ignoreNarrowing: true);

        $rows = $this->fetch(
            "SELECT `disconnect_reason_name` AS `value`, COUNT(*) AS `n`
             FROM `{$this->table('sessions')}`
             WHERE {$where} AND `disconnect_reason_name` IS NOT NULL
             GROUP BY `value` ORDER BY `n` DESC LIMIT 50",
            $params
        );

        return array_combine(array_column($rows, 'value'), array_column($rows, 'value')) ?: [];
    }

    // =====================================================================
    // Внутреннее
    // =====================================================================

    private function db()
    {
        return db($this->database);
    }

    private function table(string $name): string
    {
        return $this->prefix . $name;
    }

    /**
     * Условия для сырых агрегирующих запросов.
     *
     * Все значения уходят параметрами. В текст SQL попадают только имена колонок
     * и числовые константы, проверенные $this->int().
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function conditions(
        SessionFilter $filter,
        bool $ignoreState = false,
        bool $ignoreNarrowing = false,
    ): array {
        $where = ['`started_at` >= ?', '`started_at` <= ?'];
        $params = [$this->utc($filter->from()), $this->utc($filter->to())];

        if ($this->serverId > 0) {
            $where[] = '`server_id` = ?';
            $params[] = $this->serverId;
        }

        if ($ignoreNarrowing) {
            return [implode(' AND ', $where), $params];
        }

        if (!$ignoreState) {
            switch ($filter->state) {
                case SessionFilter::STATE_ONLINE:
                    $where[] = '`ended_at` IS NULL AND `end_kind` = 0';
                    break;
                case SessionFilter::STATE_CLOSED:
                    $where[] = '`ended_at` IS NOT NULL';
                    break;
                case SessionFilter::STATE_CRASHED:
                    $where[] = '`end_kind` = ' . SessionFilter::END_KIND_STALE;
                    break;
            }
        }

        if ($filter->map !== null) {
            $where[] = '`connect_map` = ?';
            $params[] = $filter->map;
        }

        if ($filter->country !== null) {
            $where[] = '`country_iso` = ?';
            $params[] = $filter->country;
        }

        if ($filter->reason !== null) {
            $where[] = '`disconnect_reason_name` = ?';
            $params[] = $filter->reason;
        }

        if ($filter->search !== null) {
            if ($filter->searchIsSteamId()) {
                $where[] = '`steamid64` = ?';
                $params[] = $filter->search;
            } else {
                $where[] = '`nickname` LIKE ?';
                $params[] = '%' . $this->escapeLike($filter->search) . '%';
            }
        }

        if ($filter->minDuration !== null) {
            $where[] = '`duration_seconds` >= ?';
            $params[] = $filter->minDuration;
        }

        if ($filter->maxDuration !== null) {
            $where[] = '`duration_seconds` <= ?';
            $params[] = $filter->maxDuration;
        }

        // Открытая сессия ещё не имеет длительности — её нельзя отбрасывать как короткую
        if ($filter->minSessionSeconds > 0) {
            $where[] = '(`duration_seconds` IS NULL OR `duration_seconds` >= ?)';
            $params[] = $filter->minSessionSeconds;
        }

        if ($filter->onlyNew) {
            $where[] = "`steamid64` IN (SELECT `steamid64` FROM `{$this->table('players')}` WHERE `first_seen` >= ?)";
            $params[] = $this->utc($filter->from());
        }

        return [implode(' AND ', $where), $params];
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private function snapshotConditions(SessionFilter $filter): array
    {
        $where = ['`taken_at` >= ?', '`taken_at` <= ?'];
        $params = [$this->utc($filter->from()), $this->utc($filter->to())];

        if ($this->serverId > 0) {
            $where[] = '`server_id` = ?';
            $params[] = $this->serverId;
        }

        return [implode(' AND ', $where), $params];
    }

    /** Те же условия, но для построителя запросов (таблица сессий построчно). */
    private function applyFilter(SelectQuery $query, SessionFilter $filter): SelectQuery
    {
        $query->where('started_at', '>=', $this->utc($filter->from()))
            ->where('started_at', '<=', $this->utc($filter->to()));

        if ($this->serverId > 0) {
            $query->where('server_id', $this->serverId);
        }

        switch ($filter->state) {
            case SessionFilter::STATE_ONLINE:
                $query->where('ended_at', '=', null)->where('end_kind', 0);
                break;
            case SessionFilter::STATE_CLOSED:
                $query->where('ended_at', '!=', null);
                break;
            case SessionFilter::STATE_CRASHED:
                $query->where('end_kind', SessionFilter::END_KIND_STALE);
                break;
        }

        if ($filter->map !== null) {
            $query->where('connect_map', $filter->map);
        }

        if ($filter->country !== null) {
            $query->where('country_iso', $filter->country);
        }

        if ($filter->reason !== null) {
            $query->where('disconnect_reason_name', $filter->reason);
        }

        if ($filter->search !== null) {
            $filter->searchIsSteamId()
                ? $query->where('steamid64', $filter->search)
                : $query->where('nickname', 'LIKE', '%' . $this->escapeLike($filter->search) . '%');
        }

        if ($filter->minDuration !== null) {
            $query->where('duration_seconds', '>=', $filter->minDuration);
        }

        if ($filter->maxDuration !== null) {
            $query->where('duration_seconds', '<=', $filter->maxDuration);
        }

        if ($filter->minSessionSeconds > 0) {
            $query->where(new Fragment(
                '(`duration_seconds` IS NULL OR `duration_seconds` >= ?)',
                $filter->minSessionSeconds
            ));
        }

        if ($filter->onlyNew) {
            $query->where(new Fragment(
                "`steamid64` IN (SELECT `steamid64` FROM `{$this->table('players')}` WHERE `first_seen` >= ?)",
                $this->utc($filter->from())
            ));
        }

        return $query;
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function fetch(string $sql, array $params): array
    {
        try {
            return $this->db()->query($sql, $params)->fetchAll();
        } catch (Throwable $e) {
            logs()->error('[ConnectHistory] Запрос не удался: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @param array<int, mixed> $params
     * @return array<string, mixed>
     */
    private function fetchOne(string $sql, array $params): array
    {
        $rows = $this->fetch($sql, $params);

        return $rows[0] ?? [];
    }

    /** Время уходит в базу как UTC-строка: колонки DATETIME пояса не хранят. */
    private function utc(DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }

    /**
     * Числовая константа, попадающая в текст SQL (LIMIT, INTERVAL).
     * Параметризовать их MySQL не даёт, поэтому — жёсткие границы.
     */
    private function int(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    /** Спецсимволы LIKE в пользовательском вводе — литералы, а не шаблон. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
