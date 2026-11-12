<?php
/**
 * Poller: fragt das Stellar Express Cluster zyklisch ab und schreibt
 * AP-Status, aktuelle Clients, Durchsatz und Assoziations-Historie in SQLite.
 *
 *   php poller.php          -> Endlosschleife (Docker-Service)
 *   php poller.php --once   -> ein Durchlauf (Test)
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$interval  = max(15, (int)cfg('POLL_INTERVAL', 60));
$retention = (int)cfg('RETENTION_DAYS', 90);
$sysEvery  = max(1, (int)cfg('SYSINFO_EVERY', 10));   // ap.getSystemInfo nur jeden n-ten Zyklus
$rawDir    = dirname(cfg('DB_PATH', __DIR__ . '/../data/monitor.sqlite')) . '/raw';
@mkdir($rawDir, 0775, true);

$once  = in_array('--once', $argv, true);
$api   = api();
$cycle = 0;

function logmsg(string $m): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] $m\n"); }
function dump_raw(string $dir, string $name, $data): void {
    @file_put_contents("$dir/" . preg_replace('/[^\w.-]/', '_', $name) . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

logmsg("Poller gestartet – Cluster " . cfg('CLUSTER_IP') . ", Intervall {$interval}s");

$loginFails = 0;
do {
    $t0 = microtime(true);
    $wait = $interval;
    try {
        poll($api, $cycle % $sysEvery === 0, $rawDir);
        meta_set('last_poll', time());
        meta_set('last_error', '');
        meta_set('next_login_try', '');
        $loginFails = 0;
        db()->prepare('DELETE FROM assoc_log WHERE ts < ?')->execute([time() - $retention * 86400]);
    } catch (LoginException $e) {
        // Login-Fehler: nicht weiter anklopfen, sonst bleibt der Account gesperrt.
        // Wartezeit 5, 10, 20, 30 Min. (max. LOGIN_BACKOFF_MAX Minuten)
        $loginFails++;
        $wait = min(60 * (int)cfg('LOGIN_BACKOFF_MAX', 30), 300 * 2 ** ($loginFails - 1));
        $msg = $e->getMessage() . ' – nächster Versuch ' . date('H:i', time() + $wait) . ' Uhr';
        logmsg('LOGIN-FEHLER: ' . $msg);
        meta_set('last_error', date('d.m.Y H:i:s') . ' – ' . $msg);
        meta_set('next_login_try', time() + $wait);
        $api = api();
    } catch (Throwable $e) {
        logmsg('FEHLER: ' . $e->getMessage());
        meta_set('last_error', date('d.m.Y H:i:s') . ' – ' . $e->getMessage());
        $api = api(); // Session verwerfen, beim nächsten Mal neu anmelden
    }
    meta_set('poll_duration', round(microtime(true) - $t0, 2));
    $cycle++;
    if (!$once) sleep(max(1, $wait - (int)(microtime(true) - $t0)));
} while (!$once);

function poll(StellarApi $api, bool $withSysInfo, string $rawDir): void {
    $db  = db();
    $now = time();

    /* ---------------- APs */
    $apResult = $api->call('system.getApList');
    dump_raw($rawDir, 'system.getApList', $apResult);
    $apList = find_list($apResult);
    if (!$apList) throw new RuntimeException('system.getApList lieferte keine AP-Liste (siehe data/raw/system.getApList.json)');

    $seenAps = [];
    $apUp = $db->prepare(<<<SQL
INSERT INTO aps(mac,name,ip,model,version,role,online,uptime,client_count,last_seen,first_seen,raw)
VALUES(:mac,:name,:ip,:model,:version,:role,:online,:uptime,:cc,:ls,:fs,:raw)
ON CONFLICT(mac) DO UPDATE SET
  name=COALESCE(excluded.name,name), ip=COALESCE(excluded.ip,ip), model=COALESCE(excluded.model,model),
  version=COALESCE(excluded.version,version), role=COALESCE(excluded.role,role), online=excluded.online,
  uptime=COALESCE(excluded.uptime,uptime), client_count=excluded.client_count,
  last_seen=CASE WHEN excluded.online=1 THEN excluded.last_seen ELSE last_seen END, raw=excluded.raw
SQL);

    $apsToQuery = [];
    foreach ($apList as $ap) {
        $f = flat($ap);
        $macRaw = (string)pick($f, K_AP_MAC, '');
        if ($macRaw === '') continue;
        $mac = norm_mac($macRaw);
        $online = is_online(pick($f, K_AP_STATE));

        if ($withSysInfo && $online) {
            try {
                $si = $api->call('ap.getSystemInfo', ['apmac' => $macRaw]);
                dump_raw($rawDir, "ap.getSystemInfo_$mac", $si);
                if (is_array($si)) $f = $f + flat($si);   // Werte aus der Liste haben Vorrang
            } catch (Throwable $e) { logmsg("getSystemInfo $mac: " . $e->getMessage()); }
        }
        $apsToQuery[$mac] = ['raw_mac' => $macRaw, 'online' => $online, 'f' => $f, 'ap' => $ap];
        $seenAps[] = $mac;
    }

    /* ---------------- Clients je AP */
    $prev = [];
    foreach ($db->query('SELECT * FROM clients') as $r) $prev[$r['mac']] = $r;
    $seenClients = [];
    $clUp = $db->prepare(<<<SQL
INSERT INTO clients(mac,ap_mac,ip,hostname,ssid,band,rssi,tx_bytes,rx_bytes,tx_rate,rx_rate,link_tx,link_rx,connected,assoc_since,last_seen,first_seen,raw,ostype)
VALUES(:mac,:ap,:ip,:host,:ssid,:band,:rssi,:txb,:rxb,:txr,:rxr,:ltx,:lrx,1,:since,:ls,:fs,:raw,:os)
ON CONFLICT(mac) DO UPDATE SET
  ap_mac=excluded.ap_mac, ip=COALESCE(excluded.ip,ip), hostname=COALESCE(excluded.hostname,hostname),
  ssid=COALESCE(excluded.ssid,ssid), band=excluded.band, rssi=excluded.rssi, tx_bytes=excluded.tx_bytes,
  rx_bytes=excluded.rx_bytes, tx_rate=excluded.tx_rate, rx_rate=excluded.rx_rate, link_tx=excluded.link_tx,
  link_rx=excluded.link_rx, ostype=COALESCE(excluded.ostype,ostype), connected=1, assoc_since=excluded.assoc_since, last_seen=excluded.last_seen, raw=excluded.raw
SQL);
    $log = $db->prepare('INSERT INTO assoc_log(ts,event,client_mac,ap_mac,prev_ap_mac,ip,hostname,ssid) VALUES(?,?,?,?,?,?,?,?)');

    $db->beginTransaction();
    foreach ($apsToQuery as $apMac => $info) {
        $clients = [];
        if ($info['online']) {
            try {
                $res = $api->call('ap.getClient', ['apmac' => $info['raw_mac']]);
                dump_raw($rawDir, "ap.getClient_$apMac", $res);
                $clients = find_list($res);
            } catch (Throwable $e) { logmsg("getClient $apMac: " . $e->getMessage()); }
        }

        foreach ($clients as $c) {
            $f = flat($c);
            $live = pick($f, K_CL_LIVE);
            if ($live === false || $live === 'false' || $live === 0 || $live === '0') continue; // vom AP als nicht mehr online gemeldet
            $mac = norm_mac((string)pick($f, K_CL_MAC, ''));
            if ($mac === '' || isset($seenClients[$mac])) continue;
            $seenClients[$mac] = true;
            $p = $prev[$mac] ?? null;

            $ip   = pick($f, K_CL_IP);
            if ($ip !== null && !filter_var($ip, FILTER_VALIDATE_IP)) $ip = null;
            $host = pick($f, K_CL_HOST);
            $ssid = pick($f, K_CL_SSID);
            $txb  = to_bytes(pick($f, K_CL_TXB));
            $rxb  = to_bytes(pick($f, K_CL_RXB));

            // Durchsatz aus Zählerdelta
            $txr = $rxr = null;
            if ($p && $p['connected'] && $p['ap_mac'] === $apMac && $p['last_seen']) {
                $dt = $now - (int)$p['last_seen'];
                if ($dt > 0) {
                    if ($txb !== null && $p['tx_bytes'] !== null && $txb >= $p['tx_bytes']) $txr = ($txb - $p['tx_bytes']) / $dt;
                    if ($rxb !== null && $p['rx_bytes'] !== null && $rxb >= $p['rx_bytes']) $rxr = ($rxb - $p['rx_bytes']) / $dt;
                }
            }

            // Ereignis bestimmen
            $since = $p['assoc_since'] ?? $now;
            if (!$p || !$p['connected']) {
                $log->execute([$now, 'connect', $mac, $apMac, null, $ip, $host, $ssid]); $since = $now;
            } elseif ($p['ap_mac'] !== $apMac) {
                $log->execute([$now, 'roam', $mac, $apMac, $p['ap_mac'], $ip, $host, $ssid]); $since = $now;
            }
            $onl = pick($f, K_CL_ONL);
            if (is_numeric($onl) && (int)$onl > 0 && (int)$onl < 86400 * 365) $since = min($since, $now - (int)$onl);

            $rssi = pick($f, K_CL_RSSI);
            $clUp->execute([
                ':mac' => $mac, ':ap' => $apMac, ':ip' => $ip, ':host' => $host, ':ssid' => $ssid,
                ':band' => band_label(pick($f, K_CL_BAND)), ':rssi' => is_numeric($rssi) ? (int)$rssi : null,
                ':txb' => $txb, ':rxb' => $rxb, ':txr' => $txr, ':rxr' => $rxr,
                ':ltx' => pick($f, K_CL_TXR), ':lrx' => pick($f, K_CL_RXR),
                ':since' => $since, ':ls' => $now, ':fs' => $p['first_seen'] ?? $now,
                ':raw' => json_encode($c, JSON_UNESCAPED_UNICODE),
                ':os' => pick($f, K_CL_OS),
            ]);
        }

        $f = $info['f'];
        $apUp->execute([
            ':mac' => $apMac, ':name' => pick($f, K_AP_NAME), ':ip' => pick($f, K_AP_IP),
            ':model' => pick($f, K_AP_MODEL), ':version' => pick($f, K_AP_VER), ':role' => ap_role_label(pick($f, K_AP_ROLE)),
            ':online' => $info['online'] ? 1 : 0, ':uptime' => pick($f, K_AP_UP),
            ':cc' => $info['online'] ? max(count($clients), (int)pick($f, K_AP_CCNT, 0)) : 0,
            ':ls' => $info['online'] ? $now : null, ':fs' => $now, ':raw' => json_encode($info['ap'], JSON_UNESCAPED_UNICODE),
        ]);
    }

    // Clients, die nicht mehr gesehen werden -> disconnect
    foreach ($prev as $mac => $p) {
        if ($p['connected'] && !isset($seenClients[$mac])) {
            $log->execute([$now, 'disconnect', $mac, $p['ap_mac'], null, $p['ip'], $p['hostname'], $p['ssid']]);
            $db->prepare('UPDATE clients SET connected=0, tx_rate=NULL, rx_rate=NULL WHERE mac=?')->execute([$mac]);
        }
    }
    // APs, die nicht mehr in der Liste stehen -> offline
    if ($seenAps) {
        $in = implode(',', array_fill(0, count($seenAps), '?'));
        $db->prepare("UPDATE aps SET online=0, client_count=0 WHERE mac NOT IN ($in)")->execute($seenAps);
    }
    $db->commit();

    meta_set('pvc_ip', $api->pvcIp());
    logmsg(sprintf('OK – %d APs, %d Clients (PVC %s)', count($seenAps), count($seenClients), $api->pvcIp()));
}
