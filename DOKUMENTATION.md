# Stellar Express Monitor – Technische Dokumentation

**Projekt:** stellar_web_monitor (MIT-Lizenz, siehe `LICENSE` und `README.md`)
**Zweck:** Passive WLAN-Übersicht für ein Alcatel-Lucent OmniAccess Stellar Express Cluster (bis 32 APs)
**Stand:** 12.11.2026

---

## 1. Überblick

Der Stellar Express Monitor ist eine kleine Webanwendung, die mobil nutzbar ist. Sie zeigt dem Kunden:

- alle Access Points mit Status (online/offline), IP, Modell und Firmware
- die verbundenen Clients je AP mit IP, SSID, Signal und Durchsatz
- **wann zuletzt erfolgreich an welchem AP angemeldet wurde, mit Gerät und IP**
- einen Verlauf aus Anmeldungen, AP-Wechseln (Roaming) und Abmeldungen

Die Northbound-API des Express Clusters liefert nur den **aktuellen Zustand**. Sie speichert keine Historie und liefert keine Raten. Deshalb fragt ein eigener Hintergrundprozess (der Poller) das Cluster in festen Abständen ab. Aus dem Vergleich zweier Abfragen berechnet er Ereignisse und Durchsatz und speichert alles in einer SQLite-Datenbank.

Die Anwendung **liest nur**. Sie verwendet ausschließlich lesende API-Methoden und ändert nichts am Cluster.

---

## 2. Architektur

```
┌──────────────────────┐   JSON-RPC (HTTP :8080)   ┌──────────────────────────┐
│  Stellar Express     │ ◄──────────────────────── │  Container "poller"      │
│  Cluster (PVC)       │   system.getPvc, logIn    │  php /app/poller.php     │
│  /apiaccess          │   system.getApList        │  alle POLL_INTERVAL s    │
│                      │   ap.getClient (je AP)    │                          │
│                      │   ap.getSystemInfo        └────────────┬─────────────┘
└──────────────────────┘                                        │ schreibt
                                                                ▼
                                                 ┌──────────────────────────┐
                                                 │  Volume /data            │
                                                 │  monitor.sqlite (WAL)    │
                                                 │  raw/*.json (Rohdaten)   │
                                                 └────────────┬─────────────┘
                                                              │ liest
                                                              ▼
┌──────────────────────┐        HTTP :8088        ┌──────────────────────────┐
│  Browser (Kunde)     │ ◄──────────────────────► │  Container "web"         │
│  Handy / PC          │   index.php, debug.php   │  Apache + PHP 8.3        │
└──────────────────────┘                          └──────────────────────────┘
```

| Container | Image | Aufgabe |
|---|---|---|
| `web` | `ghcr.io/dgawin/stellar_web_monitor` | Apache + PHP 8.3 und Auslieferung der Webseite |
| `poller` | dasselbe Image | Endlosschleife `php /app/poller.php`, läuft als `www-data` |

Beide Container nutzen **dasselbe Image** und **dasselbe Volume**. SQLite läuft im WAL-Modus mit `busy_timeout=5000`. Dadurch kann die Webseite lesen, während der Poller schreibt.

**Technik:** PHP 8.3 (`php:8.3-apache`), PDO SQLite und cURL. Es gibt keine weiteren Abhängigkeiten, kein Framework und kein JavaScript-Build. Das CSS ist selbst geschrieben, Hell- und Dunkelmodus folgen der Systemeinstellung.

---

## 3. Dateistruktur

```
stellar_web_monitor/
├── .github/workflows/docker.yml   Baut das Image und legt es in GHCR ab
├── Dockerfile                     Image-Definition
├── docker-compose.yml             Variante mit docker compose + .env (baut lokal)
├── portainer-stack.yml            Portainer-Stack, nutzt das GHCR-Image (Variable IMAGE)
├── .env.example                   Vorlage für alle Konfigurationswerte
├── README.md / LICENSE            Kurzanleitung, Lizenz, rechtliche Hinweise
├── DOKUMENTATION.md               Diese Datei
├── app/
│   ├── poller.php                 Abfrage-Dienst
│   ├── lib/
│   │   ├── bootstrap.php          Konfiguration, DB-Schema, API-Client, Feld-Normalisierung, Formatierung
│   │   └── auth.php               Optionaler Basic-Auth-Schutz der Webseite
│   └── public/                    DocumentRoot von Apache
│       ├── index.php              Webseite (3 Ansichten)
│       ├── debug.php              Rohdaten- und Live-Abfrage-Ansicht
│       └── style.css              Layout (responsive, Hell/Dunkel)
└── mock/
    └── cluster.php                Simuliertes Cluster (32 APs) im Antwortformat der API-Doku
```

