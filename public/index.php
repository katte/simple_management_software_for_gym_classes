<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/layout.php';

$me   = require_login();
$data = store_read();
$cmap = map_by_id($data['corsi']);
$umap = map_by_id($data['users']);
$pmap = map_by_id($data['persone']);

/* sottocorsi attivi = sottocorso attivo dentro un corso attivo */
$attivi = array_values(array_filter($data['sottocorsi'], static function ($s) use ($cmap) {
    $c = $cmap[(int)$s['corso_id']] ?? null;
    return !empty($s['attivo']) && $c && !empty($c['attivo']);
}));
sort_sottocorsi($attivi);
$attivi_ids = array_map(static function ($s) { return (int)$s['id']; }, $attivi);

$posti_tot = 0;
foreach ($attivi as $s) {
    $posti_tot += (int)$s['capienza'];
}

$occupati   = 0;
$persone_id = [];
foreach ($data['iscrizioni'] as $is) {
    if (in_array((int)$is['sottocorso_id'], $attivi_ids, true)) {
        $occupati++;
        $persone_id[(int)$is['persona_id']] = true;
    }
}
$liberi = max(0, $posti_tot - $occupati);
$corsi_attivi = count(array_filter($data['corsi'], static function ($c) { return !empty($c['attivo']); }));

/* certificati da controllare, solo per chi frequenta un corso attivo */
$alert = [];
foreach (array_keys($persone_id) as $pid) {
    $p = $pmap[$pid] ?? null;
    if (!$p) {
        continue;
    }
    [$st, $gg] = cert_stato($p['cert'] ?? '');
    if ($st === 'ok') {
        continue;
    }
    $dove = [];
    foreach ($data['iscrizioni'] as $is) {
        if ((int)$is['persona_id'] === $pid && in_array((int)$is['sottocorso_id'], $attivi_ids, true)) {
            $s = find_by_id($data['sottocorsi'], (int)$is['sottocorso_id']);
            if ($s) {
                $dove[] = ['id' => (int)$s['id'], 'label' => $s['nome']];
            }
        }
    }
    $alert[] = ['p' => $p, 'stato' => $st, 'gg' => $gg, 'dove' => $dove];
}
$ordine = ['scaduto' => 0, 'mancante' => 1, 'scadenza' => 2];
usort($alert, static function ($a, $b) use ($ordine) {
    return [$ordine[$a['stato']], (int)$a['gg']] <=> [$ordine[$b['stato']], (int)$b['gg']];
});

$n_scaduti  = count(array_filter($alert, static function ($a) { return $a['stato'] === 'scaduto'; }));
$n_scadenza = count(array_filter($alert, static function ($a) { return $a['stato'] === 'scadenza'; }));
$n_mancanti = count(array_filter($alert, static function ($a) { return $a['stato'] === 'mancante'; }));

/* riempimento per sottocorso */
$riemp = [];
foreach ($attivi as $s) {
    $n   = conta_iscritti($data, (int)$s['id']);
    $cap = max(1, (int)$s['capienza']);
    $riemp[] = ['sc' => $s, 'n' => $n, 'cap' => $cap, 'perc' => (int)round($n * 100 / $cap)];
}
usort($riemp, static function ($a, $b) { return $b['perc'] <=> $a['perc']; });

$oggi_n  = (int)date('N');
$di_oggi = array_values(array_filter($attivi, static function ($s) use ($oggi_n) { return (int)$s['giorno'] === $oggi_n; }));

layout_header('Dashboard', 'index.php');
?>
<div class="page-head">
  <h1>Dashboard</h1>
  <span class="muted"><?= h(GIORNI[$oggi_n]) ?> <?= h(date('d/m/Y')) ?></span>
</div>

