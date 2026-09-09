#!/usr/bin/env bash
set -euo pipefail

MOODLE_DIR="${MOODLE_DIR:-/var/www/moodle}"
MOODLE_DATA="${MOODLE_DATA:-/var/moodledata}"
PHP_VERSION="${PHP_VERSION:-8.4}"
PHP_FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"
REGISTRATION_CONTACT_PHONE="${REGISTRATION_CONTACT_PHONE:-}"
DEPLOY_REPO_ARCHIVE="${DEPLOY_REPO_ARCHIVE:-https://github.com/Pawel-sp9pw/szkolenia/archive/refs/heads/main.tar.gz}"

[[ $EUID -eq 0 ]] || { echo "Uruchom jako root wewnątrz LXC." >&2; exit 1; }
[[ -f "$MOODLE_DIR/config.php" ]] || { echo "Brak istniejącej instalacji Moodle: $MOODLE_DIR/config.php" >&2; exit 1; }

DEPLOY_ARCHIVE=/tmp/szkolenia-finalize.tar.gz
DEPLOY_DIR=/tmp/szkolenia-finalize
SIGNUP_BODY=/tmp/moodle-signup-test.html
LOGIN_BODY=/tmp/moodle-login-test.html
rm -rf "$DEPLOY_DIR" "$DEPLOY_ARCHIVE" "$SIGNUP_BODY" "$LOGIN_BODY"

cleanup() {
    rm -rf "$DEPLOY_DIR" "$DEPLOY_ARCHIVE" "$SIGNUP_BODY" "$LOGIN_BODY"
}
trap cleanup EXIT

echo "[1/5] Pobieram aktualną konfigurację wdrożenia..."
curl -fL --retry 3 --retry-delay 2 "$DEPLOY_REPO_ARCHIVE" -o "$DEPLOY_ARCHIVE"
mkdir -p "$DEPLOY_DIR"
tar -xzf "$DEPLOY_ARCHIVE" -C "$DEPLOY_DIR" --strip-components=1

[[ -f "$DEPLOY_DIR/moodle/configure.php" ]] || { echo "Brak moodle/configure.php w repo." >&2; exit 1; }
[[ -f "$DEPLOY_DIR/moodle/auth/manualapproval/auth.php" ]] || { echo "Brak auth_manualapproval w repo." >&2; exit 1; }
[[ -f "$DEPLOY_DIR/moodle/theme/fub/version.php" ]] || { echo "Brak motywu theme_fub w repo." >&2; exit 1; }

rm -rf "$MOODLE_DIR/public/auth/manualapproval"
cp -a "$DEPLOY_DIR/moodle/auth/manualapproval" "$MOODLE_DIR/public/auth/manualapproval"

rm -rf "$MOODLE_DIR/public/theme/fub"
cp -a "$DEPLOY_DIR/moodle/theme/fub" "$MOODLE_DIR/public/theme/fub"

# GitHub Contents API używany do publikacji projektu zapisuje pliki tekstowe.
# Dokładne logo przesłane przez użytkownika jest więc przechowywane w repo jako
# kilka fragmentów base64 i tutaj odtwarzane do normalnego pliku PNG.
LOGO_PIX_DIR="$MOODLE_DIR/public/theme/fub/pix"
LOGO_TARGET="$LOGO_PIX_DIR/logo_fub.png"
mapfile -t LOGO_PARTS < <(find "$LOGO_PIX_DIR" -maxdepth 1 -type f -name 'logo_fub.png.b64.*' | sort)
[[ "${#LOGO_PARTS[@]}" -eq 6 ]] || {
    echo "Nie znaleziono kompletu 6 fragmentów logo FUB (jest: ${#LOGO_PARTS[@]})." >&2
    exit 1
}
cat "${LOGO_PARTS[@]}" | base64 -d > "$LOGO_TARGET"
[[ -s "$LOGO_TARGET" ]] || { echo "Nie udało się odtworzyć logo FUB." >&2; exit 1; }
PNG_HEADER="$(od -An -tx1 -N8 "$LOGO_TARGET" | tr -d ' \n')"
[[ "$PNG_HEADER" == "89504e470d0a1a0a" ]] || { echo "Odtworzony plik logo nie jest poprawnym PNG." >&2; exit 1; }
rm -f "${LOGO_PARTS[@]}"

chown -R www-data:www-data "$MOODLE_DIR/public/auth/manualapproval" "$MOODLE_DIR/public/theme/fub"

