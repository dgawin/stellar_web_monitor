<?php
/**
 * Stellar Express Monitor – gemeinsame Basis (Config, DB, API-Client, Helfer)
 */
declare(strict_types=1);

date_default_timezone_set(getenv('TZ') ?: 'Europe/Berlin');

function cfg(string $key, $default = null) {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

/* ------------------------------------------------------------------ DB */

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $path = cfg('DB_PATH', __DIR__ . '/../../data/monitor.sqlite');
    @mkdir(dirname($path), 0775, true);
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
    // Datenschutzmodus: gelöschte Datensätze physisch überschreiben statt nur freigeben
    if (cfg('PRIVACY_MODE', '1') !== '0') $pdo->exec('PRAGMA secure_delete=ON;');
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS aps (
    mac TEXT PRIMARY KEY,
    name TEXT, ip TEXT, model TEXT, version TEXT, role TEXT,
    online INTEGER DEFAULT 0,
    client_count INTEGER DEFAULT 0,
    uptime TEXT,
    last_seen INTEGER, first_seen INTEGER,
    raw TEXT
);
CREATE TABLE IF NOT EXISTS clients (
    mac TEXT PRIMARY KEY,
    ap_mac TEXT, ip TEXT, hostname TEXT, ssid TEXT, band TEXT,
    rssi INTEGER,
    tx_bytes INTEGER, rx_bytes INTEGER,
    tx_rate REAL, rx_rate REAL,      -- Bytes/s, aus Delta zwischen zwei Polls
    link_tx TEXT, link_rx TEXT,      -- PHY-Rate, falls vom Cluster geliefert
    connected INTEGER DEFAULT 0,
    assoc_since INTEGER,             -- Beginn der aktuellen Assoziation (an diesem AP)
    last_seen INTEGER, first_seen INTEGER,
    raw TEXT
);
CREATE TABLE IF NOT EXISTS assoc_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts INTEGER, event TEXT,          -- connect | roam | disconnect
    client_mac TEXT, ap_mac TEXT, prev_ap_mac TEXT,
    ip TEXT, hostname TEXT, ssid TEXT
);
CREATE INDEX IF NOT EXISTS idx_log_ts ON assoc_log(ts);
CREATE INDEX IF NOT EXISTS idx_log_ap ON assoc_log(ap_mac, ts);
CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT);
SQL);
    // Migration ältere Datenbanken
    $cols = array_column($pdo->query('PRAGMA table_info(clients)')->fetchAll(), 'name');
    if (!in_array('ostype', $cols, true)) $pdo->exec('ALTER TABLE clients ADD COLUMN ostype TEXT');
    $cols = array_column($pdo->query('PRAGMA table_info(assoc_log)')->fetchAll(), 'name');
    if (!in_array('ostype', $cols, true)) $pdo->exec('ALTER TABLE assoc_log ADD COLUMN ostype TEXT');
    return $pdo;
}

