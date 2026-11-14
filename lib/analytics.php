<?php
declare(strict_types=1);

const GG_ANALYTICS_RETENTION_DAYS = 365;
const GG_ANALYTICS_TIMEZONE = 'Asia/Tokyo';
/** screenshot_open.reason 允许的取值。报告里「人主动挑的」只数 click / scene（SQL 里写死），deeplink / modal / 空串不进榜。 */
const GG_ANALYTICS_OPEN_SOURCES = ['click', 'deeplink', 'modal', 'scene'];
/** 同一会话里键入同一个词的前缀（xuan → xuank → xuankai）只算最后一次；间隔超过这个秒数就算两次搜索。 */
const GG_ANALYTICS_TYPING_GAP_SECONDS = 8;

function gg_analytics_path(): string
{
    return getenv('GG_ANALYTICS_DB') ?: dirname(__DIR__, 2) . '/.data/gallery-analytics/stats.db';
}

function gg_analytics_day(int $now): string
{
    return (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(GG_ANALYTICS_TIMEZONE))->format('Y-m-d');
}

function gg_analytics_origin(array $server): bool
{
    return in_array($server['HTTP_ORIGIN'] ?? '', ['https://pokeamice.com', 'https://www.pokeamice.com'], true)
        && !in_array($server['HTTP_SEC_FETCH_SITE'] ?? '', ['cross-site'], true);
}

function gg_analytics_browser(array $server): bool
{
    $ua = trim((string)($server['HTTP_USER_AGENT'] ?? ''));
    return ($server['HTTP_DNT'] ?? '') !== '1' && ($server['HTTP_SEC_GPC'] ?? '') !== '1'
        && str_starts_with($ua, 'Mozilla/')
        && !preg_match('/bot|crawl|spider|slurp|archiver|headless|lightpanda|phantomjs|puppeteer|playwright|selenium|webdriver|bytespider|petalbot|yisou|sogou|externalagent|facebookexternalhit|semrush|ahrefs|mj12|dataforseo|censys|zgrab|expanse|python|go-http-client|axios|node-fetch|undici|scrapy|httpx|aiohttp|libwww|curl\/|wget\//i', $ua);
}

function gg_analytics_uuid(mixed $value): bool
{
    return is_string($value) && (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $value);
}

function gg_analytics_text(mixed $value, int $limit): string
{
    if (!is_string($value)) return '';
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 0, $limit);
}

/** Validate the complete batch before opening any database. */
function gg_analytics_validate(mixed $payload): array
{
    if (!is_array($payload) || !gg_analytics_uuid($payload['visitorId'] ?? null)
        || !gg_analytics_uuid($payload['sessionId'] ?? null) || !is_array($payload['events'] ?? null)
        || !array_is_list($payload['events']) || count($payload['events']) < 1 || count($payload['events']) > 12) {
        throw new InvalidArgumentException('Invalid batch');
    }
    $events = [];
    foreach ($payload['events'] as $event) {
        if (!is_array($event) || !gg_analytics_uuid($event['id'] ?? null)
            || !in_array($event['kind'] ?? '', ['page_view', 'search_submit', 'search_result', 'screenshot_open', 'filter_change'], true)) {
            throw new InvalidArgumentException('Invalid event');
        }
        $page = $event['page'] ?? '';
        if (!is_string($page) || strlen($page) > 240 || str_contains($page, '..')
            || !preg_match('~^/game_gallery(?:/?|/(?:index|mark|scene|vision)\.php|/story/[a-zA-Z0-9%._\~/-]*)$~D', $page)) {
            throw new InvalidArgumentException('Invalid page');
        }
        $kind = $event['kind'];
        $query = $kind === 'search_submit' ? gg_analytics_text($event['q'] ?? '', 80) : '';
        $searchId = $kind === 'search_submit' ? $event['id'] : '';
        $outcome = ''; $shown = null; $more = null; $screenshotId = null;
        if ($kind === 'search_submit' && $query === '') throw new InvalidArgumentException('Missing search');
        if ($kind === 'search_result') {
            if (!gg_analytics_uuid($event['searchId'] ?? null) || !in_array($event['outcome'] ?? '', ['success', 'error'], true)) {
                throw new InvalidArgumentException('Invalid result');
            }
            $searchId = $event['searchId']; $outcome = $event['outcome'];
            if ($outcome === 'success') {
                if (!is_int($event['resultsShown'] ?? null) || $event['resultsShown'] < 0 || $event['resultsShown'] > 100
                    || !is_bool($event['hasMore'] ?? null)) throw new InvalidArgumentException('Invalid result count');
                $shown = $event['resultsShown']; $more = (int)$event['hasMore'];
            }
        }
        if ($kind === 'screenshot_open') {
            if (!is_int($event['screenshotId'] ?? null) || $event['screenshotId'] < 1) throw new InvalidArgumentException('Invalid screenshot');
            $screenshotId = $event['screenshotId'];
        }
        $events[] = ['id' => strtolower($event['id']), 'kind' => $kind, 'page' => $page, 'query' => $query,
            'game' => gg_analytics_text($event['game'] ?? '', 80), 'search_id' => strtolower($searchId),
            'screenshot_id' => $screenshotId, 'results_shown' => $shown, 'has_more' => $more, 'outcome' => $outcome,
            // screenshot_open 的 reason 是「怎么打开的」：click 图库网格里点开 / deeplink 带 ?screenshot= 载入 /
            // modal 在详情里翻上一张下一张或点相关图 / scene 剧情页里点图。旧前端不带，记空串（未知来源）。
            'reason' => in_array($event['reason'] ?? '', $kind === 'screenshot_open'
                ? GG_ANALYTICS_OPEN_SOURCES : ['input', 'submit', 'url', 'filter', 'sort', 'evolution'], true) ? $event['reason'] : ''];
    }
    $source = $payload['source'] ?? 'direct';
    return ['visitorId' => strtolower($payload['visitorId']), 'sessionId' => strtolower($payload['sessionId']), 'events' => $events,
        'source' => is_string($source) && preg_match('/^[a-zA-Z0-9.-]{1,160}$/D', $source) ? strtolower($source) : 'direct'];
}

