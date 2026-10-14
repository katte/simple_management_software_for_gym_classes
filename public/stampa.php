<?php
/**
 * Fogli da stampare o salvare in PDF (dal dialogo di stampa del browser).
 *   stampa.php?tipo=iscritti&sc=12      un solo sottocorso
 *   stampa.php?tipo=iscritti&sc=all     tutti i sottocorsi, uno per pagina
 *   stampa.php?tipo=calendario          la settimana tipo
 */
require_once __DIR__ . '/inc/config.php';

$me   = require_login();
$data = store_read();
$cmap = map_by_id($data['corsi']);
$umap = map_by_id($data['users']);
$pmap = map_by_id($data['persone']);

$tipo       = get('tipo') === 'calendario' ? 'calendario' : 'iscritti';
$archiviati = get('arch') === '1';
$filtro     = (int)get('corso', '0');

/** Un sottocorso entra nella stampa se non è archiviato (o se li vogliamo tutti). */
$visibile = function (array $s) use ($cmap, $archiviati, $filtro) {
    $c = $cmap[(int)$s['corso_id']] ?? null;
    if (!$c) {
        return false;
    }
    if ($filtro > 0 && (int)$s['corso_id'] !== $filtro) {
        return false;
    }
    return $archiviati || (!empty($s['attivo']) && !empty($c['attivo']));
};

$lista = [];
if ($tipo === 'calendario') {
    $lista = array_values(array_filter($data['sottocorsi'], $visibile));
    sort_sottocorsi($lista);
    $titolo = 'Settimana tipo';
} else {
    $sc_par = get('sc');
    if ($sc_par === 'all') {
        $lista = array_values(array_filter($data['sottocorsi'], $visibile));
        usort($lista, function ($a, $b) use ($cmap) {
            return [mb_strtolower($cmap[(int)$a['corso_id']]['nome']), (int)$a['giorno'], (string)$a['ora_inizio']]
               <=> [mb_strtolower($cmap[(int)$b['corso_id']]['nome']), (int)$b['giorno'], (string)$b['ora_inizio']];
        });
        $titolo = 'Elenchi iscritti';
    } else {
        $uno = find_by_id($data['sottocorsi'], (int)$sc_par);
        if ($uno) {
            $lista[] = $uno;
        }
        $titolo = 'Elenco iscritti';
    }
}
if ($filtro > 0 && isset($cmap[$filtro])) {
    $titolo .= ' · ' . $cmap[$filtro]['nome'];
}

$stampato = 'Stampato il ' . date('d/m/Y') . ' da ' . ($me['nome'] ?? '');
$landscape = $tipo === 'calendario';
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titolo) ?> · <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/print.css?v=1">
<?php if ($landscape): ?><style>@page{size:A4 landscape;margin:10mm}</style><?php endif; ?>
</head>
<body class="<?= $landscape ? 'landscape' : '' ?>">

<div class="toolbar">
  <span class="titolo"><?= h($titolo) ?><?= ($tipo === 'iscritti' && count($lista) > 1) ? ' — ' . count($lista) . ' pagine' : '' ?></span>
  <button class="primary" onclick="window.print()">Stampa / Salva in PDF</button>
  <a href="<?= $tipo === 'calendario' ? 'calendario.php' : 'iscritti.php' ?>">Torna indietro</a>
  <span class="hint">Nel dialogo di stampa scegli <b>Salva come PDF</b> come destinazione.
    <?= $landscape ? 'Imposta orientamento <b>orizzontale</b> se non è già selezionato.' : '' ?></span>
</div>

<?php if (!$lista): ?>
  <div class="foglio"><p>Niente da stampare.</p></div>

<?php elseif ($tipo === 'calendario'):
    $per_giorno = array_fill_keys(array_keys(GIORNI), []);
    foreach ($lista as $s) {
        $per_giorno[(int)$s['giorno']][] = $s;
    }
    $corsi_usati = [];
    foreach ($lista as $s) {
        $corsi_usati[(int)$s['corso_id']] = $cmap[(int)$s['corso_id']];
    }
