#!/usr/bin/env bash
# Builds the Community download: one zip with everything included (no Composer needed on the server) and a ready web
# folder, for hosting without a terminal. The buyer uploads it, points the domain at mailvesper/public_html and opens
# /install/ in the browser.
#   tools/build-release.sh <version> [output-dir]      e.g. tools/build-release.sh 1.0.0 ~/Desktop
# Builds from the last commit (git archive), so uncommitted changes never ship.
set -euo pipefail
VERSION="${1:?usage: tools/build-release.sh <version> [output-dir]}"
OUT_DIR="$(cd "${2:-.}" && pwd)"
SRC="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${LOCAL_PHP:-php}"
COMPOSER="${COMPOSER_BIN:-composer}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
APP="$WORK/mailvesper"
WEB="$APP/public_html"
ZIP="$OUT_DIR/mailvesper-community-$VERSION.zip"

echo "▸ Exporting the last commit"
mkdir -p "$APP"
git -C "$SRC" archive HEAD | tar -x -C "$APP"

echo "▸ Installing PHP dependencies (no dev packages)"
(cd "$APP/backend" && "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress -q)
rm -rf "$APP/backend/tests" "$APP/backend/phpunit.xml" "$APP/backend/.env.testing" "$APP/backend/phpstan.neon" "$APP/backend/vite.config.js" "$APP/backend/package.json"

echo "▸ Building the web folder"
mkdir -p "$WEB/api" "$WEB/media"
# The same pages the command-line installer links (Webroot::STATIC) plus the installer, copied: zip extractors
# don't keep links.
for pair in index.html:index.html app:app auth:site/auth login:site/login signup:site/signup docs:site/docs r:site/r u:site/u f:site/f a:site/a install:site/install; do
  name="${pair%%:*}"; from="${pair#*:}"
  [ -e "$APP/$from" ] || { echo "✗ missing $from"; exit 1; }
  mv "$APP/$from" "$WEB/$name"
done
rmdir "$APP/site" || { echo "✗ site/ has pages the web folder doesn’t publish: $(ls "$APP/site")"; exit 1; }
cp "$APP/backend/public/.htaccess" "$WEB/api/.htaccess"
cp "$APP/backend/public/favicon.ico" "$APP/backend/public/robots.txt" "$WEB/" 2>/dev/null || true
cat > "$WEB/api/index.php" <<'PHP'
<?php

// MailVesper's API, served from the web folder at /api. The app itself is in ../../backend, outside the web folder.
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));
define('SENDLINE_WEBROOT', dirname(__DIR__));
$backend = dirname(__DIR__, 2).'/backend';

if (file_exists($maintenance = $backend.'/storage/framework/maintenance.php')) {
    require $maintenance;
}
require $backend.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $backend.'/bootstrap/app.php';
$app->usePublicPath($backend.'/public');
$app->handleRequest(Request::capture());
PHP
# Uploaded images only: nothing in media/ may run as code.
cat > "$WEB/media/.htaccess" <<'HT'
Options -Indexes -ExecCGI
<FilesMatch "\.(php[0-9]?|phtml|phar|pl|py|cgi|sh|html?|svg)$">
  Require all denied
</FilesMatch>
HT
{
  echo 'Options -Indexes'
  echo 'DirectoryIndex index.html'
  # Never serve .htaccess or other dot files (most hosts block them already; this covers the ones that don't).
  echo '<FilesMatch "^\.">'
  echo '  Require all denied'
  echo '</FilesMatch>'
  sed 's/__CSP_HEADER__/Content-Security-Policy/' "$APP/deploy-web/htaccess-security.conf"
} > "$WEB/.htaccess"
# Nothing above the web folder is reachable from the web; these stop a host that serves the whole upload by mistake.
printf 'Require all denied\n' > "$APP/backend/.htaccess"
chmod -R u+rwX,go+rX "$APP"
chmod -R ug+rwX "$APP/backend/storage" "$APP/backend/bootstrap/cache"

echo "▸ Checking the package"
for f in backend/vendor/autoload.php backend/artisan backend/.env.example public_html/index.html public_html/install/index.html public_html/api/index.php public_html/app; do
  [ -e "$APP/$f" ] || { echo "✗ missing $f"; exit 1; }
done
if find "$APP" -name '.env' -o -name '*.key' -o -name 'database.sqlite' | grep -q .; then echo '✗ a secret-looking file is in the package'; exit 1; fi
"$PHP" -l "$WEB/api/index.php" >/dev/null

echo "▸ Zipping"
rm -f "$ZIP"
(cd "$WORK" && zip -qr -X "$ZIP" mailvesper)
echo "✓ $ZIP ($(du -h "$ZIP" | cut -f1))"
