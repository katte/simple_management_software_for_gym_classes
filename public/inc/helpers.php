<?php
declare(strict_types=1);

const GIORNI = [1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica'];

const COLORI = [
    '#8b5cf6' => 'Viola',
    '#ec4899' => 'Rosa',
    '#0ea5e9' => 'Azzurro',
    '#10b981' => 'Verde',
    '#f59e0b' => 'Ambra',
    '#ef4444' => 'Rosso',
    '#64748b' => 'Grigio',
];

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function post(string $k, $def = ''): string
{
    return is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : (string)$def;
}

function get(string $k, $def = ''): string
{
    return is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : (string)$def;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['_csrf'] ?? ''))) {
        http_response_code(400);
        exit('Sessione scaduta. Ricarica la pagina e riprova.');
    }
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** Normalizza "9", "9:5", "0900" in "09:05". Stringa vuota se non valido. */
function norm_ora(string $v): string
{
    $v = trim($v);
    if ($v === '') {
        return '';
    }
    if (preg_match('/^(\d{1,2})[:.]?(\d{2})$/', $v, $m)) {
        $hh = (int)$m[1];
        $mm = (int)$m[2];
    } elseif (preg_match('/^(\d{1,2})$/', $v, $m)) {
        $hh = (int)$m[1];
        $mm = 0;
    } else {
        return '';
    }
    if ($hh > 23 || $mm > 59) {
        return '';
    }
    return sprintf('%02d:%02d', $hh, $mm);
}

/** Data ISO valida (Y-m-d) oppure stringa vuota. */
function norm_data(string $v): string
{
    $v = trim($v);
    if ($v === '') {
        return '';
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}

function data_it(string $iso): string
{
    return $iso === '' ? '' : implode('/', array_reverse(explode('-', $iso)));
}

/**
 * Stato certificato medico: mancante | scaduto | scadenza | ok
 * Restituisce [stato, giorni_residui|null].
 */
function cert_stato(string $scadenza): array
{
    if ($scadenza === '') {
        return ['mancante', null];
    }
    $oggi = new DateTime('today');
    $d    = DateTime::createFromFormat('Y-m-d', $scadenza);
    if (!$d) {
        return ['mancante', null];
    }
    $d->setTime(0, 0);
    $gg = (int)$oggi->diff($d)->format('%r%a');
    if ($gg < 0) {
        return ['scaduto', $gg];
    }
    if ($gg <= CERT_WARN_DAYS) {
        return ['scadenza', $gg];
    }
    return ['ok', $gg];
}

const CERT_LABEL = [
    'mancante' => 'Certificato mancante',
    'scaduto'  => 'Certificato scaduto',
    'scadenza' => 'Certificato in scadenza',
    'ok'       => 'Certificato valido',
];

/** Etichetta completa di un sottocorso, es. "Lunedì 18:00-19:00". */
function orario_label(array $sc): string
{
    $g = GIORNI[(int)$sc['giorno']] ?? '';
    $o = $sc['ora_inizio'] ?? '';
    if (!empty($sc['ora_fine'])) {
        $o .= '-' . $sc['ora_fine'];
    }
    return trim($g . ' ' . $o);
}

/** Ordina i sottocorsi per giorno e orario. */
function sort_sottocorsi(array &$rows): void
{
    usort($rows, static function ($a, $b) {
        return [(int)$a['giorno'], (string)$a['ora_inizio']] <=> [(int)$b['giorno'], (string)$b['ora_inizio']];
    });
}

/** Iscrizioni di un sottocorso indicizzate per posto (slot). */
function iscritti_per_posto(array $data, int $sottocorso_id): array
{
    $out = [];
    foreach ($data['iscrizioni'] as $i) {
        if ((int)$i['sottocorso_id'] === $sottocorso_id) {
            $out[(int)$i['posto']] = $i;
        }
    }
    ksort($out);
    return $out;
}

function conta_iscritti(array $data, int $sottocorso_id): int
{
    $n = 0;
    foreach ($data['iscrizioni'] as $i) {
        if ((int)$i['sottocorso_id'] === $sottocorso_id) {
            $n++;
        }
    }
    return $n;
}

function nome_completo(?array $p): string
{
    return $p ? trim(($p['cognome'] ?? '') . ' ' . ($p['nome'] ?? '')) : '';
}

/** Confronto "morbido" fra nomi: senza maiuscole e spazi superflui. */
function stessa_stringa(string $a, string $b): bool
{
    return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
}

/**
 * Tutte le iscrizioni di una persona, con sottocorso e corso gia' risolti.
 * Ordinate per giorno/ora. Ogni voce: iscrizione, sottocorso, corso.
 */
function corsi_di_persona(array $data, int $persona_id): array
{
    $scmap = map_by_id($data['sottocorsi']);
    $cmap  = map_by_id($data['corsi']);
    $out   = [];
    foreach ($data['iscrizioni'] as $is) {
        if ((int)$is['persona_id'] !== $persona_id) {
            continue;
        }
        $sc = $scmap[(int)$is['sottocorso_id']] ?? null;
        if (!$sc) {
            continue;
        }
        $out[] = [
            'iscrizione' => $is,
            'sottocorso' => $sc,
            'corso'      => $cmap[(int)$sc['corso_id']] ?? ['nome' => '?', 'colore' => '#64748b', 'attivo' => false],
        ];
    }
    usort($out, static function ($a, $b) {
        return [(int)$a['sottocorso']['giorno'], (string)$a['sottocorso']['ora_inizio']]
           <=> [(int)$b['sottocorso']['giorno'], (string)$b['sottocorso']['ora_inizio']];
    });
    return $out;
}

/** Etichetta breve di un sottocorso comprensiva di corso: "Pole Dance · Base". */
function label_corso(array $corso, array $sottocorso): string
{
    return $corso['nome'] . ' · ' . $sottocorso['nome'];
}
