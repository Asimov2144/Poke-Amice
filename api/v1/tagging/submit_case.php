<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../../lib/event_identity.php';
require __DIR__ . '/../../../lib/tagging_helpers.php';
require __DIR__ . '/../../../lib/pokemon_search.php';
api_bootstrap();
api_require_method('POST');

const GG_MAX_FIELDS_PER_CASE = 3;
const GG_MAX_ANSWERS_PER_FIELD = 8;
const GG_MAX_RAW_INPUT_LENGTH = 60;

api_run(function (): void {
    $tagDb = api_tagging_db();
    api_rate_limit($tagDb, 'submit_case_v1', 60, 3600);
    $identity = gg_current_identity();
    $siteDb = api_db();
    $body = api_read_json_body();

    $screenshotId = filter_var($body['screenshot_id'] ?? null, FILTER_VALIDATE_INT);
    if ($screenshotId === false || (int)$screenshotId < 1) {
        api_error('INVALID_FIELD', 'screenshot_id 无效', 422, ['field' => 'screenshot_id']);
    }
    $existsStmt = $siteDb->prepare('SELECT 1 FROM screenshots WHERE id=?');
    $existsStmt->execute([(int)$screenshotId]);
    if (!$existsStmt->fetchColumn()) api_error('SCREENSHOT_NOT_FOUND', '未找到这张截图', 404);

    $rawFields = is_array($body['fields'] ?? null) ? $body['fields'] : [];
    if ($rawFields === []) api_error('INVALID_FIELD', 'fields 不能为空', 422, ['field' => 'fields']);
    if (count($rawFields) > GG_MAX_FIELDS_PER_CASE) {
        api_error('INVALID_FIELD', 'fields 数量超出上限', 422, ['field' => 'fields', 'max' => GG_MAX_FIELDS_PER_CASE]);
    }

    $now = microtime(true);
    $insert = $tagDb->prepare('INSERT INTO label_submissions (screenshot_id, category, canonical, raw_input, match_via, answer_kind, identity_kind, identity_id, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,\'pending\',?,?)');

    $savedFields = [];
    $validFieldCount = 0;
    $seenCategories = [];

    foreach ($rawFields as $field) {
        if (!is_array($field)) api_error('INVALID_FIELD', 'fields 中的每一项必须是对象', 422, ['field' => 'fields']);

        $category = trim((string)($field['category'] ?? ''));
        gg_validate_category($category, GG_LABEL_CATEGORIES);
        if (isset($seenCategories[$category])) {
            api_error('INVALID_FIELD', '同一个 Case 中不能重复提交同一个分类', 422, ['field' => 'category']);
        }
        $seenCategories[$category] = true;

        $answerKind = trim((string)($field['answer_kind'] ?? ''));
        if (!in_array($answerKind, ['present', 'none_of_above', 'skip'], true)) {
            api_error('INVALID_FIELD', 'answer_kind 无效', 422, ['field' => 'answer_kind', 'allowed' => ['present', 'none_of_above', 'skip']]);
        }

        $rawAnswers = is_array($field['answers'] ?? null) ? $field['answers'] : [];
        $entries = [];

        if ($answerKind === 'present') {
            if ($rawAnswers === []) {
                api_error('INVALID_FIELD', 'present 需要至少一条 answers', 422, ['field' => 'answers']);
            }
            if (count($rawAnswers) > GG_MAX_ANSWERS_PER_FIELD) {
                api_error('INVALID_FIELD', 'answers 数量超出上限', 422, ['field' => 'answers', 'max' => GG_MAX_ANSWERS_PER_FIELD]);
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

        foreach ($entries as $entry) {
            $insert->execute([(int)$screenshotId, $category, $entry['canonical'], $entry['raw_input'], $entry['match_via'], $answerKind, $identity['kind'], $identity['id'], $now, $now]);
        }

        if ($answerKind !== 'skip') $validFieldCount++;

        $savedFields[] = [
            'category' => $category,
            'answer_kind' => $answerKind,
            'saved' => array_map(static fn(array $e): array => [
                'raw_input' => $e['raw_input'],
                'canonical' => $e['canonical'],
                'match_via' => $e['match_via'],
            ], $entries),
        ];
    }

    $xpGain = 0;
    foreach ($savedFields as $field) {
        $xpGain += match ($field['answer_kind']) {
            'present' => 10,
            'none_of_above' => 5,
            default => 0,
        };
    }
    $stats = gg_award_identity_fields($tagDb, $identity, $xpGain, $validFieldCount, 'labels_submitted');

    api_success([
        'fields' => $savedFields,
        'xp_gained' => $xpGain,
        'stats' => gg_identity_stats_public($stats),
    ], [], 0, 201);
});
