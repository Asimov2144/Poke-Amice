<?php
/**
 * 人工标注功能的身份识别：登录态复用 event 应用的账号体系（不是最初设想的 tour）。
 *
 * event/server.ts 的 auth token 不是查表型 opaque token，而是自签名结构：
 *   base64url(JSON{username,exp}) + '.' + base64url(HMAC-SHA256(payload, EVENT_AUTH_SECRET))
 * （对应 event/server.ts 的 createEventToken()/readEventToken()，约 L155-183）。
 * 这里在 PHP 侧本地重算同一个 HMAC 做校验，不查询任何数据库、不发起任何网络请求——
 * 完全不依赖 event 的 Node 进程是否存活，只需要和它共享同一份 EVENT_AUTH_SECRET。
 *
 * 校验失败（缺密钥、格式不对、签名不符、已过期）一律返回 null，调用方应静默降级为
 * 游客身份，绝不能因为鉴权失败就把整个标注请求打成 500。
 *
 * 密钥来源：`event/.env` 是 `-rw------- root root`（只有 root 能读），而这台机器上
 * PHP-FPM 是以 www 用户跑的（php-fpm.conf: user=www），所以生产环境下直接读
 * event/.env 会静默失败（is_readable() 为 false）——这不是 nginx 转发头的问题，是
 * 文件权限问题。没有放宽 event/.env 本身的权限（那个文件还存着数据库密码、
 * Tencent COS 密钥、几个 API key，放宽给 www 等于把这些也暴露给这台机器上所有跑在
 * www 用户下的代码，包括 WordPress 插件之类不相关的东西）。改成从一个只装这一个
 * 密钥、单独属主 www、600 权限的小文件读——和 event/.env 一样的收紧强度，只是
 * 换成正确的属主，涉密范围也从"整个 event/.env"缩小到"这一个字符串"。
 * 这个小文件同时匹配站点已有的 `*.env` 全局 nginx 拦截规则，不会被当静态文件下载。
 */

declare(strict_types=1);

function gg_event_auth_secret(): ?string
{
    static $secret = null;
    static $loaded = false;
    if ($loaded) return $secret;
    $loaded = true;

    $dedicatedPath = dirname(__DIR__) . '/.event_auth_secret.env';
    if (is_file($dedicatedPath) && is_readable($dedicatedPath)) {
        $value = trim((string)@file_get_contents($dedicatedPath));
        if ($value !== '' && strlen($value) >= 32) {
            $secret = $value;
            return $secret;
        }
    }

    // 本地开发兜底：如果拿不到专用密钥文件（比如还没跑过一次性的提取步骤），
    // 退回直接解析 event/.env——在权限允许的环境下（比如本地用同一个用户跑）依然能工作。
    $envPath = dirname(__DIR__, 2) . '/event/.env';
    if (!is_file($envPath) || !is_readable($envPath)) return null;
    $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return null;

    foreach ($lines as $line) {
        if (!str_starts_with($line, 'EVENT_AUTH_SECRET=')) continue;
        $value = trim(substr($line, strlen('EVENT_AUTH_SECRET=')));
        $len = strlen($value);
        if ($len >= 2 && (($value[0] === '"' && $value[$len - 1] === '"') || ($value[0] === "'" && $value[$len - 1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($value !== '' && strlen($value) >= 32) $secret = $value;
        break;
    }
    return $secret;
}

function gg_base64url_decode(string $value): ?string
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) return null;
    $padding = (4 - strlen($value) % 4) % 4;
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
    return $decoded === false ? null : $decoded;
}

function gg_base64url_encode(string $binary): string
{
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

/**
 * @return array{username:string}|null
 */
function gg_event_token_verify(string $token): ?array
{
    $secret = gg_event_auth_secret();
    if ($secret === null) return null;

    $token = trim($token);
    $parts = explode('.', $token);
    if (count($parts) !== 2) return null;
    [$payload, $signature] = $parts;
    if ($payload === '' || $signature === '') return null;

    $expectedSignature = gg_base64url_encode(hash_hmac('sha256', $payload, $secret, true));
    if (!hash_equals($expectedSignature, $signature)) return null;

    $decoded = gg_base64url_decode($payload);
    if ($decoded === null) return null;
    try {
        $data = json_decode($decoded, true, 4, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    if (!is_array($data) || !isset($data['username'], $data['exp'])) return null;
    if (!is_string($data['username']) || $data['username'] === '') return null;
    if (!is_numeric($data['exp'])) return null;

    $nowMs = microtime(true) * 1000;
    if ((float)$data['exp'] <= $nowMs) return null;

    return ['username' => $data['username']];
}

/**
 * 这台服务器是 nginx + PHP-FPM，站点共用的 fastcgi 配置里没有
 * `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`——这是该组合非常常见的坑，
 * 结果是标准的 Authorization 头完全到不了 PHP（$_SERVER 里没有，apache_request_headers()
 * 在 FPM 下也不存在），哪怕签名完全正确的 token 也会被当成"未登录"。改共享的 nginx 配置
 * 影响这台机器上的所有站点，风险和范围都超出这个功能本身，所以这里换一条已经证实能穿透
 * 到 PHP 的自定义头 X-GG-Auth-Token 作为主要通道；Authorization 头继续保留做兜底，
 * 万一将来 nginx 配置修好了或者别的客户端（未来的 App 之类）用标准头也能直接work。
 */
function gg_bearer_token(): string
{
    $custom = trim((string)($_SERVER['HTTP_X_GG_AUTH_TOKEN'] ?? ''));
    if ($custom !== '') return $custom;

    $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() ?: [] as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) { $header = trim((string)$value); break; }
        }
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) return '';
    return trim($m[1]);
}

function gg_guest_id_header(): string
{
    return trim((string)($_SERVER['HTTP_X_GG_GUEST_ID'] ?? ''));
}

function gg_is_valid_guest_id(string $id): bool
{
    return (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $id);
}

/**
 * 当前请求的身份：优先校验 Bearer token（event 账号），否则退回 X-GG-Guest-Id 游客身份。
 * identity_kind='event_user' 只能在这里、且只在 token 校验成功后被设置——绝不信任客户端
 * 自称的身份类型。
 *
 * $required=true 时，如果既没有有效 token 也没有合法的游客 id，直接 api_error() 终止请求
 * （复用 bootstrap.php 的 api_error()，本文件不单独定义错误处理）。
 *
 * @return array{kind:string, id:string}
 */
function gg_current_identity(bool $required = true): array
{
    $token = gg_bearer_token();
    if ($token !== '') {
        $identity = gg_event_token_verify($token);
        if ($identity !== null) {
            return ['kind' => 'event_user', 'id' => $identity['username']];
        }
    }

    $guestId = gg_guest_id_header();
    if (gg_is_valid_guest_id($guestId)) {
        return ['kind' => 'guest', 'id' => strtolower($guestId)];
    }

    if ($required) {
        api_error('INVALID_IDENTITY', '缺少有效的登录凭证或游客标识（X-GG-Guest-Id）', 401);
    }
    return ['kind' => 'guest', 'id' => ''];
}
