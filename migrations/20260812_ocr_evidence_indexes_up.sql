-- Apply after adding screenshot_corpus_matches.evidence_json and merging the
-- 2026-08-12 OCR/corpus delta. All match statuses remain eligible for context;
-- evidence status is indexed so feedback-driven removals can be applied later.
DROP INDEX IF EXISTS idx_site_corpus_public_screenshot;
DROP INDEX IF EXISTS idx_site_corpus_public_context;
DROP INDEX IF EXISTS idx_site_corpus_public_sequence;

CREATE INDEX IF NOT EXISTS idx_site_corpus_context
ON screenshot_corpus_matches(collection, language, file_key, text_file, line_no, score DESC, screenshot_id);

CREATE INDEX IF NOT EXISTS idx_site_corpus_sequence
ON screenshot_corpus_matches(collection, language, text_file, line_no, score DESC, screenshot_id);

CREATE INDEX IF NOT EXISTS idx_site_corpus_evidence_status
ON screenshot_corpus_matches(
    COALESCE(json_extract(evidence_json, '$.best_match.status'), 'legacy'),
    screenshot_id,
    score DESC
);

CREATE INDEX IF NOT EXISTS idx_site_screenshots_game_normalized_text
ON screenshots(
    game,
    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(text), CHAR(10), ''), CHAR(13), ''), CHAR(9), ''), ' ', ''), '　', '')
);

PRAGMA optimize;
