# VHS plus the Docker CLI, so demo.tape can drive the same
# `docker compose exec ...` commands as the README against the local stack.
# The recording is a real run, not a mock-up.
FROM ghcr.io/charmbracelet/vhs:v0.12.1

COPY --from=docker:28-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker:28-cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose
