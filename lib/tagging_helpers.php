<?php
/**
 * 人工标注功能的共享逻辑：分类校验、积分/等级、身份进度读写、候选截图挑选。
 *
 * 说明一个跨库限制：`screenshots`/`glossary_matches`/`screenshot_corpus_matches` 活在只读的
 * data/site.sqlite3（api_db()），而标注/校验产出活在 data/tagging.sqlite3（api_tagging_db()）——
 * 两个独立的 SQLite 文件、两个独立的 PDO 连接，不能在一条 SQL 里直接 JOIN。这里统一采用
 * "先查 tagging.sqlite3 拿到需要排除的 id 集合，再用 PHP 数组喂给 site.sqlite3 的 NOT IN"
 * 的两步做法，而不是引入跨库 ATTACH（保持和本仓库其它查询一样简单、好读）。
 */

declare(strict_types=1);

const GG_LABEL_CATEGORIES = ['宝可梦', '人物', '地点'];
const GG_VERIFY_CATEGORIES = ['宝可梦', '人物', '地点', '剧情'];

// 单次请求里最多排除的"已回答/已投票"数量，避免重度玩家的历史记录把 NOT IN 子句撑爆。
const GG_MAX_EXCLUDED_IDS = 4000;

function gg_validate_category(string $category, array $allowed): void
{
    if (!in_array($category, $allowed, true)) {
        api_error('INVALID_FIELD', 'category 无效', 422, ['field' => 'category', 'allowed' => $allowed]);
    }
}

function gg_xp_level(int $xp): int
{
    return (int)floor(sqrt(max(0, $xp) / 50)) + 1;
}

/**
 * 同 api.php 里已经在用的种子伪随机排序表达式（避免 SQLite RANDOM() 的全表非确定排序成本）。
 */
function gg_random_order_expr(string $column = 's.id'): string
{
    return "ABS(({$column} * (1103515245 + ((? % 1000000) * 2) + 1)) % 2147483647)";
}

function gg_random_seed(): int
{
    return random_int(1, 2147483646);
}

/**
 * 取出（不存在则创建）某身份的 identity_stats 行。
 * @return array<string,mixed>
 */
function gg_identity_stats(PDO $tagDb, array $identity): array
{
    $now = microtime(true);
    $select = $tagDb->prepare('SELECT * FROM identity_stats WHERE identity_kind=? AND identity_id=?');
    $select->execute([$identity['kind'], $identity['id']]);
    $row = $select->fetch();
    if ($row) return $row;

    $insert = $tagDb->prepare('INSERT INTO identity_stats (identity_kind, identity_id, xp, level, streak, best_streak, labels_submitted, verifies_submitted, last_active_at, created_at) VALUES (?,?,0,1,0,0,0,0,?,?)');
    try {
        $insert->execute([$identity['kind'], $identity['id'], $now, $now]);
    } catch (PDOException) {
        // 并发请求已经插入过了，忽略后重新查询即可。
    }
    $select->execute([$identity['kind'], $identity['id']]);
    $row = $select->fetch();
    return $row ?: [
        'identity_kind' => $identity['kind'], 'identity_id' => $identity['id'],
        'xp' => 0, 'level' => 1, 'streak' => 0, 'best_streak' => 0,
        'labels_submitted' => 0, 'verifies_submitted' => 0,
        'last_active_at' => $now, 'created_at' => $now,
    ];
}

function gg_identity_stats_public(array $row): array
{
    return [
        'xp' => (int)$row['xp'],
        'level' => (int)$row['level'],
        'streak' => (int)$row['streak'],
        'best_streak' => (int)$row['best_streak'],
        'labels_submitted' => (int)$row['labels_submitted'],
        'verifies_submitted' => (int)$row['verifies_submitted'],
    ];
}

/**
 * 排行榜展示名：event_user 的 identity_id 本身就是用户名，直接显示；guest 的 identity_id
 * 是本机 localStorage 生成的 UUID，不直接暴露，只取末 4 位做区分。
 */
