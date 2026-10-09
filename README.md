# Omnitrack Engine

Personal workspace + task tracker. It keeps two deliberately isolated domains:

- **Projects** — large-scope work with sub-elements (**Modules / Todo**),
  optionally ingested from a Google Sheet.
- **Tasks** — ad-hoc personal checklist, independent of any project.

Plus a global **`Ctrl + P` AI command palette** backed by the DeepSeek API, and a
token-protected **`/api/harness/*`** bridge so DeepSeek Harness can drive the
workspace autonomously.

---

## Stack

| Layer | Choice |
| --- | --- |
| Runtime | PHP 8.3 (Alpine container) |
| Framework | Laravel 13 |
| Database | PostgreSQL (Neon / Supabase), ULID primary keys |
| Queue / Cache / Session | `database` driver — no Redis needed |
| Frontend | Blade + hand-authored CSS + vanilla JS (no Node build step) |
| Container | PHP-FPM + Nginx + Supervisor in one image |
| External | Google Sheets API v4, DeepSeek API |

### Why no Node/Tailwind build

The Laravel 13 skeleton no longer ships Vite by default, and this project only
needs one stylesheet. `public/css/omnitrack.css` implements `STYLEGUIDE.md`
directly using CSS custom properties whose names mirror the Tailwind token names
from the styleguide (`--canvas-light`, `--ink-primary`, `--brand-primary`, …).

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

### Database — Neon or Supabase

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

## AI Command Palette (`Ctrl + P`)

Press **`Ctrl + P`** (or **`Cmd + P`**) anywhere. **`Esc`** closes it; the input
is autofocused. There is no header button — the shortcut is the only entry point,
as requested.

**Full control (default action).** The palette uses DeepSeek *native tool
calling*, so the model returns a structured call instead of prose the app has to
parse. The model chooses one of 14 tools, and the app validates it before
touching the database. That covers:

| Area | Capabilities |
| --- | --- |
| Categories | create, rename, delete |
| Projects | create, update (including moving category), delete, reorder |
| Modules | batch create, update, delete, reorder within a project |
| Tasks | batch create, update, delete, reorder |

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

Projects can be filed under a category — *Kuliah*, *Kantor*, *Pribadi*, or
anything you add. The Projects page has a filter bar with per-category counts,
and a **Kelola Kategori** panel to add, rename, and delete categories.

Seeded starting categories: `Kuliah`, `Kantor`, `Pribadi`.

Deleting a category **never deletes its projects**: the foreign key is
`nullOnDelete`, so they simply become uncategorised.

---

## Reordering

Order is stored explicitly as `order_index` on projects, modules and tasks.

- **Drag a row** (projects and modules) or a task to reorder it.
- The new order is persisted as a **complete id list**, so the server never has
  to guess.
- Task drag is disabled while a status filter is active, because reordering a
  filtered subset would produce a partial list.
- All three reorder paths (AI, board UI, harness API) call one shared
  `reorderByIds()` helper, which skips foreign ids and closes any gaps.

---

## Sidebar

The sidebar can be collapsed and expanded:

- Click the **`‹`** control in the sidebar header, or press **`Ctrl/Cmd + B`**.
- The state is stored in `localStorage` and applied to `<html>` **before first
  paint**, so a collapsed rail never flashes open on load.
- On desktop the rail is removed from the layout and a floating **`›`** handle
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
| `GET` | `/api/harness/tools` | The 14 tool definitions, so an agent can discover the surface at runtime |
| `POST` | `/api/harness/agent` | Natural-language instruction handled by the full-control agent |
| `GET` | `/api/harness/categories` | List categories with project counts |
| `POST` | `/api/harness/categories` | Create a category |
| `POST` | `/api/harness/categories/{id}` | Rename a category |
| `DELETE` | `/api/harness/categories/{id}` | Delete a category (projects survive, unfiled) |
| `POST` | `/api/harness/projects` | Create a project, or update one by passing `id` |
| `POST` | `/api/harness/projects/{project}` | Update a project (`category_id`, `category_name`, …) |
| `DELETE` | `/api/harness/projects/{project}` | Delete a project and its modules |
| `POST` | `/api/harness/projects/reorder` | Reorder projects from a complete id list |
| `POST` | `/api/harness/projects/{id}/modules` | Batch-add modules to a project |
| `POST` | `/api/harness/projects/{id}/modules/reorder` | Reorder a project's modules |
| `POST` | `/api/harness/modules/{module}` | Update one module |
| `DELETE` | `/api/harness/modules/{module}` | Delete one module |
| `POST` | `/api/harness/tasks` | Create, update, or delete a task (`action`) |
| `POST` | `/api/harness/tasks/{task}` | Update one task |
| `DELETE` | `/api/harness/tasks/{task}` | Delete one task |
| `POST` | `/api/harness/tasks/reorder` | Reorder tasks from a complete id list |
| `POST` | `/api/harness/sync` | Queue a Google Sheet ingestion run |

All mutations are funnelled through `AiOrchestrator::callTool()`, so the palette,
the Blade UI, and this API share one validated implementation. Responses use the
shape `{status, message, data}`; validation failures return **422**, not 500.


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

## Deploying to Koyeb

1. Push this repository to GitHub.
2. In Koyeb, **Create Service → GitHub**, pick the repo.
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
   ```

5. Migrations run automatically on boot (`RUN_MIGRATIONS=true`).

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
  Enums/          ItemStatus, ItemPriority, AiActionType (with normalize())
  Http/
    Controllers/  BoardController, AiCommandController
    Controllers/Api/  HarnessController
    Middleware/   VerifyHarnessToken
  Jobs/           SyncGoogleSheetJob
  Models/         SheetSource, Project, ProjectModule, Task, AiPromptTemplate
  Services/       GoogleSheetService, DeepSeekService, AiOrchestrator
database/
  migrations/     ULID-keyed domain tables + jobs/cache/sessions
  seeders/        sheet source + palette templates
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
sheet_sources 1─┐
                └─< projects 1──< project_modules
tasks                          (standalone)
ai_prompt_templates            (palette presets)
```

All primary keys are **ULID** (`HasUlids`), which keeps rows sortable by creation
time and avoids collisions when agents insert records offline.

`projects` has a composite unique index on `(sheet_source_id, sheet_row_index)`
— that is the reference key used by `upsert()` during ingestion.

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

Current status: **26 passed (107 assertions)**.

The suite covers:

- Harness token enforcement (missing, wrong, valid) plus a fail-closed 503 when
  `HARNESS_SECRET_TOKEN` is unset
- Harness project upsert, batch module creation, and full task CRUD
- Status/priority normalization, including Indonesian values
- Graceful degradation with missing Google credentials (asserted via `Log::spy`)
- Upsert create / no-op / update semantics, and proof that manually created
  projects are never touched by a sheet sync
- Page rendering, palette presence, and local board CRUD endpoints

---

## Notable implementation decisions

Things that differ from the PRD as written, and why:

| PRD | Implemented | Reason |
| --- | --- | --- |
| `CMD ["/usr/bin/supervisord", ...]` | `entrypoint.sh` → supervisord | Gives an idempotent first boot: directories, `APP_KEY`, config cache, and migrations with retries for a slow managed Postgres |
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
