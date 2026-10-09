# Agent instructions -- Omnitrack Engine

Laravel 13 / PHP 8.3 API. Docker Compose for local dev, Postgres in production,
Google Sheets sync and a DeepSeek-backed command palette.

## Working style: minimise round trips

These rules exist because a previous session spent **16 tool calls across 8
sequential rounds** answering a question that needed two. The machine was idle
the whole time (CPU 4-12%, disk latency 0.4 ms) -- the cost was entirely
self-inflicted. Do not repeat it.

1. **Form a hypothesis before the first command.** State what you expect and
   what would falsify it. Do not "gather data and see".
2. **One reconnaissance round.** Every independent command goes into a *single*
   message as parallel calls. Never read files one at a time across rounds.
3. **Budget: 4 tool calls for a diagnostic question, 8 for a code change.**
   Exceeding it means the approach is wrong, not that more calls are needed.
   Exceeding it twice in one task: stop and say so.
4. **Batch commands inside one `pwsh` call.** A fresh PowerShell process costs
   ~0.5 s of startup; eleven separate calls paid it eleven times. Use
   `Write-Output '=== section ==='` separators instead of new invocations.
5. **Validate shell syntax before sending it.** Two calls in that session died
   on nested-quote breakage inside a `docker inspect --format` template. For any
   Docker `--format` containing quotes, prefer `--format '{{json .Field}}'` piped
   to `ConvertFrom-Json`, or drop to `--format` with no nested quotes at all.
6. **Never re-run a command whose result is already in context.** That session
   searched for the same VHDX file twice.
7. **No subagents in this repository.** The context is small enough to hold;
   a fork re-serialises the whole conversation for no benefit.
8. **Report, do not narrate.** No progress commentary between tool calls. The
   final answer carries the reasoning.
9. **`todo_write` for any task of three or more steps**, so completed work is
   visible and cannot be silently repeated.

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
| Compose project name | `omnitrack` -- set by `COMPOSE_PROJECT_NAME` in `.env`, not derived |
| Git remote | `origin` = `https://github.com/joynard/omnitrack.git`, branch `main` |
| Built images | `omnitrack-engine:local` and `omnitrack-engine:test`, both present |
| Containers | `omnitrack-app` (8080) and `omnitrack-db` (5433), both healthy when up |
| Host disk | C: 30 GB free of 477 GB **-- tight**; D: 1.36 TB free |
| Docker data disk | `C:\Users\alexa\AppData\Local\Docker\wsl\disk\docker_data.vhdx` (79 GB) |

**Do not touch the Docker/WSL resource configuration.** Docker Desktop's own WSL
distro is the supported setup here; `~/.wslconfig` must not be created and
memory/CPU limits must not be tuned by an agent. WSL's resource management is
left entirely to Docker Desktop as shipped.

**Consequence:** every `php` command runs **inside the container**. There is no
host PHP path for `artisan`, `phpunit` or `composer`. Do not attempt
`php artisan ...` on the host; it fails on the version constraint, and even if
the version matched, `pdo_pgsql` is absent from XAMPP.

## Commands -- always use these

`scripts/dev.ps1` (Windows) and `scripts/dev.sh` (POSIX) wrap the two-file
Compose invocation. Use them instead of typing `-f` flags:

```powershell
./scripts/dev.ps1 init                  # once: .env check + vendor/ into its volume
./scripts/dev.ps1 up                    # start app + db, source bind-mounted
./scripts/dev.ps1 test                  # PHPUnit suite, no rebuild
./scripts/dev.ps1 test --filter=Harness # one test
./scripts/dev.ps1 artisan migrate
./scripts/dev.ps1 tinker
./scripts/dev.ps1 composer require foo/bar
./scripts/dev.ps1 logs app
./scripts/dev.ps1 down                  # add -v to drop named volumes
```

Raw equivalent, if a script is unavailable -- and note that `--profile test` is
only needed for `run`/`down`/`ps`/`exec`, never for `up`:

```
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
docker compose -f docker-compose.yml -f docker-compose.dev.yml --profile test run --rm --pull never test
```

## Build and iteration cost -- respect this

`Dockerfile` compiles PHP extensions (gd, intl, zip, pdo_pgsql, pcntl) in a
throwaway stage. A cold `docker compose build` is **minutes**. Structure every
change so it does not need one.

