#!/usr/bin/env bash
set -euo pipefail

MOODLE_DIR="${MOODLE_DIR:-/var/www/moodle}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.4}"
REPO_ARCHIVE="${REPO_ARCHIVE:-https://github.com/Pawel-sp9pw/szkolenia/archive/refs/heads/main.tar.gz}"

[[ $EUID -eq 0 ]] || { echo "Uruchom jako root wewnątrz LXC." >&2; exit 1; }
[[ -f "$MOODLE_DIR/config.php" ]] || { echo "Brak Moodle: $MOODLE_DIR/config.php" >&2; exit 1; }
[[ -x "$PHP_BIN" ]] || { echo "Brak PHP: $PHP_BIN" >&2; exit 1; }

TMPDIR="$(mktemp -d /tmp/fub-rodo-import.XXXXXX)"
ARCHIVE="$TMPDIR/repo.tar.gz"

cleanup() {
    rm -rf "$TMPDIR"
}
trap cleanup EXIT

echo "[1/4] Pobieram aktualny pakiet szkoleń z GitHub..."
curl -fL --retry 3 --retry-delay 2 "$REPO_ARCHIVE" -o "$ARCHIVE"
mkdir -p "$TMPDIR/repo"
tar -xzf "$ARCHIVE" -C "$TMPDIR/repo" --strip-components=1

PKG="$TMPDIR/repo/moodle/training/rodo"
IMPORTER_B64="$PKG/import.php.gz.b64"
IMPORTER="$PKG/import.php"
[[ -f "$IMPORTER_B64" ]] || { echo "Brak importera: $IMPORTER_B64" >&2; exit 1; }
[[ -f "$PKG/courses.json.gz.b64" ]] || { echo "Brak danych kursów." >&2; exit 1; }

echo "[2/4] Odtwarzam importer..."
base64 -d "$IMPORTER_B64" | gzip -dc > "$IMPORTER"

echo "[3/4] Sprawdzam składnię importera..."
"$PHP_BIN" -l "$IMPORTER" >/dev/null

echo "[4/4] Importuję kursy RODO..."
cd /tmp
runuser -u www-data -- env MOODLE_DIR="$MOODLE_DIR" "$PHP_BIN" "$IMPORTER" "$@"
