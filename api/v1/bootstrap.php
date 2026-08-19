<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

const API_V1_NAME = 'PokeAmice Game Gallery API';
const API_V1_VERSION = 'v1';
const API_V1_CONTRACT_VERSION = '1.0.0';
const API_V1_DEFAULT_LIMIT = 30;
const API_V1_MAX_LIMIT = 100;

function api_bootstrap(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Authorization, Content-Type, If-None-Match, X-GG-Guest-Id, X-GG-Auth-Token');
    header('Access-Control-Expose-Headers: ETag, Retry-After, X-API-Version, X-Request-Id, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset');
    header('Access-Control-Max-Age: 86400');
    header('X-API-Version: ' . API_V1_VERSION);
    header('X-Content-Type-Options: nosniff');
    header('X-Request-Id: ' . api_request_id());

    if (api_method() === 'OPTIONS') {
        header('Cache-Control: public, max-age=86400');
        http_response_code(204);
        exit;
    }
}

function api_project_root(): string
{
    return dirname(__DIR__, 2);
}

function api_method(): string
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function api_request_id(): string
{
    static $requestId;
    if (is_string($requestId)) return $requestId;
    $provided = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    $requestId = preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $provided)
        ? $provided
        : bin2hex(random_bytes(8));
    return $requestId;
}

function api_require_method(string ...$allowed): void
{
    $method = api_method();
    if (in_array($method, $allowed, true)) return;
    header('Allow: ' . implode(', ', array_merge($allowed, ['OPTIONS'])));
    api_error('METHOD_NOT_ALLOWED', '请求方法不受支持', 405, ['allowed' => $allowed]);
}

function api_run(callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        error_log(sprintf(
            '[game_gallery_api_v1] request_id=%s %s: %s',
            api_request_id(),
            $error::class,
            $error->getMessage()
        ));
        api_error('INTERNAL_ERROR', '服务暂时繁忙，请稍后重试', 500);
    }
}

function api_success(mixed $data, array $meta = [], int $cacheSeconds = 0, int $status = 200): never
{
    $payload = [
        'ok' => true,
        'data' => $data,
        'meta' => array_merge([
            'api_version' => API_V1_VERSION,
            'contract_version' => API_V1_CONTRACT_VERSION,
            'request_id' => api_request_id(),
        ], $meta),
    ];
    api_send_json($payload, $status, $cacheSeconds);
}

function api_error(string $code, string $message, int $status = 400, array $details = []): never
{
    $error = ['code' => $code, 'message' => $message];
    if ($details !== []) $error['details'] = $details;
    $payload = [
        'ok' => false,
        'error' => $error,
        'meta' => [
            'api_version' => API_V1_VERSION,
            'contract_version' => API_V1_CONTRACT_VERSION,
            'request_id' => api_request_id(),
        ],
    ];
    api_send_json($payload, $status, 0);
}

function api_send_json(array $payload, int $status, int $cacheSeconds): never
{
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );
    $etagPayload = $payload;
    unset($etagPayload['meta']['request_id']);
    $etagJson = json_encode(
        $etagPayload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );
    $etag = 'W/"' . hash('sha256', $etagJson) . '"';

    if ($cacheSeconds > 0 && api_method() === 'GET') {
        header('Cache-Control: public, max-age=' . $cacheSeconds . ', stale-while-revalidate=' . $cacheSeconds);
        header('ETag: ' . $etag);
        if (api_etag_matches($etag)) {
            http_response_code(304);
            exit;
        }
    } else {
        header('Cache-Control: no-store');
    }

    http_response_code($status);
    echo $json;
    exit;
}

function api_etag_matches(string $etag): bool
{
    $header = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($header === '') return false;
    foreach (explode(',', $header) as $candidate) {
        if (trim($candidate) === $etag || trim($candidate) === '*') return true;
    }
    return false;
}

function api_db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $path = api_project_root() . '/data/site.sqlite3';
    if (!is_file($path)) api_error('DATABASE_UNAVAILABLE', '图库数据库暂不可用', 503);
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=5000;');
    return $pdo;
}

function api_write_db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $path = api_project_root() . '/data/corrections.sqlite3';
    if (!is_dir(dirname($path)) || !is_writable(dirname($path))) {
        api_error('WRITE_DATABASE_UNAVAILABLE', '勘误存储暂不可用', 503);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA busy_timeout=5000;');
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS correction_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            screenshot_id INTEGER NOT NULL,
            category TEXT NOT NULL,
            original_text TEXT NOT NULL DEFAULT '',
            suggested_text TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            contact TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending',
            created_at REAL NOT NULL,
            updated_at REAL NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_correction_reports_screenshot ON correction_reports(screenshot_id);
        CREATE INDEX IF NOT EXISTS idx_correction_reports_created ON correction_reports(created_at DESC);
        CREATE TABLE IF NOT EXISTS api_rate_limits (
            scope TEXT NOT NULL,
            client_hash TEXT NOT NULL,
            window_start INTEGER NOT NULL,
            request_count INTEGER NOT NULL,
            PRIMARY KEY (scope, client_hash)
        );
        CREATE INDEX IF NOT EXISTS idx_api_rate_limits_window ON api_rate_limits(window_start);
    SQL);
    return $pdo;
}

function api_tagging_db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $path = api_project_root() . '/data/tagging.sqlite3';
    if (!is_dir(dirname($path)) || !is_writable(dirname($path))) {
        api_error('WRITE_DATABASE_UNAVAILABLE', '标注存储暂不可用', 503);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA busy_timeout=5000;');
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS identity_stats (
            identity_kind TEXT NOT NULL,
            identity_id TEXT NOT NULL,
            xp INTEGER NOT NULL DEFAULT 0,
            level INTEGER NOT NULL DEFAULT 1,
            streak INTEGER NOT NULL DEFAULT 0,
            best_streak INTEGER NOT NULL DEFAULT 0,
            labels_submitted INTEGER NOT NULL DEFAULT 0,
            verifies_submitted INTEGER NOT NULL DEFAULT 0,
            last_active_at REAL NOT NULL,
            created_at REAL NOT NULL,
            PRIMARY KEY (identity_kind, identity_id)
        );
        CREATE TABLE IF NOT EXISTS label_submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            screenshot_id INTEGER NOT NULL,
            category TEXT NOT NULL,
            canonical TEXT NOT NULL DEFAULT '',
            raw_input TEXT NOT NULL DEFAULT '',
            match_via TEXT NOT NULL DEFAULT '',
            answer_kind TEXT NOT NULL,
            identity_kind TEXT NOT NULL,
            identity_id TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            created_at REAL NOT NULL,
            updated_at REAL NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_label_submissions_lookup ON label_submissions(screenshot_id, category);
        CREATE INDEX IF NOT EXISTS idx_label_submissions_identity ON label_submissions(identity_kind, identity_id, screenshot_id, category);
        CREATE INDEX IF NOT EXISTS idx_label_submissions_status ON label_submissions(status);
        CREATE INDEX IF NOT EXISTS idx_label_submissions_consensus ON label_submissions(screenshot_id, category, canonical, identity_kind, identity_id);
        CREATE TABLE IF NOT EXISTS verify_submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            screenshot_id INTEGER NOT NULL,
            category TEXT NOT NULL,
            target_type TEXT NOT NULL,
            target_key TEXT NOT NULL,
            claim_text TEXT NOT NULL DEFAULT '',
            vote TEXT NOT NULL,
            identity_kind TEXT NOT NULL,
            identity_id TEXT NOT NULL,
            created_at REAL NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_verify_submissions_lookup ON verify_submissions(screenshot_id, category, target_type, target_key);
        CREATE INDEX IF NOT EXISTS idx_verify_submissions_identity ON verify_submissions(identity_kind, identity_id, screenshot_id, category, target_type, target_key);
        CREATE INDEX IF NOT EXISTS idx_verify_submissions_vote ON verify_submissions(vote);
        CREATE TABLE IF NOT EXISTS api_rate_limits (
            scope TEXT NOT NULL,
            client_hash TEXT NOT NULL,
            window_start INTEGER NOT NULL,
            request_count INTEGER NOT NULL,
            PRIMARY KEY (scope, client_hash)
        );
        CREATE INDEX IF NOT EXISTS idx_api_rate_limits_window ON api_rate_limits(window_start);
        CREATE TABLE IF NOT EXISTS confirmed_annotations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            screenshot_id INTEGER NOT NULL,
            category TEXT NOT NULL,
            canonical TEXT NOT NULL,
            status TEXT NOT NULL,
            support_count INTEGER NOT NULL DEFAULT 0,
            source TEXT NOT NULL,
            confirmed_at REAL NOT NULL,
            UNIQUE(screenshot_id, category, canonical)
        );
        CREATE INDEX IF NOT EXISTS idx_confirmed_annotations_screenshot ON confirmed_annotations(screenshot_id, status);
    SQL);
    $hasReliability = (bool)$pdo->query("SELECT 1 FROM pragma_table_info('identity_stats') WHERE name='reliability'")->fetchColumn();
    if (!$hasReliability) $pdo->exec('ALTER TABLE identity_stats ADD COLUMN reliability REAL');
    return $pdo;
}