function meta_get(string $k, $default = null) {
    $s = db()->prepare('SELECT v FROM meta WHERE k=?'); $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? $default : $v;
}
function meta_set(string $k, $v): void {
    db()->prepare('INSERT INTO meta(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$k, (string)$v]);
}

/* ------------------------------------------------------------------ API */

class LoginException extends RuntimeException {}

class StellarApi {
    private ?string $session = null;
    private int $id = 0;
    private ?string $pvc = null;          // aktuelle PVC-IP (aus system.getPvc)
    public array $lastRaw = [];

    public function __construct(private string $host, private string $user, private string $pass,
                                private int $port = 8080, private bool $https = false, private bool $resolvePvc = true) {}

    private function url(?string $host = null): string {
        $h = $host ?? $this->pvc ?? $this->host;
        return ($this->https ? 'https' : 'http') . "://{$h}:{$this->port}/apiaccess";
    }

    public function pvcIp(): string { return $this->pvc ?? $this->host; }

    /** Passwort laut API-Doku als MD5-Hash senden. CLUSTER_PASSWORD_HASH = auto|md5|plain */
    private function pw(): string {
        $mode = strtolower((string)cfg('CLUSTER_PASSWORD_HASH', 'auto'));
        if ($mode === 'plain') return $this->pass;
        if ($mode === 'md5') return md5($this->pass);
        return preg_match('/^[0-9a-f]{32}$/i', $this->pass) ? strtolower($this->pass) : md5($this->pass);
    }

    private function post(array $payload, ?string $host = null): array {
        $url = $this->url($host);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) throw new RuntimeException("Cluster nicht erreichbar ($url): $err");
        $json = json_decode($body, true);
        if (!is_array($json)) throw new RuntimeException("Ungültige Antwort (HTTP $code): " . substr((string)$body, 0, 300));
        $this->lastRaw = $json;
        return $json;
    }

    /** system.getPvc: liefert die IP des aktuellen PVC (Aufruf an beliebige AP-IP, ohne Session). */
    public function resolvePvc(): ?string {
        $r = $this->post(['id' => ++$this->id, 'jsonrpc' => '2.0', 'method' => 'system.getPvc',
                          'params' => ['username' => $this->user, 'password' => $this->pw()]], $this->host);
        $res = $r['result'] ?? $r;
        if (self::failed($res)) {
            $m = (string)($res['message'] ?? json_encode($res));
            if (preg_match('/password|username|lock|banned/i', $m)) throw new LoginException(self::hint($m) . " (system.getPvc: \"$m\")");
            return null;
        }
        $ip = $res['pvcip'] ?? null;
        return (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : null;
    }

    private static function hint(string $m): string {
        return preg_match('/lock|banned/i', $m)
            ? 'Account gesperrt (zu viele Fehlversuche) – Zugangsdaten prüfen und Sperrzeit abwarten'
            : 'Login abgelehnt – Benutzer/Passwort prüfen';
    }

    public function login(): string {
        if ($this->resolvePvc) {
            $this->pvc = null;
            try { $this->pvc = $this->resolvePvc(); }
            catch (LoginException $e) { throw $e; }
            catch (Throwable $e) { $this->pvc = null; } // nicht erreichbar -> direkt CLUSTER_IP versuchen
        }
        $r = $this->post(['id' => ++$this->id, 'jsonrpc' => '2.0', 'method' => 'user.logIn',
                          'params' => ['username' => $this->user, 'password' => $this->pw()]]);
        if (isset($r['error'])) throw new LoginException('Login fehlgeschlagen: ' . json_encode($r['error']));
        $res = $r['result'] ?? $r;
        if (self::failed($res)) {
            $m = is_array($res) ? (string)($res['message'] ?? $res['msg'] ?? json_encode($res)) : (string)$res;
            $hint = self::hint($m);
            throw new LoginException("$hint (Cluster: \"$m\")");
        }
        $sess = self::findSession($res);
        if (!$sess) throw new LoginException('Login: keine Session in Antwort gefunden: ' . substr(json_encode($r), 0, 300));
        return $this->session = $sess;
    }

    /** success:"false" / errCode != 0 in einer Antwort erkennen. */
    public static function failed($res): bool {
        if (!is_array($res)) return false;
        if (array_key_exists('success', $res) && in_array(strtolower(var_export($res['success'], true)), ["'false'", 'false', "'0'", '0'], true)) return true;
        foreach (['errCode', 'errcode', 'code'] as $k)
            if (isset($res[$k]) && is_numeric($res[$k]) && (int)$res[$k] !== 0 && !isset($res['session'])) return true;
        return false;
    }

    /** Session-ID irgendwo in der Antwort finden (Feldname variiert je nach Firmware). */
    private static function findSession($r): ?string {
        if (is_string($r) && strlen($r) >= 8) return $r;
        if (!is_array($r)) return null;
        foreach ($r as $k => $v) {
            if (is_string($v) && preg_match('/sess|token|sid/i', (string)$k)) return $v;
        }
        foreach ($r as $v) {
            if (is_array($v) && ($s = self::findSession($v))) return $s;
        }
        return null;
    }

    public function call(string $method, array $params = [], bool $retry = true) {
        if (!$this->session) $this->login();
        $r = $this->post(['id' => ++$this->id, 'jsonrpc' => '2.0', 'method' => $method,
                          'session' => $this->session, 'params' => (object)$params]);
        $res = $r['result'] ?? null;
        if (isset($r['error']) || self::failed($res)) {
            // Session abgelaufen -> einmal neu anmelden
            if ($retry) { $this->session = null; return $this->call($method, $params, false); }
            throw new RuntimeException("$method: " . json_encode($r['error'] ?? $res, JSON_UNESCAPED_UNICODE));
        }
        return $res ?? $r;
    }

    public function logout(): void {
        if (!$this->session) return;
        try { $this->post(['id' => ++$this->id, 'jsonrpc' => '2.0', 'method' => 'user.logOut', 'session' => $this->session, 'params' => new stdClass]); }
        catch (Throwable $e) {}
        $this->session = null;
    }
}

function api(): StellarApi {
    return new StellarApi(
        (string)cfg('CLUSTER_IP', '127.0.0.1'),
        (string)cfg('CLUSTER_USER', 'Administrator'),
        (string)cfg('CLUSTER_PASSWORD', ''),
        (int)cfg('CLUSTER_PORT', 8080),
        cfg('CLUSTER_HTTPS', '0') === '1',
        cfg('RESOLVE_PVC', '1') === '1'
    );
}

/* -------------------------------------------------------- Normalisierung
 * Die Feldnamen der Northbound-API unterscheiden sich je nach Stellar-Release.
 * Darum wird jedes Feld über eine Liste möglicher Schlüssel gesucht
 * (case-insensitive, Unterstriche/Bindestriche ignoriert, auch verschachtelt).
 */

function norm_key(string $k): string { return strtolower(str_replace(['_', '-', ' '], '', $k)); }

function flat(array $a, array &$out = []): array {
    foreach ($a as $k => $v) {
        if (is_array($v) && !array_is_list($v)) flat($v, $out);
        elseif (!is_array($v)) { $nk = norm_key((string)$k); if (!array_key_exists($nk, $out)) $out[$nk] = $v; }
    }
    return $out;
}

function pick(array $flat, array $keys, $default = null) {
    foreach ($keys as $k) {
        $nk = norm_key($k);
        if (array_key_exists($nk, $flat) && $flat[$nk] !== '' && $flat[$nk] !== null) return $flat[$nk];
    }
    return $default;
}

/** Erste Liste von Objekten in einer Antwort finden (z. B. result.apList, result.clients, result selbst). */
function find_list($r): array {
    if (!is_array($r)) return [];
    if (array_is_list($r)) {
        if ($r && is_array($r[0])) return $r;
        return [];
    }
    $best = [];
    foreach ($r as $v) {
        if (is_array($v)) {
            $l = find_list($v);
            if (count($l) > count($best)) $best = $l;
        }
    }
    return $best;
}

function norm_mac(?string $m): string {
    $h = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string)$m));
    return strlen($h) === 12 ? implode(':', str_split($h, 2)) : strtolower((string)$m);
}