| Change | Rebuild needed? |
|---|---|
| Any file under `app/`, `routes/`, `config/`, `tests/`, `resources/` | **No** |
| `docker/php.dev.ini` | **No** -- re-read per request (`validate_timestamps=1`) |
| `.env` | **No** -- but restart the container so FPM re-reads it |
| `composer.json` / `composer.lock` | No rebuild; run `dev.ps1 composer install` |
| `Dockerfile`, `docker/nginx.conf`, `docker/entrypoint.sh` | **Yes** -- `dev.ps1 build` |

`docker-compose.dev.yml` bind-mounts the source tree, so editing a file is
enough. This is the single most important efficiency property of the repo.

**Keep `vendor/` and `node_modules/` in named volumes, never bind-mounted.**
They live on the Linux filesystem inside WSL. A `vendor/` on NTFS means tens of
thousands of small reads crossing the 9p boundary on every request.

## Testing

- PHPUnit 12. Tests run on **in-memory SQLite** (`phpunit.xml`), so no database
  service is required and `dev.ps1 test` works with `db` stopped.
- `.env.testing` must exist or Laravel emits a `file_get_contents()` warning
  that PHPUnit 12 reports as a warning-level result.
- The `test` service uses the `testing` Dockerfile target -- the runtime image
  plus dev dependencies. The production image ships without PHPUnit on purpose.
- Run the suite after every change. It is the fastest verification available and
  costs no rebuild.

### Known pre-existing test failures: 33 pass, 16 fail

The suite does **not** pass on a clean checkout. This is an application test gap,
not a harness problem, and it predates the dev overlay. Do not treat a red suite
as evidence that your change broke something -- compare against this list.

Root cause: `routes/web.php` wraps `/` and everything under `/projects`,
`/board/*`, `/ai/*` and `/sync/*` in `Route::middleware('auth')`, but
`WebBoardTest`, `WorkspaceControlTest` and part of `HarnessApiTest` issue
requests **without** `actingAs()` or a seeded user. The app therefore redirects
to `/login` (302) or answers 401, and the assertions fail on that instead of on
their actual subject.

Two secondary causes in the same group:

- `project_modules.project_id` and `tasks.title` arrive null in reorder tests,
  because the models under test are inserted with only `order_index` set.
- `HarnessApiTest` reorder endpoints answer 422 -- the request payload does not
  satisfy the validator.

Groups that genuinely pass: `Unit\EnumNormalizationTest`,
`Feature\GoogleSheetServiceTest`, and the token-authenticated harness tests.

Fixing these means adding `actingAs()` and factory attributes to the tests. That
is application work, and it has not been done -- do not report the suite as
green.

## Known traps

- **`APP_ENV=production` in the dev stack is dangerous.** The entrypoint then
  runs `config:cache` and `route:cache`, baking current env values into the
  container so later `.env` edits silently stop taking effect.
- **OPcache in the image has `validate_timestamps=0`** (correct for production).
  `docker/php.dev.ini` overrides it to `1` with `revalidate_freq=0`. If edits
  ever appear not to apply, check that this file is still mounted.
- **`.env` has `DB_CONNECTION=sqlite`** for host/legacy use and
  `DB_DATABASE_PGSQL=omnitrack`. Compose injects the real Postgres settings and
  those environment values win over `.env`. Do not "fix" `.env` to match.
- **Quote every `.env` value containing a space.** phpdotenv rejects an unquoted
  space with `The environment file is invalid!` and refuses to load *any* of the
  file. The failure surfaces as a bare non-zero exit from `artisan`, including
  the `package:discover` hook that runs after `composer install`, so it looks
  like a Composer problem when it is not. `OMNITRACK_USER_NAME` was the offender
  (fixed to `"Alexander Fabian"`); values like `APP_NAME=Omnitrack` are fine
  because they have no space.
- **Do not run `docker system prune -a --volumes` casually.** The Docker data
  disk holds images for other projects (`tatakeloladmaic`, from
  `D:\Projects\PT SPIL\Tata kelola DMAIC`). Prefer `docker builder prune`.
- **Check the Compose project name before acting on containers.** It is
  `omnitrack`, from `COMPOSE_PROJECT_NAME` in `.env`. Without that variable
  Compose derives the project from the directory name, and this directory is
  `Projects & Event Management` -- which yields `projectseventmanagement` and
  makes the stack nearly impossible to find in the Docker Desktop UI, since the
  UI groups by project name and ignores every `container_name`. Never export
  `COMPOSE_PROJECT_NAME` from a script: an exported value overrides `.env` and
  silently creates a second, empty set of volumes (`omnitrack_*` vs
  `projectseventmanagement_*`), which is exactly what happened once already.
