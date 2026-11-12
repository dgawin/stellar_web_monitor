<?php
/**
 * Simuliertes Stellar Express Cluster für lokale Tests – Antwortformat exakt
 * nach "AP Northbound API User Guide" (system.getPvc, user.logIn, system.getApList,
 * ap.getSystemInfo, ap.getClient, user.logOut).
 *
 *   php -S 127.0.0.1:18080 mock/cluster.php
 *
 * Login: Administrator / admin  (Passwort wird als MD5 erwartet, wie beim echten Cluster)
 * Passwort "lock" -> "Login log account lock"
 */
declare(strict_types=1);
header('Content-Type: application/json');

$req = json_decode((string)file_get_contents('php://input'), true) ?: [];
$id = $req['id'] ?? 1;
$m  = $req['method'] ?? '';
$p  = $req['params'] ?? [];
$stateFile = sys_get_temp_dir() . '/stellar_mock_state.json';
$st = is_file($stateFile) ? json_decode((string)file_get_contents($stateFile), true) : [];
$st += ['sessions' => [], 'clients' => []];

function out($id, array $result): never { echo json_encode(['id' => $id, 'jsonrpc' => '2.0', 'result' => $result]); exit; }

function check_login(array $p): ?string {
    if (($p['username'] ?? '') !== 'Administrator') return 'The username is not Administrator';
    if (($p['password'] ?? '') === md5('lock')) return 'Login log account lock';
    if (($p['password'] ?? '') !== md5('admin')) return 'Login log Password error 1';
    return null;
}

$aps = [];
for ($i = 1; $i <= 32; $i++) {
    $aps[] = [
        'ap_name' => $i === 1 ? 'AP-Rezeption' : sprintf('AP-%02d-%s', $i, ['Restaurant', 'Terrasse', 'Zimmer', 'Seminar', 'Flur'][$i % 5]),
        'mac'     => sprintf('34:e7:0b:00:00:%02x', $i),
        'ip'      => '192.168.10.' . (10 + $i),
        'status'  => in_array($i, [7, 19, 28], true) ? 4 : 3,
        'role'    => $i === 1 ? 1 : ($i === 2 ? 2 : 3),
        'version' => '4.0.5.2045',
    ];
}

switch ($m) {
    case 'system.getPvc':
        if ($e = check_login($p)) out($id, ['success' => 'false', 'message' => str_contains($e, 'Password') ? 'Password wrong' : $e]);
        out($id, ['pvcip' => '127.0.0.1', 'success' => 'true', 'message' => 'Get pvc success']);

    case 'user.logIn':
        if ($e = check_login($p)) out($id, ['success' => 'false', 'message' => $e]);
        $sid = bin2hex(random_bytes(16));
        $st['sessions'][$sid] = time();
        file_put_contents($stateFile, json_encode($st));
        out($id, ['success' => 'true', 'message' => 'Login success', 'sessionId' => $sid]);
}

$sid = $req['session'] ?? '';
if (!isset($st['sessions'][$sid]) || time() - $st['sessions'][$sid] > 600) out($id, ['success' => 'false', 'message' => 'Invalid session']);
$st['sessions'][$sid] = time();

