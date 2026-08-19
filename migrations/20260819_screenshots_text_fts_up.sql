-- 修复网页/API 检索冷启动慢：screenshots.text 的 LIKE '%term%' 查询无法用任何
-- B-tree 索引，只能全表扫描（65k 行），页缓存被挤掉后单次查询实测 ~0.7s，命中
-- 缓存后 ~0.037s。做法与 corpus_translation_fts（47万行）一致：trigram FTS5
-- 外部内容表，仅索引 text 列，用触发器和 screenshots 表保持同步。
-- game/filename/tags_json 不在此次范围内（数据量小，不是冷启动慢的主因）。

CREATE VIRTUAL TABLE IF NOT EXISTS screenshots_text_fts USING fts5(
    text,
    content='screenshots',
    content_rowid='id',
    tokenize='trigram'
);

INSERT INTO screenshots_text_fts(rowid, text)
SELECT id, text FROM screenshots;

CREATE TRIGGER IF NOT EXISTS trg_screenshots_text_fts_ai AFTER INSERT ON screenshots BEGIN
    INSERT INTO screenshots_text_fts(rowid, text) VALUES (new.id, new.text);
END;

CREATE TRIGGER IF NOT EXISTS trg_screenshots_text_fts_ad AFTER DELETE ON screenshots BEGIN
    INSERT INTO screenshots_text_fts(screenshots_text_fts, rowid, text) VALUES('delete', old.id, old.text);
END;

CREATE TRIGGER IF NOT EXISTS trg_screenshots_text_fts_au AFTER UPDATE ON screenshots BEGIN
    INSERT INTO screenshots_text_fts(screenshots_text_fts, rowid, text) VALUES('delete', old.id, old.text);
    INSERT INTO screenshots_text_fts(rowid, text) VALUES (new.id, new.text);
END;

PRAGMA optimize;
