<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/auth.php';

$db      = db();
$view    = in_array($_GET['v'] ?? '', ['aps', 'clients', 'log'], true) ? $_GET['v'] : 'aps';
$partial = isset($_GET['partial']);
$title   = cfg('SITE_TITLE', 'WLAN-Übersicht');
$staleAfter = max(15, (int)cfg('POLL_INTERVAL', 60)) * 3;

$lastPoll  = (int)meta_get('last_poll', 0);
$lastError = (string)meta_get('last_error', '');
$stale     = !$lastPoll || (time() - $lastPoll) > $staleAfter;

$aps = $db->query('SELECT * FROM aps ORDER BY online ASC, LOWER(COALESCE(name, mac))')->fetchAll();
$apName = [];
foreach ($aps as $a) $apName[$a['mac']] = $a['name'] ?: $a['mac'];

$clients = $db->query('SELECT * FROM clients WHERE connected=1 ORDER BY LOWER(COALESCE(hostname, ip, mac))')->fetchAll();
$byAp = [];
foreach ($clients as $c) $byAp[$c['ap_mac']][] = $c;

// letzte erfolgreiche Assoziation je AP
$lastAssoc = [];
foreach ($db->query("SELECT l.* FROM assoc_log l JOIN (SELECT ap_mac, MAX(id) mid FROM assoc_log WHERE event IN ('connect','roam') GROUP BY ap_mac) x ON l.id=x.mid") as $r)
    $lastAssoc[$r['ap_mac']] = $r;
// Clients, die gerade verbunden sind, zählen ebenfalls als „zuletzt assoziiert“
foreach ($clients as $c) {
    $cur = $lastAssoc[$c['ap_mac']] ?? null;
    if (!$cur || $cur['ts'] < $c['assoc_since']) $lastAssoc[$c['ap_mac']] = ['ts' => $c['assoc_since'], 'ip' => $c['ip'], 'hostname' => $c['hostname'], 'client_mac' => $c['mac'], 'ostype' => $c['ostype'] ?? null];
}

$onlineCnt = count(array_filter($aps, fn($a) => $a['online']));
$totTx = array_sum(array_map(fn($c) => (float)$c['tx_rate'], $clients));
$totRx = array_sum(array_map(fn($c) => (float)$c['rx_rate'], $clients));

function client_label(array $c): string { return dev_label($c['hostname'], $c['ostype'] ?? null, $c['mac'], $c['ip']); }
/** Signal: negative Werte = dBm (RSSI), positive Werte = Abstand zum Rauschen in dB (wie von ap.getClient geliefert). */
function rssi_class($r): string {
    if ($r === null) return '';
    $r = (int)$r;
    if ($r > 0) return $r >= 25 ? 'good' : ($r >= 15 ? 'ok' : 'bad');
    return $r >= -60 ? 'good' : ($r >= -72 ? 'ok' : 'bad');
}
function rssi_tag($r): string {
    if ($r === null) return '–';
    $r = (int)$r;
    $t = $r > 0 ? "Signal $r dB" : "$r dBm";
    return '<span class="tag rssi ' . rssi_class($r) . '" title="Signalstärke">' . h($t) . '</span>';
}
function vol(array $c): string {
    $tx = $c['tx_bytes'] !== null ? (float)$c['tx_bytes'] : null;
    $rx = $c['rx_bytes'] !== null ? (float)$c['rx_bytes'] : null;
    return '↓ ' . fmt_bytes($tx) . ' · ↑ ' . fmt_bytes($rx);
}

ob_start(); ?>
<section class="stats">
  <div class="stat"><span class="num"><?= $onlineCnt ?>/<?= count($aps) ?></span><span class="lbl">APs online</span></div>
  <div class="stat"><span class="num"><?= count($clients) ?></span><span class="lbl">Clients verbunden</span></div>
  <div class="stat"><span class="num"><?= fmt_rate($totTx ?: null) ?></span><span class="lbl">↓ an Clients</span></div>
  <div class="stat"><span class="num"><?= fmt_rate($totRx ?: null) ?></span><span class="lbl">↑ von Clients</span></div>
</section>

