# Development container

Opening the repository in a Codespace or dev container needs no manual steps.
Compose starts MySQL (persisted in the `mysql-data` volume) and the `app`
container, whose entrypoint [start.sh](start.sh) installs Composer dependencies,
creates `backend/.env` with an app key, runs migrations, seeds fake data into an
empty database and serves Laravel on port 8000. Failed setup steps are retried,
and a crashed server is restarted. Output goes to the container log and
`/tmp/asmrandle.log`, which prints `Ready` once port 8000 answers requests.

Port 8000 is intentionally not listed in `forwardPorts`: Codespaces would open
the tunnel while setup is still running and keep returning 502 afterwards.
Instead it is auto-forwarded (with the label and preview from `portsAttributes`)
once the server starts listening, which only happens after setup completes.

Seeding is offline: [DevelopmentSeeder](../backend/database/seeders/DevelopmentSeeder.php)
creates every card from `backend/data/formatted_card_list.js` with deterministic
fake EDHRec popularity. Card images load lazily in the browser from Scryfall.
Nothing is reseeded when data already exists. To start over:
`php backend/artisan migrate:fresh --force && php backend/artisan cards:initialize-development`.

`post-create.sh` only installs npm packages and Chromium for the Playwright
tests; the site does not depend on it.

See the [project README](../README.md) for testing and production scheduling.
