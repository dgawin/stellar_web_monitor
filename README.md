# stellar_web_monitor

**Autor:** [Dominik Gawin](https://dominikgawin.de) · **Lizenz:** [MIT](LICENSE)

Passive, mobil nutzbare WLAN-Übersicht für **Alcatel-Lucent OmniAccess Stellar** Access Points im **Express-Modus** (Cluster ohne Controller/OmniVista). Gedacht für Kunden und Betreiber, die ohne Zugang zur Express-Weboberfläche sehen wollen, was im WLAN los ist.

> **Unabhängiges Projekt.** Kein offizielles Produkt von Alcatel-Lucent Enterprise (ALE) und nicht mit ALE verbunden. Details unter [Rechtliche Hinweise](#rechtliche-hinweise).

*English summary: read-only web dashboard for Alcatel-Lucent Stellar APs in Express mode. It polls the cluster's Northbound JSON-RPC API, stores state in SQLite and shows AP status, connected clients, data volume per client and association history. PHP 8.3, Docker, no framework. MIT licensed. Not affiliated with ALE.*

---

## Funktionen

- **Access Points** als kompakte Liste: Status, IP, Modell, Rolle (PVC/SVC/VC), Anzahl Clients, Durchsatz
- **Letzte erfolgreiche Anmeldung je AP** mit Gerätetyp und IP
- **Clients je AP** zum Aufklappen: Gerätetyp, IP, SSID, Band, Signal, **übertragene Datenmenge** und Durchsatz
- **Client-Übersicht** über alle APs, auf dem Handy als Karten
- **Verlauf**: Anmeldungen, AP-Wechsel (Roaming) und Abmeldungen, filterbar nach AP
- **Datenschutzmodus** (Standard): keine MAC-Adressen, keine Gerätenamen, gekürzte IPs, siehe [Datenschutz](#datenschutz)
- Hell-/Dunkelmodus, automatische Aktualisierung, optionaler Passwortschutz
- **Nur lesend**: Es werden ausschließlich lesende API-Methoden verwendet, am Cluster wird nichts geändert.

## Funktionsweise

Die Northbound-API liefert nur den aktuellen Zustand. Ein Poller fragt das Cluster deshalb regelmäßig ab und leitet aus dem Vergleich zweier Abfragen Ereignisse und Durchsatz ab.

```
Stellar Express Cluster  ──JSON-RPC :8080──►  poller (PHP)  ──►  SQLite (/data)  ◄──  web (Apache/PHP)  ◄──  Browser
```

Verwendete API-Methoden: `system.getPvc`, `user.logIn`, `system.getApList`, `ap.getClient`, `ap.getSystemInfo`, `user.logOut`.

## Voraussetzungen

- Stellar APs im Express-Modus, AOS-W/Stellar **R3.0.3 oder neuer** (ab dort gibt es die Northbound-API)
- Account `Administrator` der Express-Oberfläche (die API akzeptiert nur diesen Benutzer)
- Docker-Host mit Netzwerkzugriff auf die AP-Management-IPs, Port 8080

## Schnellstart (docker compose)

```bash
git clone https://github.com/dgawin/stellar_web_monitor.git
cd stellar_web_monitor
cp .env.example .env          # CLUSTER_IP und CLUSTER_PASSWORD eintragen
docker compose up -d --build
docker compose logs -f poller # erwartet: "OK – xx APs, yy Clients"
```

Die Webseite läuft dann unter `http://<docker-host>:8088`.

### Portainer (Image direkt von GitHub)

Bei jedem Push auf `main` baut eine GitHub Action das Image (amd64 + arm64) und legt es in der GitHub Container Registry ab: `ghcr.io/dgawin/stellar_web_monitor:latest`. Selbst bauen ist damit nicht nötig.

1. Einmalig auf GitHub: unter *Packages → stellar_web_monitor → Package settings* die Sichtbarkeit auf **Public** stellen. Alternativ in Portainer unter *Registries* GHCR mit einem Token (`read:packages`) hinterlegen.
2. In Portainer *Stacks → Add stack → Repository*:
   - Repository URL: `https://github.com/dgawin/stellar_web_monitor`
   - Reference: `refs/heads/main`
   - Compose path: `portainer-stack.yml`
3. Unter *Environment variables* mindestens `CLUSTER_IP` und `CLUSTER_PASSWORD` setzen, Vorlage: `.env.example`. `IMAGE` ist mit `ghcr.io/dgawin/stellar_web_monitor:latest` vorbelegt. Dann *Deploy the stack*.

**Update:** Im Stack *Pull and redeploy* bzw. *Update the stack* mit „Re-pull image“. Mit *GitOps updates* und aktiviertem Re-pull aktualisiert Portainer automatisch. Für feste Stände ein Tag wie `v1.0.0` pushen und `IMAGE=…:1.0.0` setzen.

Die Daten liegen im Named Volume `stellar_data` und bleiben bei Updates erhalten.

## Konfiguration

| Variable | Standard | Beschreibung |
|---|---|---|
| `CLUSTER_IP` | – | IP eines beliebigen APs bzw. des PVC (**Pflicht**) |
| `CLUSTER_PORT` | `8080` | Port der API |
| `CLUSTER_HTTPS` | `0` | `1` = HTTPS (Zertifikat wird nicht geprüft) |
| `CLUSTER_USER` | `Administrator` | API-Benutzer |
| `CLUSTER_PASSWORD` | – | Passwort im Klartext oder als MD5-Hash (**Pflicht**) |
| `CLUSTER_PASSWORD_HASH` | `auto` | `auto`: Klartext wird MD5-gehasht, 32-stelliger Hex-Wert bleibt unverändert · `md5` · `plain` |
| `RESOLVE_PVC` | `1` | aktuellen PVC per `system.getPvc` ermitteln (folgt PVC-Wechseln) |
| `POLL_INTERVAL` | `60` | Sekunden zwischen zwei Abfragen (min. 15) |
| `SYSINFO_EVERY` | `10` | Modell/Firmware/Laufzeit nur jeden n-ten Durchlauf abfragen |
| `PRIVACY_MODE` | `1` | Datenschutzmodus, siehe [Datenschutz](#datenschutz). `0` = MAC-Adressen und Gerätenamen werden gespeichert |
| `PRIVACY_IP` | `mask` | nur im Datenschutzmodus: `mask` = `192.168.20.x` · `full` = volle IP · `off` = keine IP |
| `RETENTION_DAYS` | `7` / `90` | Aufbewahrung des Verlaufs in Tagen (7 im Datenschutzmodus, sonst 90) |
| `LOGIN_BACKOFF_MAX` | `30` | max. Wartezeit (Min.) nach Login-Fehlern, schützt vor Account-Sperre |
| `WEB_PORT` | `8088` | Port der Webseite |
| `SITE_TITLE` | `WLAN-Übersicht` | Seitentitel |
| `REFRESH_SECONDS` | `30` | automatische Aktualisierung im Browser |
| `WEB_USER` / `WEB_PASSWORD` | leer | HTTP-Basic-Auth für die Webseite |
| `DEBUG_PASSWORD` | leer | aktiviert `/debug.php` (Benutzer `debug`), danach wieder leeren |
| `TZ` | `Europe/Berlin` | Zeitzone |

## Datenschutz

Für die Frage „laufen die APs und können sich Geräte anmelden?“ braucht es keine Identität der Geräte. Deshalb ist der **Datenschutzmodus standardmäßig aktiv** (`PRIVACY_MODE=1`, Privacy by Default nach Art. 25 DSGVO):

| Datum | Datenschutzmodus | ohne (`PRIVACY_MODE=0`) |
|---|---|---|
| MAC-Adresse | **nicht gespeichert**, stattdessen ein Pseudonym (siehe unten) | gespeichert |
| Gerätename (Hostname) | **nicht gespeichert** | gespeichert |
| Gerätetyp (iOS, Android, Windows …) | gespeichert | gespeichert |
| IP-Adresse | gekürzt (`192.168.20.x`), zeigt weiter, dass DHCP funktioniert | vollständig |
| SSID, Band, Signal, Datenmenge, Verbindungsdauer | gespeichert | gespeichert |
| Rohdaten der Clients | nicht gespeichert, Diagnose-Dateien geschwärzt | gespeichert |
| Verlauf | 7 Tage | 90 Tage |

**Pseudonym statt Hash:** Ein einfacher Hash der MAC-Adresse wäre kein Schutz, denn MAC-Adressen lassen sich in Sekunden durchprobieren. Der Poller bildet deshalb ein HMAC-SHA256 mit einem zufälligen Schlüssel, der **nur im Arbeitsspeicher** liegt, nie in Datenbank oder Datei, und **jeden Tag neu** erzeugt wird. Aus der Datenbank lässt sich so keine MAC zurückrechnen, und Geräte lassen sich nicht über mehrere Tage verfolgen.

**Folgen:** Nach einem Neustart des Pollers werden die aktuell verbundenen Geräte neu erfasst, Anmeldungen während der Neustartphase fehlen im Verlauf. Wird der Modus eingeschaltet, löscht der Poller vorhandene Client-Daten und den Verlauf.

Die Verantwortung für den datenschutzkonformen Einsatz liegt beim Betreiber, etwa für Zugriffsschutz, Aufbewahrungsdauer und die Information der Nutzer. Auch Pseudonyme und gekürzte IPs können im Einzelfall personenbezogen sein.

## Sicherheit

- Die API verlangt den **Administrator**-Account des Clusters. Zugangsdaten nur als Umgebungsvariablen im Stack hinterlegen.
- Die API spricht standardmäßig HTTP. Den Docker-Host möglichst im Management-Netz betreiben.
- Basic-Auth überträgt Passwörter ohne TLS im Klartext. Für Zugriff von außen einen Reverse-Proxy mit HTTPS davorschalten.
- `/debug.php` zeigt Live-Antworten des Clusters (im Datenschutzmodus geschwärzt). Nur bei Bedarf über `DEBUG_PASSWORD` einschalten.

## Grenzen

- Ereignisse und Durchsatz sind nur so genau wie `POLL_INTERVAL`, kurze Verbindungen fehlen im Verlauf.
- Der Durchsatz ist ein Mittelwert aus den Byte-Zählern zwischen zwei Abfragen.
- Feldnamen folgen dem *AP Northbound API User Guide*. Andere Firmware-Stände können abweichen. Unter `/debug.php` sind Rohdaten und erkannte Felder einsehbar.
- Der Signalwert aus `ap.getClient` ist positiv (vermutlich dB über Rauschen) und wird als „Signal xx dB“ angezeigt.

---

## Rechtliche Hinweise

### Urheberschaft und Lizenz

© 2026 [Dominik Gawin](https://dominikgawin.de). Der Quellcode steht unter der [MIT-Lizenz](LICENSE). Nutzung, Änderung und Weitergabe sind erlaubt, sofern der Copyright- und Lizenzhinweis erhalten bleibt. Das Urheberrecht am Code verbleibt beim Autor. Alle nicht ausdrücklich durch die Lizenz eingeräumten Rechte bleiben vorbehalten.

### Haftungs- und Gewährleistungsausschluss

Diese Software wird **ohne jede Gewährleistung** bereitgestellt, so wie sie ist („as is“), ohne ausdrückliche oder stillschweigende Zusicherung, insbesondere der Marktgängigkeit, der Eignung für einen bestimmten Zweck oder der Fehlerfreiheit. Die Nutzung erfolgt auf eigenes Risiko. Der Autor haftet nicht für Schäden, Datenverluste, Ausfälle, gesperrte Accounts oder Fehlfunktionen am WLAN-System, die aus der Nutzung entstehen, soweit dies gesetzlich zulässig ist. Zwingende gesetzliche Haftung, etwa bei Vorsatz, bleibt unberührt.

Es gibt keinen Anspruch auf Support, Wartung oder Updates.

### Marken und Herstellerbezug

Alcatel-Lucent, OmniAccess und Stellar sind Marken oder eingetragene Marken von Nokia bzw. Alcatel-Lucent Enterprise (ALE International) und deren verbundenen Unternehmen. Die Namen werden ausschließlich verwendet, um die Kompatibilität zu beschreiben. Dieses Projekt ist **unabhängig** und wird von Nokia, ALE oder Alcatel-Lucent Enterprise weder unterstützt, geprüft noch gesponsert. Es enthält keine Software, Firmware, Dokumentation oder Logos des Herstellers.

Die Nutzung der Northbound-API unterliegt den Lizenz- und Nutzungsbedingungen, die für die eingesetzten Geräte gelten. Die API-Dokumentation des Herstellers ist nicht Teil dieses Repositorys und ist über die offiziellen Kanäle von ALE zu beziehen.
