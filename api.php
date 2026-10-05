<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/lib/pokemon_search.php';

const PAGE_SIZE = 36;
const MAX_PAGE_SIZE = 60;

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(string $message, int $status = 400): never
{
    respond(['ok' => false, 'error' => $message], $status);
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $file = __DIR__ . '/data/site.sqlite3';
    if (!is_file($file)) {
        fail('图库数据库暂不可用', 503);
    }
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 3,
    ]);
    $pdo->exec('PRAGMA query_only = ON; PRAGMA busy_timeout = 3000;');
    return $pdo;
}

function writeDb(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $file = __DIR__ . '/data/corrections.sqlite3';
    if (!is_dir(dirname($file)) || !is_writable(dirname($file))) {
        fail('勘误存储暂不可用', 503);
    }
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 3,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 3000;');
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
    SQL);
    return $pdo;
}

/**
 * 「待补完」记录（EXP-0104）：空结果落空（search_miss）和「请求补完」点击（request_completion）。
 * 单独一个库，不碰 site.sqlite3。访客只存按天轮换盐的哈希，不存 IP / UA；旧盐删掉后跨天无法关联。
 */
function wantedDb(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $file = __DIR__ . '/data/wanted.sqlite3';
    if (!is_dir(dirname($file)) || !is_writable(dirname($file))) {
        fail('记录暂不可用', 503);
    }
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 3,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 3000;');
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS wanted_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind TEXT NOT NULL,
            query TEXT NOT NULL,
            game TEXT NOT NULL DEFAULT '',
            visitor TEXT NOT NULL,
            day TEXT NOT NULL,
            created_at REAL NOT NULL,
            UNIQUE (kind, query, game, visitor, day)
        );
        CREATE INDEX IF NOT EXISTS idx_wanted_events_kind_query ON wanted_events(kind, query);
        CREATE INDEX IF NOT EXISTS idx_wanted_events_visitor_day ON wanted_events(visitor, day);
        CREATE TABLE IF NOT EXISTS visitor_salts (
            day TEXT PRIMARY KEY,
            salt TEXT NOT NULL
        );
    SQL);
    return $pdo;
}

function wantedVisitor(PDO $db, string $day): string
{
    $db->prepare('DELETE FROM visitor_salts WHERE day < ?')->execute([$day]);
    $db->prepare('INSERT OR IGNORE INTO visitor_salts (day, salt) VALUES (?, ?)')->execute([$day, bin2hex(random_bytes(16))]);
    $stmt = $db->prepare('SELECT salt FROM visitor_salts WHERE day = ?');
    $stmt->execute([$day]);
    $fingerprint = ($_SERVER['REMOTE_ADDR'] ?? '') . "\n" . ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return substr(hash_hmac('sha256', $fingerprint, (string)$stmt->fetchColumn()), 0, 16);
}

/**
 * 人工标注众包库（api/v1/tagging/*.php 写入）的只读连接，供 detail 附带展示"社区标注"用。
 * 表结构由 api/v1/bootstrap.php 的 api_tagging_db() 负责创建；这里不建表，文件不存在或
 * confirmed_annotations 还没建出来（consensus job 没跑过）时静默返回 null，不影响截图详情主体。
 */
function tagDb(): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) return $pdo ?: null;
    $file = __DIR__ . '/data/tagging.sqlite3';
    if (!is_file($file)) {
        $pdo = null;
        return null;
    }
    try {
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3,
        ]);
    } catch (Throwable) {
        $pdo = null;
    }
    return $pdo;
}