const K_AP_MAC   = ['apmac', 'mac', 'macaddr', 'macaddress', 'basemac', 'ethmac', 'apMacAddress'];
const K_AP_NAME  = ['ap_name', 'apname', 'name', 'hostname', 'devicename', 'sysname', 'apalias'];
const K_AP_IP    = ['apip', 'ip', 'ipaddr', 'ipaddress', 'ipv4', 'mgmtip'];
const K_AP_MODEL = ['model', 'apmodel', 'productname', 'devicemodel', 'type', 'hwmodel'];
const K_AP_VER   = ['version', 'swversion', 'firmware', 'fwversion', 'softwareversion', 'imageversion'];
const K_AP_ROLE  = ['role', 'aprole', 'pvcrole', 'groupRole'];
const K_AP_STATE = ['status', 'state', 'online', 'apstatus', 'onlinestatus', 'connstate', 'runstate'];
const K_AP_UP    = ['uptime', 'runtime', 'systemuptime', 'onlinetime'];
const K_AP_CCNT  = ['clientnum', 'clientcount', 'clients', 'stanum', 'usernum', 'numclients'];

const K_CL_MAC   = ['clientmac', 'stamac', 'usermac', 'mac', 'macaddr', 'macaddress', 'clientmacaddress'];
const K_CL_IP    = ['clientip', 'staip', 'ip', 'ipaddr', 'ipaddress', 'ipv4', 'ipv4addr'];
const K_CL_HOST  = ['hostname', 'clientname', 'name', 'devicename', 'staname', 'username'];
const K_CL_SSID  = ['AssocationSSID', 'AssociationSSID', 'ssid', 'essid', 'wlan', 'wlanname', 'ssidname'];
const K_CL_BAND  = ['AttachedBand', 'band', 'radio', 'radiotype', 'frequency', 'freqband', 'channel'];
const K_CL_RSSI  = ['rssi', 'signal', 'signalstrength', 'snr'];
const K_CL_TXB   = ['txbytes', 'txbyte', 'sendbytes', 'txtotalbytes', 'downbytes', 'downlinkbytes', 'txdata'];
const K_CL_RXB   = ['rxbytes', 'rxbyte', 'recvbytes', 'rxtotalbytes', 'upbytes', 'uplinkbytes', 'rxdata'];
const K_CL_TXR   = ['tx_wifirate', 'txrate', 'txlinkrate', 'txphyrate', 'linkrate', 'datarate', 'phyrate'];
const K_CL_RXR   = ['rx_wifirate', 'rxrate', 'rxlinkrate', 'rxphyrate'];
const K_CL_LIVE  = ['on_line', 'online'];
const K_CL_OS    = ['ostype', 'devtype', 'devmodel'];
const K_CL_ONL   = ['onlinetime', 'assoctime', 'connecttime', 'uptime', 'associatedtime'];

