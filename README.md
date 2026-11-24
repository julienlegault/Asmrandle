# Asmrandle

A daily Magic: The Gathering Commander card-popularity game, using Scryfall
card data and EDHRec inclusion statistics.

## Development

Open the repository in a GitHub Codespace or VS Code dev container; no manual
steps are needed. The container starts MySQL, installs dependencies, runs
migrations, seeds an empty database and serves Laravel on port 8000. Seeding is
offline and takes seconds: every card in `backend/data/formatted_card_list.js`
is inserted with **fake**, deterministic EDHRec values, and images load from
Scryfall in the browser. Existing data is not reseeded on restart. See
`.devcontainer/README.md` for details.

Open the forwarded port to play. The **Fast Mode** switch in the top-left is available only
when Laravel's `APP_ENV=local`; it changes the card reveal delay from 2800 ms
to 200 ms. Production never exposes it.

```sh
npm test                              # Browser tests; see below
php backend/artisan test              # Backend tests
npm run update-cards                  # Optional: import real Scryfall cards (needs network, slow)
```

The browser tests use the development server at `http://localhost:8000` and
write test results to its database. To run them against a disposable SQLite
database instead of the development MySQL
database, configure `APP_ENV=local`, `DB_CONNECTION=sqlite`, `DB_DATABASE` as
an absolute path to an empty SQLite file, and run migrations followed by
`php backend/artisan db:seed --class=BrowserTestSeeder`, then start Laravel on
port 3000 and run `ASMRANDLE_BASE_URL=http://localhost:3000 npm test`. The
browser test seeder must never be used for production data.

## Architecture

- `frontend/`: HTML, game UI and public assets.
- `backend/`: Laravel API, MySQL migrations, card importers and scheduling.
- `backend/data/formatted_card_list.js`: legacy slug list used to match
  double-faced cards to existing EDHRec pages; it is not served to browsers.

The API persists one snapshot of 20 cards per daily challenge, and returns
only the current pair. The challenge and community statistics roll over at
06:00 UTC. Each press of Practice selects a new random snapshot of 20 eligible
cards without replacement; the snapshot stays fixed for that game. Community results are
stored in MySQL; historical CounterAPI counts are not automatically imported.
Daily-result cookies created before this migration cannot reproduce the old
client-generated puzzle.

## Admin dashboard

After running migrations, create or update an admin account with
`php backend/artisan admin:set USERNAME`. Enter and confirm the password at
the hidden prompts; use 12-72 bytes. Updating a password invalidates existing
sessions for that account. Visit `/admin` to log in and view the dashboard.
Login attempts are rate-limited. Serve the site over HTTPS in production and
set `SESSION_SECURE_COOKIE=true`; use shared database-backed sessions and
cache across instances.

Counts represent completed submissions, not games started. All-time totals
include only results recorded by this backend (not historical CounterAPI
counts). "Today" follows the game's 06:00 UTC rollover. The graph shows daily
score distributions because practice scores are not stored. Daily card
percentages come from the challenge snapshot; answer percentages are based on
completed results in each mode.
Scores Shared counts daily runs whose scores were successfully copied and
recorded; practice scores cannot be shared. Older results only record copies
of the first perfect score, so historical sharing totals are incomplete.

Admins can schedule a seeded daily run by selecting a game date, 20 distinct
playable cards in round order, a site logo (standard or pride), and optionally
a sequence of exactly 10 emojis for perfect-score shares. The selected logo
applies to the entire site for that game day, including practice mode. The dashboard lists saved seeded runs and supports
editing, deleting, and copying them to a new date. Card statistics are
snapshotted when saved. Add cards through the picker or fill empty slots with
random playable cards, then drag their images to swap positions (or use the
move buttons). The displayed percentages are the current EDHRec values that
will be snapshotted. A run cannot be edited or deleted after it starts or its
game date passes, so all players see the same challenge.

The admin browser test requires the disposable SQLite setup above, plus an
admin account created with `php backend/artisan admin:set USERNAME` using the
same database settings. Run it with `ASMRANDLE_BASE_URL`,
`ASMRANDLE_ADMIN_TEST_USERNAME`, and `ASMRANDLE_ADMIN_TEST_PASSWORD` set;
it creates and deletes runs dated 2099-12-30 and 2099-12-31.

## Data refresh and Google Cloud

The production scheduler runs `cards:import` on the first of each month at
02:00 UTC and `cards:refresh-edhrec` daily at 07:00 UTC. The latter makes one
request per legal card with a 200 ms delay; a full run can take hours. It saves
progress per card so a failed run can be resumed with the same command. HTTP
errors fail the command rather than replacing good data. Previously created
daily snapshots are never modified by refreshes.

The root `Dockerfile` builds the web image for Cloud Run. Configure a persistent
Cloud SQL MySQL database, `APP_ENV=production`, `APP_DEBUG=false`, a securely
generated `APP_KEY`, and the appropriate `DB_*`/`DB_SOCKET` settings via Secret
Manager or environment configuration. Set `CACHE_STORE=database` so scheduler
locks are shared across instances. Run `php backend/artisan migrate --force`,
then `php backend/artisan cards:import` and
`php backend/artisan cards:refresh-edhrec` before serving traffic. Production
has no synthetic popularity data and needs at least 20 eligible cards.

Laravel's schedule still needs an external trigger: on a VM run
`php /path/to/backend/artisan schedule:run` every minute via cron. For Cloud
Run, configure Cloud Scheduler to execute a Cloud Run Job using the same image
and database settings, overriding its command to
`php backend/artisan schedule:run` every minute. Give the job a timeout long
enough for a complete EDHRec refresh and monitor failures. Creating the Google
Cloud resources, secrets, IAM permissions and deployment is not automated by
this repository.

CI runs the backend and browser tests but does not deploy to GitHub Pages:
the application now requires a running backend.
