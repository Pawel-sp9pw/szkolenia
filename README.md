# Szkolenia — Moodle 5.2 na Proxmox LXC

Automatyczne utworzenie osobnego kontenera LXC i wdrożenie Moodle dla jednej firmy.

## Docelowy stos

- Proxmox VE
- LXC Debian 13, **unprivileged**
- Nginx
- PHP 8.4-FPM
- MariaDB z repozytorium Debiana 13
- Moodle 5.2.2
- `/var/www/moodle` — kod aplikacji
- `/var/www/moodle/public` — publiczny webroot Nginx
- `/var/moodledata` — dane Moodle poza webrootem
- cron Moodle co minutę jako `www-data`

Domyślne zasoby LXC:

- 2 vCPU
- 4096 MB RAM
- 1024 MB swap
- 30 GB dysku
- DHCP
- autostart kontenera

## Najprostsza instalacja

Polecenie uruchom **jako root na hoście Proxmox**:

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/Pawel-sp9pw/szkolenia/main/proxmox-lxc/create-ct.sh)"
```

Skrypt:

1. wybiera następny wolny CTID,
2. pobiera najnowszy dostępny template Debian 13 z `pveam`,
3. tworzy unprivileged LXC,
4. uruchamia kontener i sprawdza IPv4/DNS,
5. pobiera `provision.sh` z tego repozytorium,
6. instaluje Nginx, PHP-FPM i MariaDB,
7. pobiera dokładnie Moodle 5.2.2 z `download.moodle.org`,
8. tworzy bazę i losowe hasło bazy,
9. instaluje Moodle z CLI,
10. konfiguruje Nginx pod `/public` oraz router `r.php`,
11. ustawia cron co minutę,
12. wykonuje test crona i usług.

Przy `MOODLE_URL=auto` adresem strony będzie automatycznie:

```text
http://ADRES_IP_KONTENERA
```

Hasło administratora Moodle i hasło bazy są generowane automatycznie, jeśli nie podasz ich samodzielnie.

Po instalacji skrypt wyświetli dane dostępowe. Są one również zapisane wyłącznie dla roota w kontenerze:

```bash
pct exec CTID -- cat /root/moodle-install-secrets.txt
```

## Instalacja z własnymi parametrami

Przykład ze statycznym adresem IP:

```bash
CTID=250 \
CT_HOSTNAME=moodle-firma \
STORAGE=local-lvm \
BRIDGE=vmbr0 \
IP_CONFIG='ip=192.168.10.50/24,gw=192.168.10.1' \
MOODLE_URL='http://192.168.10.50' \
SITE_FULLNAME='Szkolenia Firma' \
SITE_SHORTNAME='Szkolenia' \
ADMIN_EMAIL='administrator@firma.pl' \
bash -c "$(curl -fsSL https://raw.githubusercontent.com/Pawel-sp9pw/szkolenia/main/proxmox-lxc/create-ct.sh)"
```

Jeżeli docelowy HTTPS będzie terminowany na reverse proxy, adres `MOODLE_URL=https://...` ustawimy razem z właściwą konfiguracją proxy po potwierdzeniu docelowej ścieżki ruchu. Do pierwszego uruchomienia najbezpieczniej użyć adresu HTTP kontenera.

## Najważniejsze parametry

| Zmienna | Domyślnie | Znaczenie |
|---|---:|---|
| `CTID` | następny wolny | ID kontenera |
| `CT_HOSTNAME` | `moodle-szkolenia` | hostname LXC |
| `STORAGE` | `local-lvm` | storage rootfs |
| `TEMPLATE_STORAGE` | `local` | storage template LXC |
| `BRIDGE` | `vmbr0` | bridge sieciowy |
| `IP_CONFIG` | `ip=dhcp` | konfiguracja IPv4 dla `net0` |
| `ROOTFS_GB` | `30` | dysk w GB |
| `MEMORY_MB` | `4096` | RAM w MB |
| `SWAP_MB` | `1024` | swap w MB |
| `CORES` | `2` | vCPU |
| `MOODLE_VERSION` | `5.2.2` | wersja Moodle; instalator jest ograniczony do 5.2.x |
| `MOODLE_URL` | `auto` | URL Moodle lub automatyczny adres IP LXC |
| `MOODLE_LANG` | `pl` | język instalacji |
| `SITE_FULLNAME` | `Szkolenia` | pełna nazwa serwisu |
| `SITE_SHORTNAME` | `Szkolenia` | skrócona nazwa |
| `ADMIN_USER` | `admin` | login administratora |
| `ADMIN_PASS` | losowe | opcjonalne własne hasło administratora |
| `ADMIN_EMAIL` | `admin@noemail.invalid` | e-mail administratora do późniejszej zmiany |
| `PHP_TIMEZONE` | `Europe/Warsaw` | strefa czasu PHP/Moodle |
| `DB_NAME` | `moodle` | nazwa bazy |
| `DB_USER` | `moodle` | użytkownik bazy |
| `START_AFTER_CREATE` | `1` | pozostawienie LXC uruchomionego |

Dla statycznego IP składnia `IP_CONFIG` jest przekazywana bezpośrednio do `pct`, np.:

```bash
IP_CONFIG='ip=10.20.30.40/24,gw=10.20.30.1'
```

## Bezpieczeństwo i odporność na przypadkowe nadpisanie

- LXC jest zawsze tworzony jako `unprivileged=1`.
- Skrypt hosta przerywa pracę, jeśli wybrany CTID już istnieje.
- `provision.sh` nie nadpisuje Moodle, jeżeli istnieje `/var/www/moodle/config.php`.
- Jeśli istnieje niedokończony katalog Moodle bez `config.php`, jest przenoszony do `/root/moodle-incomplete-DATA-CZAS` zamiast kasowany.
- Kod Moodle po instalacji jest własnością `root`; Nginx/PHP ma dostęp tylko do odczytu.
- `/var/moodledata` należy do `www-data` i nie znajduje się w webroot.
- `config.php` ma prawa `0640` i właściciela `root:www-data`.
- plik z hasłami instalacyjnymi ma prawa `0600`.
- błędy PHP nie są maskowane przez `fastcgi_intercept_errors on`.

## Cron

Cron jest zapisany w:

```text
/etc/cron.d/moodle
```

Treść:

```cron
* * * * * www-data /usr/bin/php /var/www/moodle/admin/cli/cron.php >/dev/null 2>&1
```

Przy instalacji cron jest również uruchamiany ręcznie jeden raz jako test.

## Pliki

```text
proxmox-lxc/create-ct.sh   # uruchamiany na hoście Proxmox
proxmox-lxc/provision.sh   # provisioning wykonywany wewnątrz LXC
```

## Czego ten etap jeszcze nie konfiguruje

Celowo pozostawione na później, po potwierdzeniu poprawnego działania bazowej instalacji:

- publiczny DNS / Cloudflare,
- TLS i ewentualny reverse proxy,
- SMTP Mailcow,
- własna wtyczka `auth_manualapproval`,
- konfiguracja ról i uprawnień Moodle,
- dedykowane kursy i materiały szkoleniowe,
- backupy aplikacji, bazy i `moodledata`.

Najpierw uruchamiamy i testujemy czystą warstwę techniczną.
