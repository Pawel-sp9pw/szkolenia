#!/usr/bin/env bash
set -euo pipefail

MOODLE_VERSION="${MOODLE_VERSION:-5.2.2}"
MOODLE_URL="${MOODLE_URL:-auto}"
MOODLE_LANG="${MOODLE_LANG:-pl}"
SITE_FULLNAME="${SITE_FULLNAME:-Szkolenia}"
SITE_SHORTNAME="${SITE_SHORTNAME:-Szkolenia}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@noemail.invalid}"
PHP_TIMEZONE="${PHP_TIMEZONE:-Europe/Warsaw}"
DB_NAME="${DB_NAME:-moodle}"
DB_USER="${DB_USER:-moodle}"
DB_PASS="${DB_PASS:-}"
REGISTRATION_CONTACT_PHONE="${REGISTRATION_CONTACT_PHONE:-}"
DEPLOY_REPO_ARCHIVE="${DEPLOY_REPO_ARCHIVE:-https://github.com/Pawel-sp9pw/szkolenia/archive/refs/heads/main.tar.gz}"

MOODLE_DIR="/var/www/moodle"
MOODLE_DATA="/var/moodledata"
SECRETS_FILE="/root/moodle-install-secrets.txt"
PHP_VERSION="8.4"
PHP_FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"

write_secrets() {
  cat > "$SECRETS_FILE" <<EOFSECRETS
Moodle: ${MOODLE_VERSION}
URL: ${MOODLE_URL}
Administrator: ${ADMIN_USER}
Hasło administratora: ${ADMIN_PASS}
E-mail administratora: ${ADMIN_EMAIL}

Baza danych: ${DB_NAME}
Użytkownik bazy: ${DB_USER}
Hasło bazy: ${DB_PASS}

Kod Moodle: ${MOODLE_DIR}
Webroot Nginx: ${MOODLE_DIR}/public
Moodledata: ${MOODLE_DATA}
Cron: /etc/cron.d/moodle
Nginx: /etc/nginx/sites-available/moodle
Rejestracja: auth_manualapproval
Konta oczekujące: ${MOODLE_URL}/auth/manualapproval/pending.php
EOFSECRETS
  chmod 0600 "$SECRETS_FILE"
}

if [[ $EUID -ne 0 ]]; then
  echo "Provisioning musi być uruchomiony jako root wewnątrz LXC." >&2
  exit 1
fi

if [[ -f "$MOODLE_DIR/config.php" ]]; then
  echo "Moodle jest już zainstalowany ($MOODLE_DIR/config.php istnieje). Nie nadpisuję instalacji."
  [[ -f "$SECRETS_FILE" ]] && cat "$SECRETS_FILE"
  exit 0
fi

if [[ "$MOODLE_VERSION" != 5.2.* ]]; then
  echo "Ten provisioning jest przygotowany dla Moodle 5.2.x. Otrzymano: $MOODLE_VERSION" >&2
  exit 1
fi