function gg_leaderboard_display_name(string $kind, string $id): string
{
    if ($kind === 'event_user') return $id;
    $tail = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $id) ?? '', -4));
    return '游客·' . ($tail !== '' ? $tail : '0000');
}

/**
 * 提交后更新积分/连击/等级。$xpGain=0（目前只有标注模式的"跳过"会传 0）会打断连击但不
 * 清零历史最佳连击；校验模式的三种投票（含"不确定"）都算一次真实判断，一律给正的 xp。
 * $counterColumn 只能是 'labels_submitted' 或 'verifies_submitted'。
 * @return array<string,mixed> 更新后的 identity_stats 行
 */
function gg_award_identity(PDO $tagDb, array $identity, int $xpGain, string $counterColumn): array
{
    $counterColumn = in_array($counterColumn, ['labels_submitted', 'verifies_submitted'], true) ? $counterColumn : 'labels_submitted';
    $now = microtime(true);
    $stats = gg_identity_stats($tagDb, $identity);
    $xp = (int)$stats['xp'] + max(0, $xpGain);
    $streak = $xpGain > 0 ? (int)$stats['streak'] + 1 : 0;
    $bestStreak = max((int)$stats['best_streak'], $streak);
    $level = gg_xp_level($xp);

    $update = $tagDb->prepare("UPDATE identity_stats SET xp=?, level=?, streak=?, best_streak=?, {$counterColumn} = {$counterColumn} + 1, last_active_at=? WHERE identity_kind=? AND identity_id=?");
    $update->execute([$xp, $level, $streak, $bestStreak, $now, $identity['kind'], $identity['id']]);

    return gg_identity_stats($tagDb, $identity);
}

/**
 * Case 版的积分结算：一次提交可能同时回答多个字段（宝可梦/人物/地点），连击和
 * labels_submitted 都按"这次提交里有多少个字段给出了有效判断"（$validFieldCount，
 * present/none_of_above 都算，skip 不算）累加，而不是固定 +1——避免用户为了刷连击
 * 专挑只缺一个字段的截图。全部字段都是 skip 时 $validFieldCount=0，打断连击但
 * labels_submitted 仍然 +1（这一次提交本身发生过，和 gg_award_identity 的 skip 语义一致）。
 * @return array<string,mixed> 更新后的 identity_stats 行
 */
function gg_award_identity_fields(PDO $tagDb, array $identity, int $xpGain, int $validFieldCount, string $counterColumn): array
{
    $counterColumn = in_array($counterColumn, ['labels_submitted', 'verifies_submitted'], true) ? $counterColumn : 'labels_submitted';
    $now = microtime(true);
    $stats = gg_identity_stats($tagDb, $identity);
    $xp = (int)$stats['xp'] + max(0, $xpGain);
    $streak = $validFieldCount > 0 ? (int)$stats['streak'] + $validFieldCount : 0;
    $bestStreak = max((int)$stats['best_streak'], $streak);
    $level = gg_xp_level($xp);
    $increment = max(1, $validFieldCount);

    $update = $tagDb->prepare("UPDATE identity_stats SET xp=?, level=?, streak=?, best_streak=?, {$counterColumn} = {$counterColumn} + ?, last_active_at=? WHERE identity_kind=? AND identity_id=?");
    $update->execute([$xp, $level, $streak, $bestStreak, $increment, $now, $identity['kind'], $identity['id']]);

    return gg_identity_stats($tagDb, $identity);
}

/**
 * screenshots 表里精简的"答题卡片"字段，next_label.php/next_verify.php 共用，
 * 刻意不做 screenshot.php 那种全量 detail 拼装（那是给点开详情用的，这里是高频轮询）。
 */
