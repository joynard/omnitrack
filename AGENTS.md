# Agent instructions -- Omnitrack Engine

Laravel 13 / PHP 8.3 API. Docker Compose for local dev, Postgres in production,
Google Sheets sync and a DeepSeek-backed command palette.

User-global rules (communication style, code standards, context hygiene) live in
`~/.dsh/AGENTS.md` and are injected before this file. Do not repeat them here.

## Environment facts (verified, do not re-discover)

| Fact | Value |
|---|---|
| Host PHP | XAMPP **8.2.12** at `D:\xampp\php\php.exe` |
| Project PHP requirement | **`^8.3`** (`composer.json`) |
| Host PHP usable for this app? | **No.** Laravel 13 needs 8.3, host has 8.2 |
| Host Node / npm | v24.15.0 / 11.12.1 -- usable |
| Frontend toolchain | **None.** No `package.json`, no Vite, no `node_modules` |
| Container PHP | 8.3-fpm-alpine (nginx + php-fpm + supervisor in one image) |
| Docker Compose | v5.1.3, `develop.watch` supported |
| Compose project name | `omnitrack` -- from `COMPOSE_PROJECT_NAME` in `.env`, not derived |
| Git remote | `origin` = `https://github.com/joynard/omnitrack.git`, branch `main` |
| Built images | `omnitrack-engine:local` and `omnitrack-engine:test` |
| Containers | `omnitrack-app` (8080) and `omnitrack-db` (5433) |
| Host disk | C: 30 GB free of 477 GB **-- tight**; D: 1.36 TB free |

Every `php` command runs **inside the container**: there is no host PHP path for
`artisan`, `phpunit` or `composer` (wrong version, and XAMPP has no `pdo_pgsql`).

**Do not touch the Docker/WSL resource configuration.** Docker Desktop's own WSL
distro is the supported setup; `~/.wslconfig` must not be created and memory/CPU
limits must not be tuned by an agent.

## Commands -- always use these

`scripts/dev.ps1` (Windows) and `scripts/dev.sh` (POSIX) wrap the two-file
Compose invocation. Use them instead of typing `-f` flags.

```powershell
./scripts/dev.ps1 up                    # start app + db, source bind-mounted
./scripts/dev.ps1 test                  # PHPUnit suite, no rebuild
./scripts/dev.ps1 test --filter=Harness # one test
./scripts/dev.ps1 artisan migrate
./scripts/dev.ps1 composer require foo/bar
./scripts/dev.ps1 down                  # add -v to drop named volumes
```

Raw equivalent; `--profile test` is only for `run`/`down`/`ps`/`exec`, never `up`:

```
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
docker compose -f docker-compose.yml -f docker-compose.dev.yml --profile test run --rm --pull never test php artisan test
```

A single test is faster through the overlay service than through `docker exec`
(measured: 2.7 s vs 4.0 s), so always pass `--filter=`.

## Build and iteration cost -- respect this

`Dockerfile` compiles PHP extensions (gd, intl, zip, pdo_pgsql, pcntl). A cold
build is **minutes**. Structure every change so it does not need one.

| Change | Rebuild needed? |
|---|---|
| Anything under `app/`, `routes/`, `config/`, `tests/`, `resources/` | **No** |
| `docker/php.dev.ini` (mounted as `zz-dev.ini`) | **No** |
| `.env` | **No** -- but restart the container so FPM re-reads it |
| `composer.json` / `composer.lock` | No rebuild; `dev.ps1 composer install` |
| `Dockerfile`, `docker/nginx.conf`, `docker/entrypoint.sh` | **Yes** -- `dev.ps1 build` |

`docker-compose.dev.yml` bind-mounts the source, so editing a file is enough.
Keep `vendor/` and `node_modules/` in named volumes, never bind-mounted: a
`vendor/` on NTFS means tens of thousands of small reads across the 9p boundary.

## Testing

- PHPUnit 12 on **in-memory SQLite**, so no database service is needed.
- Run the suite after every change and expect **green**. A red suite is evidence
  your change broke something; the 16 historical failures (auth-less board tests,
  an `upsert()`-based reorder, two stale assertions) are fixed and documented only
  in the git log.

## Known traps

- **`APP_ENV=production` in the dev stack is dangerous:** the entrypoint then runs
  `config:cache` / `route:cache`, baking current env values into the container.
- **PHP edits that "do not apply" mean an ini ordering problem, not a stale
  mount.** `conf.d` loads alphabetically and the image ships `99-omnitrack.ini`,
  so the dev overlay must mount `zz-dev.ini` (never `99-dev.ini`). Symptom:
  `artisan route:list` sees new code while HTTP does not.
- **`.env` holds the real database password, `DEEPSEEK_API_KEY` and
  `HARNESS_SECRET_TOKEN`; never commit it.** `.gitignore` covers `.env`,
  `.env.backup`, `.env.production`, `auth.json` and `storage/app/google/*.json`.