<?php if ($stale || $lastError): ?>
<div class="alert">
  <?php if ($stale): ?>Daten sind nicht aktuell – letzte Abfrage: <?= $lastPoll ? date('d.m.Y H:i:s', $lastPoll) : 'noch keine' ?>.<?php endif; ?>
  <?php if ($lastError): ?><br><small><?= h($lastError) ?></small><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($view === 'aps'): ?>
  <div class="toolbar">
    <input type="search" class="filter" placeholder="AP filtern nach Name, Standort, IP, Modell …" data-filter=".aprow">
    <label class="chk"><input type="checkbox" id="onlyOff"> nur offline</label>
  </div>
  <?php if (!$aps): ?><p class="empty">Noch keine Daten vom Cluster. Der erste Abruf läuft gleich.</p><?php endif; ?>
  <div class="aplist">
    <div class="aphead"><span></span><span>Access Point</span><span>IP / Modell</span><span>Clients</span><span>Durchsatz ↓ / ↑</span><span>Letzte Anmeldung</span></div>
  <?php foreach ($aps as $ap):
      $cl = $byAp[$ap['mac']] ?? [];
      $la = $lastAssoc[$ap['mac']] ?? null;
      $apTx = array_sum(array_map(fn($c) => (float)$c['tx_rate'], $cl));
      $apRx = array_sum(array_map(fn($c) => (float)$c['rx_rate'], $cl));
      $isPvc = $ap['role'] && preg_match('/pvc|master|primary/i', (string)$ap['role']); ?>
    <details class="aprow <?= $ap['online'] ? 'on' : 'off' ?>" data-mac="<?= h($ap['mac']) ?>">
      <summary>
        <span class="dot" title="<?= $ap['online'] ? 'online' : 'offline' ?>"></span>
        <span class="c-name"><b><?= h($ap['name'] ?: $ap['mac']) ?></b><?= ($ap['location'] ?? '') !== '' ? '<span class="small muted loc">📍 ' . h($ap['location']) . '</span>' : '' ?><?= $isPvc ? ' <span class="tag">PVC</span>' : '' ?>
          <?php if (!$ap['online']): ?><span class="small offtxt"><?= $ap['last_seen'] ? 'offline seit ' . fmt_ago((int)$ap['last_seen']) : 'offline (Status ' . h(ap_state_label(json_decode((string)$ap['raw'], true)['status'] ?? null) ?? 'unbekannt') . ')' ?></span><?php endif; ?></span>
        <span class="c-ip"><span class="mono"><?= h($ap['ip'] ?: '–') ?></span><span class="small muted"><?= h($ap['model'] ?: '') ?></span></span>
        <span class="c-cnt"><span class="badge"><?= count($cl) ?></span></span>
        <span class="c-rate" data-l="Durchsatz"><?= $cl ? fmt_rate($apTx ?: null) . ' / ' . fmt_rate($apRx ?: null) : '–' ?></span>
        <span class="c-last" data-l="Letzte Anmeldung"><?php if ($la): ?><?= fmt_ago((int)$la['ts']) ?> · <?= h(dev_label($la['hostname'] ?? null, $la['ostype'] ?? null, $la['client_mac'] ?? null)) ?><?= $la['ip'] ? ' <span class="mono small">' . h($la['ip']) . '</span>' : '' ?><?php else: ?>–<?php endif; ?></span>
      </summary>
      <div class="apdetail">
        <div class="small muted">MAC <span class="mono"><?= h($ap['mac']) ?></span><?= ($ap['location'] ?? '') !== '' ? ' · Standort ' . h($ap['location']) : '' ?><?= $ap['version'] ? ' · Firmware <span class="mono">' . h($ap['version']) . '</span>' : '' ?><?= $ap['uptime'] ? ' · Laufzeit ' . h(is_numeric($ap['uptime']) ? fmt_dur((int)$ap['uptime']) : $ap['uptime']) : '' ?><?= $ap['role'] ? ' · Rolle ' . h($ap['role']) : '' ?> · <a href="?v=log&ap=<?= urlencode($ap['mac']) ?>">Verlauf</a></div>
        <?php if ($cl): ?>
        <ul class="clients">
        <?php foreach ($cl as $c): ?>
          <li>
            <span class="cl-name"><?= h(client_label($c)) ?></span>
            <span class="mono small"><?= h($c['ip'] ?: '—') ?></span>
            <span><?php if ($c['ssid']): ?><span class="tag"><?= h($c['ssid']) ?></span><?php endif; ?>
              <?php if ($c['band']): ?><span class="tag"><?= h($c['band']) ?></span><?php endif; ?>
              <?php if ($c['rssi'] !== null): ?><?= rssi_tag($c['rssi']) ?><?php endif; ?></span>
            <span class="rate"><span title="Übertragene Datenmenge seit Anmeldung"><?= vol($c) ?></span><br><span class="small">↓ <?= fmt_rate($c['tx_rate'] !== null ? (float)$c['tx_rate'] : null) ?> · ↑ <?= fmt_rate($c['rx_rate'] !== null ? (float)$c['rx_rate'] : null) ?></span></span>
          </li>
        <?php endforeach; ?>
        </ul>
        <?php else: ?><p class="small muted">Keine Clients verbunden.</p><?php endif; ?>
      </div>
    </details>
  <?php endforeach; ?>
  </div>