- **Enabling a Compose profile also starts that profile's services.** Passing
  `--profile test` to `up` boots `omnitrack-test-1`, which then runs
  `php artisan test` under the dev entrypoint and exits 2. Use profiles for
  `down`, `ps`, `exec` and `run`; never for `up`, `build` or `restart`. The dev
  scripts encode this (`Get-DevArgs` in dev.ps1, `dc_test` vs `dc_run` in
  dev.sh).
- **`docker compose down` deletes containers and the network, not just stops
  them.** After a `down` the stack is absent from `docker ps -a` entirely, which
  is the difference between "not running" and "gone". Use `stop` to merely halt.
- **`docker ps` hides stopped containers.** Docker Desktop shows only running
  containers unless "Show stopped containers" is enabled, so a stopped
  `omnitrack-app` is invisible in both places.

## Production readiness -- what is and is not wired up

The runtime image is production-shaped: multi-stage `Dockerfile` with a frozen
`runtime` target, production OPcache in `docker/php.ini`, nginx + php-fpm +
queue-worker under supervisor, migrations-with-retry in `docker/entrypoint.sh`,
and an unauthenticated `GET /up` health check for Koyeb. `README.md` documents
the Koyeb steps and the full production variable list.

Verified gaps as of the initial commit:

1. **The git remote exists and `main` is pushed.** `origin` is
   `https://github.com/joynard/omnitrack.git`, and the initial history is on the
   remote. A deploy can therefore start; it has not been started.
2. **The scheduler is not running in production.** `routes/console.php`
   schedules `SyncGoogleSheetJob` hourly, but `docker/supervisord.conf` defines
   only php-fpm, nginx and queue-worker. There is no `schedule:work` program, so
   hourly sheet sync never fires. `README.md` acknowledges this.
3. **The database is never seeded on deploy, so a fresh production instance is
   unusable.** `docker/entrypoint.sh` runs `migrate --force` and nothing else.
   `DatabaseSeeder` is the only thing that creates the single login account, the
   starting categories, the `SheetSource` row and the six `AiPromptTemplate`
   presets -- so without it there is no account to log in with, and the Ctrl+P
   command palette is empty. The README's Koyeb section does not mention
   `db:seed`, nor the `OMNITRACK_USER_*` variables the seeder reads.
4. **No CI.** The `testing` Dockerfile target exists for exactly this purpose
   but nothing invokes it. Note the suite is currently 16 failing (see above),
   so a naive CI job would be red on arrival.
5. **Not deployed.** Nothing in this repository has been run with
   `APP_ENV=production`; all verification to date is local-only.

### Deploying: the seeding step the README omits

Required variables for the seeder, none of which appear in the README list:

```
OMNITRACK_USER_NAME=...
OMNITRACK_USER_EMAIL=...
OMNITRACK_USER_PASSWORD=...
```

Then, once, against the running instance:

```
php artisan db:seed --force
```

The seeder is idempotent: `firstOrNew`/`firstOrCreate` everywhere, and it only
resets the password when `OMNITRACK_USER_PASSWORD` is non-empty. It also deletes
every account whose email differs from `OMNITRACK_USER_EMAIL` -- intentional for
a single-user tool, but it means changing that variable locks out the old login.

If `OMNITRACK_USER_PASSWORD` is empty, the seeder still creates the user with a
random 16-character password and warns on stdout. That account can never be
logged into, so set the variable before seeding.

Never commit `.env`: it holds the real database password, `DEEPSEEK_API_KEY` and
`HARNESS_SECRET_TOKEN`. The first commit was audited and contains none of them,
and `.gitignore` covers `.env`, `.env.backup`, `.env.production`, `auth.json`
and `storage/app/google/*.json`. Re-audit with `git ls-files` after adding any
new configuration file.

## Conventions

- Match the existing comment density. Every non-obvious decision in `Dockerfile`,
  `docker-compose.yml` and `docker/entrypoint.sh` is explained with a `WHY`.
  Follow that: explain the reason, not the syntax.
- 4-space indentation in YAML, PHP and JSON; `.editorconfig` governs the rest.
- No frontend build step exists. Do not add one without being asked.