// Clients simulieren: ~60 Geräte, alle 20 s wechseln einige den AP, Zähler steigen
$slot = intdiv(time(), 20);
$online = array_values(array_filter($aps, fn($a) => $a['status'] === 3));
$names = ['iPhone-Anna', 'Galaxy-S24', 'MacBook-Pro', 'POS-1', 'POS-2', 'iPad-Service', 'Pixel-8', 'ThinkPad', 'Drucker-Buero', 'Sonos-Restaurant', '', ''];
$oses  = ['iOS', 'Android', 'macOS', 'Linux', 'Linux', 'iOS', 'Android', 'Windows', 'Linux', 'Linux', 'Android', 'iOS'];
for ($c = 0; $c < 60; $c++) {
    $mac = sprintf('a4:5e:60:%02x:%02x:%02x', $c, $c * 3 % 256, $c * 7 % 256);
    $cl = $st['clients'][$mac] ?? ['since' => time(), 'tx' => 0, 'rx' => 0, 'ap' => null];
    $present = (($c * 31 + intdiv($slot, 3)) % 11) !== 0;                // ab und zu weg
    $apIdx = ($c * 7 + ($c % 4 === 0 ? intdiv($slot, 2) : 0)) % count($online); // einige roamen
    $ap = $online[$apIdx]['mac'];
    if (!$present) { $cl['ap'] = null; $cl['tx'] = $cl['rx'] = 0; $st['clients'][$mac] = $cl; continue; }
    if ($cl['ap'] !== $ap) { $cl = ['since' => time(), 'tx' => 0, 'rx' => 0, 'ap' => $ap]; }
    if ($m === 'system.getApList') {          // Zähler einmal pro Poll-Durchlauf erhöhen
        $cl['tx'] += random_int(20_000, 40_000_000);
        $cl['rx'] += random_int(5_000, 6_000_000);
    }
    $cl['host'] = $names[$c % count($names)] . ($names[$c % count($names)] ? '-' . $c : '');
    $cl['os'] = $oses[$c % count($oses)];
    $st['clients'][$mac] = $cl;
}
file_put_contents($stateFile, json_encode($st));

switch ($m) {
    case 'user.logOut':
        unset($st['sessions'][$sid]); file_put_contents($stateFile, json_encode($st));
        out($id, ['success' => 'true', 'message' => 'Log out success']);

    case 'system.getApList':
        out($id, ['aps' => $aps, 'success' => 'true', 'message' => 'Get aplist success']);

    case 'ap.getSystemInfo':
        foreach ($aps as $i => $a) if ($a['mac'] === strtolower($p['apmac'] ?? '')) out($id, ['apinfo' => [
            'model' => $i % 3 ? 'OAW-AP1301' : 'OAW-AP1331', 'mac' => $a['mac'], 'ip' => $a['ip'],
            'uptime' => 86400 * 3 + $i * 3600, 'version' => $a['version'], 'location' => 'Demo'],
            'success' => 'true', 'message' => 'Get ap info success']);
        out($id, ['success' => 'false', 'message' => 'Mqtt timeout']);

    case 'ap.getClient':
        $users = [];
        foreach ($st['clients'] as $mac => $cl) {
            if (($cl['ap'] ?? null) !== strtolower($p['apmac'] ?? '')) continue;
            $users[] = [
                'on_line' => true, 'mac' => $mac, 'ap' => $cl['ap'], 'vap' => $cl['ap'], 'wlanid' => 1, 'radioid' => 1,
                'AssocationSSID' => str_starts_with($cl['host'], 'POS') ? 'Intern' : 'Gast-WLAN',
                'AttachedBand' => crc32($mac) % 3 ? 5 : 2, 'Channel' => 36, 'AuthType' => 'WPA2-PSK', 'Portal_username' => '',
                'ip' => '192.168.20.' . (100 + hexdec(substr($mac, -2)) % 150), 'uptime' => time() - $cl['since'], 'livetime' => 0,
                'signal' => 15 + crc32($mac) % 35, 'mode' => 19,
                'rx_rate' => 390000, 'rx_wifirate' => 866700, 'rx_bytes' => $cl['rx'],
                'tx_rate' => 27000, 'tx_wifirate' => 866700, 'tx_bytes' => $cl['tx'],
                'portal_enable' => false, 'devtype' => 'Mobile', 'hostname' => $cl['host'], 'ostype' => $cl['os'],
                'devmodel' => '', 'delaytime' => 12, 'packetloss' => 0, 'up_rate' => 6, 'down_rate' => 4,
            ];
        }
        out($id, ['total' => count($users), 'users' => $users, 'success' => 'true', 'message' => 'Get clients success']);
}
out($id, ['success' => 'false', 'message' => 'No method']);
