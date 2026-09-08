# Szkolenia — Moodle 5.2 na Proxmox LXC

Automatyczne wdrożenie osobnego Moodle dla jednej firmy.

## Stos

- Proxmox VE
- LXC Debian 12, unprivileged
- Nginx
- PHP 8.4-FPM z `packages.sury.org/php`
- MariaDB 10.11 z repozytorium Debiana 12
- Moodle 5.2.2
- `/var/www/moodle`
- `/var/www/moodle/public` jako webroot
- `/var/moodledata` poza webrootem
- cron Moodle co minutę jako `www-data`
- własny `auth_manualapproval`

Debian 12 ma natywnie PHP 8.2, które jest za stare dla Moodle 5.2. Dlatego provisioning dodaje repozytorium Sury i instaluje PHP 8.4. MariaDB pozostaje z natywnego repo Debiana 12.

## Domyślne zasoby

- 2 vCPU
- 4096 MB RAM
- 1024 MB swap
- 30 GB dysku
- DHCP
- autostart LXC

## Instalacja

Uruchom jako `root` na hoście Proxmox:

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/Pawel-sp9pw/szkolenia/main/proxmox-lxc/create-ct.sh)"
```

Skrypt wybiera najnowszy dostępny `debian-12-standard_*`, tworzy unprivileged LXC i wykonuje provisioning Moodle.

## Rejestracja użytkowników

Po instalacji aktywna jest własna metoda `auth_manualapproval`:

- użytkownik podaje imię, nazwisko, firmę, stanowisko i hasło,
- login jest generowany automatycznie,
- techniczny e-mail ma postać `login@noemail.invalid`,
- `emailstop=1`, `maildisplay=0`,
- firma trafia do `institution`,
- stanowisko do `department`,
- konto oczekuje na zatwierdzenie administratora,
- administrator dostaje powiadomienie,
- lista kont oczekujących: `/auth/manualapproval/pending.php`,
- `moodle/my:manageblocks` jest ustawione na Prevent dla roli uwierzytelnionego użytkownika.

## Dane instalacyjne

Po wdrożeniu:

```bash
pct exec CTID -- cat /root/moodle-install-secrets.txt
```

## Własne parametry

Przykład:

```bash
CTID=250 \
CT_HOSTNAME=moodle-firma \
STORAGE=local-lvm \
BRIDGE=vmbr0 \
IP_CONFIG='ip=192.168.10.50/24,gw=192.168.10.1' \
MOODLE_URL='http://192.168.10.50' \
SITE_FULLNAME='Szkolenia Firma' \
ADMIN_EMAIL='administrator@firma.pl' \
REGISTRATION_CONTACT_PHONE='+48 32 000 00 00' \
bash -c "$(curl -fsSL https://raw.githubusercontent.com/Pawel-sp9pw/szkolenia/main/proxmox-lxc/create-ct.sh)"
```

## Ważne

Instalator nie nadpisuje istniejącego CTID ani działającej instalacji Moodle. Niedokończony katalog Moodle bez `config.php` jest przenoszony do katalogu awaryjnego zamiast kasowany.