?>
  <div class="foglio">
    <div class="testata">
      <div>
        <h1><?= h($titolo) ?></h1>
        <div class="sub"><?= count($lista) ?> sottocorsi<?= $archiviati ? ' (archiviati inclusi)' : '' ?></div>
      </div>
      <div class="dx"><b><?= h(APP_NAME) ?></b><?= CONTATTI !== '' ? '<br>' . h(CONTATTI) : '' ?><br><?= h($stampato) ?></div>
    </div>

    <?php if (count($corsi_usati) > 1): ?>
    <div class="legenda">
      <?php foreach ($corsi_usati as $c): ?>
        <span><i style="background:<?= h($c['colore']) ?>"></i><?= h($c['nome']) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="settimana">
      <?php foreach (GIORNI as $n => $g): ?>
        <div class="giorno">
          <h2><?= h($g) ?></h2>
          <?php if (!$per_giorno[$n]): ?><div class="m">—</div><?php endif; ?>
          <?php foreach ($per_giorno[$n] as $s):
              $c   = $cmap[(int)$s['corso_id']];
              $n_i = conta_iscritti($data, (int)$s['id']); ?>
            <div class="lezione" style="border-left-color:<?= h($c['colore']) ?>">
              <div class="o"><?= h($s['ora_inizio']) ?><?= !empty($s['ora_fine']) ? '–' . h($s['ora_fine']) : '' ?></div>
              <div class="n"><?= h($s['nome']) ?></div>
              <div class="m"><?= h($c['nome']) ?><?php if (!empty($umap[(int)$s['istruttore_id']])): ?> · <?= h($umap[(int)$s['istruttore_id']]['nome']) ?><?php endif; ?>
                · <?= $n_i ?>/<?= (int)$s['capienza'] ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="chiusura"><span><?= h(APP_NAME) ?></span><span><?= h($stampato) ?></span></div>
  </div>

<?php else:
    foreach ($lista as $s):
        $c         = $cmap[(int)$s['corso_id']];
        $cap       = (int)$s['capienza'];
        $per_posto = iscritti_per_posto($data, (int)$s['id']);
        $n_i       = count($per_posto);
?>
  <div class="foglio">
    <div class="testata">
      <div>
        <h1><?= h($c['nome']) ?> · <?= h($s['nome']) ?></h1>
        <div class="sub"><?= h(orario_label($s)) ?>
          · Istruttore: <?= h($umap[(int)$s['istruttore_id']]['nome'] ?? '—') ?>
          · <?= $n_i ?>/<?= $cap ?> posti occupati
          <?= empty($s['attivo']) ? ' · ARCHIVIATO' : '' ?>
          <?= !empty($s['note']) ? ' · ' . h($s['note']) : '' ?></div>
      </div>
      <div class="dx"><b><?= h(APP_NAME) ?></b><?= CONTATTI !== '' ? '<br>' . h(CONTATTI) : '' ?><br><?= h($stampato) ?></div>
    </div>

    <table>
      <thead>
        <tr><th class="num">#</th><th>Cognome</th><th>Nome</th><th class="col-tel">Telefono</th><th class="col-cert">Scad. certificato</th></tr>
      </thead>
      <tbody>
      <?php for ($i = 0; $i < $cap; $i++):
          $is = $per_posto[$i] ?? null;
          $p  = $is ? ($pmap[(int)$is['persona_id']] ?? null) : null;
          [$st] = cert_stato($p['cert'] ?? '');
      ?>
        <tr<?= $p ? '' : ' class="libera"' ?>>
          <td class="num"><?= $i + 1 ?></td>
          <td><?= h($p['cognome'] ?? '') ?></td>
          <td><?= h($p['nome'] ?? '') ?></td>
          <td><?= h($p['telefono'] ?? '') ?></td>
          <td class="<?= $p && $st !== 'ok' ? 'scaduto' : '' ?>">
            <?php if ($p && !empty($p['cert'])): ?>
              <?= h(data_it($p['cert'])) ?><?= $st === 'scaduto' ? ' (scaduto)' : ($st === 'scadenza' ? ' (in scadenza)' : '') ?>
            <?php elseif ($p): ?>
              da consegnare
            <?php endif; ?>
          </td>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
    <div class="chiusura">
      <span><?= h(APP_NAME) ?> · <?= h($c['nome']) ?> · <?= h($s['nome']) ?></span>
      <span><?= h($stampato) ?></span>
    </div>
  </div>
<?php endforeach; endif; ?>

<?php if (get('anteprima') !== '1'): ?>
<script>
  // apre subito il dialogo di stampa; con ?anteprima=1 si guarda il foglio senza dialogo
  window.addEventListener('load', function () { setTimeout(window.print, 250); });
</script>
<?php endif; ?>
</body>
</html>