function api_dataset_snapshot(?PDO $pdo = null): array
{
    static $snapshot;
    if (is_array($snapshot)) return $snapshot;
    $pdo ??= api_db();
    $screenshots = $pdo->query("SELECT COUNT(*) AS total, SUM(text <> '') AS recognized, MAX(updated_at) AS updated_at FROM screenshots")->fetch();
    $glossaryCount = (int)$pdo->query('SELECT COUNT(*) FROM glossary_matches')->fetchColumn();
    $corpusCount = (int)$pdo->query('SELECT COUNT(*) FROM screenshot_corpus_matches')->fetchColumn();
    $values = [
        'screenshots' => (int)$screenshots['total'],
        'recognized' => (int)$screenshots['recognized'],
        'glossary_matches' => $glossaryCount,
        'corpus_matches' => $corpusCount,
        'updated_at' => (float)$screenshots['updated_at'],
    ];
    $databasePath = api_project_root() . '/data/site.sqlite3';
    $walPath = $databasePath . '-wal';
    clearstatcache(true, $databasePath);
    clearstatcache(true, $walPath);
    $versionInputs = array_merge($values, [
        'database_size' => is_file($databasePath) ? (int)filesize($databasePath) : 0,
        'database_mtime' => is_file($databasePath) ? (int)filemtime($databasePath) : 0,
        'wal_size' => is_file($walPath) ? (int)filesize($walPath) : 0,
        'wal_mtime' => is_file($walPath) && (int)filesize($walPath) > 0 ? (int)filemtime($walPath) : 0,
    ]);
    $values['version'] = substr(hash('sha256', json_encode($versionInputs, JSON_THROW_ON_ERROR)), 0, 20);
    $values['updated_at_iso'] = gmdate('c', (int)$values['updated_at']);
    $snapshot = $values;
    return $snapshot;
}

function api_query_string(string $name, int $maxLength, string $default = ''): string
{
    $value = trim((string)($_GET[$name] ?? $default));
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    if (mb_strlen($value) > $maxLength) {
        api_error('INVALID_PARAMETER', "参数 {$name} 过长", 400, ['parameter' => $name, 'max_length' => $maxLength]);
    }
    return $value;
}

function api_query_int(string $name, int $default, int $minimum, int $maximum): int
{
    $raw = $_GET[$name] ?? null;
    if ($raw === null || $raw === '') return $default;
    $value = filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false || $value < $minimum || $value > $maximum) {
        api_error('INVALID_PARAMETER', "参数 {$name} 无效", 400, [
            'parameter' => $name,
            'minimum' => $minimum,
            'maximum' => $maximum,
        ]);
    }
    return (int)$value;
}

