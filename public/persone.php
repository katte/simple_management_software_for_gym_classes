<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/layout.php';

$me = require_login();

/* ---------- azioni ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $azione = post('azione');
    $pid    = (int)post('persona_id', '0');

    $data = store_read();

    /* nuova scheda slegata dai corsi: l'iscrizione a un sottocorso si fa dopo, quando serve */
    if ($azione === 'crea') {
        $nome     = post('nome');
        $cognome  = post('cognome');
        $tel      = post('telefono');
        $cert     = norm_data(post('cert'));
        $note     = post('note');
        $conferma = post('conferma') === '1';

        $_SESSION['bozza'] = ['nome' => $nome, 'cognome' => $cognome, 'telefono' => $tel, 'cert' => $cert, 'note' => $note];

        if ($nome === '' || $cognome === '') {
            flash('Nome e cognome sono obbligatori.', 'err');
            redirect('persone.php?p=new');
        }
        if (!$conferma) {
            foreach ($data['persone'] as $p) {
                if (stessa_stringa($p['nome'], $nome) && stessa_stringa($p['cognome'], $cognome)) {
                    $_SESSION['bozza']['omonimo'] = (int)$p['id'];
                    flash('In anagrafica c\'è già ' . nome_completo($p)
                        . (!empty($p['telefono']) ? ' (tel. ' . $p['telefono'] . ')' : ' senza telefono')
                        . '. Apri la sua scheda, oppure conferma qui sotto che è un\'altra persona.', 'warn');
                    redirect('persone.php?p=new');
                }
            }
        }
        unset($_SESSION['bozza']);
        $nuovo_id = store_update(function (array &$data) use ($nome, $cognome, $tel, $cert, $note) {
            $id = next_id($data, 'persone');
            $data['persone'][] = [
                'id'       => $id,
                'nome'     => $nome,
                'cognome'  => $cognome,
                'telefono' => $tel,
                'cert'     => $cert,
                'note'     => $note,
                'creato'   => date('Y-m-d H:i'),
            ];
            return $id;
        });
        flash('Scheda di ' . trim($cognome . ' ' . $nome) . ' creata. Iscrivila a un corso quando serve.');
        redirect('persone.php?p=' . $nuovo_id);
    }

    if (!find_by_id($data['persone'], $pid)) {
        flash('Scheda non trovata.', 'err');
        redirect('persone.php');
    }
    // iscrivere e disiscrivere dipende dal sottocorso, non dalla scheda: si controlla piu' sotto
    if (in_array($azione, ['save', 'delete'], true) && !puo_modificare_persona($data, $pid)) {
        flash('Puoi modificare solo le schede delle persone che frequentano i tuoi sottocorsi.', 'err');
        redirect('persone.php?p=' . $pid);
    }

    if ($azione === 'save') {
        $nome    = post('nome');
        $cognome = post('cognome');
        $tel     = post('telefono');
        $cert    = norm_data(post('cert'));
        $note    = post('note');
        if ($nome === '' || $cognome === '') {
            flash('Nome e cognome sono obbligatori.', 'err');
            redirect('persone.php?p=' . $pid);
        }
        $msg = store_update(function (array &$data) use ($pid, $nome, $cognome, $tel, $cert, $note) {
            $i = index_by_id($data['persone'], $pid);
            if ($i === null) {
                return ['Scheda non trovata.', 'err'];
            }
            $prima    = nome_completo($data['persone'][$i]);
            $rinomina = !stessa_stringa($data['persone'][$i]['nome'], $nome)
                     || !stessa_stringa($data['persone'][$i]['cognome'], $cognome);

            $data['persone'][$i]['nome']     = $nome;
            $data['persone'][$i]['cognome']  = $cognome;
            $data['persone'][$i]['telefono'] = $tel;
            $data['persone'][$i]['cert']     = $cert;
            $data['persone'][$i]['note']     = $note;

            if ($rinomina) {
                $n = count(corsi_di_persona($data, $pid));
                return ['Scheda aggiornata: ' . $prima . ' ora è ' . trim($cognome . ' ' . $nome)
                        . ($n > 1 ? ", aggiornata in tutti i $n corsi." : '.'), 'ok'];
            }
            return ['Scheda aggiornata.', 'ok'];
        });
        flash($msg[0], $msg[1]);
        redirect('persone.php?p=' . $pid);
    }

    if ($azione === 'disiscrivi') {
        $iid = (int)post('iscrizione_id', '0');
        $is  = find_by_id($data['iscrizioni'], $iid);
        $sc  = $is ? find_by_id($data['sottocorsi'], (int)$is['sottocorso_id']) : null;
        if (!$sc || !puo_modificare($sc)) {
            flash('Non puoi togliere iscrizioni da questo sottocorso.', 'err');
            redirect('persone.php?p=' . $pid);
        }
        store_update(function (array &$data) use ($iid) {
            $i = index_by_id($data['iscrizioni'], $iid);
            if ($i !== null) {
                array_splice($data['iscrizioni'], $i, 1);
            }
        });
        flash('Iscrizione rimossa. La scheda anagrafica resta.');
        redirect('persone.php?p=' . $pid);
    }

    if ($azione === 'iscrivi') {
        $sc_id = (int)post('sottocorso_id', '0');
        $sc    = find_by_id($data['sottocorsi'], $sc_id);
        if (!$sc || !puo_modificare($sc)) {
            flash('Non puoi iscrivere in questo sottocorso.', 'err');
            redirect('persone.php?p=' . $pid);
        }
        $msg = store_update(function (array &$data) use ($pid, $sc_id) {
            $sc  = find_by_id($data['sottocorsi'], $sc_id);
            $cap = (int)$sc['capienza'];
            $presi = [];
            foreach ($data['iscrizioni'] as $is) {
                if ((int)$is['sottocorso_id'] === $sc_id) {
                    if ((int)$is['persona_id'] === $pid) {
                        return ['Questa persona è già iscritta a ' . $sc['nome'] . '.', 'warn'];
                    }
                    $presi[(int)$is['posto']] = true;
                }
            }
            for ($p = 0; $p < $cap; $p++) {
                if (!isset($presi[$p])) {
                    $data['iscrizioni'][] = [
                        'id'            => next_id($data, 'iscrizioni'),
                        'sottocorso_id' => $sc_id,
                        'persona_id'    => $pid,
                        'posto'         => $p,
                        'iscritto_il'   => date('Y-m-d'),
                    ];
                    return ['Iscritta a ' . $sc['nome'] . ', posto ' . ($p + 1) . '.', 'ok'];
                }
            }
            return [$sc['nome'] . ' è al completo: alza i posti nel sottocorso.', 'err'];
        });
        flash($msg[0], $msg[1]);
        redirect('persone.php?p=' . $pid);
    }

    if ($azione === 'delete') {
        require_admin();
        $msg = store_update(function (array &$data) use ($pid) {
            if (corsi_di_persona($data, $pid)) {
                return ['Persona ancora iscritta a dei corsi: toglila prima da tutti.', 'err'];
            }
            $i = index_by_id($data['persone'], $pid);
            if ($i !== null) {
                array_splice($data['persone'], $i, 1);
            }
            return ['Scheda eliminata.', 'ok'];
        });
        flash($msg[0], $msg[1]);
        redirect($msg[1] === 'ok' ? 'persone.php' : 'persone.php?p=' . $pid);
    }
    redirect('persone.php');
}

