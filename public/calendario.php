<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/layout.php';

require_login();

$data = store_read();
$cmap = map_by_id($data['corsi']);
$umap = map_by_id($data['users']);

$filtro    = (int)get('corso', '0');
$archiviati = get('arch') === '1';

$scs = array_values(array_filter($data['sottocorsi'], static function ($s) use ($filtro, $archiviati, $cmap) {
    if ($filtro > 0 && (int)$s['corso_id'] !== $filtro) {
        return false;
    }
    if (!$archiviati) {
        $c = $cmap[(int)$s['corso_id']] ?? null;
        if (empty($s['attivo']) || ($c && empty($c['attivo']))) {
            return false;
        }
    }
    return true;
}));
sort_sottocorsi($scs);

$per_giorno = array_fill_keys(array_keys(GIORNI), []);
foreach ($scs as $s) {
    $per_giorno[(int)$s['giorno']][] = $s;
}
$oggi = (int)date('N');

layout_header('Calendario', 'calendario.php');
?>
<div class="page-head">
  <h1>Settimana tipo</h1>
  <form method="get" class="picker">
    <select name="corso" onchange="this.form.submit()">
      <option value="0">Tutti i corsi</option>
      <?php foreach ($data['corsi'] as $c): ?>
        <option value="<?= (int)$c['id'] ?>"<?= $filtro === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['nome']) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="check"><input type="checkbox" name="arch" value="1"<?= $archiviati ? ' checked' : '' ?> onchange="this.form.submit()"> Mostra archiviati</label>
    <a class="btn" target="_blank" rel="noopener"
       href="stampa.php?tipo=calendario&amp;corso=<?= $filtro ?>&amp;arch=<?= $archiviati ? '1' : '0' ?>">Stampa PDF</a>
  </form>
</div>

<div class="week">
  <?php foreach (GIORNI as $n => $g):
      $lista = $per_giorno[$n]; ?>
    <div class="day<?= $n === $oggi ? ' today' : '' ?>">
      <h3><?= h($g) ?><?php if ($lista): ?> <span class="muted small"><?= count($lista) ?></span><?php endif; ?></h3>
      <?php if (!$lista): ?>
        <p class="muted small">—</p>
      <?php endif; ?>
      <?php foreach ($lista as $s):
          $c   = $cmap[(int)$s['corso_id']] ?? ['nome' => '?', 'colore' => '#64748b'];
          $n_i = conta_iscritti($data, (int)$s['id']);
          $cap = (int)$s['capienza'];
      ?>
        <a class="ev<?= empty($s['attivo']) ? ' dim' : '' ?>" href="iscritti.php?sc=<?= (int)$s['id'] ?>"
           style="--c:<?= h($c['colore']) ?>">
          <span class="ev-ora"><?= h($s['ora_inizio']) ?><?= !empty($s['ora_fine']) ? '–' . h($s['ora_fine']) : '' ?></span>
          <span class="ev-nome"><?= h($s['nome']) ?></span>
          <span class="ev-meta"><?= h($c['nome']) ?><?php if (!empty($umap[(int)$s['istruttore_id']])): ?> · <?= h($umap[(int)$s['istruttore_id']]['nome']) ?><?php endif; ?></span>
          <span class="ev-posti <?= $n_i >= $cap ? 'full' : '' ?>"><?= $n_i ?>/<?= $cap ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php layout_footer(); ?>
