#!/usr/bin/env bash
set -euo pipefail

# Moodle LXC installer for Proxmox VE.
# Run as root on the Proxmox host.
# Defaults: Debian 12, unprivileged LXC, nesting enabled, 2 vCPU, 4 GB RAM, 1 GB swap, 30 GB disk.

CTID="${CTID:-$(pvesh get /cluster/nextid)}"
CT_HOSTNAME="${CT_HOSTNAME:-moodle-szkolenia}"
STORAGE="${STORAGE:-local-lvm}"
TEMPLATE_STORAGE="${TEMPLATE_STORAGE:-local}"
BRIDGE="${BRIDGE:-vmbr0}"
IP_CONFIG="${IP_CONFIG:-ip=dhcp}"
ROOTFS_GB="${ROOTFS_GB:-30}"
MEMORY_MB="${MEMORY_MB:-4096}"
SWAP_MB="${SWAP_MB:-1024}"
CORES="${CORES:-2}"
ONBOOT="${ONBOOT:-1}"
START_AFTER_CREATE="${START_AFTER_CREATE:-1}"

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
REGISTRATION_CONTACT_PHONE="${REGISTRATION_CONTACT_PHONE:-}"

REPO_RAW_BASE="${REPO_RAW_BASE:-https://raw.githubusercontent.com/Pawel-sp9pw/szkolenia/main}"
PROVISION_URL="${PROVISION_URL:-${REPO_RAW_BASE}/proxmox-lxc/provision.sh}"
FINALIZER_URL="${FINALIZER_URL:-${REPO_RAW_BASE}/proxmox-lxc/finalize-existing.sh}"

if [[ $EUID -ne 0 ]]; then
  echo "Ten skrypt musi być uruchomiony jako root na hoście Proxmox VE." >&2
  exit 1
fi

for cmd in pct pveam pvesh curl bash; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "Brak wymaganego polecenia: $cmd" >&2; exit 1; }
done

if pct status "$CTID" >/dev/null 2>&1; then
  echo "CTID $CTID już istnieje. Nie wykonuję żadnych zmian." >&2
  exit 1
fi

[[ "$MOODLE_VERSION" == 5.2.* ]] || { echo "Instalator jest przygotowany dla Moodle 5.2.x." >&2; exit 1; }
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ && "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Nieprawidłowa nazwa bazy/użytkownika." >&2; exit 1; }

cleanup() {
  [[ -n "${TMP_PROVISION:-}" && -f "${TMP_PROVISION:-}" ]] && rm -f "$TMP_PROVISION"
  [[ -n "${TMP_FINALIZER:-}" && -f "${TMP_FINALIZER:-}" ]] && rm -f "$TMP_FINALIZER"
}
trap cleanup EXIT

echo "[1/7] Aktualizuję listę szablonów LXC..."
pveam update >/dev/null
TEMPLATE="$(pveam available --section system | awk '$2 ~ /^debian-12-standard_/ {print $2}' | sort -V | tail -n1)"
if [[ -z "${TEMPLATE:-}" ]]; then
  echo "Nie znaleziono szablonu Debian 12 w pveam." >&2
  exit 1
fi

if ! pveam list "$TEMPLATE_STORAGE" 2>/dev/null | awk '{print $1}' | grep -q "/${TEMPLATE}$"; then
  echo "[2/7] Pobieram szablon: $TEMPLATE"
  pveam download "$TEMPLATE_STORAGE" "$TEMPLATE"
else
  echo "[2/7] Szablon jest już dostępny: $TEMPLATE"
fi

TEMPLATE_VOL="${TEMPLATE_STORAGE}:vztmpl/${TEMPLATE}"

echo "[3/7] Tworzę unprivileged LXC Debian 12 CTID=$CTID ($CT_HOSTNAME)..."
pct create "$CTID" "$TEMPLATE_VOL" \
  --hostname "$CT_HOSTNAME" \
  --ostype debian \
  --cores "$CORES" \
  --memory "$MEMORY_MB" \
  --swap "$SWAP_MB" \
  --rootfs "${STORAGE}:${ROOTFS_GB}" \
  --net0 "name=eth0,bridge=${BRIDGE},${IP_CONFIG}" \
  --unprivileged 1 \
  --features nesting=1 \
  --onboot "$ONBOOT" \
  --start 0