function requestBody(): array
{
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 32768) {
        fail('反馈内容过长', 413);
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function reportText(mixed $value, int $limit): string
{
    $text = trim((string)$value);
    return mb_substr($text, 0, $limit);
}

function cleanedQuery(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return mb_substr($value, 0, 80);
}

function searchTerms(string $query): array
{
    if ($query === '') return [];
    $parts = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_slice(array_values(array_unique($parts)), 0, 6);
}

function multilingualLocations(PDO $pdo, string $query): array
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

function screenshotTextFtsAvailable(PDO $pdo): bool
{
    static $available;
    if ($available !== null) return $available;
    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='screenshots_text_fts'")->fetchColumn();
    $available = (bool)$exists;
    return $available;
}

/**
 * 一个检索词对应的 WHERE 片段（text/game/filename/tags_json）。text 部分优先走
 * screenshots_text_fts（trigram，migrations/20260819_screenshots_text_fts_up.sql），
 * 避免全表 LIKE 扫描；词长 <3（trigram 最小单元）或索引不存在时退回 LIKE。
 */
function screenshotTextClause(PDO $pdo, string $term, array &$params): string
{
    $escaped = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    if (mb_strlen($term) >= 3 && screenshotTextFtsAvailable($pdo)) {
        $params[] = '"' . str_replace('"', '""', $term) . '"';
        $params[] = $escaped;
        $params[] = $escaped;
        $params[] = $escaped;
        return "(s.id IN (SELECT rowid FROM screenshots_text_fts WHERE screenshots_text_fts MATCH ?) OR s.game LIKE ? ESCAPE '\\' OR s.filename LIKE ? ESCAPE '\\' OR s.tags_json LIKE ? ESCAPE '\\')";
    }
    array_push($params, $escaped, $escaped, $escaped, $escaped);
    return "(s.text LIKE ? ESCAPE '\\' OR s.game LIKE ? ESCAPE '\\' OR s.filename LIKE ? ESCAPE '\\' OR s.tags_json LIKE ? ESCAPE '\\')";
}

/**
 * 多个检索词之间取"任一命中"（OR）。用于宝可梦名称解析命中后的展开检索
 * （本体名称之间，或本体+进化/退化家族名称之间）。
 */
function screenshotTermsOrClause(PDO $pdo, array $terms, array &$params): ?string
{
    $parts = [];
    foreach ($terms as $term) {
        if ($term === '') continue;
        $parts[] = screenshotTextClause($pdo, $term, $params);
    }
    return $parts ? '(' . implode(' OR ', $parts) . ')' : null;
}

function normalizedOcr(string $text): string
{
    return preg_replace('/[\s\x{3000}]+/u', '', trim($text)) ?? '';
}

function hasCorpusEvidence(PDO $pdo): bool
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

function cleanCorpusText(string $text): string
{
    if (preg_match('/^\[~.*\]$/u', trim($text))) return '';
    $text = preg_replace('/\[VAR [^\]]+\]/u', '', $text) ?? $text;
    $text = preg_replace('/\\\\(?:r|n|c)/u', "\n", $text) ?? $text;
    return trim($text);
}

function corpusContext(string $collection, string $language, string $fileKey, string $textFile, int $lineNo, int $radius = 3): array
{
    foreach ([$collection, $language, $fileKey, $textFile] as $part) {
        if (!preg_match('/^[\p{L}\p{N}._-]+$/u', $part)) return [];
    }
    $path = __DIR__ . '/data/corpus/' . $collection . '/' . $language . '_' . $fileKey . '.txt';
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
            $clean = cleanCorpusText($line);
            if ($clean !== '') $result[] = ['line_no' => $index, 'text' => $clean];
        }
        if ($index > $end) break;
        $index++;
    }
    fclose($handle);
    return $result;
}

function corpusTranslations(string $collection, string $fileKey, string $textFile, int $lineNo): array
{
    $languages = [
        'zh-Hans' => '简体中文', 'zh-Hant' => '繁體中文', 'ja' => '日本語',
        'ja-Hrkt' => '日本語（かな）', 'en' => 'English', 'en-GB' => 'English (UK)',
        'ko' => '한국어', 'fr' => 'Français', 'de' => 'Deutsch', 'es' => 'Español',
        'es-419' => 'Español (Latinoamérica)', 'it' => 'Italiano',
    ];
    $result = [];
    foreach ($languages as $language => $label) {
        $lines = corpusContext($collection, $language, $fileKey, $textFile, $lineNo, 0);
        if (!$lines) continue;
        $result[] = ['language' => $language, 'label' => $label, 'text' => (string)$lines[0]['text']];
    }
    return $result;
}

function canonicalGame(string $game): string
{
    return match ($game) {
        '宝可梦传说 Z-A' => 'Pokémon LEGENDS Z-A',
        '宝可梦传说 Z-A Nintendo Switch 2 Edition' => 'Pokémon LEGENDS Z-A',
        'Pokémon LEGENDS アルセウス' => '宝可梦传说 阿尔宙斯',
        'ポケットモンスター スカーレット' => '宝可梦 朱',
        'ポケットモンスター ソード' => '宝可梦 剑',
        'ポケットモンスター Let\'s Go! ピカチュウ' => 'Let\'s Go, Pikachu!',
        default => $game,
    };
}

