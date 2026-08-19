<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../../lib/event_identity.php';
require __DIR__ . '/../../../lib/tagging_helpers.php';
require __DIR__ . '/../../../lib/pokemon_search.php';
api_bootstrap();
api_require_method('POST');

const GG_MAX_ANSWERS_PER_SUBMISSION = 8;
const GG_MAX_RAW_INPUT_LENGTH = 60;

api_run(function (): void {
    $tagDb = api_tagging_db();
    api_rate_limit($tagDb, 'submit_label_v1', 60, 3600);
    $identity = gg_current_identity();
    $siteDb = api_db();
    $body = api_read_json_body();

    $screenshotId = filter_var($body['screenshot_id'] ?? null, FILTER_VALIDATE_INT);
    if ($screenshotId === false || (int)$screenshotId < 1) {
        api_error('INVALID_FIELD', 'screenshot_id 无效', 422, ['field' => 'screenshot_id']);
    }
    $category = api_body_text($body, 'category', 20);
    gg_validate_category($category, GG_LABEL_CATEGORIES);

    $answerKind = api_body_text($body, 'answer_kind', 20);
    if (!in_array($answerKind, ['present', 'none_of_above', 'skip'], true)) {
        api_error('INVALID_FIELD', 'answer_kind 无效', 422, ['field' => 'answer_kind', 'allowed' => ['present', 'none_of_above', 'skip']]);
    }

    $existsStmt = $siteDb->prepare('SELECT 1 FROM screenshots WHERE id=?');
    $existsStmt->execute([(int)$screenshotId]);
    if (!$existsStmt->fetchColumn()) api_error('SCREENSHOT_NOT_FOUND', '未找到这张截图', 404);

    $rawAnswers = is_array($body['answers'] ?? null) ? $body['answers'] : [];
    $entries = [];

    if ($answerKind === 'present') {
        if ($rawAnswers === []) {
            api_error('INVALID_FIELD', 'present 需要至少一条 answers', 422, ['field' => 'answers']);
        }
        if (count($rawAnswers) > GG_MAX_ANSWERS_PER_SUBMISSION) {
            api_error('INVALID_FIELD', 'answers 数量超出上限', 422, ['field' => 'answers', 'max' => GG_MAX_ANSWERS_PER_SUBMISSION]);
        }
        foreach ($rawAnswers as $answer) {
            $rawInput = trim((string)(is_array($answer) ? ($answer['raw_input'] ?? '') : ''));
            if ($rawInput === '') continue;
            if (mb_strlen($rawInput) > GG_MAX_RAW_INPUT_LENGTH) {
                api_error('INVALID_FIELD', 'raw_input 过长', 422, ['field' => 'raw_input', 'max_length' => GG_MAX_RAW_INPUT_LENGTH]);
            }

            $canonical = '';
            $matchVia = 'free_text';
            if ($category === '宝可梦') {
                $resolved = gg_pokemon_resolve($rawInput);
                if ($resolved !== null) {
                    $canonical = (string)($resolved['entry']['name']['zh'] ?? '');
                    $matchVia = (string)$resolved['matched_via'];
                }
            } else {
                $resolved = gg_glossary_resolve($siteDb, $category, $rawInput);
                if ($resolved !== null) {
                    $canonical = $resolved['canonical'];
                    $matchVia = $resolved['matched_via'];
                }
            }
            if ($canonical === '') $matchVia = 'free_text';

            $entries[] = ['raw_input' => $rawInput, 'canonical' => $canonical, 'match_via' => $matchVia];
        }
        if ($entries === []) {
            api_error('INVALID_FIELD', 'answers 中没有有效的 raw_input', 422, ['field' => 'answers']);
        }
    } else {
        // none_of_above / skip：写一条哨兵行，标记这张图这个分类已经被该身份处理过。
        $entries[] = ['raw_input' => '', 'canonical' => '', 'match_via' => 'none'];
    }

    $now = microtime(true);
    $insert = $tagDb->prepare('INSERT INTO label_submissions (screenshot_id, category, canonical, raw_input, match_via, answer_kind, identity_kind, identity_id, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,\'pending\',?,?)');
    foreach ($entries as $entry) {
        $insert->execute([(int)$screenshotId, $category, $entry['canonical'], $entry['raw_input'], $entry['match_via'], $answerKind, $identity['kind'], $identity['id'], $now, $now]);
    }

    $xpGain = match ($answerKind) {
        'present' => 10,
        'none_of_above' => 5,
        default => 0, // skip：不给分，同时打断连击
    };
    $stats = gg_award_identity($tagDb, $identity, $xpGain, 'labels_submitted');

    api_success([
        'saved' => array_map(static fn(array $e): array => [
            'raw_input' => $e['raw_input'],
            'canonical' => $e['canonical'],
            'match_via' => $e['match_via'],
        ], $entries),
        'stats' => gg_identity_stats_public($stats),
    ], [], 0, 201);
});
