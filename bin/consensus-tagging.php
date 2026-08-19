<?php
declare(strict_types=1);

/**
 * Consensus job（开发规格 §7.2）：把 label_submissions / verify_submissions 里堆积的
 * pending 数据，按"至少 2 个独立身份一致"的简单规则推成 confirmed_annotations，
 * 顺带重新算一遍 identity_stats.reliability。手动运行，也可以配 cron —— 和仓库里已有的
 * bin/build-pokemon-search-index.php 是同一种"离线 CLI 脚本"形态，不是常驻进程。
 *
 * 规则（MVP，先不做 Reliability 加权，见设计文档 §7.2/§8）：
 *   present 类标注：同一 (screenshot_id, category) 下，某个规范化答案有 >=2 个独立身份
 *     支持 → confirmed；出现两个以上都达标的不同答案 → conflict（相关行转 under_review，
 *     留给 Phase 1.5 的 Reviewer 队列）。
 *   none_of_above：同一 (screenshot_id, category) 下 >=2 个独立身份一致 → 直接把这些行标
 *     confirmed（没有 canonical 可展示，不写 confirmed_annotations）。
 *   核验模式"正确"票 >= 3 → 给现有声明背书，写一条 confirmed_annotations
 *     （source=verify_submissions）。"错误"票的处理不变，submit_verify.php 里已经在做。
 *
 * 用法：php bin/consensus-tagging.php [--dry-run]
 */

require __DIR__ . '/../api/v1/bootstrap.php';

const GG_CONSENSUS_AGREE_THRESHOLD = 2;
const GG_CONSENSUS_VERIFY_CORRECT_THRESHOLD = 3;
const GG_CONSENSUS_RELIABILITY_MIN_SAMPLES = 5;

/** present 类答案的分组 key：优先用已解析的 canonical，没有的话退回规范化后的原始输入。 */
function gg_consensus_group_key(string $canonical, string $rawInput): string
{
    if ($canonical !== '') return 'c:' . $canonical;
    $normalized = mb_strtolower(trim($rawInput));
    return $normalized === '' ? '' : 'r:' . $normalized;
}

