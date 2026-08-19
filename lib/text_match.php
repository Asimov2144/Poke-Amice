<?php
/**
 * 共享的模糊文本匹配算法 + 术语库（glossary_matches）人物/地点类的输入纠正与自动补全。
 *
 * gg_mb_levenshtein()/gg_fuzzy_distance_threshold() 原先只存在于 lib/pokemon_search.php，
 * 现抽到这里供宝可梦名和人物/地点术语共用同一套编辑距离容错逻辑，避免两处维护同一算法。
 */

declare(strict_types=1);

/**
 * UTF-8 安全的多字节 Levenshtein 编辑距离（预期输入很短，逐字符数组比较即可）。
 */
function gg_mb_levenshtein(string $a, string $b): int
{
    if ($a === $b) return 0;
    $a = mb_str_split($a);
    $b = mb_str_split($b);
    $la = count($a);
    $lb = count($b);
    if ($la === 0) return $lb;
    if ($lb === 0) return $la;
    $prev = range(0, $lb);
    for ($i = 1; $i <= $la; $i++) {
        $curr = [$i];
        for ($j = 1; $j <= $lb; $j++) {
            $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
            $curr[$j] = min($prev[$j] + 1, $curr[$j - 1] + 1, $prev[$j - 1] + $cost);
        }
        $prev = $curr;
    }
    return $prev[$lb];
}

function gg_fuzzy_distance_threshold(int $length): int
{
    if ($length <= 2) return 0;
    if ($length <= 5) return 1;
    return 2;
}

/**
 * 归一化：NFKC + 去空白，用于比较前的 key 化（不做语言相关的大小写处理，
 * 人物/地点类 canonical 目前都是中文，不需要 gg_pokemon_name_key() 的 lang 分支）。
 */
function gg_text_normalize_key(string $text): string
{
    $text = (string)preg_replace('/\s+/u', '', trim($text));
    if (function_exists('normalizer_normalize')) {
        $normalized = normalizer_normalize($text, Normalizer::FORM_KC);
        if (is_string($normalized)) $text = $normalized;
    }
    return $text;
}

/**
 * 术语库（glossary_matches）某分类下的候选 canonical 列表，进程内缓存一次请求内复用。
 * @return array<int,string>
 */
function gg_glossary_canonicals(PDO $pdo, string $category): array
{
    static $cache = [];
    if (isset($cache[$category])) return $cache[$category];
    $stmt = $pdo->prepare('SELECT DISTINCT canonical FROM glossary_matches WHERE category = ? ORDER BY canonical');
    $stmt->execute([$category]);
    $list = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $cache[$category] = $list;
    return $list;
}

/**
 * 人物/地点类输入纠正：精确匹配优先，否则在术语库已有 canonical 中找唯一最优的模糊匹配。
 * 匹配不到时返回 null，调用方应保留原始输入而不是编造 canonical。
 *
 * @return array{canonical:string, matched_via:string}|null
 */
function gg_glossary_resolve(PDO $pdo, string $category, string $query): ?array
{
    $query = trim($query);
    if ($query === '' || mb_strlen($query) > 40) return null;
    $canonicals = gg_glossary_canonicals($pdo, $category);
    $queryKey = gg_text_normalize_key($query);
    if ($queryKey === '') return null;

    foreach ($canonicals as $canonical) {
        if (gg_text_normalize_key($canonical) === $queryKey) {
            return ['canonical' => $canonical, 'matched_via' => 'exact'];
        }
    }

    $threshold = gg_fuzzy_distance_threshold(mb_strlen($queryKey));
    if ($threshold <= 0) return null;

    $best = null;
    $bestDistance = PHP_INT_MAX;
    $ambiguous = false;
    foreach ($canonicals as $canonical) {
        $candidateKey = gg_text_normalize_key($canonical);
        if ($candidateKey === '') continue;
        $distance = gg_mb_levenshtein($queryKey, $candidateKey);
        if ($distance > $threshold) continue;
        if ($distance < $bestDistance) {
            $bestDistance = $distance;
            $best = $canonical;
            $ambiguous = false;
        } elseif ($distance === $bestDistance && $best !== null && $best !== $canonical) {
            $ambiguous = true;
        }
    }
    if ($best !== null && !$ambiguous) {
        return ['canonical' => $best, 'matched_via' => 'glossary_distinct'];
    }
    return null;
}

/**
 * 人物/地点类自动补全候选（前缀优先，其次模糊容错），供 suggest.php 按键联想使用。
 * @return array<int,array{canonical:string, matched_via:string}>
 */
function gg_glossary_suggest(PDO $pdo, string $category, string $prefix, int $limit = 8): array
{
    $prefix = trim($prefix);
    if ($prefix === '') return [];
    $limit = max(1, min(20, $limit));
    $canonicals = gg_glossary_canonicals($pdo, $category);
    $prefixKey = gg_text_normalize_key($prefix);
    if ($prefixKey === '') return [];

    $scored = [];
    foreach ($canonicals as $canonical) {
        $candidateKey = gg_text_normalize_key($canonical);
        if ($candidateKey === '') continue;
        if ($candidateKey === $prefixKey) {
            $scored[] = [0, $canonical, 'exact'];
            continue;
        }
        if (str_starts_with($candidateKey, $prefixKey)) {
            $scored[] = [1, $canonical, 'prefix'];
            continue;
        }
        $threshold = gg_fuzzy_distance_threshold(mb_strlen($prefixKey));
        if ($threshold <= 0) continue;
        $distance = gg_mb_levenshtein($prefixKey, $candidateKey);
        if ($distance <= $threshold) $scored[] = [3 + $distance, $canonical, 'fuzzy'];
    }
    usort($scored, fn(array $a, array $b): int => $a[0] <=> $b[0]);

    $out = [];
    foreach (array_slice($scored, 0, $limit) as [, $canonical, $via]) {
        $out[] = ['canonical' => $canonical, 'matched_via' => $via];
    }
    return $out;
}
