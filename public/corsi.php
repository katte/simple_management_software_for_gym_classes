<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/layout.php';

$me = require_login();

/* ---------- azioni (solo admin) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    csrf_check();
    $azione = post('azione');

    if ($azione === 'corso_save') {
        $id     = (int)post('id', '0');
        $nome   = post('nome');
        $colore = array_key_exists(post('colore'), COLORI) ? post('colore') : array_key_first(COLORI);
        $attivo = post('attivo') === '1';
        if ($nome === '') {
            flash('Il nome del corso è obbligatorio.', 'err');
            redirect('corsi.php');
        }
        store_update(function (array &$data) use ($id, $nome, $colore, $attivo) {
            $i = $id > 0 ? index_by_id($data['corsi'], $id) : null;
            if ($i !== null) {
                $data['corsi'][$i]['nome']   = $nome;
                $data['corsi'][$i]['colore'] = $colore;
                $data['corsi'][$i]['attivo'] = $attivo;
            } else {
                $data['corsi'][] = [
                    'id'     => next_id($data, 'corsi'),
                    'nome'   => $nome,
                    'colore' => $colore,
                    'attivo' => $attivo,
                ];
            }
        });
        flash($id > 0 ? 'Corso aggiornato.' : 'Corso creato.');
        redirect('corsi.php');
    }

    if ($azione === 'corso_delete') {
        $id  = (int)post('id', '0');
        $msg = store_update(function (array &$data) use ($id) {
            foreach ($data['sottocorsi'] as $sc) {
                if ((int)$sc['corso_id'] === $id) {
                    return ['Elimina prima tutti i sottocorsi di questo corso.', 'err'];
                }
            }
            $i = index_by_id($data['corsi'], $id);
            if ($i !== null) {
                array_splice($data['corsi'], $i, 1);
            }
            return ['Corso eliminato.', 'ok'];
        });
        flash($msg[0], $msg[1]);
        redirect('corsi.php');
    }

    if ($azione === 'sc_save') {
        $id       = (int)post('id', '0');
        $corso_id = (int)post('corso_id', '0');
        $nome     = post('nome');
        $giorno   = (int)post('giorno', '1');
        $inizio   = norm_ora(post('ora_inizio'));
        $fine     = norm_ora(post('ora_fine'));
        $istr     = (int)post('istruttore_id', '0');
        $cap      = max(1, min(200, (int)post('capienza', '10')));
        $attivo   = post('attivo') === '1';
        $note     = post('note');

        $err = '';
        if ($nome === '') {
            $err = 'Il nome del sottocorso è obbligatorio.';
        } elseif (!isset(GIORNI[$giorno])) {
            $err = 'Giorno non valido.';
        } elseif ($inizio === '') {
            $err = 'Orario di inizio non valido.';
        } elseif ($fine !== '' && $fine <= $inizio) {
            $err = 'L\'orario di fine deve essere successivo a quello di inizio.';
        }
        if ($err !== '') {
            flash($err, 'err');
            redirect('corsi.php?sc=' . $id);
        }

        $msg = store_update(function (array &$data) use ($id, $corso_id, $nome, $giorno, $inizio, $fine, $istr, $cap, $attivo, $note) {
            if (!find_by_id($data['corsi'], $corso_id)) {
                return ['Corso non trovato.', 'err'];
            }
            if ($id > 0) {
                $minimo = 0;
                foreach ($data['iscrizioni'] as $is) {
                    if ((int)$is['sottocorso_id'] === $id) {
                        $minimo = max($minimo, (int)$is['posto'] + 1);
                    }
                }
                if ($cap < $minimo) {
                    return ['Ci sono iscritti fino al posto ' . $minimo . ': non puoi scendere sotto questa capienza.', 'err'];
                }
            }
            $riga = [
                'corso_id'      => $corso_id,
                'nome'          => $nome,
                'giorno'        => $giorno,
                'ora_inizio'    => $inizio,
                'ora_fine'      => $fine,
                'istruttore_id' => $istr,
                'capienza'      => $cap,
                'attivo'        => $attivo,
                'note'          => $note,
            ];
            $i = $id > 0 ? index_by_id($data['sottocorsi'], $id) : null;
            if ($i !== null) {
                $data['sottocorsi'][$i] = array_merge($data['sottocorsi'][$i], $riga);
                return ['Sottocorso aggiornato.', 'ok'];
            }
            $riga['id'] = next_id($data, 'sottocorsi');
            $data['sottocorsi'][] = $riga;
            return ['Sottocorso creato.', 'ok'];
        });
        flash($msg[0], $msg[1]);
        redirect('corsi.php');
    }

    if ($azione === 'sc_delete') {
        $id = (int)post('id', '0');
        store_update(function (array &$data) use ($id) {
            $i = index_by_id($data['sottocorsi'], $id);
            if ($i !== null) {
                array_splice($data['sottocorsi'], $i, 1);
            }
            $data['iscrizioni'] = array_values(array_filter(
                $data['iscrizioni'],
                static function ($is) use ($id) { return (int)$is['sottocorso_id'] !== $id; }
            ));
        });
        flash('Sottocorso e relative iscrizioni eliminati.');
        redirect('corsi.php');
    }
    redirect('corsi.php');
}

/* ---------- lettura ---------- */
$data       = store_read();
$istruttori = array_values(array_filter($data['users'], static function ($u) { return !empty($u['attivo']); }));
$umap       = map_by_id($data['users']);

