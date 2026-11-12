<?php
/**
 * Diagnose: zeigt die zuletzt vom Cluster gelieferten Roh-Antworten und welche
 * Felder der Poller daraus erkannt hat. Nur erreichbar, wenn DEBUG_PASSWORD gesetzt ist.
 * Aufruf: /debug.php  (Benutzer: debug, Passwort: DEBUG_PASSWORD)
 */
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

$pw = cfg('DEBUG_PASSWORD');
if (!$pw) { http_response_code(404); exit('Not found'); }
if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'debug' || !hash_equals($pw, $_SERVER['PHP_AUTH_PW'] ?? '')) {
    header('WWW-Authenticate: Basic realm="Stellar-Debug"'); http_response_code(401); exit('Debug-Login');
}

// Live-Abfrage einer Methode (nur lesende Methoden erlaubt)
$allowed = ['system.getPvc', 'system.getApList', 'ap.getSystemInfo', 'ap.getClient', 'wlan.list', 'wlan.get'];
$live = null;
if (isset($_GET['m']) && in_array($_GET['m'], $allowed, true)) {
    $params = [];
    if (!empty($_GET['apmac'])) $params['apmac'] = $_GET['apmac'];
    if (!empty($_GET['essid'])) $params['essid'] = $_GET['essid'];
    try { $a = api(); $live = $a->call($_GET['m'], $params); $a->logout(); if (privacy_on() && $_GET['m'] === 'ap.getClient') $live = scrub_pii($live); }
    catch (Throwable $e) { $live = ['FEHLER' => $e->getMessage()]; }
}

$rawDir = dirname(cfg('DB_PATH', __DIR__ . '/../../data/monitor.sqlite')) . '/raw';
$files = glob("$rawDir/*.json") ?: [];
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Stellar Debug</title><link rel="stylesheet" href="style.css"></head><body>
<header class="top"><h1>Debug – Rohdaten</h1><nav><a href="./">← Übersicht</a></nav></header>
<main>
<form class="toolbar" method="get">
  <select name="m"><?php foreach ($allowed as $m): ?><option <?= ($_GET['m'] ?? '') === $m ? 'selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select>
  <input name="apmac" placeholder="apmac (optional)" value="<?= h($_GET['apmac'] ?? '') ?>">
  <input name="essid" placeholder="essid (optional)" value="<?= h($_GET['essid'] ?? '') ?>">
  <button>Live abfragen</button>
</form>
<?php if ($live !== null): ?><h2>Live: <?= h($_GET['m']) ?></h2><pre class="raw"><?= h(json_encode($live, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
<p>Letzter Poll: <?= ($t = (int)meta_get('last_poll', 0)) ? date('d.m.Y H:i:s', $t) : '–' ?> · Dauer: <?= h(meta_get('poll_duration', '–')) ?> s · Fehler: <?= h(meta_get('last_error', '') ?: 'keiner') ?></p>
<?php foreach ($files as $f): $data = json_decode((string)file_get_contents($f), true); $list = find_list($data); ?>
  <details class="card"><summary><b><?= h(basename($f, '.json')) ?></b> · <?= date('H:i:s', filemtime($f)) ?> · <?= count($list) ?> Einträge</summary>
  <?php if ($list): ?><p class="small">Erkannte Felder (1. Eintrag): <span class="mono"><?= h(implode(', ', array_keys(flat($list[0])))) ?></span></p><?php endif; ?>
  <pre class="raw"><?= h(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details>
<?php endforeach; ?>
</main></body></html>
