#!/bin/sh
set -eu

/usr/local/bin/copy-theme.sh

exec /usr/local/bin/docker-entrypoint.sh "$@"

