<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
api_bootstrap();
api_require_method('GET');

api_run(function (): void {
    if (!isset($_GET['id']) || $_GET['id'] === '') {
        api_error('MISSING_PARAMETER', '缺少参数 id', 400, ['parameter' => 'id']);
    }
    $id = api_query_int('id', 0, 1, PHP_INT_MAX);
    $pdo = api_db();
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT id, game, rel_path, filename, width, height, text, tags_json, note,
               contributor, preview_url, thumb_url, created_at, updated_at
        FROM screenshots WHERE id=?
    SQL);
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    if (!$item) api_error('SCREENSHOT_NOT_FOUND', '未找到这张截图', 404);

    $sourceFolder = substr((string)$item['rel_path'], 0, max(0, strlen((string)$item['rel_path']) - strlen((string)$item['filename'])));
    $item['id'] = (int)$item['id'];
    $item['width'] = $item['width'] === null ? null : (int)$item['width'];
    $item['height'] = $item['height'] === null ? null : (int)$item['height'];
    $item['created_at'] = (float)$item['created_at'];
    $item['updated_at'] = (float)$item['updated_at'];
    $item['tags'] = json_decode((string)$item['tags_json'], true) ?: [];
    unset($item['tags_json'], $item['rel_path']);
    api_decorate_game($item);

    $item['submission_variants'] = [];
    $duplicateGroup = $pdo->prepare('SELECT group_id FROM screenshot_duplicate_groups WHERE screenshot_id=?');
    $duplicateGroup->execute([$id]);
    $groupId = $duplicateGroup->fetchColumn();
    if ($groupId !== false) {
        $variantStmt = $pdo->prepare(<<<'SQL'
            SELECT s.id, s.game, s.filename, s.contributor, s.preview_url, s.thumb_url, s.created_at
            FROM screenshot_duplicate_groups dg
            JOIN screenshots s ON s.id=dg.screenshot_id
            WHERE dg.group_id=?
            ORDER BY s.contributor, s.filename, s.id
        SQL);
        $variantStmt->execute([(int)$groupId]);
        $item['submission_variants'] = $variantStmt->fetchAll();
        foreach ($item['submission_variants'] as &$variant) {
            $variant['id'] = (int)$variant['id'];
            $variant['created_at'] = (float)$variant['created_at'];
            api_decorate_game($variant);
        }
        unset($variant);
    }

    $glossaryStmt = $pdo->prepare('SELECT category, canonical, alias, language, source FROM glossary_matches WHERE screenshot_id=? ORDER BY category, canonical LIMIT 50');
    $glossaryStmt->execute([$id]);
    $item['glossary'] = $glossaryStmt->fetchAll();

    $evidenceSelect = api_has_corpus_evidence($pdo)
        ? ", COALESCE(json_extract(evidence_json, '$.best_match.status'), 'legacy') AS match_status, json_extract(evidence_json, '$.review_reasons') AS review_reasons_json"
        : ", 'legacy' AS match_status, '[]' AS review_reasons_json";
    $corpusStmt = $pdo->prepare('SELECT text_id, score, collection, language, file_key, text_file, line_no, text, matched_text' . $evidenceSelect . ' FROM screenshot_corpus_matches WHERE screenshot_id=? ORDER BY score DESC LIMIT 20');
    $corpusStmt->execute([$id]);
    $item['corpus_matches'] = $corpusStmt->fetchAll();
    foreach ($item['corpus_matches'] as &$match) {
        $match['score'] = (float)$match['score'];
        $match['line_no'] = (int)$match['line_no'];
        $match['review_reasons'] = json_decode((string)$match['review_reasons_json'], true) ?: [];
        unset($match['review_reasons_json']);
    }
    unset($match);

    $translationSource = null;
    foreach ($item['corpus_matches'] as $match) {
        if ($match['language'] === 'zh-Hans') {
            $translationSource = $match;
            break;
        }
    }
    if ($translationSource === null && $item['corpus_matches']) $translationSource = $item['corpus_matches'][0];
    $item['translations'] = $translationSource === null ? [] : api_corpus_translations(
        (string)$translationSource['collection'],
        (string)$translationSource['file_key'],
        (string)$translationSource['text_file'],
        (int)$translationSource['line_no']
    );

    $contextSource = null;
    foreach ($item['corpus_matches'] as $match) {
        if ($match['file_key'] === 'script' && $match['language'] === 'zh-Hans') {
            $contextSource = $match;
            break;
        }
    }
    if ($contextSource === null && $item['corpus_matches']) $contextSource = $item['corpus_matches'][0];
    $item['story_context'] = [];
    if ($contextSource !== null) {
        $context = api_corpus_context(
            (string)$contextSource['collection'],
            (string)$contextSource['language'],
            (string)$contextSource['file_key'],
            (string)$contextSource['text_file'],
            (int)$contextSource['line_no']
        );
        if ($context) {
            $screensStmt = $pdo->prepare(<<<'SQL'
                SELECT s.id, s.game, s.thumb_url, s.preview_url, cm.line_no
                FROM screenshot_corpus_matches cm
                JOIN screenshots s ON s.id=cm.screenshot_id
                WHERE cm.collection=? AND cm.language=? AND cm.file_key=? AND cm.text_file=?
                  AND cm.line_no BETWEEN ? AND ? AND cm.score>=0.72
                  AND s.game=? AND s.contributor=?
                  AND substr(s.rel_path,1,length(s.rel_path)-length(s.filename))=?
                ORDER BY cm.line_no, ABS(s.id-?), cm.score DESC
                LIMIT 80
            SQL);
            $screensStmt->execute([
                $contextSource['collection'], $contextSource['language'], $contextSource['file_key'], $contextSource['text_file'],
                max(0, (int)$contextSource['line_no'] - 3), (int)$contextSource['line_no'] + 3,
                $item['source_game'], $item['contributor'], $sourceFolder, $id,
            ]);
            $screensByLine = [];
            foreach ($screensStmt->fetchAll() as $screen) {
                $screen['id'] = (int)$screen['id'];
                $screen['line_no'] = (int)$screen['line_no'];
                api_decorate_game($screen);
                $screensByLine[$screen['line_no']][] = $screen;
            }
            foreach ($context as &$line) {
                $line['line_no'] = (int)$line['line_no'];
                $line['current'] = $line['line_no'] === (int)$contextSource['line_no'];
                $line['screenshots'] = array_slice($screensByLine[$line['line_no']] ?? [], 0, 4);
            }
            unset($line);
            $item['story_context'] = $context;
        }
    }

    $item['version_matches'] = [];
    $oppositeGame = match ((string)$item['source_game']) {
        '宝可梦 朱' => '宝可梦 紫',
        '宝可梦 紫' => '宝可梦 朱',
        default => '',
    };
    $normalizedText = api_normalized_ocr((string)$item['text']);
    if ($oppositeGame !== '' && mb_strlen($normalizedText) >= 8) {
        $boundsStmt = $pdo->prepare('SELECT MIN(id) AS min_id, MAX(id) AS max_id FROM screenshots WHERE game=?');
        $boundsStmt->execute([$item['source_game']]);
        $sourceBounds = $boundsStmt->fetch();
        $boundsStmt->execute([$oppositeGame]);
        $targetBounds = $boundsStmt->fetch();
        $sourceSpan = max(1, (int)$sourceBounds['max_id'] - (int)$sourceBounds['min_id']);
        $progress = ($id - (int)$sourceBounds['min_id']) / $sourceSpan;
        $expectedId = (int)round((int)$targetBounds['min_id'] + $progress * max(1, (int)$targetBounds['max_id'] - (int)$targetBounds['min_id']));
        $versionStmt = $pdo->prepare("SELECT id,game,filename,text,thumb_url,preview_url FROM screenshots WHERE game=? AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(text),CHAR(10),''),CHAR(13),''),CHAR(9),''),' ',''),'　','')=? LIMIT 30");
        $versionStmt->execute([$oppositeGame, $normalizedText]);
        $item['version_matches'] = $versionStmt->fetchAll();
        usort($item['version_matches'], fn(array $a, array $b): int => abs((int)$a['id'] - $expectedId) <=> abs((int)$b['id'] - $expectedId));
        $item['version_matches'] = array_slice($item['version_matches'], 0, 6);
        foreach ($item['version_matches'] as &$versionMatch) {
            $versionMatch['id'] = (int)$versionMatch['id'];
            api_decorate_game($versionMatch);
        }
        unset($versionMatch);
    }

    $item['story_sequence'] = [];
    if ($item['corpus_matches']) {
        $primary = $item['corpus_matches'][0];
        $lineNo = (int)$primary['line_no'];
        $sequenceStmt = $pdo->prepare(<<<'SQL'
            SELECT s.id, s.thumb_url, s.preview_url, s.text, s.game,
                   MIN(cm.line_no) AS line_no, MAX(cm.score) AS score, MIN(cm.text_id) AS text_id
            FROM screenshot_corpus_matches cm
            JOIN screenshots s ON s.id=cm.screenshot_id
            WHERE cm.collection=? AND cm.language=? AND cm.text_file=?
              AND cm.line_no BETWEEN ? AND ? AND cm.score>=0.72
              AND s.game=? AND s.contributor=?
              AND substr(s.rel_path,1,length(s.rel_path)-length(s.filename))=?
            GROUP BY s.id
            ORDER BY line_no ASC, ABS(s.id-?) ASC
            LIMIT 30
        SQL);
        $sequenceStmt->execute([
            $primary['collection'], $primary['language'], $primary['text_file'], max(0, $lineNo - 2), $lineNo + 2,
            $item['source_game'], $item['contributor'], $sourceFolder, $id,
        ]);
        $item['story_sequence'] = $sequenceStmt->fetchAll();
        foreach ($item['story_sequence'] as &$sequenceItem) {
            $sequenceItem['id'] = (int)$sequenceItem['id'];
            $sequenceItem['line_no'] = (int)$sequenceItem['line_no'];
            $sequenceItem['score'] = (float)$sequenceItem['score'];
            api_decorate_game($sequenceItem);
        }
        unset($sequenceItem);
    }

    $dataset = api_dataset_snapshot($pdo);
    api_success(['screenshot' => $item], ['dataset_version' => $dataset['version']], 300);
});