if [[ ! "$DB_NAME" =~ ^[A-Za-z0-9_]+$ || ! "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "DB_NAME i DB_USER mogą zawierać wyłącznie litery, cyfry i znak _." >&2
  exit 1
fi

if [[ "$MOODLE_URL" == "auto" ]]; then
  CONTAINER_IP="$(ip -4 -o addr show scope global dev eth0 | awk 'NR==1 {split($4,a,"/"); print a[1]}')"
  if [[ -z "$CONTAINER_IP" ]]; then
    echo "Nie udało się ustalić adresu IPv4 kontenera." >&2
    exit 1
  fi
  MOODLE_URL="http://${CONTAINER_IP}"
fi

if [[ ! "$MOODLE_URL" =~ ^https?://[^/]+(/.*)?$ ]]; then
  echo "MOODLE_URL musi zaczynać się od http:// lub https://. Otrzymano: $MOODLE_URL" >&2
  exit 1
fi

if [[ -z "$DB_PASS" ]]; then
  DB_PASS="$(openssl rand -hex 24)"
fi
if [[ -z "$ADMIN_PASS" ]]; then
  ADMIN_PASS="Mdl!$(openssl rand -hex 12)Aa9"
fi

write_secrets
export DEBIAN_FRONTEND=noninteractive

echo "[1/10] Weryfikuję Debian 13..."
. /etc/os-release
if [[ "${ID:-}" != "debian" || "${VERSION_ID:-}" != "13" ]]; then
  echo "Wymagany jest Debian 13. Wykryto: ${PRETTY_NAME:-nieznany system}" >&2
  exit 1
fi

echo "[2/10] Aktualizuję system i instaluję pakiety..."
apt-get update
apt-get -y upgrade
apt-get install -y --no-install-recommends \
  ca-certificates curl tar unzip openssl cron \
  nginx mariadb-server mariadb-client \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-curl php8.4-gd \
  php8.4-intl php8.4-mbstring php8.4-xml php8.4-zip php8.4-soap \
  php8.4-bcmath php8.4-opcache

PHP_MISSING=0
PHP_MODULES="$(php -m)"
for ext in curl dom gd intl mbstring mysqli sodium xml zip; do
  if ! grep -qi "^${ext}$" <<<"$PHP_MODULES"; then
    echo "Brak wymaganego modułu PHP: $ext" >&2
    PHP_MISSING=1
  fi
done
[[ "$PHP_MISSING" -eq 0 ]] || exit 1
php -r 'if (version_compare(PHP_VERSION, "8.3.0", "<")) {fwrite(STDERR, "PHP jest za stare\n"); exit(1);} echo "PHP ".PHP_VERSION." OK\n";'

echo "[3/10] Konfiguruję PHP 8.4..."
for sapi in fpm cli; do
  cat > "/etc/php/${PHP_VERSION}/${sapi}/conf.d/99-moodle.ini" <<PHPINI
memory_limit = 512M
upload_max_filesize = 256M
post_max_size = 256M
max_execution_time = 300
max_input_time = 300
max_input_vars = 5000
date.timezone = ${PHP_TIMEZONE}
output_buffering = 4096
PHPINI
done

cat > "/etc/php/${PHP_VERSION}/fpm/conf.d/98-moodle-opcache.ini" <<'PHPINI'
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.revalidate_freq = 60
PHPINI

systemctl enable --now "php${PHP_VERSION}-fpm"
systemctl restart "php${PHP_VERSION}-fpm"
[[ -S "$PHP_FPM_SOCKET" ]] || { echo "Brak socketu PHP-FPM: $PHP_FPM_SOCKET" >&2; exit 1; }

echo "[4/10] Konfiguruję MariaDB..."
cat > /etc/mysql/mariadb.conf.d/60-moodle.cnf <<'DBCONF'
[mysqld]
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
innodb_file_per_table = 1
max_allowed_packet = 256M
DBCONF

systemctl enable --now mariadb
systemctl restart mariadb

MARIADB_VERSION="$(mariadb -NBe 'SELECT VERSION();' | sed 's/-.*//')"
if ! dpkg --compare-versions "$MARIADB_VERSION" ge 10.11; then
  echo "MariaDB $MARIADB_VERSION jest za stara dla Moodle 5.2." >&2
  exit 1
fi
echo "MariaDB $MARIADB_VERSION OK"

mariadb --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "[5/10] Pobieram Moodle $MOODLE_VERSION z moodle.org..."
if [[ -e "$MOODLE_DIR" ]]; then
  INCOMPLETE_BACKUP="/root/moodle-incomplete-$(date +%Y%m%d-%H%M%S)"
  echo "Znaleziono niedokończony katalog $MOODLE_DIR. Przenoszę go do $INCOMPLETE_BACKUP"
  mv "$MOODLE_DIR" "$INCOMPLETE_BACKUP"
fi

BRANCH_CODE="$(awk -F. '{printf "%d%02d", $1, $2}' <<<"$MOODLE_VERSION")"
MOODLE_ARCHIVE="/tmp/moodle-${MOODLE_VERSION}.tgz"
MOODLE_DOWNLOAD_URL="https://download.moodle.org/download.php/stable${BRANCH_CODE}/moodle-${MOODLE_VERSION}.tgz"
curl -fL --retry 3 --retry-delay 2 "$MOODLE_DOWNLOAD_URL" -o "$MOODLE_ARCHIVE"

tar -tzf "$MOODLE_ARCHIVE" | grep -Fx 'moodle/public/index.php' >/dev/null || {
  echo "Archiwum nie zawiera oczekiwanej struktury Moodle 5.2 (/public)." >&2
  exit 1
}
tar -tzf "$MOODLE_ARCHIVE" | grep -Fx 'moodle/admin/cli/install.php' >/dev/null || {
  echo "Archiwum nie zawiera admin/cli/install.php." >&2
  exit 1
}

mkdir -p /var/www
tar -xzf "$MOODLE_ARCHIVE" -C /var/www
rm -f "$MOODLE_ARCHIVE"

install -d -o www-data -g www-data -m 0770 "$MOODLE_DATA"
chown -R www-data:www-data "$MOODLE_DIR"

echo "[6/10] Instaluję Moodle z CLI..."
runuser -u www-data -- /usr/bin/php "$MOODLE_DIR/admin/cli/install.php" \
  --non-interactive \
  --agree-license \
  --lang="$MOODLE_LANG" \
  --wwwroot="$MOODLE_URL" \
  --dataroot="$MOODLE_DATA" \
  --dbtype=mariadb \
  --dbhost=localhost \
  --dbname="$DB_NAME" \
  --dbuser="$DB_USER" \
  --dbpass="$DB_PASS" \
  --prefix=mdl_ \
  --fullname="$SITE_FULLNAME" \
  --shortname="$SITE_SHORTNAME" \
  --adminuser="$ADMIN_USER" \
  --adminpass="$ADMIN_PASS" \
  --adminemail="$ADMIN_EMAIL" \
  --chmod=2770

if ! grep -q '\$CFG->routerconfigured' "$MOODLE_DIR/config.php"; then
  sed -i '/require_once(__DIR__ .*\/lib\/setup.php.*);/i $CFG->routerconfigured = true;' "$MOODLE_DIR/config.php"
fi

grep -q '\$CFG->routerconfigured = true;' "$MOODLE_DIR/config.php" || {
  echo "Nie udało się dodać \$CFG->routerconfigured do config.php." >&2
  exit 1
}

runuser -u www-data -- /usr/bin/php "$MOODLE_DIR/admin/cli/cfg.php" --name=timezone --set="$PHP_TIMEZONE" >/dev/null

echo "[7/10] Instaluję auth_manualapproval i konfiguruję rejestrację..."
DEPLOY_ARCHIVE="/tmp/szkolenia-deploy.tar.gz"
DEPLOY_DIR="/tmp/szkolenia-deploy"
rm -rf "$DEPLOY_DIR" "$DEPLOY_ARCHIVE"
curl -fL --retry 3 --retry-delay 2 "$DEPLOY_REPO_ARCHIVE" -o "$DEPLOY_ARCHIVE"
mkdir -p "$DEPLOY_DIR"
tar -xzf "$DEPLOY_ARCHIVE" -C "$DEPLOY_DIR" --strip-components=1
rm -f "$DEPLOY_ARCHIVE"

[[ -f "$DEPLOY_DIR/moodle/auth/manualapproval/auth.php" ]] || { echo "Brak auth_manualapproval w repo wdrożeniowym." >&2; exit 1; }
[[ -f "$DEPLOY_DIR/moodle/configure.php" ]] || { echo "Brak moodle/configure.php w repo wdrożeniowym." >&2; exit 1; }

rm -rf "$MOODLE_DIR/public/auth/manualapproval"
cp -a "$DEPLOY_DIR/moodle/auth/manualapproval" "$MOODLE_DIR/public/auth/manualapproval"
chown -R www-data:www-data "$MOODLE_DIR/public/auth/manualapproval"

runuser -u www-data -- /usr/bin/php "$MOODLE_DIR/admin/cli/upgrade.php" --non-interactive
runuser -u www-data -- env \
  MOODLE_DIR="$MOODLE_DIR" \
  REGISTRATION_CONTACT_PHONE="$REGISTRATION_CONTACT_PHONE" \
  /usr/bin/php "$DEPLOY_DIR/moodle/configure.php"

REGISTER_AUTH="$(runuser -u www-data -- /usr/bin/php "$MOODLE_DIR/admin/cli/cfg.php" --name=registerauth 2>/dev/null | tail -n1 | tr -d '\r')"
if [[ "$REGISTER_AUTH" != "manualapproval" ]]; then
  echo "Nie udało się ustawić registerauth=manualapproval (otrzymano: $REGISTER_AUTH)." >&2
  exit 1
fi
rm -rf "$DEPLOY_DIR"

echo "[8/10] Ustawiam bezpieczne prawa dostępu..."
chown -R root:root "$MOODLE_DIR"
find "$MOODLE_DIR" -type d -exec chmod 0755 {} +
find "$MOODLE_DIR" -type f -exec chmod 0644 {} +
chown root:www-data "$MOODLE_DIR/config.php"
chmod 0640 "$MOODLE_DIR/config.php"
chown -R www-data:www-data "$MOODLE_DATA"
find "$MOODLE_DATA" -type d -exec chmod 0770 {} +
find "$MOODLE_DATA" -type f -exec chmod 0660 {} +

echo "[9/10] Konfiguruję Nginx i cron..."
cat > /etc/nginx/sites-available/moodle <<NGINX
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

    location ~ (/vendor/|/node_modules/|composer\.json|/readme|/README|readme\.txt|/upgrade\.txt|/UPGRADING\.md|db/install\.xml|/fixtures/|/behat/|phpunit\.xml|\.lock|environment\.xml) {
        deny all;
        return 404;
    }
}
NGINX

rm -f /etc/nginx/sites-enabled/default
ln -sfn /etc/nginx/sites-available/moodle /etc/nginx/sites-enabled/moodle
nginx -t
systemctl enable --now nginx
systemctl restart nginx

cat > /etc/cron.d/moodle <<EOFCRON
* * * * * www-data /usr/bin/php ${MOODLE_DIR}/admin/cli/cron.php >/dev/null 2>&1
EOFCRON
chmod 0644 /etc/cron.d/moodle
systemctl enable --now cron
runuser -u www-data -- /usr/bin/php "$MOODLE_DIR/admin/cli/cron.php" >/dev/null

echo "[10/10] Wykonuję testy końcowe..."
nginx -t
systemctl is-active --quiet nginx
systemctl is-active --quiet "php${PHP_VERSION}-fpm"
systemctl is-active --quiet mariadb
systemctl is-active --quiet cron
curl -fsS --max-time 10 http://127.0.0.1/ >/dev/null
curl -fsS --max-time 10 http://127.0.0.1/login/signup.php | grep -q 'name="institution"' || {
  echo "Formularz auth_manualapproval nie został poprawnie wyrenderowany." >&2
  exit 1
}

write_secrets

echo
echo "============================================================"
echo "Moodle został zainstalowany poprawnie."
echo "============================================================"
cat "$SECRETS_FILE"
echo
echo "Dane są zapisane również w: $SECRETS_FILE (prawa 0600)"
echo "Rejestracja studentów jest włączona i wymaga zatwierdzenia administratora."