/* ---------- lettura ---------- */
$data = store_read();
$cmap = map_by_id($data['corsi']);
$umap = map_by_id($data['users']);

$nuova_scheda = get('p') === 'new';
$persona      = (!$nuova_scheda && get('p') !== '') ? find_by_id($data['persone'], (int)get('p')) : null;

layout_header('Anagrafica', 'persone.php');

/* ============ NUOVA SCHEDA ============ */
if ($nuova_scheda):
    $b = $_SESSION['bozza'] ?? [];
    unset($_SESSION['bozza']);
    $omonimo = !empty($b['omonimo']) ? find_by_id($data['persone'], (int)$b['omonimo']) : null;
?>
<div class="page-head">
  <h1>Nuova scheda</h1>
  <a class="btn" href="persone.php">← Tutte le schede</a>
</div>
<form class="card form-inline" method="post">
  <h2>Dati della persona</h2>
  <p class="muted small wide">La scheda vive da sola: l'iscrizione a un corso si aggiunge quando serve, da qui o dalla pagina Iscritti.</p>
  <?= csrf_field() ?>
  <input type="hidden" name="azione" value="crea">
  <label>Cognome<input name="cognome" value="<?= h($b['cognome'] ?? '') ?>" required autofocus></label>
  <label>Nome<input name="nome" value="<?= h($b['nome'] ?? '') ?>" required></label>
  <label>Telefono<input name="telefono" value="<?= h($b['telefono'] ?? '') ?>" inputmode="tel"></label>
  <label>Scadenza certificato<input type="date" name="cert" value="<?= h($b['cert'] ?? '') ?>"></label>
  <label class="wide">Note<input name="note" value="<?= h($b['note'] ?? '') ?>"></label>
  <?php if ($omonimo): ?>
    <div class="wide">
      <label class="check"><input type="checkbox" name="conferma" value="1">
        È un'altra persona: crea comunque una scheda separata</label>
      <p class="muted small">Scheda esistente: <a href="persone.php?p=<?= (int)$omonimo['id'] ?>"><?= h(nome_completo($omonimo)) ?></a>
        <?= !empty($omonimo['telefono']) ? '· ' . h($omonimo['telefono']) : '' ?></p>
    </div>
  <?php endif; ?>
  <div class="actions"><button class="btn primary">Crea scheda</button><a class="btn" href="persone.php">Annulla</a></div>
