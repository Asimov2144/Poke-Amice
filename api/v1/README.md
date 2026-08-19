# PokeAmice Game Gallery API v1

Base URL:

```text
https://pokeamice.com/game_gallery/api/v1/
```

The v1 contract is intended for native iOS/Android, Flutter, React Native,
Capacitor and browser clients. Public reads require no API key. All endpoints
return UTF-8 JSON and support CORS preflight.

## Compatibility policy

- Existing v1 fields and meanings will not be removed or renamed.
- New optional fields may be added without changing the version.
- Breaking changes require a new `/api/v2/` directory.
- Cursors are opaque. Store and resend them unchanged.
- Each response includes `meta.dataset_version`. Restart a long-running local
  sync when that value changes.
- Timestamps are Unix seconds in UTC and may contain a fractional part.

Successful response envelope:

```json
{
  "ok": true,
  "data": {},
  "meta": {
    "api_version": "v1",
    "contract_version": "1.0.0",
    "request_id": "2f5a4ec4df3b1841",
    "dataset_version": "459cdcfc6d19b9c0aa3e"
  }
}
```

Error envelope:

```json
{
  "ok": false,
  "error": {
    "code": "SCREENSHOT_NOT_FOUND",
    "message": "未找到这张截图"
  },
  "meta": {
    "api_version": "v1",
    "contract_version": "1.0.0",
    "request_id": "2f5a4ec4df3b1841"
  }
}
```

## Endpoints

### Discovery and dataset version

```text
GET index.php
GET version.php
GET health.php
```

Check `version.php` before an offline synchronization. Its dataset version is
also returned by all read endpoints.

### Gallery metadata

```text
GET meta.php
```

Returns totals, canonical game names and valid correction categories. Use the
returned canonical game name as the `game` list filter.

### Cursor-paginated screenshots

```text
GET screenshots.php?limit=30&sort=id_desc
GET screenshots.php?game=Pokémon%20LEGENDS%20Z-A&limit=30
GET screenshots.php?q=电龙&limit=30
GET screenshots.php?cursor=OPAQUE_CURSOR
```

Parameters:

- `limit`: 1–100; default 30.
- `sort`: `id_desc` or `id_asc`; aliases `newest` and `oldest` are accepted.
- `q`: OCR, filename, tag and multilingual corpus search; at most 80 chars.
- `game`: canonical name from `meta.php`.
- `include_variants`: `false` by default. Set to `true` to include screenshots
  normally folded into a duplicate submission group.
- `expand_evolution`: `false` by default. See "Pokémon name resolution" below.
- `cursor`: value from `meta.pagination.next_cursor`.

The cursor is bound to `q`, `game`, `sort`, `include_variants` and
`expand_evolution`. Changing a filter requires starting again without a cursor.

`q` terms of 3+ characters are matched against `text` through a trigram FTS5
index (`migrations/20260819_screenshots_text_fts_up.sql`) instead of a
`LIKE '%term%'` full table scan. This is what fixed intermittent slow searches
on the web client: a cold (evicted from OS page cache) `LIKE` scan over the
`screenshots` table measured ~0.7s, vs low single-digit ms once the trigram
index absorbs it. Terms under 3 characters still use `LIKE` (trigram tokens
need 3+ characters to exist) and fall back automatically if the index is
missing (e.g. a dev DB that hasn't run the migration).

### Pokémon name resolution (fuzzy search)

When `q` is a single word (no whitespace), the server first tries to resolve it
against the Pokémon name dictionary before running the literal substring
search:

1. Alias patch (`../today/lib/aliases.json`, shared with the Today module):
   legacy/free-text names → official name.
2. Exact match against the official zh / en / jp name.
3. Exact match against a precomputed pinyin key (diacritics and spaces
   stripped, e.g. `pikaqiu` matches 皮卡丘).
4. Typo tolerance: bounded Levenshtein distance against zh names and pinyin
   keys (distance 1 for 3–5 characters, distance 2 for longer strings; ties
   are treated as no match to avoid guessing wrong). Example: `皮卡丙` still
   resolves to 皮卡丘.

On a hit, the search additionally matches that Pokémon's zh/en/jp names (OR'd
with the literal query, not narrowing it), so e.g. `q=pikaqiu` or `q=Pikachu`
also surfaces screenshots whose OCR text only contains `皮卡丘`. Multi-word
queries and queries that don't resolve to a known Pokémon are unaffected and
keep the previous literal AND-of-terms behavior.