---

## 4. Verwendete API (Northbound, JSON-RPC 2.0)

Endpunkt: `http(s)://<CLUSTER_IP>:<CLUSTER_PORT>/apiaccess`, Methode `POST`, Body JSON.

| Methode | Parameter | Wann | Wofür |
|---|---|---|---|
| `system.getPvc` | `username`, `password` | vor jedem Login (an `CLUSTER_IP`) | IP des aktuellen PVC ermitteln |
| `user.logIn` | `username`, `password` | bei Start, bei abgelaufener Session | Session-ID holen (an PVC-IP) |
| `system.getApList` | – | jeder Durchlauf | AP-Liste, Name, IP, Status, Rolle |
| `ap.getClient` | `apmac` | jeder Durchlauf, je **online**-AP | Clients am AP |
| `ap.getSystemInfo` | `apmac` | jeder `SYSINFO_EVERY`-te Durchlauf | Firmware, Uptime, Modell |
| `user.logOut` | – | nur in `debug.php` nach einer Live-Abfrage | Session beenden |

Beispiel-Request:

```json
{
  "id": 3,
  "jsonrpc": "2.0",
  "method": "ap.getClient",
  "session": "<session-id>",
  "params": { "apmac": "34:E7:0B:00:00:01" }
}
```

**Last bei 32 APs:** Pro Durchlauf fallen 1× `getApList` und bis zu 32× `getClient` an. Jeden 10. Durchlauf kommen bis zu 32× `getSystemInfo` dazu. Bei 60 s Intervall sind das rund 33 Requests pro Minute.

### 4.1 Login, PVC und Session-Handling

- **Benutzer:** Laut API-Doku ist nur `Administrator` zulässig.
- **Passwort als MD5:** Die API erwartet das Passwort als MD5-Hash (Standard `admin` → `21232f297a57a5a743894a0e4a801fc3`). Mit `CLUSTER_PASSWORD_HASH=auto` wird ein Klartext-Passwort automatisch gehasht, ein 32-stelliger Hex-Wert bleibt unverändert.
- **PVC-Ermittlung:** Alle Aufrufe außer `system.getPvc` müssen an den PVC gehen (sonst Antwort `Please access pvc`). Vor jedem Login fragt der Poller deshalb `system.getPvc` an `CLUSTER_IP` ab und nutzt danach die gelieferte `pvcip`. Wechselt der PVC, schlägt der nächste Aufruf fehl, es folgt ein neuer Login samt PVC-Ermittlung. Abschaltbar mit `RESOLVE_PVC=0`.
- **Session-Timeout:** Ohne Aufruf verfällt die Session nach 10 Minuten. Bei 60 s Intervall bleibt sie gültig.
- Die Session-ID (`result.sessionId`) wird **ohne festen Feldnamen** aus der Login-Antwort gelesen. `findSession()` sucht rekursiv nach einem Schlüssel, der `sess`, `token` oder `sid` enthält. So funktioniert das auch, wenn der Feldname je nach Firmware anders heißt.
- Antwortet ein Aufruf mit `error`, mit `success: "false"` oder mit einem `errCode` ungleich 0, wird die Session verworfen. Dann folgt **ein** neuer Login und der Aufruf wird wiederholt (`call()` mit `$retry`).
- Ein Fehler bei `ap.getClient` oder `ap.getSystemInfo` für **einen** AP wird ins Log geschrieben. Der Durchlauf läuft für die übrigen APs weiter.

### 4.2 Login-Fehler und Account-Sperre (Backoff)

Wenn der Login scheitert, sperrt das Cluster den Account nach mehreren Fehlversuchen. Die Antwort lautet dann `{"success":"false","message":"login log account lock"}`. Würde der Poller jede Minute neu versuchen, bliebe die Sperre dauerhaft bestehen.

Deshalb löst ein Login-Fehler eine eigene `LoginException` aus, und der Poller wartet länger:

| Fehlversuch | Wartezeit bis zum nächsten Versuch |
|---|---|
| 1 | 5 Min. |
| 2 | 10 Min. |
| 3 | 20 Min. |
| ab 4 | 30 Min. (`LOGIN_BACKOFF_MAX`) |

