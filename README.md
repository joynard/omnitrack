# Omnitrack Engine

Personal workspace + task tracker. It keeps two deliberately isolated domains:

- **Projects** â€” large-scope work with sub-elements (**Modules / Todo**),
  optionally ingested from a Google Sheet.
- **Tasks** â€” ad-hoc personal checklist, independent of any project.

Plus a global **`Ctrl + P` Apollo palette** backed by the DeepSeek API, a
token-protected **`/api/harness/*`** bridge so DeepSeek Harness can drive the
workspace autonomously, and a **Telegram bot** that runs the same palette agent
from a phone.

---

## Stack

| Layer | Choice |
| --- | --- |
| Runtime | PHP 8.3 (Alpine container) |
| Framework | Laravel 13 |
| Database | PostgreSQL (Neon / Supabase), ULID primary keys |
| Queue / Cache / Session | `database` driver â€” no Redis needed |
| Frontend | Blade + hand-authored CSS + vanilla JS (no Node build step) |
| Container | PHP-FPM + Nginx + Supervisor in one image |
| External | Google Sheets API v4, DeepSeek API, Telegram Bot API |

### Why no Node/Tailwind build

The Laravel 13 skeleton no longer ships Vite by default, and this project only
needs one stylesheet. `public/css/omnitrack.css` implements `STYLEGUIDE.md`
directly using CSS custom properties whose names mirror the Tailwind token names
from the styleguide (`--canvas-light`, `--ink-primary`, `--brand-primary`, â€¦).

Consequences:

- `docker build` needs no `npm install` and no asset compilation.
- The palette renders identically offline, with no CDN dependency.
- If you later want a Tailwind build, the token names already match; swap the
  stylesheet for the `tailwind.config.js` in `STYLEGUIDE.md` without touching
  any markup.

---

## Quick start (Docker)

Requires Docker Desktop running.

```bash
docker compose up --build
```

Then open **<http://localhost:8080>**.

What comes up:

| Service | Address |
| --- | --- |
| App (nginx + php-fpm + queue worker) | <http://localhost:8080> |
| PostgreSQL 17 | `localhost:5433` (db `omnitrack`, user `omnitrack`, pass `secret`) |

The entrypoint generates a key if needed, caches config/routes/views, runs
migrations with retries, then starts Supervisor.

Useful commands:

```bash
# Tail logs
docker compose logs -f app

# Re-run migrations / seed
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force

# Run the test suite inside the correct PHP version
docker compose exec app php artisan test

# Open a shell
docker compose exec app sh
```

To stop and wipe the database volume:

```bash
docker compose down -v
```

---

## Configuration

All configuration lives in `.env` (git-ignored). Start from the template:

```bash
cp .env.example .env
php -r "echo 'base64:'.base64_encode(random_bytes(32));"   # APP_KEY
php -r "echo bin2hex(random_bytes(32));"                    # HARNESS_SECRET_TOKEN
```

### Database â€” Neon or Supabase

Both providers give you one connection string. The simplest option is to paste it
into a single variable:

```env
DB_CONNECTION=pgsql
DB_URL=postgresql://user:password@ep-xxx.aws.neon.tech/neondb?sslmode=require
```

Or fill in the discrete values:

```env
DB_CONNECTION=pgsql
DB_HOST=ep-xxx.aws.neon.tech
DB_PORT=5432
DB_DATABASE=neondb
DB_USERNAME=your_user
DB_PASSWORD=your_password
DB_SSLMODE=require
```

`DB_SSLMODE=require` is what Neon and Supabase expect.

### DeepSeek API

```env
DEEPSEEK_API_KEY=sk-...
DEEPSEEK_BASE_URL=https://api.deepseek.com/v1
DEEPSEEK_MODEL=deepseek-chat
```

Without a key the app still works end to end: `summarize_board` falls back to
locally computed statistics, and the two generation actions return a clear
"API key not configured" message instead of an error page.

### Google Sheets

1. Create a Google Cloud **service account** and enable the **Sheets API**.
2. Download its JSON key.
3. Save it as **`storage/app/google/credentials.json`**.
4. **Share your spreadsheet with the service account's `client_email` as Viewer.**
   Skipping this yields a 403 from Google.