function gameVariants(string $game): array
{
    return match ($game) {
        'Pokémon LEGENDS Z-A' => ['Pokémon LEGENDS Z-A', '宝可梦传说 Z-A', '宝可梦传说 Z-A Nintendo Switch 2 Edition'],
        '宝可梦传说 阿尔宙斯' => ['宝可梦传说 阿尔宙斯', 'Pokémon LEGENDS アルセウス'],
        '宝可梦 朱' => ['宝可梦 朱', 'ポケットモンスター スカーレット'],
        '宝可梦 剑' => ['宝可梦 剑', 'ポケットモンスター ソード'],
        'Let\'s Go, Pikachu!' => ['Let\'s Go, Pikachu!', 'ポケットモンスター Let\'s Go! ピカチュウ'],
        default => [$game],
    };
}

function decorateGame(array &$item): void
{
    $sourceGame = (string)$item['game'];
    $item['source_game'] = $sourceGame;
    $item['game'] = canonicalGame($sourceGame);
    $item['archive_label'] = in_array($sourceGame, [
        'Pokémon LEGENDS アルセウス',
        'ポケットモンスター スカーレット',
        'ポケットモンスター ソード',
        'ポケットモンスター Let\'s Go! ピカチュウ',
    ], true) ? '日文存档' : '中文存档';
}