runuser -u www-data -- /usr/bin/php8.4 "$MOODLE_DIR/admin/cli/upgrade.php" --non-interactive
runuser -u www-data -- env \
    MOODLE_DIR="$MOODLE_DIR" \
    REGISTRATION_CONTACT_PHONE="$REGISTRATION_CONTACT_PHONE" \
    /usr/bin/php8.4 "$DEPLOY_DIR/moodle/configure.php"

REGISTER_AUTH="$(runuser -u www-data -- /usr/bin/php8.4 "$MOODLE_DIR/admin/cli/cfg.php" --name=registerauth 2>/dev/null | tail -n1 | tr -d '\r')"
[[ "$REGISTER_AUTH" == manualapproval ]] || { echo "Nie ustawiono registerauth=manualapproval (otrzymano: $REGISTER_AUTH)." >&2; exit 1; }
ACTIVE_THEME="$(runuser -u www-data -- /usr/bin/php8.4 "$MOODLE_DIR/admin/cli/cfg.php" --name=theme 2>/dev/null | tail -n1 | tr -d '\r')"
[[ "$ACTIVE_THEME" == fub ]] || { echo "Nie ustawiono theme=fub (otrzymano: $ACTIVE_THEME)." >&2; exit 1; }

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
systemctl is-active --quiet cron
[[ -s /etc/cron.d/moodle ]] || { echo "Brak wpisu cron Moodle." >&2; exit 1; }
echo "Cron Moodle skonfigurowany; pierwsze wykonanie nastąpi automatycznie w ciągu minuty."

echo "[5/5] Testy końcowe..."
nginx -t
systemctl is-active --quiet nginx
systemctl is-active --quiet "php${PHP_VERSION}-fpm"
systemctl is-active --quiet mariadb
systemctl is-active --quiet cron
[[ -s "$LOGO_TARGET" ]] || { echo "Brak lokalnego logo FUB po finalizacji." >&2; exit 1; }
grep -Fq '[[pix:theme|logo_fub]]' "$MOODLE_DIR/public/theme/fub/style/fub.css" || {
    echo "CSS motywu FUB nie odwołuje się do lokalnego logo." >&2
    exit 1
}

WWWROOT="$(runuser -u www-data -- /usr/bin/php8.4 "$MOODLE_DIR/admin/cli/cfg.php" --name=wwwroot 2>/dev/null | tail -n1 | tr -d '\r')"
[[ -n "$WWWROOT" ]] || { echo "Nie udało się odczytać Moodle wwwroot." >&2; exit 1; }

LOGIN_URL="${WWWROOT}/login/index.php"
echo "Testuję logowanie pod adresem: $LOGIN_URL"
LOGIN_CODE="$(curl -sS -L --max-time 20 -o "$LOGIN_BODY" -w '%{http_code}' "$LOGIN_URL" || true)"
[[ "$LOGIN_CODE" == "200" ]] || { echo "Strona logowania zwróciła HTTP $LOGIN_CODE." >&2; exit 1; }
if ! grep -Fq 'Zarejestruj się' "$LOGIN_BODY"; then
    echo "Strona logowania nie zawiera tekstu przycisku 'Zarejestruj się'." >&2
    grep -Eio '<title>[^<]*</title>|login-signup[^<]*|startsignup[^<]*|exception[^<]*|error[^<]*' "$LOGIN_BODY" | head -n 40 >&2 || true
    exit 1
fi

SIGNUP_URL="${WWWROOT}/login/signup.php"
echo "Testuję rejestrację pod adresem: $SIGNUP_URL"
SIGNUP_CODE="$(curl -sS -L --max-time 20 -o "$SIGNUP_BODY" -w '%{http_code}' "$SIGNUP_URL" || true)"
[[ "$SIGNUP_CODE" == "200" ]] || { echo "Formularz rejestracji zwrócił HTTP $SIGNUP_CODE." >&2; exit 1; }
if ! grep -Fq 'name="institution"' "$SIGNUP_BODY" && ! grep -Fq 'id="id_institution"' "$SIGNUP_BODY"; then
    echo "Formularz auth_manualapproval nie zawiera pola institution." >&2
    exit 1
fi
if ! grep -Fq 'Przychodnia Bracka' "$SIGNUP_BODY"; then
    echo "Formularz rejestracji nie zawiera etykiety 'Przychodnia Bracka'." >&2
    exit 1
fi

runuser -u www-data -- /usr/bin/php8.4 "$MOODLE_DIR/admin/cli/purge_caches.php" >/dev/null

echo
echo "============================================================"
echo "Finalizacja Moodle zakończona poprawnie."
echo "Motyw: FUB"
echo "Logo: lokalny plik z załączonego logo użytkownika"
echo "Rejestracja: przycisk 'Zarejestruj się'"
echo "Pole institution: 'Przychodnia Bracka'"
echo "============================================================"