pct start "$CTID"
echo "[4/7] Czekam na start kontenera i sieci..."
for _ in $(seq 1 60); do
  pct exec "$CTID" -- bash -lc 'ip -4 -o addr show scope global dev eth0 | grep -q .' && break
  sleep 1
done
pct exec "$CTID" -- bash -lc 'ip -4 -o addr show scope global dev eth0 | grep -q .' || {
  echo "Kontener nie otrzymał IPv4. Pozostawiam go do diagnostyki." >&2; exit 1;
}

for _ in $(seq 1 30); do
  pct exec "$CTID" -- bash -lc 'getent ahostsv4 deb.debian.org >/dev/null 2>&1' && break
  sleep 1
done
pct exec "$CTID" -- bash -lc 'getent ahostsv4 deb.debian.org >/dev/null 2>&1' || {
  echo "DNS/Internet w kontenerze nie działa." >&2; exit 1;
}

echo "[5/7] Pobieram provisioning z GitHub..."
TMP_PROVISION="$(mktemp /tmp/moodle-provision.XXXXXX.sh)"
curl -fsSL "$PROVISION_URL" -o "$TMP_PROVISION"
bash -n "$TMP_PROVISION"
pct push "$CTID" "$TMP_PROVISION" /root/moodle-provision.sh
pct exec "$CTID" -- chmod 0700 /root/moodle-provision.sh

echo "[6/7] Instaluję Moodle i konfigurację szkoleniową..."
pct exec "$CTID" -- env \
  "MOODLE_VERSION=$MOODLE_VERSION" \
  "MOODLE_URL=$MOODLE_URL" \
  "MOODLE_LANG=$MOODLE_LANG" \
  "SITE_FULLNAME=$SITE_FULLNAME" \
  "SITE_SHORTNAME=$SITE_SHORTNAME" \
  "ADMIN_USER=$ADMIN_USER" \
  "ADMIN_PASS=$ADMIN_PASS" \
  "ADMIN_EMAIL=$ADMIN_EMAIL" \
  "PHP_TIMEZONE=$PHP_TIMEZONE" \
  "DB_NAME=$DB_NAME" \
  "DB_USER=$DB_USER" \
  "REGISTRATION_CONTACT_PHONE=$REGISTRATION_CONTACT_PHONE" \
  bash /root/moodle-provision.sh

echo "[7/7] Wdrażam motyw FUB, dokładne logo i finalne testy..."
TMP_FINALIZER="$(mktemp /tmp/moodle-finalizer.XXXXXX.sh)"
curl -fsSL "$FINALIZER_URL" -o "$TMP_FINALIZER"
bash -n "$TMP_FINALIZER"
pct push "$CTID" "$TMP_FINALIZER" /root/moodle-finalize.sh
pct exec "$CTID" -- chmod 0700 /root/moodle-finalize.sh
pct exec "$CTID" -- env \
  "REGISTRATION_CONTACT_PHONE=$REGISTRATION_CONTACT_PHONE" \
  bash /root/moodle-finalize.sh

if [[ "$START_AFTER_CREATE" != "1" ]]; then pct stop "$CTID"; fi

echo
echo "============================================================"
echo "Instalacja zakończona. CTID: $CTID"
echo "Motyw: FUB"
echo "============================================================"
if [[ "$START_AFTER_CREATE" == "1" ]]; then
  pct exec "$CTID" -- cat /root/moodle-install-secrets.txt
else
  echo "Po uruchomieniu kontenera: pct exec $CTID -- cat /root/moodle-install-secrets.txt"
fi

echo
echo "Konfiguracja LXC:"
pct config "$CTID" | grep -E '^(hostname|cores|memory|swap|rootfs|net0|features|unprivileged|onboot):' || true