Nach einem erfolgreichen Durchlauf startet der Zähler wieder bei 0. Die Webseite zeigt den Fehler im Klartext an, zum Beispiel *„Account gesperrt (zu viele Fehlversuche) … nächster Versuch 10:31 Uhr“*. Bei einem einfachen Zugriffsfehler steht dort *„Login abgelehnt – Benutzer/Passwort prüfen“*.

---

## 5. Feld-Normalisierung

Die Feldnamen folgen dem *AP Northbound API User Guide*: `system.getApList` liefert `result.aps[]` mit `ap_name`, `mac`, `ip`, `status`, `role`, `version`. `ap.getClient` liefert `result.users[]` mit u. a. `on_line`, `mac`, `ip`, `hostname`, `AssocationSSID` (Schreibweise laut API), `AttachedBand`, `signal`, `uptime`, `tx_bytes`, `rx_bytes`, `tx_wifirate` und `ostype`. `ap.getSystemInfo` liefert `result.apinfo` mit `model`, `mac`, `ip`, `uptime` (Sekunden) und `version`. Da Firmware-Stände abweichen können, arbeitet der Poller trotzdem mit Listen möglicher Namen, die dokumentierten stehen jeweils vorn:

1. **`find_list()`** sucht in der Antwort die größte Liste von Objekten, zum Beispiel `result.apList` oder `result.clientList`.
2. **`flat()`** löst jedes Objekt in eine flache Map auf, auch verschachtelte Unterobjekte. Die Schlüssel werden dabei in Kleinbuchstaben umgewandelt, `_`, `-` und Leerzeichen fallen weg.
3. **`pick()`** gibt den ersten Treffer aus einer Liste von Kandidaten zurück.

Kandidaten (definiert in `bootstrap.php`):

| Konstante | Bedeutung | Kandidaten (Auszug) |
|---|---|---|
| `K_AP_MAC` | AP-MAC | apmac, mac, macaddr, basemac, ethmac |
| `K_AP_NAME` | AP-Name | apname, name, hostname, devicename |
| `K_AP_IP` | AP-IP | apip, ip, ipaddr, ipv4, mgmtip |
| `K_AP_MODEL` | Modell | model, apmodel, productname |
| `K_AP_VER` | Firmware | version, swversion, firmware, fwversion |
| `K_AP_ROLE` | Rolle | role, aprole, pvcrole |
| `K_AP_STATE` | Status | status, state, online, apstatus |
| `K_AP_UP` | Uptime | uptime, runtime, systemuptime |
| `K_CL_MAC` | Client-MAC | clientmac, stamac, usermac, mac |
| `K_CL_IP` | Client-IP | clientip, staip, ip, ipaddr, ipv4 |
| `K_CL_HOST` | Hostname | hostname, clientname, name, devicename |
| `K_CL_SSID` | SSID | ssid, essid, wlan, wlanname |
| `K_CL_BAND` | Band | band, radio, radiotype, frequency |
| `K_CL_RSSI` | Signal | rssi, signal, signalstrength, snr |
| `K_CL_TXB` / `K_CL_RXB` | Byte-Zähler | txbytes, sendbytes, downbytes / rxbytes, recvbytes, upbytes |
| `K_CL_TXR` / `K_CL_RXR` | PHY-Rate | txrate, linkrate, phyrate / rxrate |
| `K_CL_ONL` | Online-Dauer | onlinetime, assoctime, connecttime |

Weitere Regeln:

- **MAC-Adressen** werden einheitlich auf `aa:bb:cc:dd:ee:ff` gebracht (`norm_mac()`).
- **AP-Status:** `getApList` liefert `status` als Zahl: 1 Joining, 2 Initializing, 3 Working, 4 Down. 1–3 gilt als online, 4 als offline. Textwerte wie `up`/`online` werden ebenfalls erkannt. Fehlt das Statusfeld, gilt der AP als online.
- **AP-Rolle:** `role` 1 = PVC, 2 = SVC, 3 = VC, angezeigt als Text.
- **Clients:** Einträge mit `on_line: false` werden ignoriert. `AttachedBand` 2/5 wird als „2,4 GHz“/„5 GHz“ angezeigt. Fehlt der Hostname, wird `ostype` genutzt („Android-Gerät“).
- **Signal:** `signal` ist in der API positiv (Beispiel 35) und wird als „Signal 35 dB“ angezeigt, Farben: grün ab 25, gelb ab 15. Negative Werte werden als dBm behandelt (grün ab −60, gelb ab −72).
- **Byte-Werte:** `to_bytes()` versteht Zahlen und auch Texte wie `"12.5 MB"`.
- **Client-IP:** Ist der Wert keine gültige IP (zum Beispiel `0.0.0.0`-Platzhalter oder Text), wird er verworfen. Die zuletzt bekannte IP bleibt dann stehen (`COALESCE`).

