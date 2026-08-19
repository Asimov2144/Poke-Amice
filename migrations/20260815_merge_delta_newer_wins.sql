-- Incremental merge for server_db_delta_since_20260808.zip.
--
-- Run from the extracted package directory so site_delta.sqlite3 is beside the
-- SQLite process. The uploaded database is authoritative for every screenshot
-- it contains; destination-only historical screenshots are retained.
ATTACH 'site_delta.sqlite3' AS delta;
BEGIN IMMEDIATE;

INSERT INTO screenshots (
    id, rel_path, game, filename, stem, ext, width, height, sha1, text,
    ocr_engine, ocr_confidence, tags_json, note, image_url, preview_url,
    thumb_url, image_key, preview_key, thumb_key, contributor, created_at, updated_at
)
SELECT
    id, rel_path, game, filename, stem, ext, width, height, sha1, text,
    ocr_engine, ocr_confidence, tags_json, note, image_url, preview_url,
    thumb_url, image_key, preview_key, thumb_key, contributor, created_at, updated_at
FROM delta.screenshots
WHERE true
ON CONFLICT(id) DO UPDATE SET
    rel_path = excluded.rel_path,
    game = excluded.game,
    filename = excluded.filename,
    stem = excluded.stem,
    ext = excluded.ext,
    width = excluded.width,
    height = excluded.height,
    sha1 = excluded.sha1,
    text = excluded.text,
    ocr_engine = excluded.ocr_engine,
    ocr_confidence = excluded.ocr_confidence,
    tags_json = excluded.tags_json,
    note = excluded.note,
    image_url = excluded.image_url,
    preview_url = excluded.preview_url,
    thumb_url = excluded.thumb_url,
    image_key = excluded.image_key,
    preview_key = excluded.preview_key,
    thumb_key = excluded.thumb_key,
    contributor = excluded.contributor,
    created_at = excluded.created_at,
    updated_at = excluded.updated_at;

DELETE FROM glossary_matches
WHERE screenshot_id IN (SELECT id FROM delta.screenshots);
INSERT INTO glossary_matches (
    screenshot_id, category, canonical, alias, language, source
)
SELECT screenshot_id, category, canonical, alias, language, source
FROM delta.glossary_matches;

DELETE FROM screenshot_corpus_matches
WHERE screenshot_id IN (SELECT id FROM delta.screenshots);
INSERT INTO screenshot_corpus_matches (
    screenshot_id, text_id, score, collection, language, file_key, text_file,
    line_no, text, matched_text, evidence_json, created_at
)
SELECT
    screenshot_id, text_id, score, collection, language, file_key, text_file,
    line_no, text, matched_text, evidence_json, created_at
FROM delta.screenshot_corpus_matches;

DELETE FROM screenshot_fts
WHERE rowid IN (SELECT id FROM delta.screenshots);
INSERT INTO screenshot_fts(rowid, text, tags, filename, game, rel_path)
SELECT id, text, tags_json, filename, game, rel_path
FROM screenshots
WHERE id IN (SELECT id FROM delta.screenshots);

COMMIT;
INSERT INTO screenshot_fts(screenshot_fts) VALUES('optimize');
DETACH delta;
