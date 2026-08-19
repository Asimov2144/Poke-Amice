<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");

$database = $argv[1] ?? (__DIR__ . '/data/site.sqlite3');
$corpusRoot = __DIR__ . '/data/corpus';
$pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout=10000; DROP TABLE IF EXISTS corpus_translation_fts;');
$pdo->exec("CREATE VIRTUAL TABLE corpus_translation_fts USING fts5(collection UNINDEXED, language UNINDEXED, file_key UNINDEXED, text_file UNINDEXED, line_no UNINDEXED, text, tokenize='trigram')");

$needed = [];
$rows = $pdo->query('SELECT DISTINCT collection, file_key, text_file, line_no FROM screenshot_corpus_matches WHERE score >= 0.72');
foreach ($rows as $row) {
    $needed[$row['collection']][$row['file_key']][$row['text_file']][(int)$row['line_no']] = true;
}

$insert = $pdo->prepare('INSERT INTO corpus_translation_fts(collection, language, file_key, text_file, line_no, text) VALUES(?,?,?,?,?,?)');
$pdo->beginTransaction();
$count = 0;
foreach ($needed as $collection => $fileKeys) {
    foreach ($fileKeys as $fileKey => $textFiles) {
        foreach (glob($corpusRoot . '/' . $collection . '/*_' . $fileKey . '.txt') ?: [] as $path) {
            $base = basename($path);
            $language = substr($base, 0, -strlen('_' . $fileKey . '.txt'));
            if ($language === 'qid') continue;
            $handle = fopen($path, 'rb');
            if (!$handle) continue;
            $activeFile = null;
            $afterDivider = false;
            $lineNo = 0;
            while (($raw = fgets($handle)) !== false) {
                $line = rtrim($raw, "\r\n");
                if (str_starts_with($line, 'Text File : ')) {
                    $activeFile = substr($line, 12);
                    $afterDivider = false;
                    $lineNo = 0;
                    continue;
                }
                if ($activeFile === null || !isset($textFiles[$activeFile])) continue;
                if (!$afterDivider) {
                    if (preg_match('/^~+$/', $line)) $afterDivider = true;
                    continue;
                }
                if (preg_match('/^~+$/', $line)) { $activeFile = null; $afterDivider = false; continue; }
                if (isset($textFiles[$activeFile][$lineNo])) {
                    $text = cleanIndexText($line);
                    if ($text !== '') { $insert->execute([$collection, $language, $fileKey, $activeFile, $lineNo, $text]); $count++; }
                }
                $lineNo++;
            }
            fclose($handle);
        }
    }
}
$pdo->commit();
$pdo->exec("INSERT INTO corpus_translation_fts(corpus_translation_fts) VALUES('optimize')");
echo "Indexed {$count} aligned translations.\n";

function cleanIndexText(string $text): string
{
    if (preg_match('/^\[~.*\]$/u', trim($text))) return '';
    $text = preg_replace('/\[VAR [^\]]+\]/u', '', $text) ?? $text;
    $text = preg_replace('/\\\\(?:r|n|c)/u', ' ', $text) ?? $text;
    return trim($text);
}
