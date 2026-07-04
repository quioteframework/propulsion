# Worker-safety test harness image (KNOWN_ISSUES.md "Phase 4 -- Worker-safety
# rework" / "Worker-safety test matrix"). Bakes the repo (including an
# already-`composer install`ed vendor/, which the driver ensures before
# building) into a FrankenPHP worker-mode image, so the container is fully
# self-contained -- no bind mount needed at `docker run` time.
#
# Build from the REPO ROOT as context (not this directory), e.g.:
#   docker build -f test/worker/Dockerfile -t propulsion-worker-test .
#
# dunglas/frankenphp is the standard FrankenPHP image; php8.5 matches this
# package's composer.json `"php": ">=8.5.0"` requirement. It ships pdo_sqlite
# out of the box (no pdo_pgsql), which is why this harness exercises a real
# SQLite-backed PropulsionPDO connection instead of Postgres -- see
# test/worker/public/index.php's docblock for the reasoning.
FROM dunglas/frankenphp:php8.5

WORKDIR /app
COPY . /app

EXPOSE 8080

CMD ["frankenphp", "run", "--config", "/app/test/worker/Caddyfile", "--adapter", "caddyfile"]
