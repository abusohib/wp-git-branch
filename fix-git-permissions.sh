#!/usr/bin/env bash
# One-time fix: let www-data (PHP-FPM) run git checkout on plugin repos.
# Usage: sudo ./fix-git-permissions.sh /path/to/plugin-directory

set -euo pipefail

PLUGIN_DIR="${1:-}"
WEB_GROUP="${WEB_GROUP:-www-data}"

if [[ -z "$PLUGIN_DIR" || ! -d "$PLUGIN_DIR/.git" ]]; then
	echo "Usage: sudo $0 /var/www/html/.../wp-content/plugins/your-plugin" >&2
	exit 1
fi

PLUGIN_DIR="$(realpath "$PLUGIN_DIR")"

chgrp -R "$WEB_GROUP" "$PLUGIN_DIR"
find "$PLUGIN_DIR" -type d -exec chmod g+rwxs {} \;
find "$PLUGIN_DIR" -type f -exec chmod g+rw {} \;

echo "Done. $PLUGIN_DIR is group-writable by $WEB_GROUP."
