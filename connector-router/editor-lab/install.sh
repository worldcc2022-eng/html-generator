#!/usr/bin/env bash
set -euo pipefail
# Invoked after extracting lab files to /home/surprisehtml/public_html/connector-router/editor-lab/.
# Downloads the original, officially published FCKeditor 2.6.11 archive on the user's server.
ROOT="/home/surprisehtml/public_html/connector-router"
LAB="$ROOT/editor-lab"
SRC="https://download.cksource.com/FCKeditor/FCKeditor/2.6.11/FCKeditor_2.6.11.zip"
USER_NAME="surprisehtml"
if [ ! -f "$ROOT/bootstrap.php" ] || [ ! -f "$ROOT/lib/GithubPages.php" ]; then
  echo "ERROR: Existing Connector Router and GitHub Pages mapping module are required in $ROOT" >&2
  exit 1
fi
for bin in curl unzip php find cp; do
  command -v "$bin" >/dev/null || { echo "Missing command: $bin" >&2; exit 1; }
done
if [ ! -d "$LAB" ] || [ ! -f "$LAB/index.php" ] || [ ! -f "$LAB/connector.php" ]; then
  echo "ERROR: First unzip the lab kit into $ROOT." >&2
  exit 1
fi
if [ "$(id -u)" -ne 0 ]; then
  echo "Run this installer as root so ownership and file protections can be set." >&2
  exit 1
fi
if [ ! -f "$LAB/fckeditor/fckeditor.js" ]; then
  TMP="$(mktemp -d)"
  trap 'rm -rf "$TMP"' EXIT
  echo "Downloading original FCKeditor 2.6.11 from CKSource ..."
  curl -fSL --retry 3 --connect-timeout 15 --max-time 90 "$SRC" -o "$TMP/fckeditor.zip"
  unzip -tqq "$TMP/fckeditor.zip"
  mkdir "$TMP/extract"
  unzip -q "$TMP/fckeditor.zip" -d "$TMP/extract"
  ENTRY="$(find "$TMP/extract" -type f -name fckeditor.js -print -quit)"
  if [ -z "$ENTRY" ]; then
    echo "ERROR: Official archive did not include fckeditor.js" >&2
    exit 1
  fi
  PKG="$(dirname "$ENTRY")"
  mkdir -p "$LAB/fckeditor"
  cp -a "$PKG/." "$LAB/fckeditor/"
fi
# Disallow server-side code bundled with the legacy editor while preserving static JS/CSS/HTML.
cat > "$LAB/fckeditor/.htaccess" <<'HTACCESS'
Options -Indexes
<FilesMatch "\.(php[0-9]*|phtml|phar|cgi|pl|py|asp|aspx|cfm|jsp)$">
    Require all denied
</FilesMatch>
HTACCESS

# Protect every resource in the lab; keep the password database within the protected directory.
cat > "$LAB/.htaccess" <<HTACCESS
Options -Indexes
AuthType Basic
AuthName "Private FCKeditor Compatibility Lab"
AuthUserFile "$LAB/.htpasswd"
Require valid-user
<FilesMatch "^(\\.htpasswd|install\\.sh)$">
    Require all denied
</FilesMatch>
HTACCESS

if [ ! -s "$LAB/.htpasswd" ]; then
  echo "Create a separate password for this isolated editor lab (not your Connector admin password)."
  read -rsp "Lab password (minimum 12 characters): " PASSWORD
  echo
  if [ "${#PASSWORD}" -lt 12 ]; then
    echo "ERROR: Password must be at least 12 characters" >&2
    exit 1
  fi
  # htpasswd -B is supported by Apache and LiteSpeed Basic authentication.
  if command -v htpasswd >/dev/null; then
    printf '%s\n' "$PASSWORD" | htpasswd -i -B -c "$LAB/.htpasswd" labadmin >/dev/null
  else
    HASH="$(printf '%s' "$PASSWORD" | php -r '$p=stream_get_contents(STDIN);echo password_hash($p,PASSWORD_BCRYPT);')"
    printf 'labadmin:%s\n' "$HASH" > "$LAB/.htpasswd"
  fi
  unset PASSWORD HASH
fi
chown -R "$USER_NAME:$USER_NAME" "$LAB"
chmod 755 "$LAB"
if getent group nobody >/dev/null 2>&1; then chgrp nobody "$LAB/.htpasswd"; fi
chmod 640 "$LAB/.htpasswd"
chmod 644 "$LAB/.htaccess" "$LAB/fckeditor/.htaccess"
find "$LAB" -type d -exec chmod 755 {} \;
find "$LAB" -type f ! -name '.htpasswd' -exec chmod 644 {} \;
chmod 700 "$LAB/install.sh"
php -l "$LAB/index.php"
php -l "$LAB/connector.php"
echo
echo "Installed at: https://html.surprisestream.com/connector-router/editor-lab/"
echo "Login username: labadmin"
echo "Browser path: https://html.surprisestream.com/connector-router/editor-lab/fckeditor/editor/filemanager/browser/default/browser.html"
echo "Legacy server-side upload scripts are blocked. Keep this lab password-protected."
