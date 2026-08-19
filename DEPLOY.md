# NS2 Server Upload Package

Snapshot source: `P:\NS2\ocr_search\data\site_export`

Snapshot time: 2026-08-02 10:39 JST

## Upload These Files

```text
server_upload/
  data/
    site.sqlite3
    manifest.jsonl
  SHA256SUMS.txt
```

- `data/site.sqlite3` is the compact public gallery database. It contains screenshot OCR text, FTS search data, glossary matches, and screenshot-to-corpus links. It does not contain original local image paths.
- `data/manifest.jsonl` is an optional public-data backup or import source. It can be omitted when the server application only reads SQLite.
- `SHA256SUMS.txt` lets the server verify the uploaded files.

## Images

Thumbnail and preview URLs in this snapshot point to Tencent COS under:

```text
https://gallery.pokeamice.com/ns2/pokeamice/thumb/<game>/...
https://gallery.pokeamice.com/ns2/pokeamice/preview/<game>/...
```

Do not upload `site_export/assets` to the VPS after the COS upload has completed. The current snapshot does not include originals, so lightbox views use the COS preview image.

## Do Not Upload

```text
site_export/upload_manifest.jsonl
site_export/upload-cos.ps1
site_export/assets/
P:\NS2 original screenshot folders
poke-corpus-main/
OCR API keys or Tencent COS secret keys
local logs and thumbnail cache
```

`upload_manifest.jsonl` contains local file paths and is only for the local COS upload step.

## Runtime Note

This is a public-data package, not the full local OCR workspace. A server gallery application must read `thumb_url` and `preview_url` from `site.sqlite3` instead of the local `screenshots.path` field.

The existing local `ns2_ocr.py serve` command is designed for the full authoring database and local images, so it must not be pointed directly at this exported SQLite file. This export supports gallery display, OCR keyword search, glossary tags, and already-linked corpus text. Full corpus browsing and semantic vector search need a separate server search bundle or service.

## Snapshot Freshness

This export predates the latest full corpus-linking and semantic-index run. Re-run `export-site-data` after those background jobs are complete, then rebuild this package before the production upload so new links are included.

## 2026-08-12 Incremental OCR/Match Update

The `server_db_delta_20260812.zip` update adds structured match evidence and
refreshes OCR-derived data without replacing the full public database.

1. Create a SQLite online backup of `data/site.sqlite3`.
2. Apply the package's `01_migrate_20260812.sql` once.
3. Run `migrations/20260812_merge_delta.sql` from the directory containing
   `site_delta.sqlite3`. It refreshes generated OCR fields while preserving
   existing editorial `note` values.
4. Apply `migrations/20260812_ocr_evidence_indexes_up.sql`.
5. Rebuild `screenshot_duplicate_groups` and `corpus_translation_fts` with the
   two checked-in PHP builders.
6. Run `PRAGMA quick_check`, `PRAGMA foreign_key_check`, and FTS integrity
   checks before deploying the staged database.

Candidates marked `needs_review` remain available to the story-context UI and
are labelled as pending review. Use the `剧情匹配错误` correction category to
collect screenshot-level feedback before removing rejected relations in a
later batch.

Rollback consists of restoring the pre-deployment SQLite backup. The matching
schema alone can be reverted with
`migrations/20260812_ocr_evidence_indexes_down.sql` after old data is restored.