function gg_analytics_db(string $path, bool $write = true): PDO
{
    if (!$write && !is_file($path)) throw new RuntimeException('Analytics database not yet created');
    $mask = umask(0077);
    try {
        $dsn = $write ? 'sqlite:' . $path : 'sqlite:file:' . $path . '?mode=ro';
        $db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 3]);
        $db->exec('PRAGMA busy_timeout=3000');
        if (!$write) { $db->exec('PRAGMA query_only=ON'); return $db; }
        $version = (int)$db->query('PRAGMA user_version')->fetchColumn();
        if (!in_array($version, [0, 1], true)) throw new RuntimeException('Unsupported analytics schema');
        if ($version === 0) {
            if ((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn() > 0) {
                throw new RuntimeException('Refusing a database owned by another application');
            }
            $db->exec('PRAGMA journal_mode=WAL');
            $db->exec('BEGIN IMMEDIATE');
            try {
            // Another first request may have finished initialization while we waited.
            $version = (int)$db->query('PRAGMA user_version')->fetchColumn();
            if ($version === 0) {
            if ((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn() > 0) {
                throw new RuntimeException('Refusing a database owned by another application');
            }
            $db->exec(<<<'SQL'
                CREATE TABLE events (
                    id TEXT PRIMARY KEY, at INTEGER NOT NULL, day TEXT NOT NULL, kind TEXT NOT NULL,
                    visitor TEXT NOT NULL, session TEXT NOT NULL, page TEXT NOT NULL, query TEXT NOT NULL,
                    game TEXT NOT NULL, search_id TEXT NOT NULL, screenshot_id INTEGER,
                    results_shown INTEGER, has_more INTEGER, outcome TEXT NOT NULL, reason TEXT NOT NULL,
                    source TEXT NOT NULL, device TEXT NOT NULL
                );
                CREATE INDEX events_at ON events(at);
                CREATE INDEX events_day_kind ON events(day,kind);
                CREATE INDEX events_search ON events(search_id);
                CREATE UNIQUE INDEX events_result_unique ON events(search_id) WHERE kind='search_result';
                CREATE TABLE meta (key TEXT PRIMARY KEY,value TEXT NOT NULL);
                CREATE TABLE rate_limits (client TEXT NOT NULL,minute INTEGER NOT NULL,count INTEGER NOT NULL,PRIMARY KEY(client,minute));
                PRAGMA user_version=1;
            SQL);
            $db->prepare("INSERT INTO meta VALUES ('key',?),('last_prune','0')")->execute([bin2hex(random_bytes(32))]);
            }
            $db->exec('COMMIT');
            } catch (Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
        }
        return $db;
    } finally { umask($mask); }
}

function gg_analytics_record(PDO $db, array $batch, array $server, int $now): int
{
    $key = (string)$db->query("SELECT value FROM meta WHERE key='key'")->fetchColumn();
    if (strlen($key) !== 64) throw new RuntimeException('Missing analytics key');
    $day = gg_analytics_day($now);
    $visitor = hash_hmac('sha256', $day . ':visitor:' . $batch['visitorId'], $key);
    $session = hash_hmac('sha256', $day . ':session:' . $batch['sessionId'], $key);
    $client = hash_hmac('sha256', $day . ':limit:' . ($server['REMOTE_ADDR'] ?? '') . ':' . ($server['HTTP_USER_AGENT'] ?? ''), $key);
    $ua = (string)($server['HTTP_USER_AGENT'] ?? '');
    $device = preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : (preg_match('/Mobile|Android|iPhone/i', $ua) ? 'mobile' : 'desktop');
    $db->exec('BEGIN IMMEDIATE');
    try {
        $minute = intdiv($now, 60);
        $q = $db->prepare('SELECT count FROM rate_limits WHERE client=? AND minute=?'); $q->execute([$client, $minute]);
        if ((int)$q->fetchColumn() + count($batch['events']) > 600) throw new OverflowException('Rate limited');
        $db->prepare('INSERT INTO rate_limits VALUES (?,?,?) ON CONFLICT(client,minute) DO UPDATE SET count=count+excluded.count')
            ->execute([$client, $minute, count($batch['events'])]);
        $q = $db->prepare('INSERT OR IGNORE INTO events VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $recorded = 0;
        foreach ($batch['events'] as $event) {
            $q->execute([$event['id'], $now, $day, $event['kind'], $visitor, $session, $event['page'], $event['query'],
                $event['game'], $event['search_id'], $event['screenshot_id'], $event['results_shown'], $event['has_more'],
                $event['outcome'], $event['reason'], $batch['source'], $device]);
            $recorded += $q->rowCount();
        }
        $lastPrune = (int)$db->query("SELECT value FROM meta WHERE key='last_prune'")->fetchColumn();
        if ($lastPrune < $now - 3600) {
            $db->prepare('DELETE FROM events WHERE at < ?')->execute([$now - GG_ANALYTICS_RETENTION_DAYS * 86400]);
            $db->prepare('DELETE FROM rate_limits WHERE minute < ?')->execute([$minute - 10]);
            $db->prepare("UPDATE meta SET value=? WHERE key='last_prune'")->execute([(string)$now]);
        }
        $db->exec('COMMIT');
        return $recorded;
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}

/**
 * 把「键入」产生的一串前缀搜索折叠成最后一次，并把「打开带 ?q= 的链接」单独数。
 * 前端防抖后每个前缀都会发一条 search_submit（xuan → xuank → xuankai），原始行数会把一次搜索算成好几次。
 * 折叠规则：同一会话、同一作品筛选、reason 是 input / submit、相邻两条间隔不超过 GG_ANALYTICS_TYPING_GAP_SECONDS，
 * 且后一条与前一条互为前缀（含完全相同：键入后按回车）→ 只留最后一条。reason=url 是载入链接 / 刷新，不算人搜了什么。
 * @return array{typed: list<array<string,mixed>>, links: int}
 */
function gg_analytics_final_searches(PDO $db, int $start, int $now): array
{
    $q = $db->prepare("SELECT s.at,s.day,s.session,s.query,s.game,s.reason,COALESCE(NULLIF(r.outcome,''),'pending') AS outcome,r.results_shown
        FROM events s LEFT JOIN events r ON r.search_id=s.id AND r.kind='search_result'
        WHERE s.kind='search_submit' AND s.at>=? AND s.at<=? ORDER BY s.session,s.at,s.id");
    $q->execute([$start, $now]);
    $typed = []; $links = 0; $prev = null; $session = null;
    while ($row = $q->fetch()) {
        if ($row['reason'] === 'url') { $links++; continue; }
        if (!in_array($row['reason'], ['input', 'submit'], true)) continue;
        if ($session !== $row['session']) {
            if ($prev !== null) $typed[] = $prev;
            $prev = null; $session = $row['session'];
        }
        if ($prev !== null && $row['game'] === $prev['game'] && (int)$row['at'] - (int)$prev['at'] <= GG_ANALYTICS_TYPING_GAP_SECONDS) {
            $a = mb_strtolower($row['query']); $b = mb_strtolower($prev['query']);
            if ($a === $b || str_starts_with($a, $b) || str_starts_with($b, $a)) { $prev = $row; continue; }
        }
        if ($prev !== null) $typed[] = $prev;
        $prev = $row;
    }
    if ($prev !== null) $typed[] = $prev;
    return ['typed' => $typed, 'links' => $links];
}

/** Read-only report; search submissions are distinct from first-page results and pagination. */
function gg_analytics_report(PDO $db, int $days = 365, ?int $now = null): array
{
    $now ??= time(); $days = max(1, min(GG_ANALYTICS_RETENTION_DAYS, $days));
    $start = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(GG_ANALYTICS_TIMEZONE))
        ->setTime(0, 0)->modify('-' . ($days - 1) . ' days')->getTimestamp();
    $run = static function (string $sql, array $args = []) use ($db): array { $q = $db->prepare($sql); $q->execute($args); return $q->fetchAll(); };
    $events = $run('SELECT kind,COUNT(*) AS count FROM events WHERE at>=? AND at<=? GROUP BY kind', [$start, $now]);
    $daily = $run("SELECT day,SUM(kind='page_view') AS views,COUNT(DISTINCT CASE WHEN kind='page_view' THEN visitor END) AS visitors,
        COUNT(DISTINCT CASE WHEN kind='page_view' THEN session END) AS sessions,SUM(kind='search_submit') AS searches,
        SUM(kind='screenshot_open') AS screenshots,
        SUM(kind='search_submit' AND reason='url') AS link_searches,
        SUM(kind='screenshot_open' AND reason IN ('click','scene')) AS chosen_opens,
        COUNT(DISTINCT CASE WHEN (kind='search_submit' AND reason IN ('input','submit')) OR kind='filter_change'
            OR (kind='screenshot_open' AND reason IN ('click','scene','modal')) THEN session END) AS interactive_sessions
        FROM events WHERE at>=? AND at<=? GROUP BY day ORDER BY day", [$start, $now]);
    $searchSql = " FROM events s LEFT JOIN events r ON r.search_id=s.id AND r.kind='search_result'
        WHERE s.kind='search_submit' AND s.at>=? AND s.at<=?";
    // 搜索词榜：键入的前缀折叠成最后一次、不含「打开 ?q= 链接」，按不同会话数排序；原始行数仍在 totals.searches。
    $final = gg_analytics_final_searches($db, $start, $now);
    $terms = []; $finalByDay = []; $finalSessions = [];
    foreach ($final['typed'] as $s) {
        $key = mb_strtolower($s['query']) . "\0" . $s['game'];
        $terms[$key] ??= ['query' => $s['query'], 'game' => $s['game'], 'searches' => 0, 'sessions' => [], 'days' => [], 'zero_results' => 0, 'errors' => 0, 'pending' => 0];
        $terms[$key]['searches']++; $terms[$key]['sessions'][$s['session']] = true; $terms[$key]['days'][$s['day']] = true;
        if ($s['outcome'] === 'error') $terms[$key]['errors']++;
        elseif ($s['outcome'] === 'pending') $terms[$key]['pending']++;
        elseif ((int)$s['results_shown'] === 0) $terms[$key]['zero_results']++;
        $finalByDay[$s['day']] = ($finalByDay[$s['day']] ?? 0) + 1; $finalSessions[$s['session']] = true;
    }
    $searches = array_map(static fn(array $t): array => ['query' => $t['query'], 'game' => $t['game'], 'searches' => $t['searches'],
        'sessions' => count($t['sessions']), 'days' => count($t['days']), 'zero_results' => $t['zero_results'], 'errors' => $t['errors'], 'pending' => $t['pending']], array_values($terms));
    usort($searches, static fn(array $a, array $b): int => [$b['sessions'], $b['searches'], $a['query']] <=> [$a['sessions'], $a['searches'], $b['query']]);
    $searches = array_slice($searches, 0, 100);
    foreach ($daily as &$dayRow) $dayRow['final_searches'] = $finalByDay[$dayRow['day']] ?? 0;
    unset($dayRow);
    $interactive = (int)$run("SELECT COUNT(DISTINCT session) AS n FROM events WHERE at>=? AND at<=? AND ((kind='search_submit' AND reason IN ('input','submit'))
        OR kind='filter_change' OR (kind='screenshot_open' AND reason IN ('click','scene','modal')))", [$start, $now])[0]['n'];
    $summary = $run("SELECT COUNT(*) AS searches,COALESCE(SUM(r.outcome='success'),0) AS completed,
        COALESCE(SUM(r.outcome='success' AND r.results_shown=0),0) AS zeroResults,
        COALESCE(SUM(r.outcome='error'),0) AS errors,COALESCE(SUM(r.id IS NULL),0) AS pending" . $searchSql, [$start, $now])[0];
    $counts = array_column($events, 'count', 'kind');
    return ['generatedAt' => (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(GG_ANALYTICS_TIMEZONE))->format(DATE_ATOM),
        'timezone' => GG_ANALYTICS_TIMEZONE, 'retentionDays' => GG_ANALYTICS_RETENTION_DAYS, 'days' => $days,
        'firstRetainedAt' => $db->query('SELECT MIN(at) FROM events')->fetchColumn() ?: null,
        'totals' => ['pageViews' => $counts['page_view'] ?? 0, 'dailyBrowsersSum' => array_sum(array_column($daily, 'visitors')),
            'screenshotOpens' => $counts['screenshot_open'] ?? 0, 'filters' => $counts['filter_change'] ?? 0, 'searches' => $summary,
            'finalSearches' => ['typed' => count($final['typed']), 'sessions' => count($finalSessions), 'linkLoads' => $final['links']],
            'interactiveSessions' => $interactive],
        'events' => $events, 'daily' => $daily, 'searchTerms' => $searches,
        'pages' => $run("SELECT page,COUNT(*) AS views FROM events WHERE kind='page_view' AND at>=? AND at<=? GROUP BY page ORDER BY views DESC LIMIT 100", [$start, $now]),
        // 截图榜：按「点开过它的不同会话数」（reason=click / scene，人主动挑的）排序；深链、详情里翻页、旧版无来源的单列，不进排序第一键。
        'screenshots' => $run("SELECT screenshot_id,COUNT(DISTINCT CASE WHEN reason IN ('click','scene') THEN session END) AS chosen_sessions,
            COUNT(DISTINCT session) AS sessions,COUNT(*) AS opens,SUM(reason='deeplink') AS deeplink_opens,SUM(reason='modal') AS modal_opens,
            SUM(reason NOT IN ('click','scene','deeplink','modal')) AS unknown_opens FROM events WHERE kind='screenshot_open' AND at>=? AND at<=?
            GROUP BY screenshot_id ORDER BY chosen_sessions DESC,sessions DESC,opens DESC,screenshot_id LIMIT 100", [$start, $now]),
        'openSources' => $run("SELECT CASE WHEN reason='' THEN 'unknown' ELSE reason END AS source,COUNT(*) AS opens,COUNT(DISTINCT session) AS sessions
            FROM events WHERE kind='screenshot_open' AND at>=? AND at<=? GROUP BY 1 ORDER BY opens DESC", [$start, $now]),
        'sources' => $run("SELECT source,COUNT(*) AS views FROM events WHERE kind='page_view' AND at>=? AND at<=? GROUP BY source ORDER BY views DESC LIMIT 100", [$start, $now]),
        'devices' => $run("SELECT device,COUNT(*) AS views FROM events WHERE kind='page_view' AND at>=? AND at<=? GROUP BY device ORDER BY views DESC", [$start, $now]),
        'notes' => ['Daily anonymous browser counts are not unique people across days.',
            'Results count the first response page; has_more means additional results exist.',
            'searchTerms: one entry per typed search (prefixes typed within ' . GG_ANALYTICS_TYPING_GAP_SECONDS . ' s collapse to the last one), excluding opened ?q= links (totals.finalSearches.linkLoads); ranked by distinct sessions. totals.searches keeps the raw event counts.',
            'screenshots: ranked by distinct sessions that clicked the screenshot open (reason click/scene). deeplink opens, in-dialog navigation (modal) and rows recorded before the source field existed (unknown) are listed but do not rank. Daily visitor/session hashes rotate, so sessions are per-day.',
            'interactive sessions: typed a search, changed a filter or clicked a screenshot; a session with only a page view or a deep link is not counted.',
            'No IP, complete user-agent, raw browser UUID, URL query string or account identity is stored.']];
}

function gg_analytics_search_rows(PDO $db, int $days = 365, ?int $now = null): Generator
{
    $now ??= time(); $days = max(1, min(365, $days));
    $start = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(GG_ANALYTICS_TIMEZONE))
        ->setTime(0, 0)->modify('-' . ($days - 1) . ' days')->getTimestamp();
    $query = $db->prepare("SELECT s.at,s.query,s.game,s.reason,r.results_shown,r.has_more,COALESCE(NULLIF(r.outcome,''),'pending') AS outcome
        FROM events s LEFT JOIN events r ON r.search_id=s.id AND r.kind='search_result'
        WHERE s.kind='search_submit' AND s.at>=? AND s.at<=? ORDER BY s.at,s.id");
    $query->execute([$start, $now]);
    while ($row = $query->fetch()) yield $row;
}

function gg_analytics_csv_cell(mixed $value): mixed
{
    // Public search input must remain a literal when opened in a spreadsheet.
    return is_string($value) && preg_match('/^[\s]*[=+@-]/u', $value) ? "'" . $value : $value;
}