- **Quote every `.env` value containing a space**, or phpdotenv rejects the whole
  file and every `artisan` command fails with a bare non-zero exit.
- **Module status drives project status, and mass updates bypass model events.**
  `ProjectModule::booted()` rolls the parent up on a single-row save or delete
  (all modules done -> done; anything open -> a project claiming done reopens as
  in_progress; pending vs in_progress stays the user's call). `ProjectModule::query()->...->update()`
  / `->delete()` fire NOTHING, so every bulk path must call
  `Project::syncStatusesFor($projectIds)` itself, with the ids captured BEFORE the
  write. Forgetting it is silent: the checklist moves, the status does not.
- **A code change to a job does not reach the running queue worker.** The worker is
  a long-lived process holding the old class definitions, so a fixed
  `SyncGoogleSheetJob` path keeps behaving like the old one until it is restarted:
  `docker exec omnitrack-app pkill -f 'artisan queue:work'` and supervisord brings it
  back within seconds. Symptom seen once: the sheet ingest kept writing a `done`
  status over an open checklist after the fix that stopped it.
- **Never export `COMPOSE_PROJECT_NAME`.** An exported value overrides `.env` and
  silently creates a second, empty set of volumes.
- **`--profile test` starts that profile's services.** Use profiles for `down`,
  `ps`, `exec` and `run`; never for `up`, `build` or `restart`.
- **`docker compose down` deletes containers and the network**, and `docker ps`
  hides stopped containers unless "Show stopped containers" is enabled. Use
  `stop` to merely halt.
- **Do not run `docker system prune -a --volumes` casually**: the Docker data disk
  holds images for other projects (`tatakeloladmaic`). Prefer `docker builder prune`.

## Production readiness (Koyeb)

| Gap | Detail |
|---|---|
| Scheduler | `routes/console.php` schedules `SyncGoogleSheetJob` hourly, but `docker/supervisord.conf` has no `schedule:work`, so it never fires |
| Seeding | `docker/entrypoint.sh` runs `migrate --force` only. A fresh instance has no login until `php artisan db:seed --force` with `OMNITRACK_USER_NAME` / `_EMAIL` / `_PASSWORD` set |
| CI | None, although the `testing` Dockerfile target exists for exactly that |
| Deployed? | No. Nothing here has run with `APP_ENV=production` |

## Second Brain (Obsidian vault)

| Fact | Value |
|---|---|
| Host vault | `D:\Alexander Files\Alexander\Obsidian Vaults\Second Brain` |
| Container mount | `/vault`, **read-only**, in `docker-compose.dev.yml` for `app` and `telegram` |
| Env | `OBSIDIAN_VAULT_HOST_PATH` (Compose), `OBSIDIAN_VAULT_PATH` (default `/vault`), `OBSIDIAN_VAULT_NAME` |
| Code | `app/Services/ObsidianVaultService.php`, `VaultController`, `resources/views/vault/`, `public/js/vault.js` |
| Surfaces | UI `/vault`; agents `GET /api/harness/vault`, `/notes`, `/search`, `/note?path=` (all GET) |
| Scripts | `scripts/vault-notes.ps1` (pull: writes notes; `-Only <Area\|Project>` refreshes one note in 1 s with the cached layout, a full run is 72 s); `scripts/vault-sync.ps1` (report; `-Apply` pushes ticks) |
| Tests | `tests/Feature/ObsidianVaultTest.php` against a throwaway vault |

Nothing about the vault is stored in Postgres: a note is a file. The service
scans on demand and degrades to `available: false` when the mount is absent, which
is the production case. `resolve()` is the security boundary: reject absolute
paths, `..` or hidden segments, non-`.md`, and anything outside the vault after
`realpath()`. Do not "simplify" it into `file_get_contents($vault.'/'.$path)`.

Two invariants keep that bridge honest, both learned the hard way on 12 Oct 2026:
the markers must stand ALONE on their own line (Home's own prose quotes them as inline
code, and a parser that accepts them anywhere swallows the real block, which destroyed
Home's keep content on every run), and every block read is journalled to
`~/.dsh/cache/omnitrack-keep.json` before the first deletion, so a run that dies
mid-way no longer takes hand-written prose with it.
Note bridge rules: generated notes carry `generated: vault-notes` and are the only
thing the generator replaces; hand-written text lives between
`<!-- omnitrack:keep -->` markers, keyed by the heading above it, and is read back
into a cache **before** cleanup deletes the files; the module checklist block is
always written from this app (pull), and `vault-sync.ps1 -Apply` is the push.

`D:\Projects\AGENTS.md` and the user skill `~/.dsh/skills/second-brain/SKILL.md`
describe the same integration outside this repository on purpose.

## Conventions

- Explain the reason, not the syntax: every non-obvious decision carries a `WHY`.
- 4-space indentation in YAML, PHP and JSON; `.editorconfig` governs the rest.
- No frontend build step exists. Do not add one without being asked.