</form>

<?php
/* ============ SCHEDA SINGOLA ============ */
elseif ($persona):
    $pid       = (int)$persona['id'];
    $iscr      = corsi_di_persona($data, $pid);
    $editabile = puo_modificare_persona($data, $pid);
    [$st, $gg] = cert_stato($persona['cert'] ?? '');

    // sottocorsi in cui posso ancora iscriverla
    $disponibili = [];
    foreach ($data['sottocorsi'] as $sc) {
        if (!puo_modificare($sc) || empty($sc['attivo'])) {
            continue;
        }
        $gia = false;
        foreach ($iscr as $v) {
            if ((int)$v['sottocorso']['id'] === (int)$sc['id']) {
                $gia = true;
                break;
            }
        }
        if ($gia || conta_iscritti($data, (int)$sc['id']) >= (int)$sc['capienza']) {
            continue;
        }
        $disponibili[] = $sc;
    }
    sort_sottocorsi($disponibili);
?>
<div class="page-head">
  <h1><?= h(nome_completo($persona)) ?></h1>
  <a class="btn" href="persone.php">← Tutte le schede</a>
</div>

<div class="cols">
  <section class="card">
    <h2>Dati</h2>
    <?php if (!$editabile): ?>
      <div class="flash warn">Sola lettura: questa persona non frequenta nessuno dei tuoi sottocorsi.</div>
    <?php endif; ?>
    <form class="form-inline" method="post" id="form-persona" data-iscrizioni="<?= count($iscr) ?>"
          data-corsi="<?= h(implode(', ', array_map(static function ($v) { return label_corso($v['corso'], $v['sottocorso']); }, $iscr))) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="azione" value="save">
      <input type="hidden" name="persona_id" value="<?= $pid ?>">
      <label>Cognome<input name="cognome" id="f-cognome" value="<?= h($persona['cognome']) ?>" required<?= $editabile ? '' : ' readonly' ?>></label>
      <label>Nome<input name="nome" id="f-nome" value="<?= h($persona['nome']) ?>" required<?= $editabile ? '' : ' readonly' ?>></label>
      <label>Telefono<input name="telefono" value="<?= h($persona['telefono'] ?? '') ?>" inputmode="tel"<?= $editabile ? '' : ' readonly' ?>></label>
      <label>Scadenza certificato<input type="date" name="cert" value="<?= h($persona['cert'] ?? '') ?>"<?= $editabile ? '' : ' readonly' ?>></label>
      <label class="wide">Note<input name="note" value="<?= h($persona['note'] ?? '') ?>"<?= $editabile ? '' : ' readonly' ?>></label>
      <div class="wide">
        <span class="badge <?= h($st) ?>"><?= h(CERT_LABEL[$st]) ?></span>
        <?php if ($st === 'scadenza'): ?><span class="muted small">tra <?= (int)$gg ?> giorni</span><?php endif; ?>
        <?php if ($st === 'scaduto'): ?><span class="muted small">da <?= abs((int)$gg) ?> giorni</span><?php endif; ?>
        <?php if (!empty($persona['creato'])): ?><span class="muted small">· scheda creata il <?= h(explode(' ', $persona['creato'])[0]) ?></span><?php endif; ?>
      </div>
      <?php if ($editabile): ?>
        <div class="actions"><button class="btn primary">Salva scheda</button></div>
      <?php endif; ?>
    </form>
    <?php if (is_admin() && !$iscr): ?>
      <form method="post" class="mt" onsubmit="return confirm('Eliminare definitivamente la scheda di <?= h(nome_completo($persona)) ?>?')">
        <?= csrf_field() ?>
        <input type="hidden" name="azione" value="delete">
        <input type="hidden" name="persona_id" value="<?= $pid ?>">
        <button class="btn sm danger">Elimina scheda</button>
        <span class="muted small">Non è iscritta a nessun corso.</span>
      </form>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Corsi frequentati <span class="pill"><?= count($iscr) ?></span></h2>
    <?php if (!$iscr): ?>
      <p class="muted">Non è iscritta a nessun corso.</p>
    <?php else: ?>
      <ul class="corsi-persona">
      <?php foreach ($iscr as $v):
          $sc = $v['sottocorso'];
          $c  = $v['corso'];
          $spento = empty($sc['attivo']) || empty($c['attivo']); ?>
        <li<?= $spento ? ' class="dim"' : '' ?>>
          <span class="chip" style="background:<?= h($c['colore']) ?>"></span>
          <div class="cp-testo">
            <a href="iscritti.php?sc=<?= (int)$sc['id'] ?>"><b><?= h($c['nome']) ?></b> · <?= h($sc['nome']) ?></a>
            <div class="muted small"><?= h(orario_label($sc)) ?>
              · <?= h($umap[(int)$sc['istruttore_id']]['nome'] ?? 'nessun istruttore') ?>
              · posto <?= (int)$v['iscrizione']['posto'] + 1 ?>
              <?= $spento ? ' · archiviato' : '' ?></div>
          </div>
          <?php if (puo_modificare($sc)): ?>
          <form method="post" onsubmit="return confirm('Togliere <?= h(nome_completo($persona)) ?> da <?= h($sc['nome']) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="azione" value="disiscrivi">
            <input type="hidden" name="persona_id" value="<?= $pid ?>">
            <input type="hidden" name="iscrizione_id" value="<?= (int)$v['iscrizione']['id'] ?>">
            <button class="btn sm danger">Togli</button>
          </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($disponibili): ?>
      <form method="post" class="picker mt">
        <?= csrf_field() ?>
        <input type="hidden" name="azione" value="iscrivi">
        <input type="hidden" name="persona_id" value="<?= $pid ?>">
        <select name="sottocorso_id" required>
          <option value="">Iscrivi a un altro sottocorso…</option>
          <?php foreach ($disponibili as $sc):
              $c = $cmap[(int)$sc['corso_id']] ?? ['nome' => '?'];
              $liberi = (int)$sc['capienza'] - conta_iscritti($data, (int)$sc['id']); ?>
            <option value="<?= (int)$sc['id'] ?>"><?= h($c['nome'] . ' · ' . $sc['nome'] . ' — ' . orario_label($sc)) ?> (<?= $liberi ?> liberi)</option>
          <?php endforeach; ?>
        </select>
        <button class="btn">Iscrivi</button>
      </form>
    <?php endif; ?>
  </section>
