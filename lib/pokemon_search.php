<?php
/**
 * 宝可梦名称检索层：别名归一 + 拼音/错别字容错 + 进化家族展开。
 *
 * 数据来源：
 * - data/pokemon_search_index.json（本仓库内，由 bin/build-pokemon-search-index.php 预生成）
 * - ../today/lib/aliases.json（今日模块维护的别名补丁，只读复用，同仓库同级目录）
 * - ../today/lib/evolutions.json（PokeAPI evolution-chain 快照，只读复用）
 *
 * 解析失败一律返回 null，调用方降级为原始文本检索，不抛错、不阻断请求。
 */

declare(strict_types=1);

require_once __DIR__ . '/text_match.php';

function gg_pokemon_index(): array
{
    static $index;
    if (is_array($index)) return $index;

    $indexPath = __DIR__ . '/../data/pokemon_search_index.json';
    $entries = [];
    if (is_file($indexPath)) {
        $decoded = json_decode((string)@file_get_contents($indexPath), true);
        if (is_array($decoded)) $entries = $decoded;
    }

    $byId = [];
    $byNameKey = [];
    $byPinyin = [];
    foreach ($entries as $entry) {
        $id = (string)($entry['id'] ?? '');
        if ($id === '') continue;
        $byId[$id] = $entry;
        foreach (['zh', 'en', 'jp'] as $lang) {
            $name = (string)($entry['name'][$lang] ?? '');
            if ($name === '') continue;
            $byNameKey[$lang . ':' . gg_pokemon_name_key($name, $lang)] = $entry;
        }
        $pinyin = (string)($entry['pinyin'] ?? '');
        if ($pinyin !== '') $byPinyin[$pinyin] = $entry;
    }

    $aliasesPath = __DIR__ . '/../../today/lib/aliases.json';
    $aliases = [];
    if (is_file($aliasesPath)) {
        $decoded = json_decode((string)@file_get_contents($aliasesPath), true);
        foreach (is_array($decoded) ? $decoded : [] as $alias => $target) {
            if (str_starts_with((string)$alias, '_')) continue; // _comment 等元字段
            $aliases[(string)$alias] = (string)$target;
        }
    }

    $evolutionsPath = __DIR__ . '/../../today/lib/evolutions.json';
    $evolutions = [];
    if (is_file($evolutionsPath)) {
        $decoded = json_decode((string)@file_get_contents($evolutionsPath), true);
        if (is_array($decoded)) $evolutions = $decoded;
    }

    $index = [
        'entries' => array_values($byId),
        'by_id' => $byId,
        'by_name_key' => $byNameKey,
        'by_pinyin' => $byPinyin,
        'aliases' => $aliases,
        'evolutions' => $evolutions,
    ];
    return $index;
}

/**
 * 名称归一：NFKC + 去空白；英文/拼音统一小写。
 */
function gg_pokemon_name_key(string $name, string $lang = 'zh'): string
{
    $name = (string)preg_replace('/\s+/u', '', trim($name));
    if (function_exists('normalizer_normalize')) {
        $normalized = normalizer_normalize($name, Normalizer::FORM_KC);
        if (is_string($normalized)) $name = $normalized;
    }
    return $lang === 'en' ? mb_strtolower($name) : $name;
}

function gg_pinyin_key(string $text): string
{
    return (string)preg_replace('/[^a-z]/', '', strtolower($text));
}

// gg_mb_levenshtein()/gg_fuzzy_distance_threshold() 现在共享自 lib/text_match.php
// （人物/地点术语库的模糊匹配复用同一套算法）。

/**
 * 名称/拼音 → 宝可梦条目。依次尝试：别名表 → zh/en/jp 精确 → 拼音精确 → 编辑距离容错。
 * 编辑距离命中要求唯一最优解，避免有歧义的模糊匹配。
 *
 * @return array{entry: array<string,mixed>, matched_via: string}|null
 */
