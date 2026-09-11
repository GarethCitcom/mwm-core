# MWM Core

Companion plugin for the Maths with Melissa block theme. It owns the data model, the REST API the theme uses, the YouTube playlist sync and the front-end **Studio** at `/studio/`.

Requires **Advanced Custom Fields Pro** (field groups are registered in PHP, nothing is click-configured).

## Data model

| Post type        | Slug              | What it is                                                                 |
|------------------|-------------------|-----------------------------------------------------------------------------|
| `mwm_lesson`     | `/lesson/{slug}/` | A video. `format` taxonomy says whether it is a **lesson**, a **short** (Quick Maths) or a **gaming** video. |
| `mwm_quiz`       | `/quiz/{slug}/`   | Quiz JSON attached to a lesson (`lesson` field).                            |
| `mwm_past_paper` | listed on `/revision/past-papers/` | Board, tier, series, paper number, question paper + mark scheme PDFs, matching worksheets. |
| `mwm_exam_date`  | (no page)         | Board, level, paper label, date, session, verified flag. Feeds exam panels and the calendar. |
| `mwm_pathway`    | (no page)         | Level (+ optional boards), topic groups → rows (lesson, note, coming soon), suggested revision plan. |

Taxonomies: `mwm_level` (GCSE Foundation / GCSE Higher / A-level), `mwm_topic` (hierarchical: topic → subtopic; term meta holds chip icon, levels, order, browse intro, sync keywords), `mwm_format`, `mwm_theme` (Roblox / Minecraft / Story), `mwm_board` (Edexcel / AQA / OCR).

Lesson fields: `youtube_url`, `youtube_id`, `duration_seconds`, `thumbnail_url`, `worksheet` (PDF), `answers` (PDF), `source_id`/`source_url` (old site), `playlist_id` (set by the sync).

### Quiz JSON schema

```json
{"questions":[
  {"type":"choice","q":"…","options":["A","B","C","D"],"correct":0,"explain":"…"},
  {"type":"choice-image","q":"…","image":"description","imageUrl":"https://…","imageAlt":"…","options":["…"],"correct":1,"explain":"…"},
  {"type":"order","q":"…","options":["…"],"correctOrder":[1,3,0,2],"explain":"…"}
]}
```

3–5 options per question, at most 10 questions. `MWM_Quiz::validate()` returns the cleaned questions or a plain-English error; the same validator runs in the Studio, the REST API and on save in wp-admin.

## Pages

Activation creates the pages the theme navigates to and stores their IDs in the `mwm_pages` option: Home, Learn Maths (`/learn-maths/`), Revision (`/revision/`), Exam calendar, Past papers, Quick Maths, Gaming & Story Maths, My Learning, Privacy. Each page holds one ACF block from the theme. Pretty URLs: `/learn-maths/{level}/{topic}/`, `/revision/{level}/{board}/`, `/studio/{view}/`.

## REST API (`/wp-json/mwm/v1/`)

Public: `lessons`, `topics`, `pathway`, `exam-dates`, `quiz/{id}`. Signed-in: `me/progress` (GET/POST). Studio (capability `mwm_manage_studio`, granted to administrators and editors): `studio/video`, `studio/lessons`, `studio/lessons/{id}`, `studio/upload`, `studio/quiz/validate`, `studio/past-papers`, `studio/exam-dates`, `studio/content`, `studio/content/{id}` (DELETE = bin), `studio/content/{id}/restore`, `studio/sync`, `studio/dashboard`.

## Settings → Maths with Melissa

* **YouTube Data API key** — used by the daily sync and to fetch video length/date in the Studio. Without a key the Studio falls back to oEmbed (title + thumbnail) and a best-effort read of the watch page.
* **Playlist IDs** — Quick Maths (Shorts), Roblox, Minecraft, Story. The sync runs daily at 3 am (WP-Cron hook `mwm_daily_playlist_sync`) and upserts videos as `mwm_lesson` posts keyed on `youtube_id`. Videos removed from a playlist go back to draft. Level/topic are guessed from the topic terms' keyword meta and never overwrite manual edits.
* **Redirects** — the 301 map from old URLs (filled by the import).

## WP-CLI

```
wp mwm seed-demo [--remove]                                  # prototype sample content
wp mwm import-lessons --source=https://old-site [--post-type=lesson] [--dry-run] [--update] [--no-media] [--limit=N]
wp mwm import-lessons --file=export.xml|lessons.json
wp mwm redirect-map [--format=option|nginx|apache|csv|json]
wp mwm sync
```

The importer reads the old site's REST API (or a WXR export / JSON list), creates `mwm_lesson` posts with `source_id` + `source_url`, extracts the YouTube ID from meta or embedded iframes, maps level/topic from the old taxonomies or title keywords, sideloads worksheet/answer PDFs, and saves the 301 map. Unknown old `/lesson/{id}/` URLs are also resolved on the fly from `source_id`.