$edit_corso = null;
if (is_admin() && get('corso') !== '') {
    $edit_corso = get('corso') === 'new'
        ? ['id' => 0, 'nome' => '', 'colore' => array_key_first(COLORI), 'attivo' => true]
        : find_by_id($data['corsi'], (int)get('corso'));
}
$edit_sc = null;
if (is_admin() && get('sc') !== '') {
    $edit_sc = find_by_id($data['sottocorsi'], (int)get('sc'));
}
if (is_admin() && get('nuovo_sc') !== '') {
    $edit_sc = ['id' => 0, 'corso_id' => (int)get('nuovo_sc'), 'nome' => '', 'giorno' => 1,
                'ora_inizio' => '', 'ora_fine' => '', 'istruttore_id' => 0, 'capienza' => 10,
                'attivo' => true, 'note' => ''];
}

layout_header('Corsi', 'corsi.php');
?>
<div class="page-head">
  <h1>Corsi e sottocorsi</h1>
  <?php if (is_admin()): ?><a class="btn primary" href="corsi.php?corso=new">+ Nuovo corso</a><?php endif; ?>
</div>
<?php if (!is_admin()): ?>
  <p class="muted">Sola lettura: la struttura dei corsi la modifica un amministratore.</p>
<?php endif; ?>

<?php if ($edit_corso): ?>
<form class="card form-inline" method="post">
  <h2><?= $edit_corso['id'] ? 'Modifica corso' : 'Nuovo corso' ?></h2>
  <?= csrf_field() ?>
  <input type="hidden" name="azione" value="corso_save">
  <input type="hidden" name="id" value="<?= (int)$edit_corso['id'] ?>">
  <label>Nome<input name="nome" value="<?= h($edit_corso['nome']) ?>" required autofocus placeholder="Pole Dance"></label>
  <label>Colore
    <select name="colore">
      <?php foreach (COLORI as $hex => $nomec): ?>
        <option value="<?= h($hex) ?>"<?= $edit_corso['colore'] === $hex ? ' selected' : '' ?>><?= h($nomec) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="check"><input type="checkbox" name="attivo" value="1"<?= !empty($edit_corso['attivo']) ? ' checked' : '' ?>> Attivo</label>
  <div class="actions"><button class="btn primary">Salva</button><a class="btn" href="corsi.php">Annulla</a></div>
</form>
<?php endif; ?>

