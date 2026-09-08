#!/usr/bin/env bash
set -euo pipefail

MOODLE_DIR="${MOODLE_DIR:-/var/www/moodle}"
MOODLE_DATA="${MOODLE_DATA:-/var/moodledata}"
PHP_VERSION="${PHP_VERSION:-8.4}"
PHP_FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"
PHP_TIMEZONE="${PHP_TIMEZONE:-Europe/Warsaw}"
REGISTRATION_CONTACT_PHONE="${REGISTRATION_CONTACT_PHONE:-}"
DEPLOY_REPO_ARCHIVE="${DEPLOY_REPO_ARCHIVE:-https://github.com/Pawel-sp9pw/szkolenia/archive/refs/heads/main.tar.gz}"

[[ $EUID -eq 0 ]] || { echo "Uruchom jako root wewnątrz LXC." >&2; exit 1; }
[[ -f "$MOODLE_DIR/config.php" ]] || { echo "Brak istniejącej instalacji Moodle: $MOODLE_DIR/config.php" >&2; exit 1; }

DEPLOY_ARCHIVE=/tmp/szkolenia-finalize.tar.gz
DEPLOY_DIR=/tmp/szkolenia-finalize
SIGNUP_BODY=/tmp/moodle-signup-test.html
rm -rf "$DEPLOY_DIR" "$DEPLOY_ARCHIVE" "$SIGNUP_BODY"

cleanup() {
    rm -rf "$DEPLOY_DIR" "$DEPLOY_ARCHIVE" "$SIGNUP_BODY"
}
trap cleanup EXIT

echo "[1/5] Pobieram aktualną konfigurację wdrożenia..."
curl -fL --retry 3 --retry-delay 2 "$DEPLOY_REPO_ARCHIVE" -o "$DEPLOY_ARCHIVE"
mkdir -p "$DEPLOY_DIR"
tar -xzf "$DEPLOY_ARCHIVE" -C "$DEPLOY_DIR" --strip-components=1

[[ -f "$DEPLOY_DIR/moodle/configure.php" ]] || { echo "Brak moodle/configure.php w repo." >&2; exit 1; }
[[ -f "$DEPLOY_DIR/moodle/auth/manualapproval/auth.php" ]] || { echo "Brak auth_manualapproval w repo." >&2; exit 1; }

rm -rf "$MOODLE_DIR/public/auth/manualapproval"
cp -a "$DEPLOY_DIR/moodle/auth/manualapproval" "$MOODLE_DIR/public/auth/manualapproval"
chown -R www-data:www-data "$MOODLE_DIR/public/auth/manualapproval"

runuser -u www-data -- /usr/bin/php8.4 "$MOODLE_DIR/admin/cli/upgrade.php" --non-interactive
runuser -u www-data -- env \
    MOODLE_DIR="$MOODLE_DIR" \
    REGISTRATION_CONTACT_PHONE="$REGISTRATION_CONTACT_PHONE" \
    /usr/bin/php8.4 "$DEPLOY_DIR/moodle/configure.php"

REGISTER_AUTH="$(runuser -u www-data -- /usr/bin/php8.4 "$MOODLE_DIR/admin/cli/cfg.php" --name=registerauth 2>/dev/null | tail -n1 | tr -d '\r')"
[[ "$REGISTER_AUTH" == manualapproval ]] || { echo "Nie ustawiono registerauth=manualapproval (otrzymano: $REGISTER_AUTH)." >&2; exit 1; }

echo "[2/5] Ustawiam bezpieczne prawa dostępu..."
chown -R root:root "$MOODLE_DIR"
find "$MOODLE_DIR" -type d -exec chmod 0755 {} +
find "$MOODLE_DIR" -type f -exec chmod 0644 {} +
chown root:www-data "$MOODLE_DIR/config.php"
chmod 0640 "$MOODLE_DIR/config.php"
chown -R www-data:www-data "$MOODLE_DATA"
find "$MOODLE_DATA" -type d -exec chmod 0770 {} +
find "$MOODLE_DATA" -type f -exec chmod 0660 {} +