<div class="kpis">
  <div class="kpi"><span class="k"><?= $corsi_attivi ?></span><span class="l">Corsi attivi</span></div>
  <div class="kpi"><span class="k"><?= count($attivi) ?></span><span class="l">Sottocorsi attivi</span></div>
  <div class="kpi"><span class="k"><?= count($persone_id) ?></span><span class="l">Persone iscritte</span></div>
  <div class="kpi"><span class="k"><?= $occupati ?><small>/<?= $posti_tot ?></small></span><span class="l">Posti occupati</span></div>
  <div class="kpi good"><span class="k"><?= $liberi ?></span><span class="l">Posti liberi</span></div>
  <div class="kpi <?= $n_scaduti + $n_mancanti > 0 ? 'bad' : ($n_scadenza > 0 ? 'warn' : '') ?>">
    <span class="k"><?= $n_scaduti + $n_scadenza + $n_mancanti ?></span><span class="l">Certificati da sistemare</span></div>
</div>

<div class="cols">
  <section class="card">
    <h2>Certificati medici</h2>
    <?php if (!$alert): ?>
      <p class="muted">Tutto in regola.</p>
    <?php else: ?>
      <p class="muted small"><?= $n_scaduti ?> scaduti · <?= $n_mancanti ?> mancanti · <?= $n_scadenza ?> in scadenza entro <?= CERT_WARN_DAYS ?> giorni</p>
      <div class="table-wrap">
      <table class="grid">
        <thead><tr><th>Persona</th><th>Telefono</th><th>Scadenza</th><th>Corsi</th></tr></thead>
        <tbody>
        <?php foreach ($alert as $a): ?>
          <tr>
            <td><a href="persone.php?p=<?= (int)$a['p']['id'] ?>"><b><?= h(nome_completo($a['p'])) ?></b></a><br><span class="badge <?= h($a['stato']) ?>"><?= h(CERT_LABEL[$a['stato']]) ?></span></td>
            <td><?= h($a['p']['telefono'] ?? '') ?></td>
            <td><?= $a['p']['cert'] ? h(data_it($a['p']['cert'])) : '—' ?>
                <?php if ($a['stato'] === 'scadenza'): ?><div class="muted small">tra <?= (int)$a['gg'] ?> gg</div><?php endif; ?>
                <?php if ($a['stato'] === 'scaduto'): ?><div class="muted small">da <?= abs((int)$a['gg']) ?> gg</div><?php endif; ?></td>
            <td><?php foreach ($a['dove'] as $d): ?><a class="tag link" href="iscritti.php?sc=<?= $d['id'] ?>"><?= h($d['label']) ?></a> <?php endforeach; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Oggi · <?= h(GIORNI[$oggi_n]) ?></h2>
    <?php if (!$di_oggi): ?>
      <p class="muted">Nessuna lezione in programma.</p>
    <?php else: ?>
      <ul class="today-list">
      <?php foreach ($di_oggi as $s):
          $c   = $cmap[(int)$s['corso_id']];
          $n   = conta_iscritti($data, (int)$s['id']);
          $cap = (int)$s['capienza']; ?>
        <li>
          <span class="chip" style="background:<?= h($c['colore']) ?>"></span>
          <span class="ora"><?= h($s['ora_inizio']) ?></span>
          <a href="iscritti.php?sc=<?= (int)$s['id'] ?>"><?= h($s['nome']) ?></a>
          <span class="muted small"><?= h($umap[(int)$s['istruttore_id']]['nome'] ?? '—') ?></span>
          <span class="pill <?= $n >= $cap ? 'full' : '' ?>"><?= $n ?>/<?= $cap ?></span>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h2>Riempimento</h2>
    <?php if (!$riemp): ?>
      <p class="muted">Nessun sottocorso attivo.</p>
    <?php else: ?>
      <ul class="bars">
      <?php foreach ($riemp as $r):
          $c = $cmap[(int)$r['sc']['corso_id']]; ?>
        <li>
          <div class="bar-head">
            <a href="iscritti.php?sc=<?= (int)$r['sc']['id'] ?>"><?= h($r['sc']['nome']) ?></a>
            <span class="muted small"><?= h(orario_label($r['sc'])) ?></span>
            <b><?= $r['n'] ?>/<?= $r['cap'] ?></b>
          </div>
          <div class="bar"><span style="width:<?= min(100, $r['perc']) ?>%;background:<?= h($c['colore']) ?>"></span></div>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
<?php layout_footer(); ?>
