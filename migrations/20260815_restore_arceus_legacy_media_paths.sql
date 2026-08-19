-- Restore Arceus media objects that still live under the legacy /ns2 paths.
-- OCR text, tags, notes, corpus links, glossary links, and timestamps are not
-- changed. Run from the game_gallery project root.
ATTACH 'backups/site.sqlite3.pre-delta-20260815.cold.sqlite3' AS rollback_source;
BEGIN IMMEDIATE;

CREATE TEMP TABLE arceus_media_path_restore_ids (
    id INTEGER PRIMARY KEY
);

INSERT INTO arceus_media_path_restore_ids (id)
SELECT current.id
FROM main.screenshots AS current
JOIN rollback_source.screenshots AS old ON old.id = current.id
WHERE current.game IN ('宝可梦传说 阿尔宙斯', 'Pokémon LEGENDS アルセウス')
  AND current.thumb_url LIKE 'https://gallery.pokeamice.com/ns2/pokeamice/thumb/%'
  AND old.thumb_url LIKE 'https://gallery.pokeamice.com/ns2/thumb/%'
  AND (
      current.thumb_url IS NOT old.thumb_url
      OR current.preview_url IS NOT old.preview_url
      OR current.thumb_key IS NOT old.thumb_key
      OR current.preview_key IS NOT old.preview_key
  );

SELECT 'Arceus media rows selected for restore', COUNT(*)
FROM arceus_media_path_restore_ids;

UPDATE main.screenshots
SET image_url = (
        SELECT old.image_url FROM rollback_source.screenshots AS old
        WHERE old.id = main.screenshots.id
    ),
    preview_url = (
        SELECT old.preview_url FROM rollback_source.screenshots AS old
        WHERE old.id = main.screenshots.id
    ),
    thumb_url = (
        SELECT old.thumb_url FROM rollback_source.screenshots AS old
        WHERE old.id = main.screenshots.id
    ),
    image_key = (
        SELECT old.image_key FROM rollback_source.screenshots AS old
        WHERE old.id = main.screenshots.id
    ),
    preview_key = (
        SELECT old.preview_key FROM rollback_source.screenshots AS old
        WHERE old.id = main.screenshots.id
    ),
    thumb_key = (
        SELECT old.thumb_key FROM rollback_source.screenshots AS old
        WHERE old.id = main.screenshots.id
    )
WHERE id IN (SELECT id FROM arceus_media_path_restore_ids);

SELECT 'Arceus media rows restored', changes();

DROP TABLE arceus_media_path_restore_ids;
COMMIT;
DETACH rollback_source;
