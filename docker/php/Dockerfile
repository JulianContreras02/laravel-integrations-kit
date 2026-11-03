# Small CLI image for the app, the queue worker and the test runner.
# The same image runs locally and in CI, so "works on my machine" means "works in CI".
FROM php:8.4-cli-bookworm

# zip: OpenSpout writes XLSX files (a zip container).
# pcntl: lets queue:work honour job timeouts and stop gracefully.
# intl: Laravel's Number helpers (file sizes in the email).
# pcov: code coverage for the test suite.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions zip pcntl intl pcov \
    && apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Run as the host user's UID so files created in the bind mount are not owned by root.
# /app/vendor is pre-created so the named volume mounted there inherits this owner.
ARG UID=1000
RUN useradd --uid "${UID}" --create-home app \
    && mkdir -p /app/vendor \
    && chown -R app:app /app
USER app

WORKDIR /app
