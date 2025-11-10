#!/usr/bin/env bash
set -euo pipefail

THEME_SOURCE="/usr/src/wordpress/wp-content/themes/hpp-timber"
THEME_TARGET="/var/www/html/wp-content/themes/hpp-timber"

if [ -d "${THEME_SOURCE}" ]; then
  mkdir -p "${THEME_TARGET}"
  rsync -a --delete "${THEME_SOURCE}/" "${THEME_TARGET}/"
  chown -R www-data:www-data "${THEME_TARGET}"
fi

