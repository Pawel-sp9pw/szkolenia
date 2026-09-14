#!/usr/bin/env bash
set -euo pipefail

MOODLE_DIR="${MOODLE_DIR:-/var/www/moodle}"
PHP_VERSION="${PHP_VERSION:-8.4}"
PHP_FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"
SITE_HOST="${SITE_HOST:-szkolenia.uniabracka.pl}"
SITE_URL="${SITE_URL:-https://${SITE_HOST}}"
SSL_DIR="${SSL_DIR:-/etc/nginx/ssl}"
SSL_KEY="${SSL_KEY:-${SSL_DIR}/${SITE_HOST}.key}"
SSL_CERT="${SSL_DIR}/${SITE_HOST}.crt"
SSL_CA="${SSL_DIR}/${SITE_HOST}.ca.pem"
SSL_FULLCHAIN="${SSL_DIR}/${SITE_HOST}.fullchain.pem"
DEPLOY_REPO_ARCHIVE="${DEPLOY_REPO_ARCHIVE:-https://github.com/Pawel-sp9pw/szkolenia/archive/refs/heads/main.tar.gz}"

[[ $EUID -eq 0 ]] || { echo "Uruchom jako root wewnątrz LXC." >&2; exit 1; }
[[ -f "$MOODLE_DIR/config.php" ]] || { echo "Brak Moodle: $MOODLE_DIR/config.php" >&2; exit 1; }
[[ -x "/usr/bin/php${PHP_VERSION}" ]] || { echo "Brak PHP ${PHP_VERSION}." >&2; exit 1; }
command -v openssl >/dev/null 2>&1 || { echo "Brak openssl." >&2; exit 1; }
command -v nginx >/dev/null 2>&1 || { echo "Brak nginx." >&2; exit 1; }

if [[ ! -f "$SSL_KEY" ]]; then
    echo "Brak klucza prywatnego: $SSL_KEY" >&2
    echo "Najpierw wgraj klucz prywatny do CT 116 i ustaw chmod 600." >&2
    exit 2
fi

# Klucz prywatny pozostaje wyłącznie lokalnie na serwerze.
chown root:root "$SSL_KEY"
chmod 0600 "$SSL_KEY"

TMPDIR="$(mktemp -d /tmp/fub-ssl.XXXXXX)"
ARCHIVE="$TMPDIR/repo.tar.gz"
cleanup() { rm -rf "$TMPDIR"; }
trap cleanup EXIT

echo "[1/6] Pobieram publiczny certyfikat i łańcuch CA z repo..."
curl -fL --retry 3 --retry-delay 2 "$DEPLOY_REPO_ARCHIVE" -o "$ARCHIVE"
mkdir -p "$TMPDIR/repo"
tar -xzf "$ARCHIVE" -C "$TMPDIR/repo" --strip-components=1

SRC_SSL="$TMPDIR/repo/proxmox-lxc/ssl"
[[ -f "$SRC_SSL/${SITE_HOST}.crt" ]] || { echo "Brak certyfikatu w repo." >&2; exit 1; }
[[ -f "$SRC_SSL/${SITE_HOST}.ca.pem" ]] || { echo "Brak łańcucha CA w repo." >&2; exit 1; }

install -d -o root -g root -m 0700 "$SSL_DIR"
install -o root -g root -m 0644 "$SRC_SSL/${SITE_HOST}.crt" "$SSL_CERT"
install -o root -g root -m 0644 "$SRC_SSL/${SITE_HOST}.ca.pem" "$SSL_CA"
cat "$SSL_CERT" "$SSL_CA" > "$SSL_FULLCHAIN"
chown root:root "$SSL_FULLCHAIN"
chmod 0644 "$SSL_FULLCHAIN"

echo "[2/6] Weryfikuję certyfikat i klucz prywatny..."
openssl x509 -in "$SSL_CERT" -noout -subject -issuer -dates
openssl pkey -in "$SSL_KEY" -check -noout >/dev/null

CERT_PUB_SHA="$(openssl x509 -in "$SSL_CERT" -pubkey -noout | openssl pkey -pubin -outform DER 2>/dev/null | sha256sum | awk '{print $1}')"
KEY_PUB_SHA="$(openssl pkey -in "$SSL_KEY" -pubout -outform DER 2>/dev/null | sha256sum | awk '{print $1}')"
[[ -n "$CERT_PUB_SHA" && "$CERT_PUB_SHA" == "$KEY_PUB_SHA" ]] || {
    echo "BŁĄD: klucz prywatny NIE pasuje do certyfikatu ${SITE_HOST}." >&2
    exit 1
}
echo "Klucz prywatny pasuje do certyfikatu."

