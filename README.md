# Szkolenia — Moodle 5.2 na Proxmox LXC

Automatyczne utworzenie osobnego kontenera LXC i kompletne wdrożenie Moodle dla jednej firmy.

## Co instaluje skrypt

- Proxmox VE → LXC Debian 13 **unprivileged**
- Nginx
- PHP 8.4-FPM
- MariaDB z repozytorium Debiana 13
- Moodle 5.2.2
- `/var/www/moodle` — kod aplikacji
- `/var/www/moodle/public` — publiczny webroot Nginx
- `/var/moodledata` — dane Moodle poza webrootem
- cron Moodle co minutę jako `www-data`
- własny plugin `auth_manualapproval`
- rejestrację użytkowników bez podawania prawdziwego adresu e-mail
- automatyczne generowanie loginu
- ręczne zatwierdzanie nowych kont przez administratora
- powiadomienie administratora o nowej rejestracji
- zablokowanie studentom zarządzania blokami na Kokpicie (`moodle/my:manageblocks = Prevent` dla roli Uwierzytelniony użytkownik)

Domyślne zasoby LXC:

- 2 vCPU
- 4096 MB RAM
- 1024 MB swap
- 30 GB dysku
- DHCP
- autostart kontenera

## Najprostsza instalacja

Uruchom jako `root` na hoście Proxmox:

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/Pawel-sp9pw/szkolenia/main/proxmox-lxc/create-ct.sh)"
```

Skrypt wybierze kolejny wolny CTID, pobierze Debian 13, utworzy unprivileged LXC, zainstaluje cały stos oraz skonfiguruje Moodle i rejestrację.

Przy `MOODLE_URL=auto` adresem strony będzie:

```text
http://ADRES_IP_KONTENERA
```

Hasło administratora Moodle i hasło bazy są generowane automatycznie, jeśli nie podasz ich samodzielnie.

Po instalacji dane dostępowe są wyświetlane na konsoli i zapisane w kontenerze z prawami `0600`:

```bash
pct exec CTID -- cat /root/moodle-install-secrets.txt
```

## Rejestracja użytkowników

Formularz rejestracyjny zawiera:

- imię,
- nazwisko,
- firmę,
- stanowisko,
- hasło,
- powtórzenie hasła.

Użytkownik nie podaje loginu ani adresu e-mail.

Po wysłaniu formularza plugin:

1. generuje login w rodzaju `jan.kowalski`,
2. przy kolizji tworzy kolejny unikalny wariant,
3. ustawia techniczny adres `login@noemail.invalid`,
4. ustawia `emailstop = 1`,
5. ustawia `maildisplay = 0`,
6. zapisuje firmę w standardowym polu `institution`,
7. zapisuje stanowisko w standardowym polu `department`,
8. jeśli istnieją pola niestandardowe o shortname `institution` lub `department`, kopiuje wartości także do nich,
9. tworzy konto jako niezatwierdzone (`confirmed = 0`),
10. pokazuje użytkownikowi wygenerowany login i informację o aktywacji do 2 dni roboczych,
11. wysyła administratorom Moodle powiadomienie o nowym koncie.

Nie jest wysyłany link aktywacyjny do użytkownika. Konto może zostać aktywowane wyłącznie przez administratora.

## Zatwierdzanie kont

Administrator ma stronę:

```text
/auth/manualapproval/pending.php
```

W panelu administracyjnym jest ona dostępna jako **Konta oczekujące na zatwierdzenie**.

Na liście widać:

- imię i nazwisko,
- wygenerowany login,
- firmę,
- stanowisko,
- datę rejestracji.

Po weryfikacji administrator wybiera **Zatwierdź konto**, a następnie może przypisać użytkownika do właściwego kursu lub kursów.

Operacja zatwierdzania wymaga capability `moodle/user:update` i jest chroniona standardowym `sesskey` Moodle.

## Instalacja z własnymi parametrami

Przykład ze statycznym adresem IP i numerem telefonu wyświetlanym po rejestracji:

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
REGISTRATION_CONTACT_PHONE='+48 32 000 00 00' \
bash -c "$(curl -fsSL https://raw.githubusercontent.com/Pawel-sp9pw/szkolenia/main/proxmox-lxc/create-ct.sh)"
```

Jeżeli `REGISTRATION_CONTACT_PHONE` pozostanie pusty, użytkownik zobaczy ogólną informację o kontakcie telefonicznym z administratorem szkoleń.

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
| `REGISTRATION_CONTACT_PHONE` | puste | numer telefonu pokazywany użytkownikowi po rejestracji |
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
- kod Moodle po instalacji jest własnością `root`; Nginx/PHP ma dostęp tylko do odczytu,
- `/var/moodledata` należy do `www-data` i nie znajduje się w webroot,
- `config.php` ma prawa `0640` i właściciela `root:www-data`,
- plik z hasłami instalacyjnymi ma prawa `0600`,
- `fastcgi_intercept_errors` jest ustawione na `off`,
- plugin jest instalowany przed odebraniem praw zapisu do kodu,
- provisioning wykonuje `admin/cli/upgrade.php` po skopiowaniu pluginu,
- końcowy test sprawdza dostępność strony oraz wyrenderowanie formularza rejestracyjnego.

## Cron

```text
/etc/cron.d/moodle
```

```cron
* * * * * www-data /usr/bin/php /var/www/moodle/admin/cli/cron.php >/dev/null 2>&1
```

Cron jest uruchamiany ręcznie jeden raz podczas instalacji jako test.

## Pliki repozytorium

```text
proxmox-lxc/create-ct.sh
proxmox-lxc/provision.sh
moodle/configure.php
moodle/auth/manualapproval/auth.php
moodle/auth/manualapproval/signup_form.php
moodle/auth/manualapproval/pending.php
moodle/auth/manualapproval/settings.php
moodle/auth/manualapproval/version.php
moodle/auth/manualapproval/db/messages.php
moodle/auth/manualapproval/classes/privacy/provider.php
moodle/auth/manualapproval/lang/pl/auth_manualapproval.php
moodle/auth/manualapproval/lang/en/auth_manualapproval.php
```

## Co pozostaje do konfiguracji po pierwszym wdrożeniu

Po potwierdzeniu działania instalacji można skonfigurować:

- publiczny DNS / Cloudflare,
- TLS i ewentualny reverse proxy,
- SMTP Mailcow,
- właściwy adres e-mail administratora,
- kursy, materiały i zasady ukończenia,
- backup aplikacji, bazy i `moodledata`.

Historia ukończeń i wyniki szkoleń powinny być zachowywane pomiędzy kolejnymi wersjami kursów; nie należy resetować poprzednich szkoleń tylko po to, aby uruchomić nową edycję.