<?php elseif ($view === 'clients'): ?>
  <input type="search" class="filter" placeholder="Filtern nach Name, IP, MAC, AP …" data-filter=".row">
  <?php if (!$clients): ?><p class="empty">Aktuell sind keine Clients verbunden.</p><?php endif; ?>
  <div class="table">
    <div class="thead"><span>Client</span><span>IP</span><span>Access Point</span><span>Signal</span><span>Durchsatz ↓ / ↑</span><span>Datenmenge ↓ / ↑</span><span>Verbunden seit</span></div>
    <?php foreach ($clients as $c): ?>
    <div class="row">
      <span data-l="Client"><b><?= h(client_label($c)) ?></b><br><span class="mono small muted"><?= h(client_ref($c['mac'])) ?></span></span>
      <span data-l="IP" class="mono"><?= h($c['ip'] ?: '—') ?></span>
      <span data-l="AP"><?= h($apName[$c['ap_mac']] ?? $c['ap_mac']) ?><?= $c['ssid'] ? '<br><span class="small muted">' . h($c['ssid']) . ($c['band'] ? ' · ' . h($c['band']) : '') . '</span>' : '' ?></span>
      <span data-l="Signal"><?= rssi_tag($c['rssi']) ?></span>
      <span data-l="Durchsatz"><?= fmt_rate($c['tx_rate'] !== null ? (float)$c['tx_rate'] : null) ?> / <?= fmt_rate($c['rx_rate'] !== null ? (float)$c['rx_rate'] : null) ?></span>
      <span data-l="Datenmenge"><?= vol($c) ?></span>
      <span data-l="Seit"><?= date('d.m. H:i', (int)$c['assoc_since']) ?> <span class="small muted">(<?= fmt_dur(time() - (int)$c['assoc_since']) ?>)</span></span>
    </div>
    <?php endforeach; ?>
  </div>

<?php else:
    $apFilter = $_GET['ap'] ?? '';
    $sql = 'SELECT * FROM assoc_log' . ($apFilter ? ' WHERE ap_mac = :ap OR prev_ap_mac = :ap' : '') . ' ORDER BY id DESC LIMIT 300';
    $st = $db->prepare($sql); $st->execute($apFilter ? [':ap' => $apFilter] : []);
    $events = $st->fetchAll(); ?>
  <div class="toolbar">
    <select onchange="location.href='?v=log&ap='+encodeURIComponent(this.value)">
      <option value="">Alle Access Points</option>
      <?php foreach ($aps as $a): ?><option value="<?= h($a['mac']) ?>" <?= $apFilter === $a['mac'] ? 'selected' : '' ?>><?= h($a['name'] ?: $a['mac']) ?></option><?php endforeach; ?>
    </select>
    <input type="search" class="filter" placeholder="Filtern …" data-filter=".ev">
  </div>
  <?php if (!$events): ?><p class="empty">Noch keine Ereignisse aufgezeichnet.</p><?php endif; ?>
  <ol class="events">
  <?php foreach ($events as $e):
      $lbl = ['connect' => 'angemeldet', 'roam' => 'gewechselt', 'disconnect' => 'abgemeldet'][$e['event']] ?? $e['event']; ?>
    <li class="ev <?= h($e['event']) ?>">
      <time><?= date('d.m.Y H:i', (int)$e['ts']) ?></time>
      <div>
        <b><?= h(dev_label($e['hostname'], $e['ostype'] ?? null, $e['client_mac'])) ?></b>
        <?= $e['ip'] ? '<span class="mono small">' . h($e['ip']) . '</span>' : '' ?>
        <span class="evt"><?= $lbl ?></span>
        <?php if ($e['event'] === 'roam'): ?><?= h($apName[$e['prev_ap_mac']] ?? $e['prev_ap_mac']) ?> → <?php endif; ?>
        <?= $e['event'] === 'disconnect' ? 'von' : ($e['event'] === 'connect' ? 'an' : '') ?> <b><?= h($apName[$e['ap_mac']] ?? $e['ap_mac']) ?></b>
        <?= $e['ssid'] ? '<span class="tag">' . h($e['ssid']) . '</span>' : '' ?>
      </div>
    </li>
  <?php endforeach; ?>
  </ol>
