<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");

$database = $argv[1] ?? (__DIR__ . '/data/site.sqlite3');
$pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout=10000');
$rows = $pdo->query("SELECT id, game, contributor, rel_path, filename, width, height, sha1, text FROM screenshots ORDER BY game, contributor, rel_path, filename, id")->fetchAll(PDO::FETCH_ASSOC);
$parent = [];
foreach ($rows as $row) $parent[(int)$row['id']] = (int)$row['id'];
$find = function (int $id) use (&$parent, &$find): int { return $parent[$id] === $id ? $id : ($parent[$id] = $find($parent[$id])); };
$union = function (int $a, int $b) use (&$parent, $find): void { $a = $find($a); $b = $find($b); if ($a !== $b) $parent[max($a, $b)] = min($a, $b); };

$hashes = [];
$previous = [];
foreach ($rows as $row) {
    $id = (int)$row['id'];
    $sha1 = (string)$row['sha1'];
    if ($sha1 !== '') {
        $hashKey = $row['game'] . "\0" . $sha1;
        isset($hashes[$hashKey]) ? $union($id, $hashes[$hashKey]) : $hashes[$hashKey] = $id;
    }
    $folder = substr((string)$row['rel_path'], 0, max(0, strlen((string)$row['rel_path']) - strlen((string)$row['filename'])));
    $partition = $row['game'] . "\0" . $row['contributor'] . "\0" . $folder;
    $normalized = preg_replace('/\s+/u', '', trim((string)$row['text'])) ?? '';
    if (isset($previous[$partition])) {
        $prev = $previous[$partition];
        if ($normalized !== '' && $normalized === $prev['text'] && (int)$row['width'] === $prev['width'] && (int)$row['height'] === $prev['height'] && adjacentFilenames($prev['filename'], (string)$row['filename'])) {
            $union($id, $prev['id']);
        }
    }
    $previous[$partition] = ['id' => $id, 'filename' => (string)$row['filename'], 'text' => $normalized, 'width' => (int)$row['width'], 'height' => (int)$row['height']];
}

$groups = [];
foreach (array_keys($parent) as $id) $groups[$find($id)][] = $id;
$pdo->beginTransaction();
$pdo->exec('DROP TABLE IF EXISTS screenshot_duplicate_groups');
$pdo->exec('CREATE TABLE screenshot_duplicate_groups (screenshot_id INTEGER PRIMARY KEY, group_id INTEGER NOT NULL, group_size INTEGER NOT NULL)');
$insert = $pdo->prepare('INSERT INTO screenshot_duplicate_groups VALUES(?,?,?)');
$members = 0; $groupCount = 0;
foreach ($groups as $group) {
    if (count($group) < 2) continue;
    $groupId = min($group); $size = count($group); $groupCount++;
    foreach ($group as $id) { $insert->execute([$id, $groupId, $size]); $members++; }
}
$pdo->exec('CREATE INDEX idx_duplicate_groups_group ON screenshot_duplicate_groups(group_id, screenshot_id)');
$pdo->commit();
echo "Built {$groupCount} duplicate groups with {$members} screenshots.\n";

function adjacentFilenames(string $left, string $right): bool
{
    if (preg_match('/^(\d{14})/', $left, $a) && preg_match('/^(\d{14})/', $right, $b)) {
        $ta = DateTimeImmutable::createFromFormat('!YmdHis', $a[1]);
        $tb = DateTimeImmutable::createFromFormat('!YmdHis', $b[1]);
        return $ta && $tb && ($tb->getTimestamp() - $ta->getTimestamp()) >= 0 && ($tb->getTimestamp() - $ta->getTimestamp()) <= 10;
    }
    return preg_match('/(\d+)(?!.*\d)/', pathinfo($left, PATHINFO_FILENAME), $a)
        && preg_match('/(\d+)(?!.*\d)/', pathinfo($right, PATHINFO_FILENAME), $b)
        && (int)$b[1] === (int)$a[1] + 1;
}