try {
    $pdo = db();
    $action = $_GET['action'] ?? 'list';

    if ($action === 'meta') {
        $rawGames = $pdo->query('SELECT game, COUNT(*) AS count FROM screenshots GROUP BY game')->fetchAll();
        $groupedGames = [];
        foreach ($rawGames as $row) {
            $name = canonicalGame((string)$row['game']);
            $groupedGames[$name] = ($groupedGames[$name] ?? 0) + (int)$row['count'];
        }
        arsort($groupedGames, SORT_NUMERIC);
        $games = [];
        foreach ($groupedGames as $gameName => $count) $games[] = ['game' => $gameName, 'count' => $count];
        $stats = $pdo->query("SELECT COUNT(*) AS total, COUNT(DISTINCT game) AS games, SUM(text <> '') AS recognized FROM screenshots")->fetch();
        $stats['games'] = count($games);
        respond(['ok' => true, 'games' => $games, 'stats' => $stats]);
    }

    if ($action === 'corrections') {
        $reportDb = writeDb();
        $stats = $reportDb->query('SELECT COUNT(*) AS total, COUNT(DISTINCT screenshot_id) AS screenshots FROM correction_reports WHERE status = "pending"')->fetch();
        $stmt = $reportDb->query('SELECT id, screenshot_id, category, original_text, suggested_text, description, status, created_at FROM correction_reports WHERE status = "pending" ORDER BY created_at DESC, id DESC LIMIT 200');
        $reports = $stmt->fetchAll();
        $screenshotStmt = $pdo->prepare('SELECT game, filename, thumb_url, preview_url FROM screenshots WHERE id = ?');
        foreach ($reports as &$report) {
            $screenshotStmt->execute([(int)$report['screenshot_id']]);
            $screenshot = $screenshotStmt->fetch();
            if ($screenshot) {
                $report = array_merge($report, $screenshot);
            } else {
                $report['game'] = '已移除的截图';
                $report['filename'] = '';
                $report['thumb_url'] = '';
                $report['preview_url'] = '';
            }
        }
        unset($report);
        respond(['ok' => true, 'stats' => $stats, 'reports' => $reports]);
    }

    if ($action === 'submit_correction') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            fail('请使用提交方式发送勘误', 405);
        }
        $body = requestBody();
        $id = filter_var($body['screenshot_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            fail('无效的截图编号');
        }
        $category = reportText($body['category'] ?? '', 40);
        $allowedCategories = ['OCR文字错误', '剧情匹配错误', '截图信息错误', '无效截图', '其他问题'];
        if (!in_array($category, $allowedCategories, true)) {
            fail('请选择有效的问题类型');
        }
        $suggestedText = reportText($body['suggested_text'] ?? '', 2000);
        $description = reportText($body['description'] ?? '', 2000);
        $contact = reportText($body['contact'] ?? '', 200);
        if ($suggestedText === '' && $description === '') {
            fail('请填写修正内容或问题说明');
        }
        $reportDb = writeDb();
        $screenshotStmt = $pdo->prepare('SELECT id, text FROM screenshots WHERE id = ?');
        $screenshotStmt->execute([$id]);
        $screenshot = $screenshotStmt->fetch();
        if (!$screenshot) {
            fail('未找到这张截图', 404);
        }
        $now = microtime(true);
        $duplicateStmt = $reportDb->prepare('SELECT id FROM correction_reports WHERE screenshot_id = ? AND category = ? AND suggested_text = ? AND description = ? AND created_at > ? LIMIT 1');
        $duplicateStmt->execute([$id, $category, $suggestedText, $description, $now - 300]);
        $duplicateId = $duplicateStmt->fetchColumn();
        if ($duplicateId !== false) {
            respond(['ok' => true, 'duplicate' => true, 'id' => (int)$duplicateId]);
        }
        $insert = $reportDb->prepare('INSERT INTO correction_reports (screenshot_id, category, original_text, suggested_text, description, contact, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, "pending", ?, ?)');
        $insert->execute([$id, $category, (string)$screenshot['text'], $suggestedText, $description, $contact, $now, $now]);
        respond(['ok' => true, 'id' => (int)$reportDb->lastInsertId()]);
    }

    if ($action === 'log_miss' || $action === 'request_completion') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            fail('请使用提交方式发送', 405);
        }
        $body = requestBody();
        $query = cleanedQuery((string)($body['q'] ?? ''));
        if ($query === '') {
            fail('缺少搜索词');
        }
        $game = reportText($body['game'] ?? '', 80);
        $wanted = wantedDb();
        $day = date('Y-m-d');
        $visitor = wantedVisitor($wanted, $day);
        $countStmt = $wanted->prepare('SELECT COUNT(*) FROM wanted_events WHERE visitor = ? AND day = ?');
        $countStmt->execute([$visitor, $day]);
        if ((int)$countStmt->fetchColumn() >= 60) {
            respond(['ok' => true, 'recorded' => false]);
        }
        $insert = $wanted->prepare('INSERT OR IGNORE INTO wanted_events (kind, query, game, visitor, day, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([$action === 'log_miss' ? 'search_miss' : 'request_completion', $query, $game, $visitor, $day, microtime(true)]);
        respond(['ok' => true, 'recorded' => $insert->rowCount() > 0]);
    }

    if ($action === 'detail') {
        $rawId = $_GET['id'] ?? null;
        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        if (!$id) fail('无效的截图编号');
        $stmt = $pdo->prepare('SELECT id, game, rel_path, filename, width, height, sha1, text, tags_json, note, contributor, preview_url, thumb_url, created_at, updated_at FROM screenshots WHERE id = ?');
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (!$item) fail('未找到这张截图', 404);
        decorateGame($item);
        $sourceFolder = substr((string)$item['rel_path'], 0, max(0, strlen((string)$item['rel_path']) - strlen((string)$item['filename'])));
        $item['tags'] = json_decode($item['tags_json'], true) ?: [];
        unset($item['tags_json']);
        $item['submission_variants'] = [];
        $duplicateGroup = $pdo->prepare('SELECT group_id FROM screenshot_duplicate_groups WHERE screenshot_id = ?');
        $duplicateGroup->execute([(int)$item['id']]);
        $groupId = $duplicateGroup->fetchColumn();
        if ($groupId !== false) {
            $variantStmt = $pdo->prepare('SELECT s.id, s.game, s.filename, s.contributor, s.preview_url, s.thumb_url, s.created_at FROM screenshot_duplicate_groups dg JOIN screenshots s ON s.id=dg.screenshot_id WHERE dg.group_id = ? ORDER BY s.contributor, s.filename, s.id');
            $variantStmt->execute([(int)$groupId]);
            $item['submission_variants'] = $variantStmt->fetchAll();
            foreach ($item['submission_variants'] as &$variant) decorateGame($variant);
            unset($variant);
        }
        unset($item['sha1']);
        $stmt = $pdo->prepare('SELECT category, canonical, alias, language, source FROM glossary_matches WHERE screenshot_id = ? ORDER BY category, canonical LIMIT 30');
        $stmt->execute([$id]);
        $item['glossary'] = $stmt->fetchAll();
        $item['community_tags'] = [];
        $tagPdo = tagDb();
        if ($tagPdo) {
            try {
                $stmt = $tagPdo->prepare("SELECT category, canonical, support_count FROM confirmed_annotations WHERE screenshot_id = ? AND status = 'confirmed' ORDER BY category, canonical LIMIT 30");
                $stmt->execute([$id]);
                $item['community_tags'] = $stmt->fetchAll();
            } catch (Throwable) {
                // confirmed_annotations 表可能还不存在（consensus job 还没跑过一次），静默忽略。
            }
        }
        $evidenceSelect = hasCorpusEvidence($pdo)
            ? ", COALESCE(json_extract(evidence_json, '$.best_match.status'), 'legacy') AS match_status, json_extract(evidence_json, '$.review_reasons') AS review_reasons_json"
            : ", 'legacy' AS match_status, '[]' AS review_reasons_json";
        $stmt = $pdo->prepare('SELECT text_id, score, collection, language, file_key, text_file, line_no, text, matched_text' . $evidenceSelect . ' FROM screenshot_corpus_matches WHERE screenshot_id = ? ORDER BY score DESC LIMIT 12');
        $stmt->execute([$id]);
        $item['corpus'] = $stmt->fetchAll();
        foreach ($item['corpus'] as &$corpusMatch) {
            $corpusMatch['review_reasons'] = json_decode((string)$corpusMatch['review_reasons_json'], true) ?: [];
            unset($corpusMatch['review_reasons_json']);
        }
        unset($corpusMatch);
        $item['translations'] = [];
        $translationSource = null;
        foreach ($item['corpus'] as $corpusMatch) {
            if ($corpusMatch['language'] === 'zh-Hans') { $translationSource = $corpusMatch; break; }
        }
        if ($translationSource === null && $item['corpus']) $translationSource = $item['corpus'][0];
        if ($translationSource !== null) {
            $item['translations'] = corpusTranslations(
                (string)$translationSource['collection'], (string)$translationSource['file_key'],
                (string)$translationSource['text_file'], (int)$translationSource['line_no']
            );
        }
        $item['storyContext'] = [];
        $contextSource = null;
        foreach ($item['corpus'] as $corpusMatch) {
            if ($corpusMatch['file_key'] === 'script' && $corpusMatch['language'] === 'zh-Hans') { $contextSource = $corpusMatch; break; }
        }
        if ($contextSource === null && $item['corpus']) $contextSource = $item['corpus'][0];
        if ($contextSource !== null) {
            $context = corpusContext((string)$contextSource['collection'], (string)$contextSource['language'], (string)$contextSource['file_key'], (string)$contextSource['text_file'], (int)$contextSource['line_no']);
            if ($context) {
                $contextStmt = $pdo->prepare('SELECT s.id, s.game, s.thumb_url, s.preview_url, cm.line_no FROM screenshot_corpus_matches cm JOIN screenshots s ON s.id = cm.screenshot_id WHERE cm.collection = ? AND cm.language = ? AND cm.file_key = ? AND cm.text_file = ? AND cm.line_no BETWEEN ? AND ? AND cm.score >= 0.72 AND s.game = ? AND s.contributor = ? AND substr(s.rel_path, 1, length(s.rel_path) - length(s.filename)) = ? ORDER BY cm.line_no, ABS(s.id - ?), cm.score DESC LIMIT 80');
                $contextStmt->execute([$contextSource['collection'], $contextSource['language'], $contextSource['file_key'], $contextSource['text_file'], max(0, (int)$contextSource['line_no'] - 3), (int)$contextSource['line_no'] + 3, $item['source_game'], $item['contributor'], $sourceFolder, (int)$item['id']]);
                $screensByLine = [];
                foreach ($contextStmt->fetchAll() as $screen) {
                    decorateGame($screen);
                    $screensByLine[(int)$screen['line_no']][] = $screen;
                }
                foreach ($context as &$contextLine) {
                    $contextLine['current'] = (int)$contextLine['line_no'] === (int)$contextSource['line_no'];
                    $contextLine['screenshots'] = array_slice($screensByLine[(int)$contextLine['line_no']] ?? [], 0, 4);
                }
                unset($contextLine);
                $item['storyContext'] = $context;
            }
        }
        $item['version_matches'] = [];
        $sourceGame = (string)$item['source_game'];
        $oppositeGame = match ($sourceGame) {
            '宝可梦 朱' => '宝可梦 紫',
            '宝可梦 紫' => '宝可梦 朱',
            default => '',
        };
        $normalizedText = normalizedOcr((string)$item['text']);
        if ($oppositeGame !== '' && mb_strlen($normalizedText) >= 8) {
            $boundsStmt = $pdo->prepare('SELECT MIN(id) AS min_id, MAX(id) AS max_id FROM screenshots WHERE game = ?');
            $boundsStmt->execute([$sourceGame]);
            $sourceBounds = $boundsStmt->fetch();
            $boundsStmt->execute([$oppositeGame]);
            $targetBounds = $boundsStmt->fetch();
            $sourceSpan = max(1, (int)$sourceBounds['max_id'] - (int)$sourceBounds['min_id']);
            $progress = ((int)$item['id'] - (int)$sourceBounds['min_id']) / $sourceSpan;
            $expectedId = (int)round((int)$targetBounds['min_id'] + $progress * max(1, (int)$targetBounds['max_id'] - (int)$targetBounds['min_id']));
            $matchStmt = $pdo->prepare("SELECT id, game, filename, text, thumb_url, preview_url FROM screenshots WHERE game = ? AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(text), CHAR(10), ''), CHAR(13), ''), CHAR(9), ''), ' ', ''), '　', '') = ? LIMIT 30");
            $matchStmt->execute([$oppositeGame, $normalizedText]);
            $item['version_matches'] = $matchStmt->fetchAll();
            usort($item['version_matches'], fn(array $a, array $b): int => abs((int)$a['id'] - $expectedId) <=> abs((int)$b['id'] - $expectedId));
            $item['version_matches'] = array_slice($item['version_matches'], 0, 6);
            foreach ($item['version_matches'] as &$match) decorateGame($match);
            unset($match);
        }
        $item['storySequence'] = [];
        if ($item['corpus']) {
            $primary = $item['corpus'][0];
            $stmt = $pdo->prepare('SELECT s.id, s.thumb_url, s.preview_url, s.text, s.game, MIN(cm.line_no) AS line_no, MAX(cm.score) AS score, MIN(cm.text_id) AS text_id FROM screenshot_corpus_matches cm JOIN screenshots s ON s.id = cm.screenshot_id WHERE cm.collection = ? AND cm.language = ? AND cm.text_file = ? AND cm.line_no BETWEEN ? AND ? AND cm.score >= 0.72 AND s.game = ? AND s.contributor = ? AND substr(s.rel_path, 1, length(s.rel_path) - length(s.filename)) = ? GROUP BY s.id ORDER BY line_no ASC, ABS(s.id - ?) ASC LIMIT 30');
            $line = (int)$primary['line_no'];
            $stmt->execute([$primary['collection'], $primary['language'], $primary['text_file'], max(0, $line - 2), $line + 2, $item['source_game'], $item['contributor'], $sourceFolder, (int)$item['id']]);
            $item['storySequence'] = $stmt->fetchAll();
        }
        unset($item['rel_path']);
        respond(['ok' => true, 'item' => $item]);
    }

    if ($action !== 'list') fail('未知请求');

    $query = cleanedQuery((string)($_GET['q'] ?? ''));
    $game = cleanedQuery((string)($_GET['game'] ?? ''));
    $page = max(1, min(1000, (int)($_GET['page'] ?? 1)));
    $limit = max(12, min(MAX_PAGE_SIZE, (int)($_GET['limit'] ?? PAGE_SIZE)));
    $sort = (string)($_GET['sort'] ?? 'story');
    if (!in_array($sort, ['random', 'story', 'terms', 'newest', 'oldest', 'id_asc', 'id_desc'], true)) $sort = 'story';
    $randomSeed = max(1, min(2147483646, (int)($_GET['seed'] ?? 1)));
    $expandEvolution = in_array(strtolower(trim((string)($_GET['expand_evolution'] ?? ''))), ['1', 'true', 'yes'], true);

    // 宝可梦名称解析：仅对单个词的 q 尝试（别名归一 → 拼音/错别字容错）。
    // 命中后 primaryTerms=本体 zh/en/jp 名称；expand_evolution=1 时 familyOnlyTerms=
    // 进化/退化家族其余成员名称（已排除 primaryTerms 重复项）。
    $primaryTerms = [$query];
    $familyOnlyTerms = [];
    $resolvedPokemon = null;
    if ($query !== '' && !preg_match('/\s/u', $query)) {
        $resolution = gg_pokemon_resolve($query);
        if ($resolution !== null) {
            $primaryTerms = array_values(array_unique(array_merge(
                [$query],
                gg_pokemon_name_variants($resolution['entry'])
            )));
            if ($expandEvolution) {
                $familyNames = [];
                foreach (gg_pokemon_evolution_family((string)$resolution['entry']['id']) as $familyId) {
                    $familyEntry = gg_pokemon_by_id($familyId);
                    if ($familyEntry !== null) {
                        $familyNames = array_merge($familyNames, gg_pokemon_name_variants($familyEntry));
                    }
                }
                $familyOnlyTerms = array_values(array_diff(array_unique($familyNames), $primaryTerms));
            }
            $resolvedPokemon = [
                'pokemon_id' => (string)$resolution['entry']['id'],
                'pokemon_name' => (string)($resolution['entry']['name']['zh'] ?? ''),
                'matched_via' => $resolution['matched_via'],
                'expanded_terms' => array_values(array_unique(array_merge($primaryTerms, $familyOnlyTerms))),
            ];
        }
    }
    // twoPhase：先出完本体（match_tier=primary），再出进化/退化家族（match_tier=family）。
    // offset 分页下不需要 api/v1 那种两段式游标，一个 ORDER BY CASE 就够。
    $twoPhase = $resolvedPokemon !== null && $familyOnlyTerms !== [];

    $where = ['(dg.screenshot_id IS NULL OR dg.screenshot_id = dg.group_id)'];
    $params = [];
    if ($game !== '') {
        $variants = gameVariants($game);
        $where[] = 's.game IN (' . implode(',', array_fill(0, count($variants), '?')) . ')';
        array_push($params, ...$variants);
    }
    $locations = multilingualLocations($pdo, $query);
    $buildCorpusExistsClause = function (array &$paramsRef) use ($locations): ?string {
        if (!$locations) return null;
        $rows = [];
        foreach ($locations as $location) {
            $rows[] = '(?,?,?,?)';
            array_push($paramsRef, $location['collection'], $location['file_key'], $location['text_file'], (int)$location['line_no']);
        }
        return 'EXISTS (SELECT 1 FROM screenshot_corpus_matches cm WHERE cm.screenshot_id=s.id AND cm.score>=0.72 AND (cm.collection, cm.file_key, cm.text_file, cm.line_no) IN (VALUES ' . implode(',', $rows) . '))';
    };

    $keywordClauses = [];
    if ($resolvedPokemon !== null) {
        // 已解析出宝可梦：展开词之间是"任一命中"（OR），而不是原有多词的"全部命中"（AND）。
        $allTerms = $twoPhase ? array_merge($primaryTerms, $familyOnlyTerms) : $primaryTerms;
        $clause = screenshotTermsOrClause($pdo, $allTerms, $params);
        if ($clause !== null) $keywordClauses[] = $clause;
    } else {
        foreach (searchTerms($query) as $term) {
            $keywordClauses[] = screenshotTextClause($pdo, $term, $params);
        }
    }
    $queryClauses = $keywordClauses ? ['(' . implode(' AND ', $keywordClauses) . ')'] : [];
    $corpusClauseForWhere = $buildCorpusExistsClause($params);
    if ($corpusClauseForWhere !== null) $queryClauses[] = $corpusClauseForWhere;
    if ($queryClauses) $where[] = '(' . implode(' OR ', $queryClauses) . ')';
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // match_tier：twoPhase 时 0=本体命中（含语料匹配）、1=只命中家族；否则恒为 0（单一 tier）。
    $tierSelectParams = [];
    $tierSelectSql = '0 AS match_tier_flag';
    if ($twoPhase) {
        $primaryOnlyClause = screenshotTermsOrClause($pdo, $primaryTerms, $tierSelectParams);
        $corpusClauseForTier = $buildCorpusExistsClause($tierSelectParams);
        $tierParts = array_values(array_filter([$primaryOnlyClause, $corpusClauseForTier], fn($x) => $x !== null));
        if ($tierParts) {
            $tierSelectSql = 'CASE WHEN (' . implode(' OR ', $tierParts) . ') THEN 0 ELSE 1 END AS match_tier_flag';
        }
    }
    // 这里刻意不再用 match_tier_flag 打头强制"本体排完才轮到家族"：很多宝可梦本体命中数
    // 远超一页（比如"雷丘"单独就有 70+ 张），家族命中永远排不到前几页，勾选"含进化/退化
    // 家族"在观感上等于没生效。改成本体和家族按同一套相关性/随机排序混在一起出现，
    // match_tier 仍然计算、仍然返回，只是不再主导排序——前端用它给卡片加一个小角标。
    $tierOrderSql = '';

    // 命中宝可梦解析（resolvedPokemon）时不用这个"原文字面量是否出现在 s.text 里"的排序：
    // 家族成员的截图文本里天然不会出现原始查询词（比如搜"雷丘"展开出的"皮卡丘"截图不含
    // "雷丘"三个字），这条 CASE 会把它们全部压到最后一档，等于重新制造出上面刚去掉的
    // "本体优先"效果。非宝可梦的普通文本搜索保留这个排序，行为不变。
    $rankSql = ($query !== '' && $resolvedPokemon === null) ? 'CASE WHEN s.text = ? THEN 0 WHEN s.text LIKE ? THEN 1 ELSE 2 END,' : '';
    $sortSql = match ($sort) {
        'random' => 'ABS((s.id * (1103515245 + ((? % 1000000) * 2) + 1)) % 2147483647), s.id DESC',
        'terms' => 'term_count DESC, story_score DESC, s.id DESC',
        'newest' => 's.id DESC',
        'oldest' => 's.id ASC',
        'id_asc' => 's.id ASC',
        'id_desc' => 's.id DESC',
        default => 'CASE WHEN story_score >= 0.72 THEN 0 ELSE 1 END, term_count DESC, story_score DESC, s.id DESC',
    };
    // 占位符按 SQL 文本出现顺序绑定：SELECT 的 tier CASE 在最前面，其次 WHERE，再 rankSql/sortSql，最后 LIMIT/OFFSET。
    $listParams = $tierSelectParams;
    array_push($listParams, ...$params);
    if ($query !== '' && $resolvedPokemon === null) {
        array_push($listParams, $query, '%' . $query . '%');
    }
    if ($sort === 'random') $listParams[] = $randomSeed;
    array_push($listParams, $limit + 1, ($page - 1) * $limit);
    $sql = "SELECT s.id, s.game, s.filename, s.width, s.height, s.text, s.tags_json, s.thumb_url, s.preview_url, s.created_at,
                   COALESCE(g.term_count, 0) AS term_count, COALESCE(c.story_score, 0) AS story_score,
                   COALESCE(dg.group_size, 1) AS variant_count, $tierSelectSql
            FROM screenshots s
            LEFT JOIN screenshot_duplicate_groups dg ON dg.screenshot_id=s.id
            LEFT JOIN (SELECT screenshot_id, COUNT(DISTINCT canonical) AS term_count FROM glossary_matches GROUP BY screenshot_id) g ON g.screenshot_id = s.id
            LEFT JOIN (SELECT screenshot_id, MAX(score) AS story_score FROM screenshot_corpus_matches GROUP BY screenshot_id) c ON c.screenshot_id = s.id
            $whereSql ORDER BY $tierOrderSql $rankSql $sortSql LIMIT ? OFFSET ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($listParams);
    $items = $stmt->fetchAll();
    $hasMore = count($items) > $limit;
    if ($hasMore) array_pop($items);
    foreach ($items as &$item) {
        decorateGame($item);
        $item['tags'] = json_decode($item['tags_json'], true) ?: [];
        unset($item['tags_json']);
        $item['match_tier'] = $resolvedPokemon !== null ? (((int)$item['match_tier_flag']) === 0 ? 'primary' : 'family') : null;
        unset($item['match_tier_flag']);
    }
    unset($item);
    respond([
        'ok' => true,
        'items' => $items,
        'page' => $page,
        'hasMore' => $hasMore,
        'query' => $query,
        'sort' => $sort,
        'expandEvolution' => $expandEvolution,
        'resolvedPokemon' => $resolvedPokemon,
    ]);
} catch (Throwable $error) {
    error_log('[game_gallery] ' . $error->getMessage());
    fail('服务暂时繁忙，请稍后重试', 500);
}