<?php endif; ?>

<p class="foot">Stand: <?= $lastPoll ? date('d.m.Y H:i:s', $lastPoll) : '–' ?> · aktualisiert sich automatisch<?= ($pvc = meta_get('pvc_ip')) ? ' · PVC ' . h($pvc) : '' ?>
<?php if (privacy_on()): ?><br><span title="Keine MAC-Adressen, keine Gerätenamen. Geräte werden mit einem täglich wechselnden Pseudonym geführt, IP-Adressen gekürzt.">🔒 Datenschutzmodus: keine MAC-Adressen und Gerätenamen gespeichert · Verlauf <?= (int)cfg('RETENTION_DAYS', 7) ?> Tage</span><?php endif; ?></p>
<?php
$main = ob_get_clean();
if ($partial) { header('Content-Type: text/html; charset=utf-8'); echo $main; exit; }
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#2f5d3a">
<title><?= h($title) ?></title>
<link rel="stylesheet" href="style.css?v=4">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path fill='%232f5d3a' d='M12 20a2 2 0 100-4 2 2 0 000 4zm-4.2-5.6l1.4 1.4a4 4 0 015.6 0l1.4-1.4a6 6 0 00-8.4 0zM5 11.6l1.4 1.4a8 8 0 0111.2 0l1.4-1.4a10 10 0 00-14 0zM2.2 8.8l1.4 1.4a12 12 0 0116.8 0l1.4-1.4a14 14 0 00-19.6 0z'/></svg>">
</head>
<body>
<header class="top">
  <h1><?= h($title) ?></h1>
  <nav>
    <a href="?v=aps" class="<?= $view === 'aps' ? 'act' : '' ?>">Access Points</a>
    <a href="?v=clients" class="<?= $view === 'clients' ? 'act' : '' ?>">Clients</a>
    <a href="?v=log" class="<?= $view === 'log' ? 'act' : '' ?>">Verlauf</a>
  </nav>
</header>
<main id="main"><?= $main ?></main>
<script>
(() => {
  const main = document.getElementById('main');
  const keep = () => ({
    open: [...main.querySelectorAll('details[open][data-mac]')].map(d => d.dataset.mac),
    q: main.querySelector('.filter')?.value || '',
    off: main.querySelector('#onlyOff')?.checked || false
  });
  const applyFilter = () => main.querySelectorAll('.filter').forEach(inp => {
    const q = inp.value.toLowerCase();
    const off = main.querySelector('#onlyOff')?.checked;
    main.querySelectorAll(inp.dataset.filter).forEach(r => {
      const hit = r.textContent.toLowerCase().includes(q) && (!off || r.classList.contains('off'));
      r.style.display = hit ? '' : 'none';
    });
  });
  main.addEventListener('input', e => { if (e.target.matches('.filter, #onlyOff')) applyFilter(); });
  const refresh = async () => {
    if (document.hidden || document.activeElement?.matches('select')) return;
    const st = keep();
    try {
      const u = new URL(location.href); u.searchParams.set('partial', '1');
      const r = await fetch(u, { cache: 'no-store' });
      if (!r.ok) return;
      main.innerHTML = await r.text();
      st.open.forEach(m => { const d = main.querySelector(`details[data-mac="${CSS.escape(m)}"]`); if (d) d.open = true; });
      const o = main.querySelector('#onlyOff'); if (o) o.checked = st.off;
      const f = main.querySelector('.filter'); if (f) { f.value = st.q; applyFilter(); }
    } catch (e) {}
  };
  setInterval(refresh, <?= max(10, (int)cfg('REFRESH_SECONDS', 30)) * 1000 ?>);
  document.addEventListener('visibilitychange', () => !document.hidden && refresh());
})();
</script>
</body>
</html>