</div>

<?php
/* ============ ELENCO ============ */
else:
    $q     = get('q');
    $stato = get('stato');
    $qn    = mb_strtolower($q);

    $righe = [];
    foreach ($data['persone'] as $p) {
        [$st] = cert_stato($p['cert'] ?? '');
        if ($stato !== '' && $stato !== $st) {
            continue;
        }
        if ($qn !== '') {
            $blob = mb_strtolower($p['cognome'] . ' ' . $p['nome'] . ' ' . ($p['telefono'] ?? ''));
            if (mb_strpos($blob, $qn) === false) {
                continue;
            }
        }
        $righe[] = ['p' => $p, 'stato' => $st, 'corsi' => corsi_di_persona($data, (int)$p['id'])];
    }
    usort($righe, static function ($a, $b) {
        return [mb_strtolower($a['p']['cognome']), mb_strtolower($a['p']['nome'])]
           <=> [mb_strtolower($b['p']['cognome']), mb_strtolower($b['p']['nome'])];
    });
?>
<div class="page-head">
  <h1>Anagrafica <span class="muted"><?= count($righe) ?><?= count($righe) !== count($data['persone']) ? ' di ' . count($data['persone']) : '' ?></span></h1>
  <form method="get" class="picker">
    <input name="q" value="<?= h($q) ?>" placeholder="Cerca cognome, nome o telefono" style="min-width:220px">
    <select name="stato" onchange="this.form.submit()">
      <option value="">Tutti i certificati</option>
      <?php foreach (CERT_LABEL as $k => $lab): ?>
        <option value="<?= h($k) ?>"<?= $stato === $k ? ' selected' : '' ?>><?= h($lab) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn">Cerca</button>
    <?php if ($q !== '' || $stato !== ''): ?><a class="btn" href="persone.php">Azzera</a><?php endif; ?>
    <a class="btn primary" href="persone.php?p=new">+ Nuova scheda</a>
  </form>