function api_query_bool(string $name, bool $default = false): bool
{
    if (!array_key_exists($name, $_GET)) return $default;
    $value = strtolower(trim((string)$_GET[$name]));
    if (in_array($value, ['1', 'true', 'yes'], true)) return true;
    if (in_array($value, ['0', 'false', 'no'], true)) return false;
    api_error('INVALID_PARAMETER', "参数 {$name} 无效", 400, ['parameter' => $name]);
}

function api_read_json_body(int $maxBytes = 32768): array
{
    $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
    if (!str_starts_with($contentType, 'application/json')) {
        api_error('UNSUPPORTED_MEDIA_TYPE', '请求体必须使用 application/json', 415);
    }
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > $maxBytes) api_error('PAYLOAD_TOO_LARGE', '请求内容过长', 413);
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || trim($raw) === '') api_error('INVALID_JSON', '请求体不能为空', 400);
    if (strlen($raw) > $maxBytes) api_error('PAYLOAD_TOO_LARGE', '请求内容过长', 413);
    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        api_error('INVALID_JSON', 'JSON 格式无效', 400);
    }
    if (!is_array($data)) api_error('INVALID_JSON', 'JSON 顶层必须是对象', 400);
    return $data;
}

function api_body_text(array $body, string $name, int $maxLength): string
{
    $value = trim((string)($body[$name] ?? ''));
    if (mb_strlen($value) > $maxLength) {
        api_error('INVALID_FIELD', "字段 {$name} 过长", 422, ['field' => $name, 'max_length' => $maxLength]);
    }
    return $value;
}

function api_rate_limit(PDO $pdo, string $scope, int $limit, int $windowSeconds): void
{
    $now = time();
    $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
    $client = api_client_address();
    $clientHash = hash('sha256', $scope . "\0" . $client);

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $select = $pdo->prepare('SELECT window_start, request_count FROM api_rate_limits WHERE scope=? AND client_hash=?');
        $select->execute([$scope, $clientHash]);
        $row = $select->fetch();
        $count = $row && (int)$row['window_start'] === $windowStart ? (int)$row['request_count'] + 1 : 1;
        $upsert = $pdo->prepare('INSERT INTO api_rate_limits(scope, client_hash, window_start, request_count) VALUES(?,?,?,?) ON CONFLICT(scope,client_hash) DO UPDATE SET window_start=excluded.window_start, request_count=excluded.request_count');
        $upsert->execute([$scope, $clientHash, $windowStart, $count]);
        $pdo->prepare('DELETE FROM api_rate_limits WHERE window_start < ?')->execute([$windowStart - 86400]);
        $pdo->exec('COMMIT');
    } catch (Throwable $error) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // The connection may already have rolled back the failed transaction.
        }
        throw $error;
    }

    header('X-RateLimit-Limit: ' . $limit);
    header('X-RateLimit-Remaining: ' . max(0, $limit - $count));
    header('X-RateLimit-Reset: ' . ($windowStart + $windowSeconds));
    if ($count > $limit) {
        header('Retry-After: ' . max(1, $windowStart + $windowSeconds - $now));
        api_error('RATE_LIMITED', '提交过于频繁，请稍后再试', 429);
    }
}

function api_client_address(): string
{
    $candidates = [];
    $realIp = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($realIp !== '') $candidates[] = $realIp;
    $forwarded = array_reverse(array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
    array_push($candidates, ...$forwarded);
    $candidates[] = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    foreach ($candidates as $candidate) {
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) return $candidate;
    }
    return 'unknown';
}

function api_canonical_game(string $game): string
{
    return match ($game) {
        '宝可梦传说 Z-A' => 'Pokémon LEGENDS Z-A',
        'Pokémon LEGENDS アルセウス' => '宝可梦传说 阿尔宙斯',
        'ポケットモンスター スカーレット' => '宝可梦 朱',
        'ポケットモンスター ソード' => '宝可梦 剑',
        'ポケットモンスター Let\'s Go! ピカチュウ' => 'Let\'s Go, Pikachu!',
        default => $game,
    };
}