**Anpassen:** Bleibt ein Wert auf der Webseite leer, schaust du in `debug.php` unter „Erkannte Felder“ nach dem echten Feldnamen. Diesen trägst du in die passende `K_*`-Liste ein und baust das Image neu.

---

## 6. Ablauf eines Poll-Durchlaufs (`poll()`)

1. **AP-Liste holen** mit `system.getApList` und als Rohdaten unter `raw/system.getApList.json` speichern.
2. **Je AP** Werte normalisieren. Ist ein Systeminfo-Zyklus fällig und der AP online, zusätzlich `ap.getSystemInfo` abfragen. Werte aus der AP-Liste haben dabei Vorrang.
3. **Vorherigen Zustand** aller Clients aus der Datenbank laden.
4. **In einer Transaktion je online-AP** `ap.getClient` abfragen und für jeden Client:
   - **Durchsatz berechnen** (siehe 6.1)
   - **Ereignis bestimmen** (siehe 6.2)
   - Datensatz per Upsert in `clients` schreiben, mit `connected=1`
5. **AP-Datensatz** per Upsert schreiben. `last_seen` wird nur aktualisiert, wenn der AP online ist. So zeigt ein offline-AP, seit wann er weg ist.
6. **Abmeldungen:** Clients, die vorher verbunden waren und jetzt fehlen, bekommen `connected=0` und einen `disconnect`-Eintrag im Verlauf.
7. **Fehlende APs:** APs, die nicht mehr in der Liste stehen, werden auf `online=0` gesetzt.
8. **Aufräumen:** Verlaufseinträge, die älter als `RETENTION_DAYS` sind, werden gelöscht. Danach werden `meta.last_poll` und `meta.poll_duration` gesetzt.

Ein Client, der in einem Durchlauf an mehreren APs gemeldet wird, wird nur beim ersten gezählt.

### 6.1 Durchsatz

Das Cluster liefert kumulierte Byte-Zähler. Daraus wird die Rate berechnet:

```
rate = (bytes_jetzt − bytes_vorher) / (zeit_jetzt − zeit_vorher)    [Bytes/s]
```

- Die Rate wird nur berechnet, wenn der Client in beiden Durchläufen **am selben AP** verbunden war und der Zähler nicht kleiner geworden ist. Ein kleinerer Zähler bedeutet einen Reset, zum Beispiel nach Roaming oder einem AP-Neustart.
- Das Ergebnis ist ein **Mittelwert über das Poll-Intervall**. Kurze Spitzen sind darin nicht sichtbar.
- Direkt nach einer Anmeldung oder einem AP-Wechsel steht der Wert für einen Durchlauf auf `–`.
- **Richtung:** `tx` ist aus Sicht des APs gezählt, also ↓ zum Client. `rx` ist ↑ vom Client. Falls das Cluster das umgekehrt zählt, kannst du `K_CL_TXB` und `K_CL_RXB` tauschen.
- Die Anzeige erfolgt in Bit/s mit 1000er-Schritten (kbit/s, Mbit/s), die Volumina in Bytes mit 1024er-Schritten.

### 6.2 Ereignisse (Verlauf)

| Bedingung | Ereignis | `assoc_since` |
|---|---|---|
| Client unbekannt oder vorher nicht verbunden | `connect` | jetzt |
| Client vorher an einem anderen AP | `roam` (mit `prev_ap_mac`) | jetzt |
| Client vorher verbunden, jetzt nicht mehr gemeldet | `disconnect` | – |

Liefert das Cluster eine Online-Dauer (`K_CL_ONL`), wird `assoc_since` damit korrigiert. Dann ist die Verbindungszeit auch beim ersten Start des Pollers schon richtig.

**Genauigkeit:** Ereignisse sind nur so genau wie das Poll-Intervall. Wer sich zwischen zwei Abfragen kurz an- und wieder abmeldet, taucht im Verlauf nicht auf.

