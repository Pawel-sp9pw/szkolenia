#!/usr/bin/env bash
set -euo pipefail

MOODLE_DIR="${MOODLE_DIR:-/var/www/moodle}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.4}"
REPO_ARCHIVE="${REPO_ARCHIVE:-https://github.com/Pawel-sp9pw/szkolenia/archive/refs/heads/main.tar.gz}"

[[ $EUID -eq 0 ]] || { echo "Uruchom jako root wewnątrz LXC." >&2; exit 1; }
[[ -f "$MOODLE_DIR/config.php" ]] || { echo "Brak Moodle: $MOODLE_DIR/config.php" >&2; exit 1; }
[[ -x "$PHP_BIN" ]] || { echo "Brak PHP: $PHP_BIN" >&2; exit 1; }
command -v python3 >/dev/null 2>&1 || { echo "Brak python3." >&2; exit 1; }

TMPDIR="$(mktemp -d /tmp/fub-rodo-import.XXXXXX)"
ARCHIVE="$TMPDIR/repo.tar.gz"
cleanup() { rm -rf "$TMPDIR"; }
trap cleanup EXIT

echo "[1/5] Pobieram aktualny pakiet szkoleń z GitHub..."
curl -fL --retry 3 --retry-delay 2 "$REPO_ARCHIVE" -o "$ARCHIVE"
mkdir -p "$TMPDIR/repo"
tar -xzf "$ARCHIVE" -C "$TMPDIR/repo" --strip-components=1

PKG="$TMPDIR/repo/moodle/training/rodo"
IMPORTER="$PKG/import.php"
VALIDATOR="$PKG/validate.py"

[[ -f "$IMPORTER" ]] || { echo "Brak importera: $IMPORTER" >&2; exit 1; }
[[ -f "$VALIDATOR" ]] || { echo "Brak walidatora: $VALIDATOR" >&2; exit 1; }
[[ -f "$PKG/data/manifest.json" ]] || { echo "Brak manifestu kursów." >&2; exit 1; }

echo "[2/5] Waliduję komplet treści i banków pytań..."
python3 "$VALIDATOR" "$PKG"

echo "[3/5] Sprawdzam składnię importera PHP..."
"$PHP_BIN" -l "$IMPORTER"

echo "[4/5] Importuję/aktualizuję kursy RODO..."
cd /tmp
runuser -u www-data -- env MOODLE_DIR="$MOODLE_DIR" "$PHP_BIN" "$IMPORTER" "$@"

echo "[5/5] Gotowe."
echo "Pakiet RODO został zweryfikowany i przetworzony przez Moodle."