/** Status-Feld interpretieren: true = online. */
function is_online($v): bool {
    if ($v === null) return true; // Liste enthält nur aktive APs
    if (is_bool($v)) return $v;
    if (is_numeric($v)) return in_array((int)$v, [1, 2, 3], true); // getApList: 1 Joining, 2 Initializing, 3 Working, 4 Down
    return (bool)preg_match('/^(up|on|online|active|running|connected|normal|ok|1|true|managed)/i', trim((string)$v));
}

/** AP-Status als Text (getApList liefert Zahlen). */
function ap_state_label($v): ?string {
    if (is_numeric($v)) return [1 => 'Verbindet', 2 => 'Initialisiert', 3 => 'Aktiv', 4 => 'Down'][(int)$v] ?? null;
    return null;
}

/** AP-Rolle: getApList liefert 1 PVC, 2 SVC, 3 VC. */
function ap_role_label($v): ?string {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return [1 => 'PVC', 2 => 'SVC', 3 => 'VC'][(int)$v] ?? (string)$v;
    return strtoupper((string)$v);
}

/** Band: AttachedBand liefert 2 bzw. 5 (GHz). */
function band_label($v): ?string {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) { $n = (float)$v; if ($n == 2 || $n == 2.4) return '2,4 GHz'; if ($n == 5) return '5 GHz'; if ($n == 6) return '6 GHz'; }
    return (string)$v;
}

