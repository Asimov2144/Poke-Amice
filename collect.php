<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/analytics.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); http_response_code(405); exit; }
if (!gg_analytics_origin($_SERVER)) { http_response_code(403); exit; }
if (!gg_analytics_browser($_SERVER)) { http_response_code(204); exit; }
if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) { http_response_code(415); exit; }
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) { http_response_code(413); exit; }
$raw = file_get_contents('php://input', false, null, 0, 16385);
if ($raw === false || strlen($raw) > 16384) { http_response_code(413); exit; }
try {
    $batch = gg_analytics_validate(json_decode($raw, true, 16, JSON_THROW_ON_ERROR));
} catch (JsonException | InvalidArgumentException) { http_response_code(400); exit; }
try {
    gg_analytics_record(gg_analytics_db(gg_analytics_path()), $batch, $_SERVER, time());
    http_response_code(204);
} catch (OverflowException) {
    header('Retry-After: 60'); http_response_code(429);
} catch (Throwable $error) {
    error_log('[gallery_analytics] collection unavailable: ' . $error::class);
    http_response_code(503);
}