function api_game_variants(string $game): array
{
    return match ($game) {
        'Pokémon LEGENDS Z-A' => ['Pokémon LEGENDS Z-A', '宝可梦传说 Z-A'],
        '宝可梦传说 阿尔宙斯' => ['宝可梦传说 阿尔宙斯', 'Pokémon LEGENDS アルセウス'],
        '宝可梦 朱' => ['宝可梦 朱', 'ポケットモンスター スカーレット'],
        '宝可梦 剑' => ['宝可梦 剑', 'ポケットモンスター ソード'],
        'Let\'s Go, Pikachu!' => ['Let\'s Go, Pikachu!', 'ポケットモンスター Let\'s Go! ピカチュウ'],
        default => [$game],
    };
}

function api_decorate_game(array &$item): void
{
    $sourceGame = (string)$item['game'];
    $item['source_game'] = $sourceGame;
    $item['game'] = api_canonical_game($sourceGame);
    $item['archive_label'] = in_array($sourceGame, [
        'Pokémon LEGENDS アルセウス',
        'ポケットモンスター スカーレット',
        'ポケットモンスター ソード',
        'ポケットモンスター Let\'s Go! ピカチュウ',
    ], true) ? '日文存档' : '中文存档';
}

function api_search_terms(string $query): array
{
    if ($query === '') return [];
    $parts = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_slice(array_values(array_unique($parts)), 0, 6);
}

function api_multilingual_locations(PDO $pdo, string $query): array
{
    if ($query === '') return [];
    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='corpus_translation_fts'")->fetchColumn();
    if (!$exists) return [];
    if (mb_strlen($query) >= 3) {
        $stmt = $pdo->prepare('SELECT DISTINCT collection, file_key, text_file, line_no FROM corpus_translation_fts WHERE corpus_translation_fts MATCH ? LIMIT 500');
        $stmt->execute(['"' . str_replace('"', '""', $query) . '"']);
    } else {
        $stmt = $pdo->prepare("SELECT DISTINCT collection, file_key, text_file, line_no FROM corpus_translation_fts WHERE text LIKE ? ESCAPE '\\' LIMIT 500");
        $escaped = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
        $stmt->execute([$escaped]);
    }
    return $stmt->fetchAll();
}

function api_screenshots_text_fts_available(PDO $pdo): bool
{
    static $available;
    if ($available !== null) return $available;
    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='screenshots_text_fts'")->fetchColumn();
    $available = (bool)$exists;
    return $available;
}

/**
 * screenshots.text 的一个检索词对应的 WHERE 片段（含 game/filename/tags_json）。
 * text 部分优先走 screenshots_text_fts（trigram，migrations/20260819_screenshots_text_fts_up.sql），
 * 避免全表 LIKE 扫描（冷页缓存下单次可达 ~0.7s，命中索引后 <5ms）；trigram 要求
 * 词长 >=3 才能命中，更短的词或索引不存在时退回 LIKE，行为不变。
 */
function api_screenshot_text_clause(PDO $pdo, string $term, array &$params): string
{
    $escaped = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    if (mb_strlen($term) >= 3 && api_screenshots_text_fts_available($pdo)) {
        $params[] = '"' . str_replace('"', '""', $term) . '"';
        $params[] = $escaped;
        $params[] = $escaped;
        $params[] = $escaped;
        return "(s.id IN (SELECT rowid FROM screenshots_text_fts WHERE screenshots_text_fts MATCH ?) OR s.game LIKE ? ESCAPE '\\' OR s.filename LIKE ? ESCAPE '\\' OR s.tags_json LIKE ? ESCAPE '\\')";
    }
    array_push($params, $escaped, $escaped, $escaped, $escaped);
    return "(s.text LIKE ? ESCAPE '\\' OR s.game LIKE ? ESCAPE '\\' OR s.filename LIKE ? ESCAPE '\\' OR s.tags_json LIKE ? ESCAPE '\\')";
}

