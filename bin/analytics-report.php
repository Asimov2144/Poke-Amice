<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/analytics.php';
$options = getopt('', ['db:', 'days:', 'csv', 'searches']);
$days = filter_var($options['days'] ?? 365, FILTER_VALIDATE_INT);
if ($days === false || $days < 1 || $days > 365) { fwrite(STDERR, "--days must be between 1 and 365\n"); exit(2); }
try {
    $db = gg_analytics_db((string)($options['db'] ?? gg_analytics_path()), false);
    if (isset($options['searches'])) {
        $out = fopen('php://stdout', 'wb');
        fputcsv($out, ['time', 'query', 'game', 'reason', 'results_shown', 'has_more', 'outcome']);
        foreach (gg_analytics_search_rows($db, $days) as $row) {
            $row['at'] = (new DateTimeImmutable('@' . $row['at']))->setTimezone(new DateTimeZone(GG_ANALYTICS_TIMEZONE))->format(DATE_ATOM);
            fputcsv($out, array_map('gg_analytics_csv_cell', array_values($row)));
        }
        exit;
    }
    $report = gg_analytics_report($db, $days);
    if (isset($options['csv'])) {
        $out = fopen('php://stdout', 'wb');
        // 前六列和以前一样（searches / screenshot_opens 仍是原始事件数）；后四列是干净口径：链接载入、人点开的、有交互的会话、折叠后的键入搜索。
        fputcsv($out, ['day', 'page_views', 'daily_anonymous_browsers', 'sessions', 'searches', 'screenshot_opens',
            'link_searches', 'chosen_opens', 'interactive_sessions', 'final_searches']);
        foreach ($report['daily'] as $day) fputcsv($out, array_values($day));
    } else echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) { fwrite(STDERR, "Gallery analytics report unavailable: " . $e->getMessage() . "\n"); exit(1); }
