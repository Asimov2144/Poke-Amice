<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../../lib/event_identity.php';
require __DIR__ . '/../../../lib/tagging_helpers.php';
api_bootstrap();
api_require_method('POST');

api_run(function (): void {
    $tagDb = api_tagging_db();
    api_rate_limit($tagDb, 'submit_verify_v1', 90, 3600);
    $identity = gg_current_identity();
    $siteDb = api_db();
    $body = api_read_json_body();

    $screenshotId = filter_var($body['screenshot_id'] ?? null, FILTER_VALIDATE_INT);
    if ($screenshotId === false || (int)$screenshotId < 1) {
        api_error('INVALID_FIELD', 'screenshot_id 无效', 422, ['field' => 'screenshot_id']);
    }
    $category = api_body_text($body, 'category', 20);
    gg_validate_category($category, GG_VERIFY_CATEGORIES);
    $targetType = api_body_text($body, 'target_type', 30);
    if (!in_array($targetType, ['glossary_match', 'corpus_match', 'label_submission'], true)) {
        api_error('INVALID_FIELD', 'target_type 无效', 422, ['field' => 'target_type']);
    }
    $targetKey = api_body_text($body, 'target_key', 200);
    if ($targetKey === '') api_error('INVALID_FIELD', 'target_key 不能为空', 422, ['field' => 'target_key']);
    $vote = api_body_text($body, 'vote', 10);
    if (!in_array($vote, ['正确', '错误', '不确定'], true)) {
        api_error('INVALID_FIELD', 'vote 无效', 422, ['field' => 'vote', 'allowed' => ['正确', '错误', '不确定']]);
    }
    $claimText = api_body_text($body, 'claim_text', 400);

    $existsStmt = $siteDb->prepare('SELECT 1 FROM screenshots WHERE id=?');
    $existsStmt->execute([(int)$screenshotId]);
    if (!$existsStmt->fetchColumn()) api_error('SCREENSHOT_NOT_FOUND', '未找到这张截图', 404);

    $dedupe = $tagDb->prepare('SELECT id, vote FROM verify_submissions WHERE screenshot_id=? AND category=? AND target_type=? AND target_key=? AND identity_kind=? AND identity_id=? LIMIT 1');
    $dedupe->execute([(int)$screenshotId, $category, $targetType, $targetKey, $identity['kind'], $identity['id']]);
    $existing = $dedupe->fetch();
    if ($existing) {
        $stats = gg_identity_stats($tagDb, $identity);
        api_success(['vote' => ['id' => (int)$existing['id'], 'vote' => $existing['vote'], 'duplicate' => true], 'stats' => gg_identity_stats_public($stats)], [], 0);
    }

    $now = microtime(true);
    $insert = $tagDb->prepare('INSERT INTO verify_submissions (screenshot_id, category, target_type, target_key, claim_text, vote, identity_kind, identity_id, created_at) VALUES (?,?,?,?,?,?,?,?,?)');
    $insert->execute([(int)$screenshotId, $category, $targetType, $targetKey, $claimText, $vote, $identity['kind'], $identity['id'], $now]);
    $voteId = (int)$tagDb->lastInsertId();

    if ($vote === '错误') {
        gg_maybe_file_auto_correction($tagDb, (int)$screenshotId, $category, $targetType, $targetKey, $claimText);
    }

    $stats = gg_award_identity($tagDb, $identity, 8, 'verifies_submitted');

    api_success(['vote' => ['id' => $voteId, 'vote' => $vote, 'duplicate' => false], 'stats' => gg_identity_stats_public($stats)], [], 0, 201);
});
