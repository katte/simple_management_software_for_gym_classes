<?php
declare(strict_types=1);

/**
 * Strato di accesso ai dati.
 * Unico punto che tocca il formato di archiviazione (oggi: un file JSON).
 * Per passare a MySQL basta riscrivere le funzioni di questo file
 * mantenendo le stesse firme (store_read / store_update).
 */

function store_default(): array
{
    return [
        'meta'         => ['version' => 1, 'seq' => []],
        'impostazioni' => [],
        'users'        => [],
        'corsi'        => [],
        'sottocorsi'   => [],
        'persone'      => [],
        'iscrizioni'   => [],
    ];
}

/** Valori di fabbrica delle impostazioni modificabili dall'area amministrazione. */
function impostazioni_default(): array
{
    return [
        'nome'        => 'Gestionale Palestra',
        'contatti'    => '',      // riga di contatti stampata in calce ai fogli
        'cert_giorni' => 30,      // preavviso scadenza certificato
        'accento'     => '#8b5cf6',
    ];
}

/**
 * Impostazioni correnti, con i valori di fabbrica per le chiavi mancanti.
 * Non deve mai far fallire una pagina: se l'archivio non e' leggibile torna i default.
 */
function impostazioni(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $data = store_read();
    } catch (Throwable $e) {
        return impostazioni_default();
    }
    $salvate = is_array($data['impostazioni'] ?? null) ? $data['impostazioni'] : [];
    return $cache = array_merge(impostazioni_default(), $salvate);
}

/**
 * Copia dell'archivio tenuta per la durata della richiesta: senza, ogni pagina
 * rileggerebbe il file tre o quattro volte. Si azzera a ogni scrittura.
 */
function store_cache(?array $set = null, bool $azzera = false): ?array
{
    static $c = null;
    if ($azzera) {
        $c = null;
    } elseif ($set !== null) {
        $c = $set;
    }
    return $c;
}

function store_init(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }
    $ht = DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    if (!file_exists(DATA_FILE)) {
        store_save(store_default());
    }
}

function store_save(array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Serializzazione dati fallita: ' . json_last_error_msg());
    }
    $tmp = DATA_FILE . '.tmp';
    if (file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Scrittura non riuscita. Verifica i permessi sulla cartella ' . DATA_DIR);
    }
    if (!rename($tmp, DATA_FILE)) {          // sostituzione atomica
        @unlink($tmp);
        throw new RuntimeException('Salvataggio non riuscito.');
    }
    store_cache($data);
}

function store_read(bool $fresco = false): array
{
    if (!$fresco) {
        $c = store_cache();
        if ($c !== null) {
            return $c;
        }
    }
    store_init();
    $raw  = file_get_contents(DATA_FILE);
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Archivio dati illeggibile o corrotto: ' . DATA_FILE);
    }
    $data += store_default();
    store_cache($data);
    return $data;
}

/**
 * Legge, modifica e riscrive l'archivio in mutua esclusione.
 * $fn riceve i dati per riferimento; il suo valore di ritorno viene restituito.
 */
function store_update(callable $fn)
{
    store_init();
    $lock = fopen(LOCK_FILE, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Archivio occupato, riprova.');
    }
    try {
        $data   = store_read(true);   // dentro il lock si rilegge sempre dal file
        $result = $fn($data);
        store_save($data);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Prossimo id progressivo per una collezione. */
function next_id(array &$data, string $coll): int
{
    $max = (int)($data['meta']['seq'][$coll] ?? 0);
    foreach ($data[$coll] ?? [] as $row) {
        $max = max($max, (int)($row['id'] ?? 0));
    }
    $data['meta']['seq'][$coll] = $max + 1;
    return $max + 1;
}

/** Cerca una riga per id. */
function find_by_id(array $rows, $id): ?array
{
    foreach ($rows as $r) {
        if ((int)$r['id'] === (int)$id) {
            return $r;
        }
    }
    return null;
}

/** Indice di una riga per id, o null. */
function index_by_id(array $rows, $id): ?int
{
    foreach ($rows as $i => $r) {
        if ((int)$r['id'] === (int)$id) {
            return $i;
        }
    }
    return null;
}

/** Riorganizza una lista in mappa id => riga. */
function map_by_id(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['id']] = $r;
    }
    return $out;
}