---

## 7. Datenbank (SQLite)

Pfad: `DB_PATH`, Standard `/data/monitor.sqlite`. Das Schema wird beim ersten Zugriff automatisch angelegt.

**`aps`**: ein Datensatz je AP

| Spalte | Inhalt |
|---|---|
| `mac` (PK) | normalisierte AP-MAC |
| `name`, `ip`, `model`, `version`, `role`, `uptime` | Stammdaten (letzter bekannter Wert) |
| `online` | 1/0 |
| `client_count` | Clients im letzten Durchlauf |
| `last_seen` | letzter Zeitpunkt online |
| `first_seen` | erstes Auftauchen |
| `raw` | letzter Roh-Eintrag aus `getApList` (JSON) |

**`clients`**: ein Datensatz je Client-MAC (aktueller bzw. letzter Zustand)

| Spalte | Inhalt |
|---|---|
| `mac` (PK) | Client-MAC |
| `ap_mac`, `ip`, `hostname`, `ssid`, `band`, `rssi` | aktuelle Verbindung |
| `tx_bytes`, `rx_bytes` | Zählerstände |
| `tx_rate`, `rx_rate` | berechneter Durchsatz in Bytes/s |
| `link_tx`, `link_rx` | PHY-Rate (falls geliefert) |
| `connected` | 1 = aktuell verbunden |
| `assoc_since` | Beginn der aktuellen Verbindung an diesem AP |
| `last_seen`, `first_seen`, `raw` | wie oben |

**`assoc_log`**: Verlauf (Indizes auf `ts` und `ap_mac, ts`)

| Spalte | Inhalt |
|---|---|
| `id`, `ts` | laufende Nummer, Unix-Zeit |
| `event` | `connect` / `roam` / `disconnect` |
| `client_mac`, `ap_mac`, `prev_ap_mac` | beteiligte Geräte |
| `ip`, `hostname`, `ssid` | Werte zum Zeitpunkt des Ereignisses |

**`meta`**: Schlüssel/Wert-Tabelle mit `last_poll`, `last_error`, `poll_duration` und `next_login_try`

**Datenbank sichern:** Poller stoppen, dann `monitor.sqlite` samt `-wal`- und `-shm`-Datei aus dem Volume kopieren.

---

## 8. Webseite (`index.php`)

### 8.1 Kopfbereich (alle Ansichten)

- **Kennzahlen:** APs online/gesamt, verbundene Clients, Summe des Durchsatzes ↓ und ↑
- **Warnhinweis**, wenn die letzte Abfrage älter als 3 × `POLL_INTERVAL` ist oder `meta.last_error` gesetzt ist

### 8.2 Ansicht „Access Points“ (`?v=aps`)

Die APs stehen als **Liste** mit einer Zeile je AP (für 32 APs ausgelegt):

| Spalte | Inhalt |
|---|---|
| ● | grün = online, rot = offline |
| Access Point | Name, Kennzeichen `PVC`, bei offline „offline seit …“ |
| IP / Modell | AP-IP, Modell |
| Clients | Anzahl |
| Durchsatz ↓ / ↑ | Summe aller Clients am AP |
| Letzte Anmeldung | Zeit, Gerätename und IP der letzten erfolgreichen Anmeldung |

- **Sortierung:** offline-APs zuerst, danach alphabetisch
- **Filter:** Freitext (Name, IP, Modell) und Haken „nur offline“
- **Aufklappen:** Ein Klick auf die Zeile zeigt MAC, Firmware, Uptime, einen Link zum Verlauf des APs und die verbundenen Clients mit Name, IP, SSID, RSSI und Durchsatz
- **„Letzte Anmeldung“** ist der jüngste Wert aus dem letzten `connect`/`roam`-Eintrag im Verlauf und dem `assoc_since` der aktuell verbundenen Clients

### 8.3 Ansicht „Clients“ (`?v=clients`)

Tabelle aller verbundenen Clients: Name/MAC, IP, AP mit SSID/Band, Signal, Durchsatz, Volumen und „verbunden seit“. Mit Freitextfilter. Auf dem Handy wird jede Zeile zu einer Karte.

**Signalfarben:** siehe Abschnitt 5. In der AP-Ansicht steht je Client die übertragene Datenmenge seit Anmeldung (↓/↑) und darunter der Durchsatz.