Set `expand_evolution=true` to also include every other member of the
resolved Pokémon's evolution family — both evolutions and pre-evolutions
(data from `../today/lib/evolutions.json`, a PokeAPI evolution-chain
snapshot) — e.g. `q=皮卡丘&expand_evolution=true` also matches 皮丘 and 雷丘.
This is opt-in because it changes the result set; omit it to keep matching
only the exact Pokémon.

Results are tiered, not interleaved: every screenshot matching the resolved
Pokémon itself (`match_tier: "primary"`) is returned before any screenshot
that only matches through the evolution family (`match_tier: "family"`).
Pagination is cursor-based and crosses the tier boundary transparently — a
page can contain both tiers (primary rows first) when the boundary falls
inside it, and continuing past the last primary row moves the cursor into
the family tier automatically. Every `ScreenshotSummary` includes
`match_tier`; it is `null` when `q` didn't resolve to a Pokémon (plain text
search, multi-word `q`, or no `q` at all) since there is only one tier.

The response includes `meta.resolved_pokemon` (`null` when nothing resolved):

```json
{
  "meta": {
    "resolved_pokemon": {
      "pokemon_id": "0025",
      "pokemon_name": "皮卡丘",
      "matched_via": "pinyin",
      "expanded_terms": ["pikaqiu", "皮卡丘", "Pikachu", "ピカチュウ"]
    }
  }
}
```

`matched_via` is one of `exact`, `alias`, `pinyin`, `fuzzy`. This endpoint
reads `pokemon_db.json` and the two Today files above as read-only upstream
data (same pattern Today itself uses for this repo's `data/site.sqlite3`);
regenerate `data/pokemon_search_index.json` with
`php bin/build-pokemon-search-index.php` after `pokemon_db.json` changes
(new/renamed Pokémon) — it precomputes pinyin so requests don't pay ICU
transliteration cost.

### Screenshot detail

```text
GET screenshot.php?id=8615
```

Returns the screenshot, media URLs, glossary matches, corpus evidence,
translations, story context, story sequence, version matches and duplicate
submissions. Load this endpoint lazily when the user opens a card.

Use `thumb_url` in lists and `preview_url` for the larger view. Treat the URL as
opaque and let the platform URL library handle Unicode escaping.

### Submit a correction

```text
POST corrections.php
Content-Type: application/json
```

```json
{
  "screenshot_id": 8615,
  "category": "OCR文字错误",
  "suggested_text": "修正后的文字",
  "description": "问题说明",
  "contact": ""
}
```

This endpoint is limited to 20 requests per client per hour. `suggested_text`
and `description` cannot both be empty. Read valid categories from `meta.php`.

### Human tagging (labeling and verification)

```text
GET  tagging/next_case.php?game=宝可梦 朱   (optional game filter; Phase 1 primary labeling flow)
POST tagging/submit_case.php
GET  tagging/games_progress.php
GET  tagging/next_label.php?category=宝可梦   (kept for backwards compatibility)
POST tagging/submit_label.php
GET  tagging/next_verify.php?category=剧情
POST tagging/submit_verify.php
GET  tagging/suggest.php?category=人物&q=小
GET  tagging/leaderboard.php
```

`next_case.php`/`submit_case.php` are the current primary labeling flow (see
`pokeamice_game_gallery_research_system.md` §4): instead of asking one category at a
time, a Case bundles whichever of 宝可梦/人物/地点 are still missing for that screenshot
into one card, and `submit_case.php` accepts all of them in a single POST. `next_label.php`/
`submit_label.php` still work — they're the single-category building block `submit_case.php`
loops internally — kept around for compatibility, not the primary path any more.

`games_progress.php` needs no identity and powers the "choose a game" step: it returns
each canonical game's screenshot count and an MVP-caliber coverage ratio (glossary
evidence OR any label_submissions, regardless of confirmation status).