function gg_screenshot_card_row(PDO $siteDb, int $screenshotId): ?array
{
    $stmt = $siteDb->prepare('SELECT id, game, filename, text, width, height, thumb_url, preview_url FROM screenshots WHERE id=?');
    $stmt->execute([$screenshotId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    api_decorate_game($row);
    return [
        'id' => (int)$row['id'],
        'game' => $row['game'],
        'source_game' => $row['source_game'],
        'archive_label' => $row['archive_label'],
        'filename' => $row['filename'],
        'text' => (string)$row['text'],
        'width' => $row['width'] !== null ? (int)$row['width'] : null,
        'height' => $row['height'] !== null ? (int)$row['height'] : null,
        'thumb_url' => (string)$row['thumb_url'],
        'preview_url' => (string)$row['preview_url'],
    ];
}

/**
 * 某身份在某分类下，已经回答过（含 none_of_above/skip）的截图 id 集合。
 * @return array<int,int>
 */
function gg_already_labeled_ids(PDO $tagDb, array $identity, string $category): array
{
    $stmt = $tagDb->prepare('SELECT DISTINCT screenshot_id FROM label_submissions WHERE category=? AND identity_kind=? AND identity_id=? ORDER BY id DESC LIMIT ' . GG_MAX_EXCLUDED_IDS);
    $stmt->execute([$category, $identity['kind'], $identity['id']]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 挑一张该身份还没在此分类标注过、且不是重复投稿组非主图的截图。优先（非强制）挑该分类
 * glossary_matches 零命中的截图——找不到时自动退回不带这个偏好的第二次查询。
 */
function gg_pick_label_candidate(PDO $siteDb, PDO $tagDb, array $identity, string $category): ?array
{
    $excluded = gg_already_labeled_ids($tagDb, $identity, $category);
    $seed = gg_random_seed();
    $orderExpr = gg_random_order_expr('s.id');

    $excludeClause = '';
    $excludeParams = [];
    if ($excluded !== []) {
        $excludeClause = ' AND s.id NOT IN (' . implode(',', array_fill(0, count($excluded), '?')) . ')';
        $excludeParams = $excluded;
    }

    $base = 'SELECT s.id FROM screenshots s
        LEFT JOIN screenshot_duplicate_groups dg ON dg.screenshot_id = s.id
        WHERE (dg.screenshot_id IS NULL OR dg.screenshot_id = dg.group_id)' . $excludeClause;

    $preferUnmatched = $base . ' AND NOT EXISTS (SELECT 1 FROM glossary_matches gm WHERE gm.screenshot_id = s.id AND gm.category = ?)'
        . " ORDER BY {$orderExpr} LIMIT 1";
    $stmt = $siteDb->prepare($preferUnmatched);
    $stmt->execute([...$excludeParams, $category, $seed]);
    $id = $stmt->fetchColumn();

    if ($id === false) {
        $fallback = $base . " ORDER BY {$orderExpr} LIMIT 1";
        $stmt = $siteDb->prepare($fallback);
        $stmt->execute([...$excludeParams, $seed]);
        $id = $stmt->fetchColumn();
    }

    return $id === false ? null : gg_screenshot_card_row($siteDb, (int)$id);
}

/**
 * 某身份在给定分类集合下，已经回答过的 (screenshot_id => [category => true]) 映射——
 * gg_pick_case_candidate() 用它一次判断一张截图在三个分类上分别是否已经问过这个人了。
 * @return array<int,array<string,true>>
 */
function gg_already_labeled_pairs(PDO $tagDb, array $identity, array $categories): array
{
    $placeholders = implode(',', array_fill(0, count($categories), '?'));
    $stmt = $tagDb->prepare(
        "SELECT DISTINCT screenshot_id, category FROM label_submissions
         WHERE category IN ({$placeholders}) AND identity_kind=? AND identity_id=?
         ORDER BY id DESC LIMIT " . GG_MAX_EXCLUDED_IDS
    );
    $stmt->execute([...$categories, $identity['kind'], $identity['id']]);
    $pairs = [];
    foreach ($stmt->fetchAll() as $row) {
        $pairs[(int)$row['screenshot_id']][(string)$row['category']] = true;
    }
    return $pairs;
}

/**
 * 同屏多字段 Case 候选挑选：不再按单一分类过滤，而是在（可选按作品筛选的）截图里找一张
 * 宝可梦/人物/地点三个分类中至少有一个"该身份还没答过，且官方术语库也没有证据"的，把
 * 缺的那几个分类一起返回，交给前端在同一张卡片上问。$gameVariants 为 null 表示不限作品
 * （复用 api_game_variants() 的输出，同一个canonical game 在 site.sqlite3 里可能对应多个
 * 原始 game 字符串，比如中文/日文存档）。
 *
 * @return array{card:array, missing_fields:array<int,string>}|null
 */
function gg_pick_case_candidate(PDO $siteDb, PDO $tagDb, array $identity, ?array $gameVariants = null): ?array
{
    $answered = gg_already_labeled_pairs($tagDb, $identity, GG_LABEL_CATEGORIES);
    $seed = gg_random_seed();
    $orderExpr = gg_random_order_expr('s.id');

    $gameClause = '';
    $gameParams = [];
    if ($gameVariants !== null && $gameVariants !== []) {
        $gameClause = ' AND s.game IN (' . implode(',', array_fill(0, count($gameVariants), '?')) . ')';
        $gameParams = $gameVariants;
    }

    $sql = "SELECT s.id,
                   MAX(CASE WHEN gm.category = '宝可梦' THEN 1 ELSE 0 END) AS has_pokemon,
                   MAX(CASE WHEN gm.category = '人物' THEN 1 ELSE 0 END) AS has_character,
                   MAX(CASE WHEN gm.category = '地点' THEN 1 ELSE 0 END) AS has_location
            FROM screenshots s
            LEFT JOIN screenshot_duplicate_groups dg ON dg.screenshot_id = s.id
            LEFT JOIN glossary_matches gm ON gm.screenshot_id = s.id AND gm.category IN ('宝可梦','人物','地点')
            WHERE (dg.screenshot_id IS NULL OR dg.screenshot_id = dg.group_id){$gameClause}
            GROUP BY s.id
            ORDER BY {$orderExpr}
            LIMIT 60";
    $stmt = $siteDb->prepare($sql);
    $stmt->execute([...$gameParams, $seed]);

    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['id'];
        $coverage = [
            '宝可梦' => (bool)$row['has_pokemon'],
            '人物' => (bool)$row['has_character'],
            '地点' => (bool)$row['has_location'],
        ];
        $missing = [];
        foreach (GG_LABEL_CATEGORIES as $category) {
            if (!$coverage[$category] && !isset($answered[$id][$category])) $missing[] = $category;
        }
        if ($missing === []) continue;
        $card = gg_screenshot_card_row($siteDb, $id);
        if ($card === null) continue;
        return ['card' => $card, 'missing_fields' => $missing];
    }
    return null;
}

/**
 * 某身份对某分类+target_type 已经投过票的 (screenshot_id, target_key) 组合，编码成
 * "id|target_key" 字符串集合，供候选池在 PHP 侧过滤（组合键，不适合单列 NOT IN）。
 * @return array<string,true>
 */
function gg_already_voted_pairs(PDO $tagDb, array $identity, string $category, string $targetType): array
{
    $stmt = $tagDb->prepare('SELECT screenshot_id, target_key FROM verify_submissions WHERE category=? AND target_type=? AND identity_kind=? AND identity_id=? ORDER BY id DESC LIMIT ' . GG_MAX_EXCLUDED_IDS);
    $stmt->execute([$category, $targetType, $identity['kind'], $identity['id']]);
    $pairs = [];
    foreach ($stmt->fetchAll() as $row) {
        $pairs[$row['screenshot_id'] . '|' . $row['target_key']] = true;
    }
    return $pairs;
}

/**
 * 候选池：OCR 术语库（glossary_matches）里该分类下已有的 (screenshot_id, canonical)。
 * target_key = canonical 本身。
 */
function gg_verify_pool_glossary(PDO $siteDb, string $category, int $seed): array
{
    $orderExpr = gg_random_order_expr('s.id');
    $sql = "SELECT s.id AS screenshot_id, gm.canonical AS claim, gm.canonical AS target_key
        FROM screenshots s
        JOIN (SELECT DISTINCT screenshot_id, canonical FROM glossary_matches WHERE category = ?) gm ON gm.screenshot_id = s.id
        LEFT JOIN screenshot_duplicate_groups dg ON dg.screenshot_id = s.id
        WHERE (dg.screenshot_id IS NULL OR dg.screenshot_id = dg.group_id)
        ORDER BY {$orderExpr} LIMIT 50";
    $stmt = $siteDb->prepare($sql);
    $stmt->execute([$category, $seed]);
    return $stmt->fetchAll();
}

/**
 * 候选池：`screenshot_corpus_matches` 里标记 needs_review 的剧情匹配（仅剧情分类使用）。
 * target_key = text_id。对应 DEPLOY.md 里点名过、但还没做的"用 剧情匹配错误 分类收集反馈"工作流。
 */
function gg_verify_pool_corpus_needs_review(PDO $siteDb, int $seed): array
{
    $orderExpr = gg_random_order_expr('s.id');
    $sql = "SELECT s.id AS screenshot_id, scm.text AS claim, scm.text_id AS target_key
        FROM screenshots s
        JOIN screenshot_corpus_matches scm ON scm.screenshot_id = s.id
        LEFT JOIN screenshot_duplicate_groups dg ON dg.screenshot_id = s.id
        WHERE (dg.screenshot_id IS NULL OR dg.screenshot_id = dg.group_id)
          AND COALESCE(json_extract(scm.evidence_json, '\$.best_match.status'), 'legacy') = 'needs_review'
        ORDER BY {$orderExpr} LIMIT 50";
    $stmt = $siteDb->prepare($sql);
    $stmt->execute([$seed]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) $row['claim'] = api_clean_corpus_text((string)$row['claim']);
    unset($row);
    return array_values(array_filter($rows, static fn(array $r): bool => $r['claim'] !== ''));
}

/**
 * 候选池：众包标注（label_submissions）里还待复核的 present 类答案，让校验模式也能
 * 反过来复核标注模式自己产出的数据，形成闭环。target_key = label_submissions.id。
 */
function gg_verify_pool_label_submissions(PDO $tagDb, string $category): array
{
    $stmt = $tagDb->prepare("SELECT screenshot_id, canonical AS claim, CAST(id AS TEXT) AS target_key
        FROM label_submissions
        WHERE category = ? AND answer_kind = 'present' AND status = 'pending' AND canonical <> ''
        ORDER BY RANDOM() LIMIT 50");
    $stmt->execute([$category]);
    return $stmt->fetchAll();
}

/**
 * 从候选行里挑第一条该身份还没投过票的，返回 null 表示这一轮候选池都被过滤光了
 * （调用方可以换一个池子类型重试，或者直接告诉前端"这类暂时没有可核验的了"）。
 */
function gg_first_unvoted(array $rows, array $votedPairs): ?array
{
    foreach ($rows as $row) {
        $key = $row['screenshot_id'] . '|' . $row['target_key'];
        if (!isset($votedPairs[$key])) return $row;
    }
    return null;
}

/**
 * 校验模式候选挑选：剧情分类只用 needs_review 语料池；其余分类在 glossary/众包标注两个
 * 池子间随机挑一个尝试，对方池子空了就 fallback 到另一个。
 *
 * @return array{screenshot_id:int, target_type:string, target_key:string, claim_text:string, card:array}|null
 */
function gg_pick_verify_candidate(PDO $siteDb, PDO $tagDb, array $identity, string $category): ?array
{
    $seed = gg_random_seed();
    $pools = $category === '剧情'
        ? [['corpus_match', fn(): array => gg_verify_pool_corpus_needs_review($siteDb, $seed)]]
        : (mt_rand(0, 1) === 0
            ? [
                ['glossary_match', fn(): array => gg_verify_pool_glossary($siteDb, $category, $seed)],
                ['label_submission', fn(): array => gg_verify_pool_label_submissions($tagDb, $category)],
            ]
            : [
                ['label_submission', fn(): array => gg_verify_pool_label_submissions($tagDb, $category)],
                ['glossary_match', fn(): array => gg_verify_pool_glossary($siteDb, $category, $seed)],
            ]
        );

    foreach ($pools as [$targetType, $fetch]) {
        $votedPairs = gg_already_voted_pairs($tagDb, $identity, $category, $targetType);
        $picked = gg_first_unvoted($fetch(), $votedPairs);
        if ($picked === null) continue;
        $card = gg_screenshot_card_row($siteDb, (int)$picked['screenshot_id']);
        if ($card === null) continue;
        return [
            'screenshot_id' => (int)$picked['screenshot_id'],
            'target_type' => $targetType,
            'target_key' => (string)$picked['target_key'],
            'claim_text' => (string)$picked['claim'],
            'card' => $card,
        ];
    }
    return null;
}

const GG_CONSENSUS_WRONG_THRESHOLD = 3;

/**
 * 校验投票从不直接改 glossary_matches/screenshot_corpus_matches——它们和 site.sqlite3
 * 的其它内容一样是离线流水线的只读产物。达到共识阈值的"错误"投票改为向现有的
 * correction_reports 表（corrections.sqlite3，勘误功能已经在用的那张表）追加一条自动生成的
 * 反馈，复用既有的人工审阅入口，而不是新建第二套审核界面。
 *
 * 用一个嵌在 description 里的 [auto:target_type:target_key] 标记做去重，避免同一个
 * target 每多一票"错误"就重复写入——没有另外改 correction_reports 的表结构。
 */
function gg_maybe_file_auto_correction(PDO $tagDb, int $screenshotId, string $category, string $targetType, string $targetKey, string $claimText): void
{
    $stmt = $tagDb->prepare('SELECT COUNT(*) FROM verify_submissions WHERE screenshot_id=? AND category=? AND target_type=? AND target_key=? AND vote=?');
    $stmt->execute([$screenshotId, $category, $targetType, $targetKey, '错误']);
    $wrongCount = (int)$stmt->fetchColumn();
    if ($wrongCount < GG_CONSENSUS_WRONG_THRESHOLD) return;

    $marker = "[auto:{$targetType}:{$targetKey}]";
    $correctionCategory = match ($targetType) {
        'corpus_match' => '剧情匹配错误',
        'glossary_match' => 'OCR文字错误',
        default => '其他问题',
    };

    $writeDb = api_write_db();
    $existing = $writeDb->prepare("SELECT id FROM correction_reports WHERE screenshot_id=? AND description LIKE ? ESCAPE '\\' LIMIT 1");
    $existing->execute([$screenshotId, '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $marker)]);
    if ($existing->fetchColumn() !== false) return;

    $description = "众包核验：{$wrongCount} 人认为「{$claimText}」标注有误 {$marker}";
    $now = microtime(true);
    $insert = $writeDb->prepare('INSERT INTO correction_reports (screenshot_id,category,original_text,suggested_text,description,contact,status,created_at,updated_at) VALUES (?,?,?,\'\',?,\'\',\'pending\',?,?)');
    $insert->execute([$screenshotId, $correctionCategory, $claimText, $description, $now, $now]);
}
