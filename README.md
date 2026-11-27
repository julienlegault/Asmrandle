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
to 200 ms. The adjacent date picker shows the selected run's welcome message
and previews the run when you press Play Daily; clear it to return to today's
message and play normally. Previews can be repeated,
do not record scores or lock scheduled runs, and report an error if no run
exists for the selected date. Practice ignores the picker. Production never
exposes these development controls.

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
Speedy Runs counts daily and practice games completed in under 60 seconds,
measured from the first matchup appearing to the final choice. Durations are
reported by the browser; older games without recorded durations are excluded
from the fastest and average duration statistics as well. Daily duration
statistics use the selected game day, including practice games completed
between 06:00 UTC and the following 06:00 UTC.

Admins can schedule a seeded daily run by selecting a game date, 20 distinct
playable cards in round order, a site logo (standard or pride), and optionally
a welcome message of up to 40 characters and a sequence of exactly 10 emojis
for perfect-score shares. The welcome message appears over the landing-page
logo for that game day. The selected logo
applies to the entire site for that game day, including practice mode. The dashboard lists saved seeded runs and supports
editing, deleting, and copying them to a new date. Card statistics are
snapshotted when saved. Shuffle randomizes all slots, including empty ones; Easy pairs the
lowest inclusion rates with the highest, while Hard pairs adjacent inclusion
rates. Easy and Hard randomize the order of the pairs and leave any unpaired
card and empty slots at the end. Add cards through the picker or fill empty slots with
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

**Before enabling EDHREC jobs:** EDHREC's
[terms](https://edhrec.com/terms) restrict automated requests and reuse. Obtain
authorization or clarify the applicable terms with EDHREC first.

The web container does not import or refresh card data. Run these commands in
separate Cloud Run Jobs:

| Job | Artisan arguments | Cloud Scheduler frequency (UTC) |
| --- | --- | --- |
| Database migration | `migrate --force` | Manual only |
| Scryfall import | `cards:import` | `0 2 1 * *` (monthly, 1st at 02:00) |
| Full EDHREC refresh | `cards:refresh-edhrec` | `0 7 * * 0` (Sunday at 07:00) |
| Partial EDHREC refresh | `cards:refresh-edhrec --partial` | `0 7 * * 1-6` (Monday-Saturday at 07:00) |

The partial job randomly selects up to 5% of the total legal catalog (rounded
up), sampling cards with existing EDHREC data, and additionally checks every
legal card without data. After a card has been continuously missing for one
calendar month from its first missing response or failed request, partial jobs
stop checking it; the weekly full job still checks it. A successful response
clears the missing period. For cards with existing data, failures preserve that
data and do not start a missing period. Failed requests still fail the job and
are reported in its logs. Existing missing cards start their month on their
first missing response or failed request after the migration.

Each EDHREC request has a 200 ms delay, so full runs can take hours. The daily
sample and per-card progress are saved for same-day retries. A shared database
cache lock prevents full and partial jobs from running concurrently, and a
separate lock prevents duplicate Scryfall imports. A force-terminated job can
leave its lock in place for up to 24 hours. Previously created game snapshots
are not modified by refreshes. The monthly Scryfall import and EDHREC jobs
retain the last good data on request errors.

### Cloud Run setup in the Google Cloud console

These resources are not automatically created or deployed by this repository.
Console labels can change; see Google's
[job creation](https://cloud.google.com/run/docs/create-jobs) and
[scheduled jobs](https://cloud.google.com/run/docs/execute/jobs-on-schedule)
documentation.

1. Build and push an image from the root `Dockerfile` containing these changes.
   The web service still performs database setup, but no card import.
2. Enable the **Cloud Run**, **Cloud Scheduler**, **Cloud SQL Admin** and
   **Secret Manager** APIs as needed. Ensure the project has a persistent
   Cloud SQL MySQL database and the web service uses `APP_ENV=production`,
   `APP_DEBUG=false`, a secure `APP_KEY`, and its `DB_*` settings. Keep secrets
   in Secret Manager. The jobs must connect to the **same database**.
3. In **IAM & Admin > Service Accounts**, use or create a runtime service
   account for the jobs. Grant it **Cloud SQL Client** and permission to access
   the required secrets. If using private IP, configure the same VPC
   connectivity as the web service.
4. In **Cloud Run > Jobs**, click **Deploy container**. Create each of the
   four jobs in the table above, using the new image and the same region as
   the service. For each job:
   - Set **Tasks** to `1`; under **Containers, Connections, Security** set the
     container command to `php` and supply `backend/artisan` followed by the
     job's Artisan arguments as separate arguments. This overrides the image's
     web entrypoint, so the job does not start Apache or run startup setup.
   - Set the runtime service account and Cloud SQL connection. Copy the
     service's `APP_ENV`, `APP_KEY`, `DB_CONNECTION`, `DB_DATABASE`,
     `DB_USERNAME`, `DB_PASSWORD`, `DB_SOCKET` (and any other needed `DB_*`)
     settings, using Secret Manager references for secrets. For a Cloud SQL
     Unix socket, `DB_SOCKET` is `/cloudsql/PROJECT:REGION:INSTANCE`.
   - Set `CACHE_STORE=database` and use the same cache database and prefix for
     all refresh jobs. Set task timeout to **20 hours** and retries to **0**;
     monitor failures and retry manually. A full run must finish within that
     timeout. Do not configure multiple tasks.
5. Execute the migration job manually. In **Cloud Run > Jobs**, check its
   execution status and logs to confirm success before running any refresh
   jobs. Then deploy the new image to the web service.
6. For a new empty database, execute the Scryfall job manually first. Once
   authorized, execute the full EDHREC job manually and wait for it to succeed
   before serving traffic: at least 20 legal cards with EDHREC data are needed.
7. Create a scheduler trigger for the Scryfall job and, **after obtaining
   EDHREC authorization**, the two EDHREC jobs. For each, open the job, select
   **Triggers > Add Scheduler Trigger**, enter the matching cron expression
   from the table, and choose timezone **UTC**. Choose a Scheduler service
   account with **Cloud Run Invoker** on the job. Grant the person creating the
   trigger permission to use that service account. The EDHREC triggers
   intentionally never run on the same scheduled day. Do not schedule the
   migration job.
8. Check job execution status and logs, and set alerts for failures and data
   becoming stale. After authorization, test the partial job on a non-Sunday
   and verify its log reports the number of cards refreshed.
9. When deploying a new image, update **each job's image** as well as the web
   service. The job image is not updated automatically with the service.

For existing deployments, disable any old external triggers running
`schedule:run` for card refreshes and avoid duplicate job triggers. The
remaining Laravel schedule only cleans up old practice games; if desired,
continue running `schedule:run` every minute in a separate scheduled job for
that cleanup. It no longer refreshes cards.

CI runs the backend and browser tests but does not deploy to GitHub Pages:
the application now requires a running backend.