### 8.4 Ansicht „Verlauf“ (`?v=log`)

Die letzten 300 Ereignisse, neueste zuerst. Filterbar nach AP (`?v=log&ap=<mac>`) und per Freitext. Farbleiste: grün = angemeldet, gelb = gewechselt, grau = abgemeldet.

### 8.5 Automatische Aktualisierung

- Alle `REFRESH_SECONDS` lädt die Seite nur den Inhaltsbereich neu (`?partial=1`, per `fetch`), nicht die ganze Seite.
- **Zustand bleibt erhalten:** aufgeklappte APs (über die MAC), Filtertext und der Haken „nur offline“.
- Ist der Tab im Hintergrund, pausiert die Aktualisierung. Beim Zurückkehren wird sofort aktualisiert.
- Ist gerade ein Auswahlfeld geöffnet, wird nicht aktualisiert.

### 8.6 Zugriffsschutz (`auth.php`)

Sind `WEB_USER` und `WEB_PASSWORD` gesetzt, verlangt die Seite HTTP-Basic-Auth. Der Vergleich läuft zeitkonstant über `hash_equals`. Sind beide leer, ist die Seite offen.

Basic-Auth überträgt das Passwort ohne TLS im Klartext. Für Zugriff von außen gehört deshalb ein Reverse-Proxy mit HTTPS davor.

---

## 9. Diagnose (`debug.php`)

- **Aktiv** nur, wenn `DEBUG_PASSWORD` gesetzt ist. Sonst antwortet die Seite mit 404.
- **Login:** Benutzer `debug`, Passwort `DEBUG_PASSWORD`
- **Anzeige:** letzter Poll, Dauer, letzter Fehler
- **Rohdaten:** je Datei aus `/data/raw/` die vollständige JSON-Antwort, die Anzahl der Einträge und die **erkannten Feldnamen** des ersten Eintrags
- **Live-Abfrage** einer der lesenden Methoden `system.getPvc`, `system.getApList`, `ap.getSystemInfo`, `ap.getClient`, `wlan.list` oder `wlan.get`, optional mit `apmac` bzw. `essid`

Jede Live-Abfrage meldet sich neu am Cluster an. Bei einem gesperrten Account solltest du sie deshalb nicht verwenden. Nach der Inbetriebnahme `DEBUG_PASSWORD` wieder leeren.

---

## 10. Konfiguration (Umgebungsvariablen)

| Variable | Standard | Beschreibung |
|---|---|---|
| `CLUSTER_IP` | – | IP des Clusters / PVC (**Pflicht**) |
| `CLUSTER_PORT` | `8080` | Port der API |
| `CLUSTER_HTTPS` | `0` | `1` = https (Zertifikat wird nicht geprüft) |
| `CLUSTER_USER` | `Administrator` | API-Benutzer (laut Doku nur Administrator) |
| `CLUSTER_PASSWORD` | – | Passwort, Klartext oder MD5 (**Pflicht**) |
| `CLUSTER_PASSWORD_HASH` | `auto` | `auto` / `md5` / `plain` |
| `RESOLVE_PVC` | `1` | PVC per `system.getPvc` ermitteln |
| `IMAGE` | – | nur Portainer-Stack: z. B. `ghcr.io/dgawin/stellar_web_monitor:latest` |
| `POLL_INTERVAL` | `60` | Sekunden zwischen zwei Durchläufen (min. 15) |
| `SYSINFO_EVERY` | `10` | `ap.getSystemInfo` nur jeden n-ten Durchlauf |
| `RETENTION_DAYS` | `90` | Aufbewahrung des Verlaufs |
| `LOGIN_BACKOFF_MAX` | `30` | maximale Wartezeit nach Login-Fehlern (Minuten) |
| `WEB_PORT` | `8088` | Port der Webseite auf dem Host |
| `SITE_TITLE` | `WLAN-Übersicht` | Seitentitel |
| `REFRESH_SECONDS` | `30` | automatische Aktualisierung im Browser (min. 10) |
| `WEB_USER` / `WEB_PASSWORD` | leer | Basic-Auth für die Kundenseite |
| `DEBUG_PASSWORD` | leer | aktiviert `debug.php` |
| `TZ` | `Europe/Berlin` | Zeitzone |
| `DB_PATH` | `/data/monitor.sqlite` | Pfad der Datenbank (im Image gesetzt) |

---

