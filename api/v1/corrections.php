<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
api_bootstrap();
api_require_method('POST');

api_run(function (): void {
    $writeDb = api_write_db();
    api_rate_limit($writeDb, 'submit_correction_v1', 20, 3600);
    $body = api_read_json_body();

    $id = filter_var($body['screenshot_id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || (int)$id < 1) api_error('INVALID_FIELD', 'screenshot_id 无效', 422, ['field' => 'screenshot_id']);
    $category = api_body_text($body, 'category', 40);
    $allowedCategories = ['OCR文字错误', '剧情匹配错误', '截图信息错误', '无效截图', '其他问题'];
    if (!in_array($category, $allowedCategories, true)) {
        api_error('INVALID_FIELD', 'category 无效', 422, ['field' => 'category', 'allowed' => $allowedCategories]);
    }
    $suggestedText = api_body_text($body, 'suggested_text', 2000);
    $description = api_body_text($body, 'description', 2000);
    $contact = api_body_text($body, 'contact', 200);
    if ($suggestedText === '' && $description === '') {
        api_error('INVALID_FIELD', 'suggested_text 和 description 至少填写一项', 422);
    }

    $screenshotStmt = api_db()->prepare('SELECT id, text FROM screenshots WHERE id=?');
    $screenshotStmt->execute([(int)$id]);
    $screenshot = $screenshotStmt->fetch();
    if (!$screenshot) api_error('SCREENSHOT_NOT_FOUND', '未找到这张截图', 404);

    $now = microtime(true);
    $duplicateStmt = $writeDb->prepare('SELECT id FROM correction_reports WHERE screenshot_id=? AND category=? AND suggested_text=? AND description=? AND created_at>? LIMIT 1');
    $duplicateStmt->execute([(int)$id, $category, $suggestedText, $description, $now - 300]);
    $duplicateId = $duplicateStmt->fetchColumn();
    if ($duplicateId !== false) {
        api_success(['report' => ['id' => (int)$duplicateId, 'status' => 'pending', 'duplicate' => true]], [], 0);
    }

    $insert = $writeDb->prepare('INSERT INTO correction_reports (screenshot_id,category,original_text,suggested_text,description,contact,status,created_at,updated_at) VALUES (?,?,?,?,?,? ,"pending",?,?)');
    $insert->execute([(int)$id, $category, (string)$screenshot['text'], $suggestedText, $description, $contact, $now, $now]);
    api_success(['report' => ['id' => (int)$writeDb->lastInsertId(), 'status' => 'pending', 'duplicate' => false]], [], 0, 201);
});