</div>

<section class="card">
  <?php if (!$righe): ?>
    <p class="muted"><?= $data['persone'] ? 'Nessun risultato.' : 'Nessuna scheda ancora. Creane una qui, oppure compila le tabelle in Iscritti.' ?></p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="grid">
    <thead><tr><th>Persona</th><th>Telefono</th><th>Certificato</th><th>Corsi</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($righe as $r): ?>
      <tr>
        <td><a href="persone.php?p=<?= (int)$r['p']['id'] ?>"><b><?= h(nome_completo($r['p'])) ?></b></a></td>
        <td><?= h($r['p']['telefono'] ?? '') ?></td>
        <td><span class="badge <?= h($r['stato']) ?>"><?= h(CERT_LABEL[$r['stato']]) ?></span>
            <?php if (!empty($r['p']['cert'])): ?><div class="muted small"><?= h(data_it($r['p']['cert'])) ?></div><?php endif; ?></td>
        <td><?php if (!$r['corsi']): ?><span class="muted small">nessuno</span><?php endif; ?>
            <?php foreach ($r['corsi'] as $v): ?>
              <a class="tag link" href="iscritti.php?sc=<?= (int)$v['sottocorso']['id'] ?>"><?= h(label_corso($v['corso'], $v['sottocorso'])) ?></a>
            <?php endforeach; ?></td>
        <td class="row-actions"><a class="btn sm" href="persone.php?p=<?= (int)$r['p']['id'] ?>">Scheda</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php layout_footer(); ?>