## 11. Deployment

### 11.1 Portainer mit Image aus GitHub

Die GitHub Action `.github/workflows/docker.yml` baut bei jedem Push auf `main` (und bei Tags `v*`) ein Image für amd64 und arm64 und legt es unter `ghcr.io/dgawin/stellar_web_monitor` ab (Tags `latest`, Versionsnummer, Commit-SHA).

1. **Einmalig:** Paket auf GitHub auf *Public* stellen, oder in Portainer unter *Registries* GHCR mit Token (`read:packages`) hinterlegen.
2. **Stack anlegen:** *Stacks → Add stack → Repository*, URL des Repos, Reference `refs/heads/main`, Compose path `portainer-stack.yml`. Alternativ den Inhalt von `portainer-stack.yml` im Web editor einfügen.
3. **Variablen** unter *Environment variables*, zum Beispiel:

```
IMAGE=ghcr.io/dgawin/stellar_web_monitor:latest
CLUSTER_IP=192.168.1.10
CLUSTER_PASSWORD=********
SITE_TITLE=WLAN-Übersicht
```

4. *Deploy the stack*. In den Logs des Containers `poller` muss `OK – 32 APs, xx Clients (PVC …)` stehen.

**Update:** *Pull and redeploy* bzw. *Update the stack* mit „Re-pull image“. Mit *GitOps updates* zieht Portainer Änderungen automatisch.

**Daten:** Named Volume `stellar_data`, bleibt bei Updates erhalten.

### 11.2 docker compose

```bash
cp .env.example .env        # Werte eintragen
docker compose up -d --build
docker compose logs -f poller
```

Hier liegen die Daten im Ordner `./data/` neben der Compose-Datei.

### 11.3 Aufbau des Images

- **Basis:** `php:8.3-apache`. `pdo_sqlite` und `curl` sind dort bereits enthalten.
- **Apache:** DocumentRoot ist `/app/public`. `ServerTokens Prod` und `ServerSignature Off` sind gesetzt, dazu die Header `X-Content-Type-Options: nosniff` und `X-Frame-Options: SAMEORIGIN`.
- **Basic-Auth:** Der `Authorization`-Header wird an PHP durchgereicht.
- **PHP:** Es gilt die `php.ini-production`.
- **Rechte:** `/data` gehört `www-data`. Der Poller läuft als `www-data`, damit Web und Poller dieselbe Datenbank beschreiben dürfen.

---

## 12. Fehlerbehebung

| Meldung / Symptom | Ursache | Lösung |
|---|---|---|
| `Account gesperrt (zu viele Fehlversuche)` bzw. Cluster-Antwort `login log account lock` | falsche Zugangsdaten, Cluster hat den Account gesperrt | Stack stoppen, Zugangsdaten an der Express-Weboberfläche prüfen, Sperrzeit abwarten, Variablen korrigieren, Stack starten |
| `Login abgelehnt – Benutzer/Passwort prüfen` | Zugangsdaten falsch | Variablen prüfen. Ein `$` im Passwort kann in Portainer oder Compose als Variable gedeutet werden, dann `$$` schreiben oder das Passwort ändern |
| `Cluster nicht erreichbar (…)` | IP/Port falsch, Firewall, Docker-Host hat keine Route ins Management-Netz | `CLUSTER_IP`/`CLUSTER_PORT` prüfen, vom Docker-Host `curl http://<ip>:8080/apiaccess` testen |
| `system.getApList lieferte keine AP-Liste` | unbekanntes Antwortformat | `debug.php` öffnen, Rohdaten von `system.getApList.json` prüfen |
| AP-Namen, IPs oder Durchsatz bleiben leer | Feldname nicht in den `K_*`-Listen | Feldnamen in `debug.php` ablesen und in `bootstrap.php` ergänzen |
| Durchsatz immer `–` | Cluster liefert keine Byte-Zähler, oder Clients roamen ständig | Rohdaten von `ap.getClient` prüfen. Sonst pfSense als Datenquelle (siehe 13) |
| „Daten sind nicht aktuell“ | Poller läuft nicht oder hängt | Logs des `poller`-Containers prüfen, Container neu starten |
| Portainer: `pull access denied` / `unauthorized` für ghcr.io | Paket ist privat | Paket auf Public stellen oder Registry mit Token hinterlegen |
| `Login abgelehnt` trotz richtigem Passwort | Passwort im falschen Format | `CLUSTER_PASSWORD_HASH` prüfen (`auto` hasht Klartext per MD5) |
| `Please access pvc` | Aufruf ging nicht an den PVC | `RESOLVE_PVC=1` lassen, `CLUSTER_IP` muss eine erreichbare AP-IP sein |
| Uhrzeiten um 1 Std. verschoben | Zeitzone | `TZ=Europe/Berlin` setzen |

