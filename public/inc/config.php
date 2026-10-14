<?php
declare(strict_types=1);

/*
 * Dove vive l'archivio. Tre possibilita', in ordine di precedenza:
 *
 * 1. inc/config.local.php definisce GESTPAL_DATA_DIR  -> comanda quello
 * 2. esiste <progetto>/../dati                        -> layout con document root su public/,
 *                                                        i dati stanno FUORI dal web
 * 3. altrimenti                                       -> <progetto>/data (installazione piatta)
 *
 * Il percorso effettivamente in uso e' mostrato nell'area Amministrazione.
 */
if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}
if (defined('GESTPAL_DATA_DIR')) {
    define('DATA_DIR', rtrim(GESTPAL_DATA_DIR, "/\\"));
} elseif (is_dir(__DIR__ . '/../../dati')) {
    define('DATA_DIR', __DIR__ . '/../../dati');
} else {
    define('DATA_DIR', __DIR__ . '/../data');
}
define('DATA_FILE',  DATA_DIR . '/db.json');
define('LOCK_FILE',  DATA_DIR . '/db.lock');
define('BACKUP_DIR', DATA_DIR . '/backup');

date_default_timezone_set('Europe/Rome');
mb_internal_encoding('UTF-8');

if (session_status() === PHP_SESSION_NONE) {
    session_name('GESTPAL');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

/* Impostazioni modificabili da admin.php: diventano costanti per il resto della richiesta. */
$imp = impostazioni();
define('APP_NAME',       $imp['nome'] !== '' ? $imp['nome'] : 'Gestionale Palestra');
define('CONTATTI',       (string)$imp['contatti']);
define('ACCENTO',        preg_match('/^#[0-9a-f]{6}$/i', (string)$imp['accento']) ? $imp['accento'] : '#8b5cf6');
define('CERT_WARN_DAYS', max(1, min(365, (int)$imp['cert_giorni'])));
unset($imp);