<?php if ($edit_sc): ?>
<form class="card form-inline" method="post">
  <h2><?= $edit_sc['id'] ? 'Modifica sottocorso' : 'Nuovo sottocorso' ?></h2>
  <?= csrf_field() ?>
  <input type="hidden" name="azione" value="sc_save">
  <input type="hidden" name="id" value="<?= (int)$edit_sc['id'] ?>">
  <label>Corso
    <select name="corso_id" required>
      <?php foreach ($data['corsi'] as $c): ?>
        <option value="<?= (int)$c['id'] ?>"<?= (int)$edit_sc['corso_id'] === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['nome']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Nome<input name="nome" value="<?= h($edit_sc['nome']) ?>" required placeholder="Intermedio teenagers"></label>
  <label>Giorno
    <select name="giorno">
      <?php foreach (GIORNI as $n => $g): ?>
        <option value="<?= $n ?>"<?= (int)$edit_sc['giorno'] === $n ? ' selected' : '' ?>><?= h($g) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Dalle<input type="time" name="ora_inizio" value="<?= h($edit_sc['ora_inizio']) ?>" required></label>
  <label>Alle<input type="time" name="ora_fine" value="<?= h($edit_sc['ora_fine']) ?>"></label>
  <label>Istruttore
    <select name="istruttore_id">
      <option value="0">— nessuno —</option>
      <?php foreach ($istruttori as $u): ?>
        <option value="<?= (int)$u['id'] ?>"<?= (int)$edit_sc['istruttore_id'] === (int)$u['id'] ? ' selected' : '' ?>><?= h($u['nome']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Posti<input type="number" name="capienza" min="1" max="200" value="<?= (int)$edit_sc['capienza'] ?>" required></label>
  <label class="check"><input type="checkbox" name="attivo" value="1"<?= !empty($edit_sc['attivo']) ? ' checked' : '' ?>> Attivo</label>
  <label class="wide">Note<input name="note" value="<?= h($edit_sc['note'] ?? '') ?>" placeholder="facoltative"></label>
  <div class="actions"><button class="btn primary">Salva</button><a class="btn" href="corsi.php">Annulla</a></div>
</form>
<?php endif; ?>

<?php if (!$data['corsi']): ?>
  <div class="card empty">Nessun corso. <?= is_admin() ? 'Comincia creandone uno.' : '' ?></div>
<?php endif; ?>

<?php foreach ($data['corsi'] as $c):
    $scs = array_values(array_filter($data['sottocorsi'], static function ($s) use ($c) { return (int)$s['corso_id'] === (int)$c['id']; }));
    sort_sottocorsi($scs);
?>
<section class="card corso">
  <div class="corso-head">
    <h2><span class="chip" style="background:<?= h($c['colore']) ?>"></span><?= h($c['nome']) ?>
      <?= empty($c['attivo']) ? '<b class="tag off">archiviato</b>' : '' ?></h2>
    <div class="row-actions">
      <?php if (is_admin()): ?>
        <a class="btn sm" href="corsi.php?corso=<?= (int)$c['id'] ?>">Modifica</a>
        <a class="btn sm" href="corsi.php?nuovo_sc=<?= (int)$c['id'] ?>">+ Sottocorso</a>
        <form method="post" onsubmit="return confirm('Eliminare il corso <?= h($c['nome']) ?>?')">
          <?= csrf_field() ?>
          <input type="hidden" name="azione" value="corso_delete">
          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button class="btn sm danger">Elimina</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if (!$scs): ?>
    <p class="muted">Nessun sottocorso.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="grid">
    <thead><tr><th>Quando</th><th>Sottocorso</th><th>Istruttore</th><th>Posti</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($scs as $sc):
        $n   = conta_iscritti($data, (int)$sc['id']);
        $cap = (int)$sc['capienza'];
    ?>
      <tr<?= empty($sc['attivo']) ? ' class="dim"' : '' ?>>
        <td><?= h(orario_label($sc)) ?></td>
        <td><b><?= h($sc['nome']) ?></b><?= empty($sc['attivo']) ? ' <b class="tag off">archiviato</b>' : '' ?>
            <?= !empty($sc['note']) ? '<div class="muted small">' . h($sc['note']) . '</div>' : '' ?></td>
        <td><?= h($umap[(int)$sc['istruttore_id']]['nome'] ?? '—') ?></td>
        <td><span class="pill <?= $n >= $cap ? 'full' : ($n === 0 ? 'zero' : '') ?>"><?= $n ?>/<?= $cap ?></span></td>
        <td class="row-actions">
          <a class="btn sm" href="iscritti.php?sc=<?= (int)$sc['id'] ?>">Iscritti</a>
          <?php if (is_admin()): ?>
            <a class="btn sm" href="corsi.php?sc=<?= (int)$sc['id'] ?>">Modifica</a>
            <form method="post" onsubmit="return confirm('Eliminare <?= h($sc['nome']) ?> e le sue <?= $n ?> iscrizioni?')">
              <?= csrf_field() ?>
              <input type="hidden" name="azione" value="sc_delete">
              <input type="hidden" name="id" value="<?= (int)$sc['id'] ?>">
              <button class="btn sm danger">Elimina</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<?php layout_footer(); ?>