function gg_pokemon_resolve(string $query): ?array
{
    $query = trim($query);
    if ($query === '' || mb_strlen($query) > 40) return null;
    $index = gg_pokemon_index();

    // 别名补丁（旧译名/错别字 → 官方名），最多追一层防环。
    if (isset($index['aliases'][$query])) {
        $target = $index['aliases'][$query];
        if ($target !== $query) {
            $resolved = gg_pokemon_resolve($target);
            if ($resolved !== null) return ['entry' => $resolved['entry'], 'matched_via' => 'alias'];
        }
    }

    foreach (['zh', 'en', 'jp'] as $lang) {
        $key = $lang . ':' . gg_pokemon_name_key($query, $lang);
        if (isset($index['by_name_key'][$key])) {
            return ['entry' => $index['by_name_key'][$key], 'matched_via' => 'exact'];
        }
    }

    $pinyinQuery = gg_pinyin_key($query);
    if ($pinyinQuery !== '' && isset($index['by_pinyin'][$pinyinQuery])) {
        return ['entry' => $index['by_pinyin'][$pinyinQuery], 'matched_via' => 'pinyin'];
    }

    // 拼音/错别字容错：拼音串够长才允许模糊（避免短串误命中）。
    $zhKey = gg_pokemon_name_key($query, 'zh');
    $best = null;
    $bestDistance = PHP_INT_MAX;
    $ambiguous = false;
    foreach ($index['entries'] as $entry) {
        $candidates = [];
        $zhName = (string)($entry['name']['zh'] ?? '');
        if ($zhName !== '') $candidates[] = [gg_pokemon_name_key($zhName, 'zh'), $zhKey, mb_strlen($zhKey)];
        $pinyin = (string)($entry['pinyin'] ?? '');
        if ($pinyin !== '' && $pinyinQuery !== '') $candidates[] = [$pinyin, $pinyinQuery, strlen($pinyinQuery)];

        foreach ($candidates as [$candidateKey, $queryKey, $queryLen]) {
            if ($candidateKey === '' || $queryLen < 2) continue;
            $threshold = gg_fuzzy_distance_threshold($queryLen);
            if ($threshold <= 0) continue;
            $distance = gg_mb_levenshtein($queryKey, $candidateKey);
            if ($distance > $threshold) continue;
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $entry;
                $ambiguous = false;
            } elseif ($distance === $bestDistance && $best !== null && ($best['id'] ?? null) !== $entry['id']) {
                $ambiguous = true;
            }
        }
    }
    if ($best !== null && !$ambiguous) {
        return ['entry' => $best, 'matched_via' => 'fuzzy'];
    }

    return null;
}

/**
 * 按前缀/拼音/编辑距离给出候选列表，供输入时的自动补全使用（区别于 gg_pokemon_resolve()
 * 只返回单个最佳匹配——补全需要展示若干候选，不能只给一个"最终答案"）。
 *
 * @return array<int,array{id:string,name:array<string,string>,matched_via:string}>
 */
function gg_pokemon_suggest(string $prefix, int $limit = 8): array
{
    $prefix = trim($prefix);
    if ($prefix === '') return [];
    $limit = max(1, min(20, $limit));
    $index = gg_pokemon_index();

    $zhKey = gg_pokemon_name_key($prefix, 'zh');
    $pinyinPrefix = gg_pinyin_key($prefix);

    $scored = [];
    foreach ($index['entries'] as $entry) {
        $zhName = (string)($entry['name']['zh'] ?? '');
        $zhNameKey = $zhName !== '' ? gg_pokemon_name_key($zhName, 'zh') : '';
        $pinyin = (string)($entry['pinyin'] ?? '');

        $rank = null;
        $via = '';
        if ($zhNameKey !== '' && $zhNameKey === $zhKey) {
            $rank = 0;
            $via = 'exact';
        } elseif ($zhNameKey !== '' && $zhKey !== '' && str_starts_with($zhNameKey, $zhKey)) {
            $rank = 1;
            $via = 'prefix';
        } elseif ($pinyinPrefix !== '' && $pinyin !== '' && str_starts_with($pinyin, $pinyinPrefix)) {
            $rank = 2;
            $via = 'pinyin';
        } elseif ($zhNameKey !== '' && mb_strlen($zhKey) >= 2) {
            $threshold = gg_fuzzy_distance_threshold(mb_strlen($zhKey));
            if ($threshold > 0) {
                $distance = gg_mb_levenshtein($zhKey, $zhNameKey);
                if ($distance <= $threshold) {
                    $rank = 3 + $distance;
                    $via = 'fuzzy';
                }
            }
        }
        if ($rank === null) continue;
        $scored[] = [$rank, $entry, $via];
    }

    usort($scored, fn(array $a, array $b): int => $a[0] <=> $b[0]);

    $out = [];
    foreach (array_slice($scored, 0, $limit) as [, $entry, $via]) {
        $out[] = ['id' => (string)$entry['id'], 'name' => $entry['name'], 'matched_via' => $via];
    }
    return $out;
}

function gg_pokemon_by_id(string $id): ?array
{
    return gg_pokemon_index()['by_id'][$id] ?? null;
}

/**
 * 某图鉴号的进化家族成员 id 列表（不含自己）。
 * @return array<int,string>
 */
function gg_pokemon_evolution_family(string $dexId): array
{
    $family = gg_pokemon_index()['evolutions'][$dexId] ?? [];
    return is_array($family) ? array_values(array_map('strval', $family)) : [];
}

/**
 * 条目 → 去重后的名称变体（zh/en/jp），用于展开检索关键词。
 * @param array<string,mixed> $entry
 * @return array<int,string>
 */
function gg_pokemon_name_variants(array $entry): array
{
    $names = [];
    foreach (['zh', 'en', 'jp'] as $lang) {
        $name = (string)($entry['name'][$lang] ?? '');
        if ($name !== '') $names[] = $name;
    }
    return array_values(array_unique($names));
}
