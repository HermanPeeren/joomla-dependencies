#!/usr/bin/env bash
# Installs the latest stable Joomla release into joomla/ as a local test site.
#
# The Super User's password comes from ADMIN_PASSWORD (at least 12 characters); the username from
# ADMIN_USERNAME, default admin. Database settings come from the environment too; their defaults
# suit a local WAMP or XAMPP.
#
#   ADMIN_PASSWORD=... tools/install-joomla.sh            install, refuses when joomla/ exists
#   ADMIN_PASSWORD=... tools/install-joomla.sh --force    remove joomla/ and its database first

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SITE="$ROOT/joomla"

ADMIN_USERNAME="${ADMIN_USERNAME:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:?Set ADMIN_PASSWORD to the Super User password for the local test site}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-joomla_dependencies}"
DB_PREFIX="${DB_PREFIX:-jdep_}"

if [ -e "$SITE" ]; then
    if [ "${1:-}" != "--force" ]; then
        echo "joomla/ already exists. Use --force to remove it and its database first." >&2
        exit 1
    fi
    rm -rf "$SITE"
fi

# The release GitHub marks Latest; it skips release candidates.
if command -v gh >/dev/null 2>&1; then
    VERSION="$(gh release view --repo joomla/joomla-cms --json tagName --jq .tagName)"
else
    VERSION="$(curl -fsSL https://api.github.com/repos/joomla/joomla-cms/releases/latest | php -r 'echo json_decode(stream_get_contents(STDIN))->tag_name;')"
fi

echo "Installing Joomla $VERSION into joomla/"

ZIP="$(mktemp -d)/joomla.zip"
curl -fsSL -o "$ZIP" "https://github.com/joomla/joomla-cms/releases/download/$VERSION/Joomla_$VERSION-Stable-Full_Package.zip"
mkdir -p "$SITE"
unzip -q "$ZIP" -d "$SITE"
rm -f "$ZIP"

# (Re)create the database. Done from PHP so no mysql client is needed.
DB_HOST="$DB_HOST" DB_USER="$DB_USER" DB_PASS="$DB_PASS" DB_NAME="$DB_NAME" php -r '
    $db = new mysqli(getenv("DB_HOST"), getenv("DB_USER"), getenv("DB_PASS"));
    $name = str_replace("`", "", getenv("DB_NAME"));
    $db->query("DROP DATABASE IF EXISTS `$name`");
    $db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
'

php "$SITE/installation/joomla.php" install -n \
    --site-name="Joomla dependencies test site" \
    --admin-user="Admin" \
    --admin-username="$ADMIN_USERNAME" \
    --admin-password="$ADMIN_PASSWORD" \
    --admin-email=admin@example.invalid \
    --db-type=mysqli \
    --db-host="$DB_HOST" \
    --db-user="$DB_USER" \
    --db-pass="$DB_PASS" \
    --db-name="$DB_NAME" \
    --db-prefix="$DB_PREFIX"

# Show errors on the test site.
php "$SITE/cli/joomla.php" config:set error_reporting=maximum debug=true -n >/dev/null

# For browser tests later on; git-ignored.
cat > "$ROOT/cypress.env.json" <<EOF
{
    "username": "$ADMIN_USERNAME",
    "password": "$ADMIN_PASSWORD",
    "email": "admin@example.invalid",
    "db_host": "$DB_HOST",
    "db_name": "$DB_NAME",
    "db_prefix": "$DB_PREFIX"
}
EOF

echo "Done: Joomla $VERSION, administrator login $ADMIN_USERNAME"
