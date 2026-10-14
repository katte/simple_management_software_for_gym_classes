<?php
/**
 * Dati di esempio per provare l'applicazione.
 * Gira una sola volta e solo se non esistono ancora corsi. Cancella questo file in produzione.
 */
require_once __DIR__ . '/inc/config.php';
require_admin();

$creati = store_update(function (array &$data) {
    if ($data['corsi']) {
        return null;
    }
    $palinsesto = [
        ['Pole Dance', '#ec4899', [
            [1, '18:00', '19:00', 'Intermedio teenagers', 8],
            [1, '18:00', '19:00', 'Intermedio', 8],
            [1, '19:00', '20:00', 'Base', 10],
            [3, '18:00', '19:00', 'Base', 10],
            [3, '19:00', '20:00', 'Avanzato', 6],
            [5, '19:00', '20:30', 'Open class', 12],
        ]],
        ['Acrobatica', '#0ea5e9', [
            [2, '17:00', '18:00', 'Bambini', 12],
            [2, '18:00', '19:30', 'Ragazzi', 10],
            [4, '18:00', '19:30', 'Adulti', 10],
            [6, '10:00', '11:30', 'Open class', 14],
        ]],
    ];
    $n = 0;
    foreach ($palinsesto as [$nome, $colore, $scs]) {
        $cid = next_id($data, 'corsi');
        $data['corsi'][] = ['id' => $cid, 'nome' => $nome, 'colore' => $colore, 'attivo' => true];
        foreach ($scs as [$g, $da, $a, $sn, $cap]) {
            $data['sottocorsi'][] = [
                'id'            => next_id($data, 'sottocorsi'),
                'corso_id'      => $cid,
                'nome'          => $sn,
                'giorno'        => $g,
                'ora_inizio'    => $da,
                'ora_fine'      => $a,
                'istruttore_id' => 0,
                'capienza'      => $cap,
                'attivo'        => true,
                'note'          => '',
            ];
            $n++;
        }
    }
    return $n;
});

flash($creati === null
    ? 'Ci sono già dei corsi: nessun dato di esempio inserito.'
    : "Inseriti 2 corsi e $creati sottocorsi di esempio. Assegna gli istruttori e cancella seed.php.",
    $creati === null ? 'warn' : 'ok');
redirect('corsi.php');