# Sprawdź, czy certyfikat rzeczywiście obejmuje nazwę serwera.
openssl x509 -in "$SSL_CERT" -noout -checkhost "$SITE_HOST" >/dev/null || {
    echo "BŁĄD: certyfikat nie obejmuje hosta $SITE_HOST." >&2
    exit 1
}

echo "[3/6] Ustawiam Moodle wwwroot na HTTPS..."
SITE_URL="$SITE_URL" MOODLE_CONFIG="$MOODLE_DIR/config.php" "/usr/bin/php${PHP_VERSION}" -r '
$config = getenv("MOODLE_CONFIG");
$url = getenv("SITE_URL");
$content = file_get_contents($config);
if ($content === false) { fwrite(STDERR, "Nie udało się odczytać config.php\n"); exit(1); }
$pattern = "/\\" . chr(36) . "CFG->wwwroot\\s*=\\s*[^;]+;/";
$replacement = chr(36) . "CFG->wwwroot = " . var_export($url, true) . ";";
$count = 0;
$content = preg_replace($pattern, $replacement, $content, 1, $count);
if ($count !== 1) { fwrite(STDERR, "Nie znaleziono wpisu wwwroot w config.php\n"); exit(1); }
if (file_put_contents($config, $content) === false) { fwrite(STDERR, "Nie udało się zapisać config.php\n"); exit(1); }
'

CURRENT_WWWROOT="$(runuser -u www-data -- "/usr/bin/php${PHP_VERSION}" "$MOODLE_DIR/admin/cli/cfg.php" --name=wwwroot 2>/dev/null | tail -n1 | tr -d '\r')"
[[ "$CURRENT_WWWROOT" == "$SITE_URL" ]] || { echo "Nieprawidłowy wwwroot: $CURRENT_WWWROOT" >&2; exit 1; }

echo "[4/6] Konfiguruję Nginx HTTPS..."
cat > /etc/nginx/sites-available/moodle <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name ${SITE_HOST};
    return 301 https://${SITE_HOST}\$request_uri;
}

server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    server_name ${SITE_HOST};

    root ${MOODLE_DIR}/public;
    index index.php;
    client_max_body_size 256M;

    ssl_certificate ${SSL_FULLCHAIN};
    ssl_certificate_key ${SSL_KEY};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    ssl_session_tickets off;

    add_header Strict-Transport-Security "max-age=31536000" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files \$uri \$uri/ /r.php\$is_args\$args;
    }

    location ~ \.php(/|\$) {
        fastcgi_split_path_info ^(.+\.php)(/.*)\$;
        set \$path_info \$fastcgi_path_info;
        try_files \$fastcgi_script_name \$fastcgi_script_name/ /r.php\$is_args\$args;
        include fastcgi_params;
        fastcgi_param HTTPS on;
        fastcgi_param HTTP_SCHEME https;
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
systemctl restart nginx

echo "[5/6] Czyszczę cache Moodle i testuję HTTPS lokalnie..."
runuser -u www-data -- "/usr/bin/php${PHP_VERSION}" "$MOODLE_DIR/admin/cli/purge_caches.php" >/dev/null
systemctl restart "php${PHP_VERSION}-fpm"

HTTP_CODE="$(curl -sS --max-time 10 -o /dev/null -w '%{http_code}' -H "Host: $SITE_HOST" http://127.0.0.1/login/index.php || true)"
[[ "$HTTP_CODE" == "301" || "$HTTP_CODE" == "308" ]] || {
    echo "HTTP nie przekierowuje prawidłowo do HTTPS (kod: $HTTP_CODE)." >&2
    exit 1
}

HTTPS_CODE="$(curl -k -sS --max-time 15 --resolve "${SITE_HOST}:443:127.0.0.1" -o /dev/null -w '%{http_code}' "https://${SITE_HOST}/login/index.php" || true)"
case "$HTTPS_CODE" in
    200|301|302|303|307|308) ;;
    *) echo "Lokalny HTTPS zwrócił HTTP $HTTPS_CODE." >&2; exit 1 ;;
esac

ss -ltn | grep -q ':443 ' || { echo "Nginx nie nasłuchuje na 443." >&2; exit 1; }

echo "[6/6] SSL aktywny."
echo "============================================================"
echo "HTTPS: https://${SITE_HOST}"
echo "Certyfikat: ${SSL_FULLCHAIN}"
echo "Klucz prywatny: ${SSL_KEY}"
echo "HTTP -> HTTPS: aktywne"
echo "============================================================"
