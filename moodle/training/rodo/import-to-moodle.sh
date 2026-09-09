#!/usr/bin/env bash
set -euo pipefail

SCRIPT_VERSION="2026-09-09-v3"
MOODLE_DIR="${MOODLE_DIR:-/var/www/moodle}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.4}"
REPO_ARCHIVE="${REPO_ARCHIVE:-https://github.com/Pawel-sp9pw/szkolenia/archive/refs/heads/main.tar.gz}"

[[ $EUID -eq 0 ]] || { echo "Uruchom jako root wewnątrz LXC." >&2; exit 1; }
[[ -f "$MOODLE_DIR/config.php" ]] || { echo "Brak Moodle: $MOODLE_DIR/config.php" >&2; exit 1; }
[[ -x "$PHP_BIN" ]] || { echo "Brak PHP: $PHP_BIN" >&2; exit 1; }
command -v python3 >/dev/null 2>&1 || { echo "Brak python3." >&2; exit 1; }

echo "FUB RODO importer wrapper: $SCRIPT_VERSION"

TMPDIR="$(mktemp -d /tmp/fub-rodo-download.XXXXXX)"
ARCHIVE="$TMPDIR/repo.tar.gz"
RUNDIR="$MOODLE_DIR/.fub-rodo-import"

cleanup() {
    rm -rf "$TMPDIR"
    rm -rf "$RUNDIR"
}
trap cleanup EXIT

echo "[1/5] Pobieram aktualny pakiet szkoleń z GitHub..."
curl -fL --retry 3 --retry-delay 2 "$REPO_ARCHIVE" -o "$ARCHIVE"
mkdir -p "$TMPDIR/repo"
tar -xzf "$ARCHIVE" -C "$TMPDIR/repo" --strip-components=1

SRCPKG="$TMPDIR/repo/moodle/training/rodo"
[[ -f "$SRCPKG/import.php" ]] || { echo "Brak importera w pobranym pakiecie." >&2; exit 1; }
[[ -f "$SRCPKG/validate.py" ]] || { echo "Brak walidatora w pobranym pakiecie." >&2; exit 1; }
[[ -f "$SRCPKG/data/manifest.json" ]] || { echo "Brak manifestu kursów." >&2; exit 1; }

echo "[2/5] Waliduję komplet treści i banków pytań..."
python3 "$SRCPKG/validate.py" "$SRCPKG"

echo "[3/5] Sprawdzam składnię importera PHP..."
"$PHP_BIN" -l "$SRCPKG/import.php"

# Właściwy import uruchamiamy z katalogu wewnątrz Moodle. Eliminuje to zarówno
# ograniczenia /tmp, jak i ewentualne ustawienia open_basedir dla PHP CLI.
rm -rf "$RUNDIR"
install -d -o www-data -g www-data -m 0700 "$RUNDIR"
cp -a "$SRCPKG"/. "$RUNDIR"/
chown -R www-data:www-data "$RUNDIR"
find "$RUNDIR" -type d -exec chmod 0700 {} +
find "$RUNDIR" -type f -exec chmod 0600 {} +

IMPORTER="$RUNDIR/import.php"

echo "  Runtime importer: $IMPORTER"
echo "  PHP: $PHP_BIN"
echo "  Użytkownik: www-data"

runuser -u www-data -- test -r "$IMPORTER" || {
    echo "Użytkownik www-data nie może odczytać importera: $IMPORTER" >&2
    exit 1
}

runuser -u www-data -- "$PHP_BIN" -l "$IMPORTER"

echo "[4/5] Importuję/aktualizuję kursy RODO..."
cd "$MOODLE_DIR"
runuser -u www-data -- env MOODLE_DIR="$MOODLE_DIR" "$PHP_BIN" "$IMPORTER" "$@"

echo "[5/5] Gotowe."
echo "Pakiet RODO został zweryfikowany i przetworzony przez Moodle."