---

## 13. Grenzen und mögliche Erweiterungen

**Grenzen**
- **Auflösung:** Ereignisse und Durchsatz sind nur so genau wie `POLL_INTERVAL`. Kurze Verbindungen und Spitzen gehen verloren.
- **Feldnamen:** Abgeglichen mit dem *AP Northbound API User Guide* und getestet gegen ein simuliertes Cluster in diesem Format. Gegen ein echtes Cluster einmal mit `debug.php` prüfen, vor allem die Richtung der Byte-Zähler und die Bedeutung von `signal`.
- **Keine Kurven:** Es gibt keine Zeitreihe des Durchsatzes, gespeichert wird nur der aktuelle Wert je Client.
- **Abhängigkeit vom Login:** Ein gesperrter API-Account stoppt die Datenerfassung, bis die Sperre abläuft.

**Mögliche Erweiterungen**
- **pfSense:** Traffic je Client-IP aus pfSense lesen (z. B. über die REST-API oder ntopng), falls das Cluster keine Zähler liefert.
- **Diagramme:** Durchsatz-Historie je Client und AP in einer eigenen Tabelle speichern und als Diagramm anzeigen.
- **Benachrichtigung:** E-Mail oder Push, wenn ein AP offline geht.
- **Gerätenamen:** Namen für bekannte MACs pflegen, zum Beispiel „Kasse Bar“.
- **Export:** Verlauf als CSV exportieren.

---

## 14. Lokaler Test ohne Cluster

`mock/cluster.php` simuliert ein Express Cluster:

- Antwortformat wie im *AP Northbound API User Guide*
- 32 APs, davon 3 mit Status 4 (Down), AP 1 ist PVC, AP 2 SVC
- etwa 60 Clients, die alle 20 s teilweise den AP wechseln oder verschwinden
- steigende Byte-Zähler
- Login `Administrator` / `admin` (MD5 wird erwartet), Passwort `lock` liefert `Login log account lock`

```bash
php -S 127.0.0.1:18080 mock/cluster.php &
export CLUSTER_IP=127.0.0.1 CLUSTER_PORT=18080 CLUSTER_PASSWORD=admin DB_PATH=./data/monitor.sqlite
php app/poller.php --once          # ein Durchlauf
DEBUG_PASSWORD=dbg php -S 127.0.0.1:18088 -t app/public
# Browser: http://127.0.0.1:18088
```

`php app/poller.php --once` führt einen einzelnen Durchlauf aus und beendet sich danach. Das ist auch im Container zum Testen nützlich:

```bash
docker exec -it <poller-container> php /app/poller.php --once
```

---

## 15. Änderungsverlauf

| Datum | Änderung |
|---|---|
| 12.11.2026 | Erste Version: Poller, Webseite mit AP-Karten, Clients, Verlauf, Debug-Seite, Docker Compose |
| 12.11.2026 | AP-Ansicht als kompakte, aufklappbare Liste (32 APs), Filter „nur offline“, offline-APs zuerst |
| 12.11.2026 | Portainer: Stack-Datei, unkomprimiertes Image-tar, `Dockerfile.portainer` mit eingebettetem Code |
| 12.11.2026 | Login-Fehler und Account-Sperre erkennen, Backoff 5/10/20/30 Min., Klartext-Meldung |
| 12.11.2026 | Abgleich mit API-Doku: AP-Status 3 = Working (vorher fälschlich offline), Rollen PVC/SVC/VC, Passwort als MD5, PVC-Ermittlung per `system.getPvc`, Felder `AssocationSSID`/`AttachedBand`/`on_line`/`ostype`, Datenmenge je Client in der AP-Ansicht, Laufzeit lesbar, CSS-Fehler bei aufgeklappter AP-Zeile behoben, Mock-Cluster ergänzt |
| 12.11.2026 | Veröffentlichung auf GitHub: MIT-Lizenz, README, Image-Build per GitHub Actions (GHCR), Portainer-Stack zieht Image aus GHCR |
