# CI

The test workflow runs Laravel tests against SQLite and Playwright against a
local Laravel server with synthetic fixture data. It does not publish to GitHub
Pages; production needs a Laravel host and persistent MySQL database. See the
[project README](../../README.md) for deployment and scheduling requirements.