OCR can read text but cannot tell you a screenshot contains Pikachu. This
group of endpoints powers a quiz-style crowd tagging page (`mark.php`):
**labeling mode** asks players to tag untagged screenshots for `宝可梦`,
`人物` or `地点`; **verification mode** asks players to judge whether an
existing claim is correct, for those three categories plus `剧情` (sourced
from `screenshot_corpus_matches` rows flagged `needs_review` — see
`DEPLOY.md`).

Every request needs an identity header, resolved in this order:

- `Authorization: Bearer <token>` — an auth token issued by the `event` app
  (`/event/api/login`, `/event/api/register`). This API verifies it locally
  by recomputing the same HMAC-SHA256 signature `event/server.ts` uses
  (`base64url(JSON{username,exp}).base64url(HMAC-SHA256(payload, secret))`);
  it never calls out to the `event` process or reads its database.
- `X-GG-Guest-Id: <uuidv4>` — a client-generated UUID for guest play, sent
  when there is no token. Missing both returns `401 INVALID_IDENTITY`.

`submit_label.php` resolves each typed name against the Pokémon dictionary
(reusing the same fuzzy matcher `screenshots.php` uses for `q`) or the
`glossary_matches` term list, so a typo like `皮卡丙` is still recorded as
`皮卡丘`. Unresolved input is kept as free text (`match_via: "free_text"`),
never discarded and never invented.

Nothing from this endpoint group writes to `data/site.sqlite3` — it is a
read-only artifact rebuilt by an offline pipeline, same as every other part
of this API. Labels and votes land in a separate pending-review queue; once
enough independent players flag the same claim `错误`, one row is
auto-filed into the existing `correction_reports` table (the same table
`corrections.php` writes to) for a human to act on later.

`leaderboard.php` is the odd one out in this group: it needs no identity
header at all, just `GET`s the top 20 identities by xp. If an identity header
*is* sent, the response's `data.me` also reports that caller's own rank/xp
(useful for "you are #37" even when they are off the visible list); `null`
when they have no xp yet.

### Turning submissions into confirmed data

`label_submissions`/`verify_submissions` rows stay `pending` until
`bin/consensus-tagging.php` (a manual/cron CLI script, same shape as
`bin/build-pokemon-search-index.php` — not a daemon) is run:

```text
php bin/consensus-tagging.php [--dry-run]
```

It promotes rows to `confirmed`/`under_review` using a simple "≥2 independent
identities agree" rule (≥3 `正确` votes for verify claims), writes agreed-upon
answers into a new `confirmed_annotations` table in `tagging.sqlite3`, and
recomputes each identity's `identity_stats.reliability` (confirmed / total,
`null` until an identity has ≥5 samples). `confirmed_annotations` is read live
by `api.php`'s `detail` action (`item.community_tags`) so confirmed community
tags show up in the gallery immediately — no offline OCR pipeline rebuild
needed for that. Merging confirmed data into the authoritative, searchable
`glossary_matches` in `data/site.sqlite3` stays a manual step for whoever runs
the offline pipeline (see `DEPLOY.md`), by design — this endpoint group never
writes to `site.sqlite3`.

## TypeScript / React Native example

```ts
const API = 'https://pokeamice.com/game_gallery/api/v1';

async function apiGet(path: string, params: Record<string, string> = {}) {
  const url = new URL(`${API}/${path}`);
  Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
  const response = await fetch(url, { headers: { Accept: 'application/json' } });
  const body = await response.json();
  if (!response.ok || !body.ok) throw new Error(body.error?.code ?? 'API_ERROR');
  return body;
}

let cursor = '';
do {
  const body = await apiGet('screenshots.php', {
    limit: '100',
    sort: 'id_desc',
    ...(cursor ? { cursor } : {}),
  });
  saveScreenshots(body.data.items);
  cursor = body.meta.pagination.next_cursor ?? '';
} while (cursor);
```

## Flutter example

```dart
final uri = Uri.https(
  'pokeamice.com',
  '/game_gallery/api/v1/screenshots.php',
  {'game': 'Pokémon LEGENDS Z-A', 'limit': '30'},
);
final response = await http.get(uri, headers: {'Accept': 'application/json'});
final body = jsonDecode(response.body) as Map<String, dynamic>;
if (response.statusCode != 200 || body['ok'] != true) {
  throw Exception(body['error']?['code'] ?? 'API_ERROR');
}
```

The machine-readable contract is in `openapi.yaml`.
