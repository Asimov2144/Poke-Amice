<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../../lib/event_identity.php';
api_bootstrap();

header('Content-Type: application/json; charset=utf-8');
$token = gg_bearer_token();
$verified = $token !== '' ? gg_event_token_verify($token) : null;
$identity = gg_current_identity(false);
echo json_encode([
    'token_seen' => $token,
    'token_len' => strlen($token),
    'verified' => $verified,
    'identity' => $identity,
    'secret_loaded' => gg_event_auth_secret() !== null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