echo "[3/5] Konfiguruję Nginx..."
cat > /etc/nginx/sites-available/moodle <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    root ${MOODLE_DIR}/public;
    index index.php;
    client_max_body_size 256M;

    location / {
        try_files \$uri \$uri/ /r.php\$is_args\$args;
    }

    location ~ \.php(/|\$) {
        fastcgi_split_path_info ^(.+\.php)(/.*)\$;
        set \$path_info \$fastcgi_path_info;
        try_files \$fastcgi_script_name \$fastcgi_script_name/ /r.php\$is_args\$args;
        include fastcgi_params;
        fastcgi_param PATH_INFO \$path_info;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_pass unix:${PHP_FPM_SOCKET};
        fastcgi_intercept_errors off;
    }

    location ~ /\.(?!well-known).* {
        return 404;
    }
}
EOF
rm -f /etc/nginx/sites-enabled/default
ln -sfn /etc/nginx/sites-available/moodle /etc/nginx/sites-enabled/moodle
nginx -t
systemctl enable --now nginx
systemctl restart nginx

echo "[4/5] Konfiguruję cron..."
cat > /etc/cron.d/moodle <<EOF
* * * * * www-data /usr/bin/php8.4 ${MOODLE_DIR}/admin/cli/cron.php >/dev/null 2>&1
EOF
chmod 0644 /etc/cron.d/moodle
systemctl enable --now cron
runuser -u www-data -- /usr/bin/php8.4 "$MOODLE_DIR/admin/cli/cron.php" >/dev/null

echo "[5/5] Testy końcowe..."
nginx -t
systemctl is-active --quiet nginx
systemctl is-active --quiet "php${PHP_VERSION}-fpm"
systemctl is-active --quiet mariadb
systemctl is-active --quiet cron

WWWROOT="$(runuser -u www-data -- /usr/bin/php8.4 "$MOODLE_DIR/admin/cli/cfg.php" --name=wwwroot 2>/dev/null | tail -n1 | tr -d '\r')"
[[ -n "$WWWROOT" ]] || { echo "Nie udało się odczytać Moodle wwwroot." >&2; exit 1; }

echo "Testuję formularz pod adresem: ${WWWROOT}/login/signup.php"
HTTP_CODE="$(curl -sS -L --max-time 15 -o "$SIGNUP_BODY" -w '%{http_code}' "${WWWROOT}/login/signup.php" || true)"
if [[ "$HTTP_CODE" != "200" ]]; then
    echo "Formularz rejestracji zwrócił HTTP $HTTP_CODE." >&2
    echo "--- Fragment odpowiedzi ---" >&2
    sed -n '1,80p' "$SIGNUP_BODY" >&2 || true
    echo "--- Ostatnie logi Nginx/PHP ---" >&2
    tail -n 30 /var/log/nginx/error.log >&2 2>/dev/null || true
    journalctl -u "php${PHP_VERSION}-fpm" -n 30 --no-pager >&2 2>/dev/null || true
    exit 1
fi

if ! grep -Eq 'name=["'"']institution["'"']|id=["'"']id_institution["'"']' "$SIGNUP_BODY"; then
    echo "Formularz auth_manualapproval nie zawiera pola Firma (institution)." >&2
    echo "--- Tytuł / treść diagnostyczna strony ---" >&2
    grep -Eio '<title>[^<]*</title>|exception[^<]*|error[^<]*|signup[^<]*|registration[^<]*' "$SIGNUP_BODY" | head -n 40 >&2 || true
    echo "--- Pierwsze 120 linii odpowiedzi ---" >&2
    sed -n '1,120p' "$SIGNUP_BODY" >&2 || true
    exit 1
fi

echo
echo "============================================================"
echo "Finalizacja istniejącej instalacji Moodle zakończona poprawnie."
echo "============================================================"