Full walkthrough: [`storage/app/google/README.md`](storage/app/google/README.md).

**Graceful degradation.** If that file does not exist, `GoogleSheetService`:

- logs `Google credentials file missing. Ingestion skipped.`
- returns `['status' => 'skipped', 'message' => 'Credentials not injected yet']`
- never throws, so the UI, the queue worker, and the scheduler all stay healthy

### Obsidian Second Brain (optional, read-only)

Omnitrack can browse, search and read an Obsidian vault. It never writes to it.

```env
OBSIDIAN_VAULT_HOST_PATH="D:/path/to/vault"   # host path, read by Compose
OBSIDIAN_VAULT_PATH=/vault                    # path inside the container
OBSIDIAN_VAULT_NAME="Second Brain"            # label used in obsidian:// links
```

`docker-compose.dev.yml` bind-mounts the host path at `/vault` **read-only** for
`app` and `telegram`. The base `docker-compose.yml` mounts nothing, so a production
instance reports `available: false` and the page stays empty instead of failing --
there is no vault to leak on a server. The UI lives at `/vault` (nav: "Second
Brain"); the agent surface is `/api/harness/vault*`; notes are read from disk on
each request, so nothing needs migrating or re-indexing.

To fill the vault from Omnitrack (one note per project, grouped by category, plus
a `Projects MOC` index), run `./scripts/vault-notes.ps1`. It is safe to re-run:
only notes carrying `omnitrack_id:` are rewritten, so the hand-written `## Catatan`
section of every note survives.

### Notes and the two-way bridge

`./scripts/vault-notes.ps1` writes a small structure, not just one note per
project:

| Path | What it is |
| --- | --- |
| `Projects/<Kategori>/<Project>.md` | one note per project: roles, module mirror, disk inventory, checklist |
| `Areas/<Kategori>.md` | one note per area: project table, tech rollup, open modules |
| `Projects/Projects MOC.md`, `Home.md` | indexes; every generated note is reachable from `Home` |
| `Rencana/Fokus Saat Ini.md` | what is open right now, and which projects have no checklist yet |
| `Journal/Rekap/<tahun>-W<minggu>.md` | weekly digest of the current ISO week |
| `Ide/Ide.md` | scratchpad index for ideas that are not projects yet |
| `Templates/Harian.md`, `Templates/Mingguan.md` | daily and weekly templates (core plugins are enabled on first run) |

Two rules make regeneration safe:

- Your writing goes **between `<!-- omnitrack:keep -->` and
  `<!-- /omnitrack:keep -->`**, keyed by the heading above it. That text is read
  back and re-emitted; everything else in a generated note is overwritten.
- The module checklist (`<!-- omnitrack:modules -->`) is written from Omnitrack.
  Tick a box, then run `./scripts/vault-sync.ps1 -Apply` to push it in.
  `./scripts/vault-sync.ps1` without `-Apply` only reports: checklist
  disagreements, notes that need regenerating, and notes nothing links to.

Two rules make the markers themselves safe: they must stand **alone on their own
line**, because Home quotes them as inline code and a parser that accepts them
anywhere swallows the real block; and every block read is journalled to
`~/.dsh/cache/omnitrack-keep.json` **before** the first deletion, so a run that dies
mid-way is recoverable on the next run.
Re-running is cheap when only one note is stale: pass
`-Only 'PT SPIL/Forecast Container'` (an area, `Area/Project`, or a name
fragment) and the generator rewrites just those project notes, reusing a cached
folder inventory. Measured: 1.0 s for one note, 1.6 s for everything with the
cache, 72 s with `-Fresh`. Areas, MOC, Home, Rencana, Ide, the journal and the
templates are only rewritten on a full run, because they describe the whole
workspace.

---

## Google Sheet column mapping

The source range defaults to `Sheet1!A2:H`. Column order is fixed:

| Index | Column | Target field |
| --- | --- | --- |
| 0 | Project Name | `name` |
| 1 | My Role | `my_role` |
| 2 | Department Terkait | `department` |
| 3 | User | `target_user` |
| 4 | Project Owner | `project_owner` |
| 5 | Desc | `description` |
| 6 | Status | `status` (normalized) |
| 7 | Link Docs/Sheet | `doc_link` |

Status text is normalized, so both Indonesian and English work:

| Sheet value | Stored |
| --- | --- |
| `Selesai`, `Done`, `100%` | `done` |
| `WIP`, `In Progress`, `Jalan` | `in_progress` |
| anything else | `pending` |

**Sync safety.** Rows are matched on the composite key
`(sheet_source_id, sheet_row_index)` via `Project::upsert()`, and a SHA-256
`row_hash` of the raw row detects real mutations. Projects you create manually in
the UI have `sheet_source_id = NULL`, so a sync can never modify or delete them.

---

## Apollo palette (`Ctrl + P`)

Press **`Ctrl + P`** (or **`Cmd + P`**) anywhere. **`Esc`** closes it; the input
is autofocused. There is no header button â€” the shortcut is the only entry point,
as requested.

**Full control (default action).** The palette uses DeepSeek *native tool
calling*, so the model returns a structured call instead of prose the app has to
parse. The model chooses one of 16 tools, and the app validates it before
touching the database. That covers:

| Area | Capabilities |
| --- | --- |
| Categories | create (with colour), rename, recolour, reorder, delete, **bulk delete by id list or `all`** |
| Projects | create, update (including moving category), delete, reorder, **bulk update/delete by id list or `all` + `where`** |
| Modules | batch create, update, delete, reorder within a project, **move to another project**, **bulk delete/update across a project or the whole workspace**, **shift due dates** |
| Tasks | batch create, update, delete, reorder, **bulk delete/update by id list or `all` + `where`**, **shift due dates** |

Examples that work:

- `Pindahkan project Vendor Migration Q4 ke kategori Kantor`
- `Urutkan modul project ini: yang paling mendesak di atas`
- `Buat 2 task: "Bayar SPP" prioritas tinggi dan "Belanja bulanan" prioritas rendah`
- `Hapus kategori Pribadi`

The board snapshot is sent as context on every call, so the model can resolve
names like "project ini" or "modul kedua" and knows the current IDs and order.

Other actions keep their original behaviour: **Buat task dari teks**, **Pecah
project jadi sub-modul** (both now run through the same agent), **Ringkas board**,
and **Sinkronkan Google Sheets**.

Useful for scripted use: appending `?open_palette=1` to `/projects` or `/tasks`
opens the palette on page load.

---

## Categories

Projects can be filed under a category â€” *Kuliah*, *Kantor*, *Pribadi*, or
anything you add. The Projects page has a filter bar with per-category counts,
and a **Kelola Kategori** panel to add, rename, and delete categories.

Seeded starting categories: `Kuliah`, `Kantor`, `Pribadi`.

Deleting a category **never deletes its projects**: the foreign key is
`nullOnDelete`, so they simply become uncategorised.

---

## Project status follows the checklist

Ticking every module of a project marks the project itself **Done**, and a project
that still has an open module can no longer claim to be Done â€” it reopens as In
Progress. Which transitions happen automatically is deliberately narrow:

| Module states | Project status |
| --- | --- |
| Every module done | `done` (always) |
| Something still open, project was `done` | `in_progress` |
| Nothing started yet | left alone â€” `pending` vs `in_progress` is your call |
| No modules at all | left alone |

The rule lives in `Project::syncStatusFromModules()` and is applied from
`ProjectModule` model events, so the board UI, the AI palette, the Telegram bridge
and the harness API all behave identically. A mass `update()`/`delete()` fires no
model events, so the bulk module endpoints call `Project::syncStatusesFor()`
explicitly with the projects they touched.

## Reordering

Order is stored explicitly as `order_index` on projects, modules and tasks.

- **Drag a row** (projects and modules) or a task to reorder it.
- The new order is persisted as a **complete id list**, so the server never has
  to guess.
- Task drag is disabled while a status filter is active, because reordering a
  filtered subset would produce a partial list.
- All three reorder paths (AI, board UI, harness API) call one shared
  `ReordersByIds` trait, which skips foreign ids and closes any gaps.
- That helper used `upsert()` until it was replaced with a single `CASE`
  expression. `upsert()` proposes a whole INSERT tuple, so every NOT NULL column
  the row does not mention (`projects.name`, `tasks.title`,
  `project_modules.project_id`) still had to be supplied, and SQLite rejected the
  proposal outright â€” which is why every reorder test used to be red.

---

## Sidebar

The sidebar can be collapsed and expanded:

- Click the **`â€¹`** control in the sidebar header, or press **`Ctrl/Cmd + B`**.
- The state is stored in `localStorage` and applied to `<html>` **before first
  paint**, so a collapsed rail never flashes open on load.
- On desktop the rail is removed from the layout and a floating **`â€º`** handle
  appears to bring it back. On mobile it collapses in place to a header row.
- `?sidebar=collapsed` / `?sidebar=expanded` forces a start state.

Layout note: the content column keeps equal top and bottom padding so the board
reads as centred in the area to the right of the sidebar, while the sidebar itself
stays pinned to the left edge.

---

## Health check

`GET /up` returns JSON and reflects real dependency state, so a container that
cannot reach Postgres is reported unhealthy to Koyeb:

```json
{"status":"ok","checks":{"app":true,"database":true},"php":"8.3.35","timestamp":"..."}
```

It returns HTTP 503 when the database is unreachable.

---

## Harness API

Base path `/api/harness`, protected by `Authorization: Bearer <HARNESS_SECRET_TOKEN>`.
The token may also be passed as `?token=` for quick manual checks. If
`HARNESS_SECRET_TOKEN` is unset the API fails closed with HTTP 503.

| Method | Endpoint | Behaviour |
| --- | --- | --- |
| `GET` | `/api/harness/context` | Compact JSON summary of categories, projects, modules, tasks, and sync state, sized for context injection |
| `GET` | `/api/harness/tools` | The 16 tool definitions, so an agent can discover the surface at runtime |
| `POST` | `/api/harness/agent` | Natural-language instruction handled by the full-control agent |
| `GET` | `/api/harness/categories` | List categories with project counts |
| `POST` | `/api/harness/categories` | Create a category |
| `POST` | `/api/harness/categories/{id}` | Rename a category |
| `DELETE` | `/api/harness/categories/{id}` | Delete a category (projects survive, unfiled) |
| `POST` | `/api/harness/projects` | Create a project, or update one by passing `id` |
| `POST` | `/api/harness/projects/{project}` | Update a project (`category_id`, `category_name`, â€¦) |
| `DELETE` | `/api/harness/projects/{project}` | Delete a project and its modules |
| `POST` | `/api/harness/projects/reorder` | Reorder projects from a complete id list |
| `POST` | `/api/harness/projects/bulk` | **Bulk update or delete projects** (`action`, then `project_ids` or `all` + `where`) |
| `POST` | `/api/harness/projects/{id}/modules` | Batch-add modules to a project |
| `POST` | `/api/harness/projects/{id}/modules/reorder` | Reorder a project's modules |
| `POST` | `/api/harness/modules/bulk` | **Bulk update or delete modules** by id list, or `all` + `where` (one project, one status, â€¦) |
| `POST` | `/api/harness/modules/{module}` | Update one module |
| `DELETE` | `/api/harness/modules/{module}` | Delete one module |
| `POST` | `/api/harness/categories/reorder` | Reorder categories from a complete id list |
| `POST` | `/api/harness/categories/bulk` | **Bulk delete categories** (the projects survive, unfiled) |
| `POST` | `/api/harness/tasks` | Create, update, or delete a task (`action`) |
| `POST` | `/api/harness/tasks/bulk` | **Bulk update or delete tasks** by id list, or `all` + `where` |
| `POST` | `/api/harness/tasks/{task}` | Update one task |
| `DELETE` | `/api/harness/tasks/{task}` | Delete one task |
| `POST` | `/api/harness/tasks/reorder` | Reorder tasks from a complete id list |
| `POST` | `/api/harness/sync` | Queue a Google Sheet ingestion run |
| `GET` | `/api/harness/vault` | Whether the Obsidian vault is mounted, with note/folder/tag counts |
| `GET` | `/api/harness/vault/notes` | Note headers, newest first (`folder`, `q`, `limit`) |
| `GET` | `/api/harness/vault/search` | Full-text vault search with a snippet per hit |
| `GET` | `/api/harness/vault/note` | One note by `path`: body, frontmatter, tags, wikilinks, backlinks |

All mutations are funnelled through `AiOrchestrator::callTool()`, so the palette,
the Blade UI, and this API share one validated implementation. Responses use the
shape `{status, message, data}`; validation failures return **422**, not 500.

### Bulk targeting

Every mutating tool â€” and therefore every `/bulk` endpoint â€” resolves its target
the same way, in this order:

1. **`*_ids`** â€” an explicit list. Wins over everything else.
2. **`all: true`** â€” every row matching the optional `where` filter. Each entity
   advertises exactly the filters it can express: `status` for projects, modules
   and tasks; `priority` for modules and tasks; `project_id` for modules;
   `category_id` for projects; and **nothing at all** for categories, so an `all`
   there is always a whole-workspace wipe and always needs `confirm`.
3. **`*_id`** â€” a single row (categories also accept `name`).

Two update-only keys are not selectors, but neither can be expressed as a single
mass `UPDATE`, so both take a slower per-row path:

- **`move_to_project_id`** (modules) â€” moves the targets to another project,
  appended after that project's current maximum `order_index`.
- **`due_date_shift_days`** (modules and tasks) â€” shifts *each* row's own due date
  by N days; rows with no due date are left alone. Mutually exclusive with
  `due_date`, which itself also accepts anything `strtotime()` understands, so
  `"+7 days"` works for a single row.

`all` is a separate boolean rather than "an omitted id means everything", because
the dangerous case has to be said out loud: a typo in an id list can only ever
affect rows that were named. An `all: true` with no `where` at all is the one
path that can touch rows nobody named, so it also requires **`confirm: true`** â€”
two deliberate decisions before a whole-workspace wipe. A scoped `all` (one
project, one status) needs no confirmation.

```bash
# Delete three specific modules
curl -s -X POST "$BASE/api/harness/modules/bulk" -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"action":"delete","module_ids":["01H...","01H...","01H..."]}'

# Delete every module in one project (scoped, so no confirm needed)
curl -s -X POST "$BASE/api/harness/modules/bulk" -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"action":"delete","all":true,"where":{"project_id":"01H..."}}'

# Mark every pending task done
curl -s -X POST "$BASE/api/harness/tasks/bulk" -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"action":"update","all":true,"where":{"status":"pending"},"status":"done"}'
```


### Examples

```bash
TOKEN=your_harness_secret_token
BASE=http://localhost:8080

# 1. Read full workspace context
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/api/harness/context"

# 2. Create a project
curl -s -X POST "$BASE/api/harness/projects" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Q3 Vendor Migration","my_role":"Lead","department":"Operations","status":"wip"}'

# 3. Batch-add modules (replace_existing also supported)
curl -s -X POST "$BASE/api/harness/projects/<PROJECT_ID>/modules" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"modules":[{"title":"Audit vendor list","priority":"p1"},{"title":"Draft SLA","priority":"medium"}]}'

# 4. Create a task, then mark it done
curl -s -X POST "$BASE/api/harness/tasks" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"action":"create","title":"Follow up vendor","priority":"tinggi"}'

curl -s -X POST "$BASE/api/harness/tasks" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"action":"update","id":"<TASK_ID>","status":"selesai"}'

# 5. Trigger a sheet sync
curl -s -X POST "$BASE/api/harness/sync" -H "Authorization: Bearer $TOKEN"
```

`GET /up` is an unauthenticated health check used by Koyeb and the compose
healthcheck.

---

## Telegram bot â€” drive Omnitrack from your phone

Send instructions to your own Telegram bot and Omnitrack executes them. It reuses
the palette's agent, so everything `Ctrl + P` can do works from a phone:

```
Buat project "Vendor Migration Q4" di kategori Kantor
Tambah modul "Draft SLA" dan "Audit vendor" ke project itu
Tandai task "Follow up vendor" selesai
Sinkronkan Google Sheets
Apa progres project Vendor Migration Q4?
```

### Why the laptop has to stay on

The bot uses **long polling**, not a webhook: the container calls `getUpdates` and
Telegram holds that request open for 25 s. That needs no public URL, no tunnel and
no inbound port, so it works from a laptop behind NAT â€” and stops the moment the
stack does. A webhook would instead need a public HTTPS endpoint (Koyeb, ngrok)
and would keep working with the laptop off.

### Setup

1. Talk to **@BotFather**, run `/newbot`, and copy the token into `.env`:

   ```env
   TELEGRAM_BOT_TOKEN=123456789:AA...
   TELEGRAM_ALLOWED_CHAT_IDS=
   ```

2. Start the poller. It is the **`telegram` Compose service**, so it comes up with
   the stack and needs **no image rebuild** â€” the service reuses the
   `omnitrack-engine:local` image that is already built:

   ```powershell
   ./scripts/dev.ps1 up
   ```

   To follow just the bot, or to watch it attached to the terminal:

   ```powershell
   ./scripts/dev.ps1 logs telegram
   ./scripts/dev.ps1 telegram
   ```

   Only one poller at a time: a second one makes Telegram answer **409 Conflict**
   to whichever long poll arrives second. `dev.ps1 telegram` therefore attaches to
   the Compose service instead of starting a rival process.

3. Send `/start` to your bot. While `TELEGRAM_ALLOWED_CHAT_IDS` is empty the bot
   answers with your chat id and nothing else, so paste it in:

   ```env
   TELEGRAM_ALLOWED_CHAT_IDS=123456789
   ```

4. `./scripts/dev.ps1 restart`, then verify:

   ```powershell
   ./scripts/dev.ps1 artisan telegram:status
   ```

| Command | Purpose |
| --- | --- |
| `./scripts/dev.ps1 up` | Starts the stack **including the poller**, as the `telegram` Compose service. |
| `./scripts/dev.ps1 logs telegram` | Follow just the bot's output. |
| `php artisan telegram:poll` | The long-poll worker itself. `--once` processes what is pending and exits; `--timeout=` overrides the 25 s poll. |
| `php artisan telegram:status` | Configuration, chats seen so far, webhook state, last error. `--webhook-off` unregisters a webhook. |

### Security

`TELEGRAM_ALLOWED_CHAT_IDS` is not optional. This module can create **and delete**
projects, modules and tasks, so the authorisation check fails closed: with an
empty list nobody is served, and a message from an unlisted chat is ignored and
logged as a `warning`. While the list is empty the bot replies to strangers with
nothing but their own chat id â€” the only way to learn yours without a debug
console, and harmless once the list is filled in, because that branch is then dead.

### Behaviour worth knowing

| Message | Routed to | Why |
| --- | --- | --- |
| Ends with `?`, or starts with `apa` / `berapa` / `status` / `list` / â€¦ | `summarize_board` (read-only) | The full-control agent runs with `tool_choice: required`, so it **always** returns a mutation. A question must never reach it. |
| Starts with `sinkron` / `sync` / `refresh` | `trigger_sync` | Queues the Google Sheets ingestion job. |
| `/start`, `/help`, `/id` | Onboarding only | Everything else is meant to be said in plain language. |
| Anything else | The full-control agent | The same 14 workspace tools and the same validation as the palette. |

Other guarantees:

- **Every update is stored before it runs.** `telegram_messages.update_id` is
  unique, so a redelivery after an interrupted poll is a no-op â€” and the table
  doubles as the long-poll cursor (`max(update_id) + 1`), surviving a restart
  without a second store.
- **Replies are plain text.** Model output regularly contains `*`, `_` and
  backticks, and one malformed entity makes Telegram reject the whole message;
  answers longer than the 4096-character limit are split on a newline.
- **A failed instruction still gets a reply** and is recorded as `failed`, so
  `telegram:status` shows the last error instead of failing silently.
- **No token is harmless.** `telegram:poll` exits 0, and the service is declared
  `restart: on-failure` â€” not `always` â€” precisely so an intentional exit 0 leaves
  the container stopped instead of hot-looping. A genuine crash (exit 1) is still
  restarted.

---

## Deploying to Koyeb

1. Push this repository to GitHub.
2. In Koyeb, **Create Service â†’ GitHub**, pick the repo.
3. **Builder:** Dockerfile. **Port:** `8080`. **Health check path:** `/up`.
4. Add environment variables (mark secrets as *secret*):

   ```
   APP_NAME=Omnitrack
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=base64:...            # generate locally
   APP_URL=https://your-app.koyeb.app
   APP_TIMEZONE=Asia/Jakarta
   LOG_CHANNEL=stderr

   DB_CONNECTION=pgsql
   DB_URL=postgresql://...?sslmode=require

   QUEUE_CONNECTION=database
   CACHE_STORE=database
   SESSION_DRIVER=database
   SESSION_SECURE_COOKIE=true

   DEEPSEEK_API_KEY=sk-...
   HARNESS_SECRET_TOKEN=...
   GOOGLE_SHEET_ID=1lgivxc5ZnctCyX9swahxWjZMiq2tl7JSOEHvJ2Dxktw

   TELEGRAM_BOT_TOKEN=123456789:AA...
   TELEGRAM_ALLOWED_CHAT_IDS=123456789
   ```

5. Migrations run automatically on boot (`RUN_MIGRATIONS=true`).

The Telegram poller needs one extra step here: it runs as a **Compose service**
locally, and Koyeb runs only the image. Add it as a fourth Supervisor program
before deploying, or the bot will not run there. `docker/supervisord.conf` carries
a commented-out block with the exact settings, including why `startsecs=0` and a
`stopwaitsecs` above the 25 s long poll matter.

### Google credentials on Koyeb

The container filesystem is ephemeral. Two options:

- **Secret file** mounted at `/var/www/html/storage/app/google/credentials.json`
  (recommended), or
- set `GOOGLE_APPLICATION_CREDENTIALS` to wherever you mount the JSON.

### Scheduling the sheet sync

`routes/console.php` schedules `SyncGoogleSheetJob` hourly. To actually run it,
add the scheduler as a fourth Supervisor program, or add a program running:

```
php /var/www/html/artisan schedule:work
```

---

## Project layout

```
app/
  Console/Commands/ TelegramPollCommand, TelegramStatusCommand
  Enums/          ItemStatus, ItemPriority, AiActionType, TelegramMessageStatus
  Http/
    Controllers/  BoardController, AiCommandController
    Controllers/Api/  HarnessController
    Middleware/   VerifyHarnessToken
  Jobs/           SyncGoogleSheetJob
  Models/         SheetSource, Project, ProjectModule, Task, AiPromptTemplate,
                  TelegramMessage, User
  Services/       GoogleSheetService, DeepSeekService, AiOrchestrator,
                  TelegramService, TelegramBot
database/
  migrations/     ULID-keyed domain tables + jobs/cache/sessions + telegram_messages
  seeders/        the single login account only -- see DatabaseSeeder's comment
  factories/
docker/           nginx.conf, supervisord.conf, php.ini, entrypoint.sh
public/
  css/omnitrack.css   STYLEGUIDE implementation
  js/                 core.js, palette.js, board-projects.js, board-tasks.js
resources/views/  layouts/app.blade.php, projects/, tasks/
tests/            Feature + Unit
```

## Data model

```
sheet_sources 1â”€â”
                â””â”€< projects 1â”€â”€< project_modules
tasks                          (standalone)
ai_prompt_templates            (palette presets)
telegram_messages              (bot audit log + long-poll cursor)
```

All primary keys are **ULID** (`HasUlids`), which keeps rows sortable by creation
time and avoids collisions when agents insert records offline.

`projects` has a composite unique index on `(sheet_source_id, sheet_row_index)`
â€” that is the reference key used by `upsert()` during ingestion.

---

## Testing

The production image deliberately excludes dev dependencies, so PHPUnit is not
available inside the running container. Tests run against the `testing` Docker
target, which is the production runtime plus dev dependencies:

```bash
docker compose -f docker-compose.test.yml build
docker compose -f docker-compose.test.yml run --rm --no-deps --entrypoint php test artisan test
```

`phpunit.xml` points at an in-memory SQLite database, so no database service is
needed. Tests must never reach external APIs; the suite runs with
`DEEPSEEK_API_KEY` empty precisely to prove the fallbacks work.

For day-to-day work the dev overlay is faster than the CI-shaped file above:
`./scripts/dev.ps1 test` runs the same suite with the source bind-mounted and no
rebuild, and a single test costs 2.7 s through the overlay against 4.0 s through
`docker exec`, so pass `--filter=` liberally.

Current status: **116 passed, 0 failed (517 assertions)**. The suite is green â€” the
last pre-existing failures were fixed alongside the bulk-CRUD work: the reorder
tests were failing on `upsert()`, the board tests lacked `actingAs()`, and two
assertions were stale against deliberate application decisions. See the testing
note in `AGENTS.md`.

The suite covers:

- Harness token enforcement (missing, wrong, valid) plus a fail-closed 503 when
  `HARNESS_SECRET_TOKEN` is unset
- Harness project upsert, batch module creation, and full task CRUD
- Status/priority normalization, including Indonesian values
- Graceful degradation with missing Google credentials (asserted via `Log::spy`)
- Upsert create / no-op / update semantics, and proof that manually created
  projects are never touched by a sheet sync
- Page rendering, palette presence, and local board CRUD endpoints
- The Telegram bot, entirely through `Http::fake`: the authorisation gate, the
  onboarding reply, natural-language execution, sync routing, and that a
  redelivered update and a read-only question can neither duplicate nor mutate

---

## Notable implementation decisions

Things that differ from the PRD as written, and why:

| PRD | Implemented | Reason |
| --- | --- | --- |
| `CMD ["/usr/bin/supervisord", ...]` | `entrypoint.sh` â†’ supervisord | Gives an idempotent first boot: directories, `APP_KEY`, config cache, and migrations with retries for a slow managed Postgres |
| `command=docker-php-fpm` | `php-fpm --nodaemonize` | Alpine's PHP image has no `docker-php-fpm` binary; that program would crash-loop |
| `stdout_logfile=/dev/stdout` for all programs | Same, plus `logfile=/dev/null` for supervisord | Supervisor writing its own log to a `/dev/stdout` *file* conflicts with nginx |
| `apk del` dev packages in one stage | Separate `extensions` build stage | Deleting `libpng-dev` also removed the `libpng` runtime library, silently breaking the `gd` extension. The multi-stage build never removes runtime libs |
| Reverb (WebSocket) | Database queue + polling refresh | Single-user personal tool; Reverb would need a second always-on process and its own port on a free host. Nothing in the PRD's feature list requires push |
| Tailwind CDN or Node build | Hand-authored CSS | Deterministic, offline, no build step. Token names mirror the STYLEGUIDE Tailwind config so a future build is a drop-in swap |
| Laravel 13 on PHP 8.3 | `config.platform.php = 8.3.35` in composer.json | Laravel 13 permits `symfony/* ^7.4 \|\| ^8.0`, and Symfony 8 requires PHP 8.4. Without the platform pin, Composer resolved Symfony 8.1 and the container crash-looped on `platform_check.php` |

---

## Security notes

- `.env` is git-ignored; `.env.example` carries no secrets.
- `storage/app/google/.gitignore` excludes everything but its README, so
  credentials can never be committed.
- `.dockerignore` excludes `.env`, `tests`, and `storage/app/google/*.json` from
  the image build context.
- Nginx explicitly denies `/storage/app/google/` and all dotfiles.
- Harness tokens are compared with `hash_equals()` (constant time).
- Rotate `HARNESS_SECRET_TOKEN` and `DEEPSEEK_API_KEY` if they are ever exposed.