function gg_consensus_run(PDO $tagDb, bool $dryRun): void
{
    $now = microtime(true);

    $upsert = $tagDb->prepare(
        'INSERT INTO confirmed_annotations (screenshot_id, category, canonical, status, support_count, source, confirmed_at)
         VALUES (?,?,?,?,?,?,?)
         ON CONFLICT(screenshot_id, category, canonical) DO UPDATE SET
           status=excluded.status, support_count=excluded.support_count,
           source=excluded.source, confirmed_at=excluded.confirmed_at'
    );
    $promoteLabel = $tagDb->prepare('UPDATE label_submissions SET status=?, updated_at=? WHERE id=?');

    // ---- 1. present 类标注 ----
    $pending = $tagDb->query(
        "SELECT id, screenshot_id, category, canonical, raw_input, identity_kind, identity_id
         FROM label_submissions WHERE answer_kind='present' AND status='pending'"
    )->fetchAll();

    $groups = [];
    foreach ($pending as $row) {
        $key = gg_consensus_group_key((string)$row['canonical'], (string)$row['raw_input']);
        if ($key === '') continue;
        $groupKey = $row['screenshot_id'] . '|' . $row['category'] . '|' . $key;
        $groups[$groupKey]['screenshot_id'] ??= (int)$row['screenshot_id'];
        $groups[$groupKey]['category'] ??= (string)$row['category'];
        $groups[$groupKey]['canonical'] ??= (string)$row['canonical'] !== '' ? (string)$row['canonical'] : (string)$row['raw_input'];
        $groups[$groupKey]['identities'][$row['identity_kind'] . ':' . $row['identity_id']] = true;
        $groups[$groupKey]['row_ids'][] = (int)$row['id'];
    }

    $byCase = [];
    foreach ($groups as $group) {
        $caseKey = $group['screenshot_id'] . '|' . $group['category'];
        $byCase[$caseKey][] = $group;
    }

    $presentConfirmed = 0;
    $presentConflict = 0;
    foreach ($byCase as $caseGroups) {
        $qualified = array_values(array_filter(
            $caseGroups,
            static fn(array $g): bool => count($g['identities']) >= GG_CONSENSUS_AGREE_THRESHOLD
        ));
        if ($qualified === []) continue;

        $isConflict = count($qualified) > 1;
        foreach ($qualified as $group) {
            $status = $isConflict ? 'conflict' : 'confirmed';
            if (!$dryRun) {
                $upsert->execute([
                    $group['screenshot_id'], $group['category'], $group['canonical'],
                    $status, count($group['identities']), 'label_submissions', $now,
                ]);
                $rowStatus = $isConflict ? 'under_review' : 'confirmed';
                foreach ($group['row_ids'] as $rowId) $promoteLabel->execute([$rowStatus, $now, $rowId]);
            }
            if ($isConflict) $presentConflict++; else $presentConfirmed++;
        }
    }

    // ---- 2. none_of_above ----
    $noneRows = $tagDb->query(
        "SELECT id, screenshot_id, category, identity_kind, identity_id
         FROM label_submissions WHERE answer_kind='none_of_above' AND status='pending'"
    )->fetchAll();
    $noneGroups = [];
    foreach ($noneRows as $row) {
        $caseKey = $row['screenshot_id'] . '|' . $row['category'];
        $noneGroups[$caseKey]['identities'][$row['identity_kind'] . ':' . $row['identity_id']] = true;
        $noneGroups[$caseKey]['row_ids'][] = (int)$row['id'];
    }
    $noneConfirmed = 0;
    foreach ($noneGroups as $group) {
        if (count($group['identities']) < GG_CONSENSUS_AGREE_THRESHOLD) continue;
        if (!$dryRun) foreach ($group['row_ids'] as $rowId) $promoteLabel->execute(['confirmed', $now, $rowId]);
        $noneConfirmed++;
    }

    // ---- 3. 核验模式"正确"票，给现有声明背书 ----
    $correctRows = $tagDb->query(
        "SELECT screenshot_id, category, claim_text,
                COUNT(DISTINCT identity_kind || ':' || identity_id) AS supporters
         FROM verify_submissions
         WHERE vote='正确'
         GROUP BY screenshot_id, category, target_type, target_key
         HAVING supporters >= " . GG_CONSENSUS_VERIFY_CORRECT_THRESHOLD
    )->fetchAll();
    $verifyConfirmed = 0;
    foreach ($correctRows as $row) {
        $canonical = trim((string)$row['claim_text']);
        if ($canonical === '') continue;
        if (!$dryRun) {
            $upsert->execute([
                (int)$row['screenshot_id'], (string)$row['category'], $canonical,
                'confirmed', (int)$row['supporters'], 'verify_submissions', $now,
            ]);
        }
        $verifyConfirmed++;
    }

    // ---- 4. Reliability：present/none_of_above 里被 confirmed 的比例，样本太少先留 NULL ----
    $reliabilityRows = $tagDb->query(
        "SELECT identity_kind, identity_id,
                SUM(CASE WHEN status='confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
                COUNT(*) AS total_count
         FROM label_submissions
         WHERE answer_kind IN ('present','none_of_above')
         GROUP BY identity_kind, identity_id"
    )->fetchAll();
    $updateReliability = $tagDb->prepare('UPDATE identity_stats SET reliability=? WHERE identity_kind=? AND identity_id=?');
    $reliabilityUpdated = 0;
    foreach ($reliabilityRows as $row) {
        $total = (int)$row['total_count'];
        $reliability = $total >= GG_CONSENSUS_RELIABILITY_MIN_SAMPLES
            ? round((int)$row['confirmed_count'] / $total, 4)
            : null;
        if (!$dryRun) $updateReliability->execute([$reliability, $row['identity_kind'], $row['identity_id']]);
        $reliabilityUpdated++;
    }

    printf(
        "[consensus-tagging] %s present_confirmed=%d present_conflict=%d none_of_above_confirmed=%d verify_confirmed=%d identities_reliability_updated=%d\n",
        $dryRun ? 'DRY-RUN' : 'APPLIED',
        $presentConfirmed, $presentConflict, $noneConfirmed, $verifyConfirmed, $reliabilityUpdated
    );
}

function main(array $argv): int
{
    $dryRun = in_array('--dry-run', $argv, true);
    gg_consensus_run(api_tagging_db(), $dryRun);
    return 0;
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(main($argv));
}
