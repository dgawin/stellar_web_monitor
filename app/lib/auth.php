<?php
/** Optionaler Basic-Auth-Schutz: greift, wenn WEB_USER und WEB_PASSWORD gesetzt sind. */
declare(strict_types=1);

function require_auth(?string $user, ?string $pass, string $realm = 'WLAN-Uebersicht'): void {
    if (!$user || !$pass) return;
    $u = $_SERVER['PHP_AUTH_USER'] ?? '';
    $p = $_SERVER['PHP_AUTH_PW'] ?? '';
    if (hash_equals($user, $u) && hash_equals($pass, $p)) return;
    header('WWW-Authenticate: Basic realm="' . $realm . '", charset="UTF-8"');
    http_response_code(401);
    exit('Anmeldung erforderlich.');
}

require_auth(cfg('WEB_USER'), cfg('WEB_PASSWORD'));
