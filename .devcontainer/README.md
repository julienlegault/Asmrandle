# Development container

The Compose setup runs PHP/Laravel, MySQL with a persistent named volume, and
the Laravel scheduler. The post-create script installs Composer and npm
dependencies and Chromium; post-start runs migrations, initializes an empty
database from Scryfall with random development-only popularity values, and
starts the web server on port 8000.

Live EDHRec refreshes are disabled in the local environment. Restarting the
container preserves the database and does not reseed existing cards. To inspect
startup failures, check the lifecycle command output and
`/tmp/asmrandle-web.log`.

See the [project README](../README.md) for testing and production scheduling.