function api_encode_cursor(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

function api_decode_cursor(string $cursor): array
{
    if ($cursor === '' || strlen($cursor) > 512 || !preg_match('/^[A-Za-z0-9_-]+$/', $cursor)) {
        api_error('INVALID_CURSOR', '分页游标无效', 400);
    }
    $padding = (4 - strlen($cursor) % 4) % 4;
    $decoded = base64_decode(strtr($cursor . str_repeat('=', $padding), '-_', '+/'), true);
    if ($decoded === false) api_error('INVALID_CURSOR', '分页游标无效', 400);
    try {
        $payload = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        api_error('INVALID_CURSOR', '分页游标无效', 400);
    }
    if (!is_array($payload)) api_error('INVALID_CURSOR', '分页游标无效', 400);
    return $payload;
}

function api_has_corpus_evidence(PDO $pdo): bool
{
    static $available;
    if (is_bool($available)) return $available;
    $available = false;
    foreach ($pdo->query('PRAGMA table_info(screenshot_corpus_matches)') as $column) {
        if (($column['name'] ?? '') === 'evidence_json') {
            $available = true;
            break;
        }
    }
    return $available;
}

function api_clean_corpus_text(string $text): string
{
    if (preg_match('/^\[~.*\]$/u', trim($text))) return '';
    $text = preg_replace('/\[VAR [^\]]+\]/u', '', $text) ?? $text;
    $text = preg_replace('/\\\\(?:r|n|c)/u', "\n", $text) ?? $text;
    return trim($text);
}

function api_corpus_context(string $collection, string $language, string $fileKey, string $textFile, int $lineNo, int $radius = 3): array
{
    foreach ([$collection, $language, $fileKey, $textFile] as $part) {
        if (!preg_match('/^[\p{L}\p{N}._-]+$/u', $part)) return [];
    }
    $path = api_project_root() . '/data/corpus/' . $collection . '/' . $language . '_' . $fileKey . '.txt';
    if (!is_file($path)) return [];
    $handle = fopen($path, 'rb');
    if ($handle === false) return [];
    $targetHeader = 'Text File : ' . $textFile;
    $inside = false;
    $afterDivider = false;
    $index = 0;
    $start = max(0, $lineNo - $radius);
    $end = $lineNo + $radius;
    $result = [];
    while (($raw = fgets($handle)) !== false) {
        $line = rtrim($raw, "\r\n");
        if (!$inside) {
            if ($line === $targetHeader) $inside = true;
            continue;
        }
        if (!$afterDivider) {
            if (preg_match('/^~+$/', $line)) $afterDivider = true;
            continue;
        }
        if (preg_match('/^~+$/', $line)) break;
        if ($index >= $start && $index <= $end) {
            $clean = api_clean_corpus_text($line);
            if ($clean !== '') $result[] = ['line_no' => $index, 'text' => $clean];
        }
        if ($index > $end) break;
        $index++;
    }
    fclose($handle);
    return $result;
}

function api_corpus_translations(string $collection, string $fileKey, string $textFile, int $lineNo): array
{
    $languages = [
        'zh-Hans' => '简体中文', 'zh-Hant' => '繁體中文', 'ja' => '日本語',
        'ja-Hrkt' => '日本語（かな）', 'en' => 'English', 'en-GB' => 'English (UK)',
        'ko' => '한국어', 'fr' => 'Français', 'de' => 'Deutsch', 'es' => 'Español',
        'es-419' => 'Español (Latinoamérica)', 'it' => 'Italiano',
    ];
    $result = [];
    foreach ($languages as $language => $label) {
        $lines = api_corpus_context($collection, $language, $fileKey, $textFile, $lineNo, 0);
        if ($lines) $result[] = ['language' => $language, 'label' => $label, 'text' => (string)$lines[0]['text']];
    }
    return $result;
}

function api_normalized_ocr(string $text): string
{
    return preg_replace('/[\s\x{3000}]+/u', '', trim($text)) ?? '';
}