function to_bytes($v): ?int {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return (int)$v;
    if (preg_match('/([\d.]+)\s*([kmgt]?)i?b/i', (string)$v, $m)) {
        $mul = ['' => 1, 'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3, 't' => 1024 ** 4][strtolower($m[2])];
        return (int)round((float)$m[1] * $mul);
    }
    return null;
}

/* --------------------------------------------------------- Datenschutz
 * PRIVACY_MODE=1 (Standard): keine MAC-Adressen, keine Gerätenamen, keine Roh-
 * daten der Clients in Datenbank oder Dateien. Clients werden über ein Pseudonym
 * geführt: HMAC-SHA256(MAC) mit einem Schlüssel, der NUR im Arbeitsspeicher des
 * Pollers liegt und täglich neu erzeugt wird. Ein einfacher Hash der MAC wäre
 * per Durchprobieren umkehrbar, mit dem flüchtigen Schlüssel ist er es nicht.
 */

function privacy_on(): bool { return cfg('PRIVACY_MODE', '1') !== '0'; }

final class Pseudonymizer {
    private ?string $key = null;
    private ?string $oldKey = null;
    private ?string $day = null;
    public bool $fresh = false;      // erster Schlüssel seit Start: alte Pseudonyme sind nicht zuordenbar

    /** Neuen Tagesschlüssel erzeugen, falls nötig. true = Schlüssel gewechselt. */
    public function rotate(): bool {
        $d = date('Y-m-d');
        if ($this->day === $d) { $this->fresh = false; return false; }
        $this->fresh = $this->key === null;
        $this->oldKey = $this->key;
        $this->key = random_bytes(32);
        $this->day = $d;
        return true;
    }
    public function id(string $mac): string { return substr(hash_hmac('sha256', $mac, (string)$this->key), 0, 16); }
    public function oldId(string $mac): ?string { return $this->oldKey === null ? null : substr(hash_hmac('sha256', $mac, $this->oldKey), 0, 16); }
    public function forgetOld(): void { $this->oldKey = null; }
}

/** IP kürzen: PRIVACY_IP = mask (Standard, 192.168.20.x) | full | off */
function privacy_ip(?string $ip): ?string {
    if ($ip === null || !privacy_on()) return $ip;
    $mode = strtolower((string)cfg('PRIVACY_IP', 'mask'));
    if ($mode === 'full') return $ip;
    if ($mode === 'off') return null;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return preg_replace('/\.\d+$/', '.x', $ip);
    $b = @inet_pton($ip);
    return $b === false ? null : implode(':', str_split(bin2hex(substr($b, 0, 8)), 4)) . '::x';
}

/** Personenbezogene Werte in Client-Rohdaten ersetzen (Feldnamen bleiben für die Diagnose sichtbar). */
const PII_KEYS = ['mac', 'vap', 'ip', 'hostname', 'portalusername', 'username', 'devmodel', 'clientmac', 'stamac',
                  'usermac', 'clientip', 'staip', 'clientname', 'devicename', 'staname', 'ipv6', 'ipv4'];
function scrub_pii($v) {
    if (!is_array($v)) return $v;
    foreach ($v as $k => $x) {
        if (is_array($x)) $v[$k] = scrub_pii($x);
        elseif (is_string($k) && in_array(norm_key($k), PII_KEYS, true) && $x !== '' && $x !== null) $v[$k] = '***';
    }
    return $v;
}

/** Anzeigename eines Clients: Hostname, sonst Gerätetyp, sonst Kurz-ID. */
function dev_label(?string $host, ?string $os, ?string $mac, ?string $ip = null): string {
    if ($host) return $host;
    if ($os) return $os . '-Gerät';
    if ($mac && !str_contains($mac, ':')) return 'Gerät ' . substr($mac, 0, 6);
    return $ip ?: (string)$mac;
}

/** Client-Kennung zur Anzeige: MAC oder (im Datenschutzmodus) kurzes Pseudonym. */
function client_ref(?string $mac): string {
    if (!$mac) return '';
    return str_contains($mac, ':') ? $mac : 'ID ' . substr($mac, 0, 8);
}

/* -------------------------------------------------------------- Format */

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_bytes(?float $b): string {
    if ($b === null) return '–';
    $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return number_format($b, $i ? 1 : 0, ',', '.') . ' ' . $u[$i];
}

function fmt_rate(?float $bps): string {
    if ($bps === null) return '–';
    $bits = $bps * 8;
    $u = ['bit/s', 'kbit/s', 'Mbit/s', 'Gbit/s']; $i = 0;
    while ($bits >= 1000 && $i < 3) { $bits /= 1000; $i++; }
    return number_format($bits, $i ? 1 : 0, ',', '.') . ' ' . $u[$i];
}

function fmt_ago(?int $ts): string {
    if (!$ts) return 'nie';
    $d = time() - $ts;
    if ($d < 60) return 'gerade eben';
    if ($d < 3600) return 'vor ' . intdiv($d, 60) . ' Min.';
    if ($d < 86400) return 'vor ' . intdiv($d, 3600) . ' Std.';
    if ($d < 86400 * 7) return 'vor ' . intdiv($d, 86400) . ' Tag' . (intdiv($d, 86400) > 1 ? 'en' : '');
    return date('d.m.Y H:i', $ts);
}

function fmt_dur(?int $s): string {
    if ($s === null || $s < 0) return '–';
    if ($s < 3600) return intdiv($s, 60) . ' Min.';
    if ($s < 86400) return intdiv($s, 3600) . ' Std. ' . intdiv($s % 3600, 60) . ' Min.';
    return intdiv($s, 86400) . ' T. ' . intdiv($s % 86400, 3600) . ' Std.';
}
