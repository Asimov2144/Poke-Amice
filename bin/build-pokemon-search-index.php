<?php
/**
 * 预生成宝可梦名称检索索引：pokemon_db.json → data/pokemon_search_index.json。
 *
 * 只在此处调用 ICU Transliterator（Han-Latin，逐字转拼音）：1,025 条约 0.3s，
 * 不适合放进请求路径，因此离线生成一次，运行时只读 JSON。
 * pokemon_db.json 更新（新增/改名宝可梦）后需要重新运行本脚本。
 *
 * 用法：php bin/build-pokemon-search-index.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sourcePath = $root . '/pokemon_db.json';
$outPath = __DIR__ . '/../data/pokemon_search_index.json';

if (!is_file($sourcePath)) {
    fwrite(STDERR, "pokemon_db.json not found at {$sourcePath}\n");
    exit(1);
}

$entries = json_decode((string)file_get_contents($sourcePath), true);
if (!is_array($entries)) {
    fwrite(STDERR, "pokemon_db.json did not decode to an array\n");
    exit(1);
}

$transliterator = class_exists('Transliterator')
    ? Transliterator::create('Han-Latin; Latin-ASCII; Lower(); [:Nonspacing Mark:] Remove; NFC')
    : null;
if ($transliterator === null) {
    fwrite(STDERR, "intl/Transliterator unavailable; pinyin field will be empty\n");
}

function pinyin_key(string $text): string
{
    // 转写结果形如 "pi ka qiu"：去空格/非字母字符，得到紧凑可比对的拼音串。
    return (string)preg_replace('/[^a-z]/', '', strtolower($text));
}

$out = [];
foreach ($entries as $entry) {
    $id = (string)($entry['id'] ?? '');
    if ($id === '') continue;
    $zh = (string)($entry['name']['zh'] ?? '');
    $en = (string)($entry['name']['en'] ?? '');
    $jp = (string)($entry['name']['jp'] ?? '');
    $pinyin = '';
    if ($transliterator !== null && $zh !== '') {
        $transliterated = $transliterator->transliterate($zh);
        if (is_string($transliterated)) $pinyin = pinyin_key($transliterated);
    }
    $out[] = [
        'id' => $id,
        'name' => ['zh' => $zh, 'en' => $en, 'jp' => $jp],
        'pinyin' => $pinyin,
        'type' => is_array($entry['type'] ?? null) ? array_values($entry['type']) : [],
    ];
}

$json = json_encode(
    $out,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
);
file_put_contents($outPath, $json . "\n");
fwrite(STDOUT, sprintf("wrote %d entries to %s\n", count($out), $outPath));
