<?php
declare(strict_types=1);
// 统计口径自测：只用临时数据库，不碰线上 stats.db。  php bin/analytics-selftest.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/analytics.php';

$path = tempnam(sys_get_temp_dir(), 'gg-analytics-selftest-');
unlink($path);
$fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fail): void {
    if (!$ok) $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : '  → ' . $detail) . "\n";
};
$uuid = static fn(): string => sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));
$validate = static function (array $events) use ($uuid): array {
    return gg_analytics_validate(['visitorId' => $uuid(), 'sessionId' => $uuid(), 'events' => $events, 'source' => 'direct']);
};
$event = static fn(string $kind, array $f = []): array => ['id' => $uuid(), 'kind' => $kind, 'page' => '/game_gallery/'] + $f;

try {
    // —— 校验：screenshot_open 的来源 ——
    foreach (['click', 'deeplink', 'modal', 'scene'] as $reason) {
        $b = $validate([$event('screenshot_open', ['screenshotId' => 5, 'reason' => $reason])]);
        $check("open reason {$reason} accepted", $b['events'][0]['reason'] === $reason, (string)$b['events'][0]['reason']);
    }
    $b = $validate([$event('screenshot_open', ['screenshotId' => 5])]);
    $check('old client (no reason) → empty', $b['events'][0]['reason'] === '');
    foreach (['input', 'url', 'bogus', 'CLICK'] as $reason) {
        $b = $validate([$event('screenshot_open', ['screenshotId' => 5, 'reason' => $reason])]);
        $check("open reason {$reason} rejected → empty", $b['events'][0]['reason'] === '', (string)$b['events'][0]['reason']);
    }
    $b = $validate([$event('search_submit', ['q' => '皮卡丘', 'reason' => 'input'])]);
    $check('search reason input unchanged', $b['events'][0]['reason'] === 'input');
    $b = $validate([$event('search_submit', ['q' => '皮卡丘', 'reason' => 'click'])]);
    $check('search reason click not accepted', $b['events'][0]['reason'] === '');

    // —— 记录 + 报告 ——
    $db = gg_analytics_db($path, true);
    $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0 selftest'];
    $now = 1_800_000_000;                       // 固定时刻，保证 day 一致
    $record = static function (string $session, array $events, int $at) use ($db, $server, $uuid): void {
        gg_analytics_record($db, ['visitorId' => $uuid(), 'sessionId' => $session, 'events' => $events, 'source' => 'direct'], $server, $at);
    };
    // 直接构造 validate 之后的形状
    $row = static function (string $kind, array $f) use ($uuid): array {
        return array_merge(['id' => $uuid(), 'kind' => $kind, 'page' => '/game_gallery/', 'query' => '', 'game' => '', 'search_id' => '', 'screenshot_id' => null,
            'results_shown' => null, 'has_more' => null, 'outcome' => '', 'reason' => ''], $f);
    };
    $search = static function (string $q, string $reason, ?int $shown = 3) use ($row): array {
        $submit = $row('search_submit', ['query' => $q, 'reason' => $reason]);
        $submit['search_id'] = $submit['id'];
        $result = $row('search_result', ['search_id' => $submit['id'], 'outcome' => 'success', 'results_shown' => $shown, 'has_more' => 0]);
        return [$submit, $result];
    };
    $s1 = $uuid(); $s2 = $uuid(); $s3 = $uuid(); $s4 = $uuid(); $s5 = $uuid();
    // s1：键入 xuan → xuank → xuankai（间隔 1 s）后按回车，再过 30 s 另搜一个词；中间还打开过一个 ?q= 链接
    $e = [];
    foreach ([['xuan', 'input', 0], ['xuank', 'input', 1], ['xuankai', 'input', 2], ['xuankai', 'submit', 3], ['皮卡丘', 'input', 40]] as [$q, $r, $t]) {
        foreach ($search($q, $r) as $ev) $e[] = [$ev, $now + $t];
    }
    foreach ($search('link-term', 'url') as $ev) $e[] = [$ev, $now + 10];
    foreach ($e as [$ev, $t]) $record($s1, [$ev], $t);
    // s2：同样搜「皮卡丘」，再点开截图 100（click）
    foreach ($search('皮卡丘', 'input', 0) as $ev) $record($s2, [$ev], $now + 5);
    $record($s2, [$row('screenshot_open', ['screenshot_id' => 100, 'reason' => 'click'])], $now + 6);
    // s3：只来一次深链打开截图 100，再翻两张（modal）
    $record($s3, [$row('screenshot_open', ['screenshot_id' => 100, 'reason' => 'deeplink'])], $now + 7);
    $record($s3, [$row('screenshot_open', ['screenshot_id' => 101, 'reason' => 'modal'])], $now + 8);
    $record($s3, [$row('screenshot_open', ['screenshot_id' => 102, 'reason' => 'modal'])], $now + 9);
    // s4：旧前端（无来源）打开截图 100；s5：剧情页点图 100（scene）+ 同会话再点一次（同会话同图只算一个会话）
    $record($s4, [$row('screenshot_open', ['screenshot_id' => 100, 'reason' => ''])], $now + 11);
    $record($s5, [$row('screenshot_open', ['screenshot_id' => 100, 'reason' => 'scene'])], $now + 12);
    $record($s5, [$row('screenshot_open', ['screenshot_id' => 100, 'reason' => 'scene'])], $now + 13);
    $record($s5, [$row('page_view', [])], $now + 14);

    $ro = gg_analytics_db($path, false);
    $r = gg_analytics_report($ro, 30, $now + 100);
    $t = $r['totals'];
    $check('raw searches kept (5 typed + link)', (int)$t['searches']['searches'] === 5 + 1 + 1, json_encode($t['searches']));
    // 折叠后：s1 的 xuankai 一次 + s1 的皮卡丘一次 + s2 的皮卡丘一次；url 链接不算
    $check('final typed searches = 3', $t['finalSearches']['typed'] === 3, json_encode($t['finalSearches']));
    $check('link loads counted apart', $t['finalSearches']['linkLoads'] === 1, json_encode($t['finalSearches']));
    $terms = array_column($r['searchTerms'], null, 'query');
    $check('prefixes collapsed (no xuan / xuank)', !isset($terms['xuan']) && !isset($terms['xuank']) && isset($terms['xuankai']), json_encode(array_keys($terms), JSON_UNESCAPED_UNICODE));
    $check('xuankai counted once after Enter', ($terms['xuankai']['searches'] ?? 0) === 1);
    $check('皮卡丘 = 2 searches in 2 sessions (typed twice apart, gap > 8 s is a new search)', ($terms['皮卡丘']['searches'] ?? 0) === 2 && ($terms['皮卡丘']['sessions'] ?? 0) === 2, json_encode($terms['皮卡丘'] ?? null, JSON_UNESCAPED_UNICODE));
    $check('url link term not in ranking', !isset($terms['link-term']));
    $check('zero_results counted on final row only', ($terms['皮卡丘']['zero_results'] ?? 0) === 1, json_encode($terms['皮卡丘'] ?? null, JSON_UNESCAPED_UNICODE));
    $check('ranking order: 皮卡丘 (2 sessions) first', ($r['searchTerms'][0]['query'] ?? '') === '皮卡丘');
    $shots = array_column($r['screenshots'], null, 'screenshot_id');
    $check('shot 100: 2 chosen sessions (click + scene), 4 sessions total', (int)$shots[100]['chosen_sessions'] === 2 && (int)$shots[100]['sessions'] === 4, json_encode($shots[100]));
    $check('shot 100 deeplink / unknown listed apart', (int)$shots[100]['deeplink_opens'] === 1 && (int)$shots[100]['unknown_opens'] === 1, json_encode($shots[100]));
    $check('modal-only shots rank below', (int)$shots[101]['chosen_sessions'] === 0 && (int)$shots[101]['modal_opens'] === 1);
    $check('top shot is 100', (int)$r['screenshots'][0]['screenshot_id'] === 100);
    $src = array_column($r['openSources'], null, 'source');
    $check('openSources split', ($src['click']['opens'] ?? 0) === 1 && ($src['scene']['opens'] ?? 0) === 2 && ($src['deeplink']['opens'] ?? 0) === 1 && ($src['modal']['opens'] ?? 0) === 2 && ($src['unknown']['opens'] ?? 0) === 1, json_encode($r['openSources']));
    // 有交互的会话：s1（键入）、s2（点开）、s3（modal 翻页）、s5（scene）；s4 只有无来源的打开 → 不算
    $check('interactive sessions = 4', $t['interactiveSessions'] === 4, (string)$t['interactiveSessions']);
    $day = $r['daily'][0] ?? [];
    $check('daily carries new columns', isset($day['link_searches'], $day['chosen_opens'], $day['interactive_sessions'], $day['final_searches'])
        && (int)$day['chosen_opens'] === 3 && (int)$day['final_searches'] === 3, json_encode($day));
    $check('csv columns match header (10)', count($day) === 10, (string)count($day));
} catch (Throwable $e) {
    $fail++;
    echo 'FAIL exception: ' . $e::class . ': ' . $e->getMessage() . "\n";
} finally {
    foreach ([$path, $path . '-wal', $path . '-shm'] as $f) if (is_file($f)) unlink($f);
}
echo $fail === 0 ? "\nALL PASS\n" : "\n{$fail} FAILED\n";
exit($fail === 0 ? 0 : 1);
