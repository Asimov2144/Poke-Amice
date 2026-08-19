-- Restore the pre-2026-08-12 schema after restoring pre-delta data from the
-- deployment backup. Drop dependent indexes before removing evidence_json.
DROP INDEX IF EXISTS idx_site_corpus_public_screenshot;
DROP INDEX IF EXISTS idx_site_corpus_public_context;
DROP INDEX IF EXISTS idx_site_corpus_public_sequence;
DROP INDEX IF EXISTS idx_site_corpus_context;
DROP INDEX IF EXISTS idx_site_corpus_sequence;
DROP INDEX IF EXISTS idx_site_corpus_evidence_status;
DROP INDEX IF EXISTS idx_site_screenshots_game_normalized_text;
ALTER TABLE screenshot_corpus_matches DROP COLUMN evidence_json;
