<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/layout.php';

$me = require_login();

/* ---------- salvataggio di un'intera tabella ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $sc_id = (int)post('sottocorso_id', '0');
    $righe = is_array($_POST['riga'] ?? null) ? $_POST['riga'] : [];

    $data = store_read();
    $sc   = find_by_id($data['sottocorsi'], $sc_id);
    if (!$sc) {
        flash('Sottocorso non trovato.', 'err');
        redirect('iscritti.php');
    }
    if (!puo_modificare($sc)) {
        flash('Puoi modificare solo i sottocorsi che tieni tu.', 'err');
        redirect('iscritti.php?sc=' . $sc_id);
    }

    $esito = store_update(function (array &$data) use ($sc_id, $righe) {
        $sc  = find_by_id($data['sottocorsi'], $sc_id);
        $cap = (int)$sc['capienza'];

        $per_posto = [];
        foreach ($data['iscrizioni'] as $k => $is) {
            if ((int)$is['sottocorso_id'] === $sc_id) {
                $per_posto[(int)$is['posto']] = $k;   // posto => indice nell'array iscrizioni
            }
        }

        $da_togliere = [];
        $usate       = [];   // persona_id gia' assegnata in questa tabella
        $avvisi      = [];

        for ($i = 0; $i < $cap; $i++) {
            $r       = is_array($righe[$i] ?? null) ? $righe[$i] : [];
            $nome    = trim((string)($r['nome'] ?? ''));
            $cognome = trim((string)($r['cognome'] ?? ''));
            $tel     = trim((string)($r['telefono'] ?? ''));
            $cert    = norm_data((string)($r['cert'] ?? ''));
            $pid     = (int)($r['persona_id'] ?? 0);

            // riga vuota: l'iscrizione sparisce, la persona resta in anagrafica
            if ($nome === '' && $cognome === '') {
                if (isset($per_posto[$i])) {
                    $da_togliere[] = $per_posto[$i];
                }
                continue;
            }

            // l'utente ha chiesto esplicitamente una scheda separata (omonima diversa, o cambio persona sul posto)
            $nuova = !empty($r['nuova']);

            // 1. la chiave della persona e' l'id, mai il nome
            $pi = (!$nuova && $pid > 0) ? index_by_id($data['persone'], $pid) : null;

            // 2. senza id: aggancio un omonimo esatto, segnalandolo
            if ($pi === null && !$nuova) {
                foreach ($data['persone'] as $k => $p) {
                    if (stessa_stringa($p['nome'], $nome) && stessa_stringa($p['cognome'], $cognome)) {
                        $pi       = $k;
                        $avvisi[] = trim($cognome . ' ' . $nome) . ': collegata alla scheda già esistente in anagrafica.';
                        break;
                    }
                }
            }

            // 3. altrimenti nuova scheda
            if ($pi === null) {
                $data['persone'][] = [
                    'id'       => next_id($data, 'persone'),
                    'nome'     => $nome,
                    'cognome'  => $cognome,
                    'telefono' => $tel,
                    'cert'     => $cert,
                    'creato'   => date('Y-m-d H:i'),
                ];
                $pi = count($data['persone']) - 1;
            } else {
                $prima    = nome_completo($data['persone'][$pi]);
                $rinomina = !stessa_stringa($data['persone'][$pi]['nome'], $nome)
                         || !stessa_stringa($data['persone'][$pi]['cognome'], $cognome);

                $data['persone'][$pi]['nome']     = $nome;
                $data['persone'][$pi]['cognome']  = $cognome;
                $data['persone'][$pi]['telefono'] = $tel;
                $data['persone'][$pi]['cert']     = $cert;

                if ($rinomina) {
                    $altrove = 0;
                    foreach ($data['iscrizioni'] as $is2) {
                        if ((int)$is2['persona_id'] === (int)$data['persone'][$pi]['id']
                            && (int)$is2['sottocorso_id'] !== $sc_id) {
                            $altrove++;
                        }
                    }
                    $avvisi[] = $prima . ' rinominata in ' . trim($cognome . ' ' . $nome)
                        . ($altrove > 0 ? ": aggiornata anche negli altri $altrove corsi." : '.');
                }
            }
            $persona_id = (int)$data['persone'][$pi]['id'];

            // stessa persona due volte nello stesso sottocorso: tengo la prima riga
            if (isset($usate[$persona_id])) {
                $avvisi[] = trim($cognome . ' ' . $nome) . ' compare piu\' volte: tenuta solo la prima riga.';
                if (isset($per_posto[$i])) {
                    $da_togliere[] = $per_posto[$i];
                }
                continue;
            }
            $usate[$persona_id] = true;

            if (isset($per_posto[$i])) {
                $data['iscrizioni'][$per_posto[$i]]['persona_id'] = $persona_id;
            } else {
                $data['iscrizioni'][] = [
                    'id'            => next_id($data, 'iscrizioni'),
                    'sottocorso_id' => $sc_id,
                    'persona_id'    => $persona_id,
                    'posto'         => $i,
                    'iscritto_il'   => date('Y-m-d'),
                ];
            }
        }

        // rimuovo anche eventuali iscrizioni oltre la capienza attuale
        foreach ($per_posto as $posto => $k) {
            if ($posto >= $cap) {
                $da_togliere[] = $k;
            }
        }
        if ($da_togliere) {
            foreach (array_unique($da_togliere) as $k) {
                unset($data['iscrizioni'][$k]);
            }
            $data['iscrizioni'] = array_values($data['iscrizioni']);
        }
        return $avvisi;
    });

    flash($esito ? implode(' ', $esito) : 'Elenco salvato.', $esito ? 'warn' : 'ok');
    redirect('iscritti.php?sc=' . $sc_id);
}

/* ---------- lettura ---------- */
$data = store_read();
$cmap = map_by_id($data['corsi']);
$umap = map_by_id($data['users']);
$pmap = map_by_id($data['persone']);

$tutti = $data['sottocorsi'];
sort_sottocorsi($tutti);

// quelli che finiscono nella stampa cumulativa: attivi dentro corsi attivi
$stampabili = array_values(array_filter($tutti, static function ($s) use ($cmap) {
    $c = $cmap[(int)$s['corso_id']] ?? null;
    return $c && !empty($s['attivo']) && !empty($c['attivo']);
}));

$sc_id = (int)get('sc', '0');
$sc    = $sc_id ? find_by_id($data['sottocorsi'], $sc_id) : null;
if (!$sc && $tutti) {
    $sc = $tutti[0];
}

layout_header('Iscritti', 'iscritti.php');
?>
<div class="page-head">
  <h1>Iscritti</h1>
  <form method="get" class="picker">
    <select name="sc" onchange="this.form.submit()">
      <?php foreach ($cmap as $cid => $c):
          $scs = array_values(array_filter($tutti, static function ($s) use ($cid) { return (int)$s['corso_id'] === $cid; }));
          if (!$scs) { continue; } ?>
        <optgroup label="<?= h($c['nome']) ?>">
          <?php foreach ($scs as $s): ?>
            <option value="<?= (int)$s['id'] ?>"<?= $sc && (int)$s['id'] === (int)$sc['id'] ? ' selected' : '' ?>>
              <?= h(orario_label($s) . ' · ' . $s['nome']) ?><?= empty($s['attivo']) ? ' (archiviato)' : '' ?>
            </option>
          <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn">Vai</button></noscript>
  </form>
  <div class="picker">
    <?php if ($sc): ?>
      <a class="btn" target="_blank" rel="noopener" href="stampa.php?tipo=iscritti&amp;sc=<?= (int)$sc['id'] ?>">Stampa questo elenco</a>
    <?php endif; ?>
    <?php if ($stampabili): ?>
      <a class="btn" target="_blank" rel="noopener" href="stampa.php?tipo=iscritti&amp;sc=all">Stampa tutti (<?= count($stampabili) ?> pagine)</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$sc): ?>
  <div class="card empty">Nessun sottocorso ancora creato. <a href="corsi.php">Vai ai corsi</a>.</div>
<?php else:
    $corso     = $cmap[(int)$sc['corso_id']] ?? ['nome' => '?', 'colore' => '#64748b'];
    $cap       = (int)$sc['capienza'];
    $per_posto = iscritti_per_posto($data, (int)$sc['id']);
    $editabile = puo_modificare($sc);

    // gli altri sottocorsi di ciascuna persona: servono per avvisare che il nome e' condiviso
    $scmap  = map_by_id($data['sottocorsi']);
    $altrove = [];
    foreach ($data['iscrizioni'] as $is) {
        if ((int)$is['sottocorso_id'] === (int)$sc['id']) {
            continue;
        }
        $s = $scmap[(int)$is['sottocorso_id']] ?? null;
        if (!$s) {
            continue;
        }
        $altrove[(int)$is['persona_id']][] = label_corso($cmap[(int)$s['corso_id']] ?? ['nome' => '?'], $s);
    }
    $altri = array_map('count', $altrove);
?>
<section class="card">
  <div class="corso-head">
    <h2><span class="chip" style="background:<?= h($corso['colore']) ?>"></span>
        <?= h($corso['nome']) ?> · <?= h($sc['nome']) ?></h2>
    <div class="meta">
      <span><?= h(orario_label($sc)) ?></span>
      <span><?= h($umap[(int)$sc['istruttore_id']]['nome'] ?? 'nessun istruttore') ?></span>
      <span class="pill <?= count($per_posto) >= $cap ? 'full' : '' ?>"><?= count($per_posto) ?>/<?= $cap ?> posti</span>
      <?= empty($sc['attivo']) ? '<b class="tag off">archiviato</b>' : '' ?>
    </div>
  </div>

  <?php if (!$editabile): ?>
    <div class="flash warn">Sola lettura: questo sottocorso non è tuo.</div>
  <?php endif; ?>

  <form method="post" id="form-iscritti">
    <?= csrf_field() ?>
    <input type="hidden" name="sottocorso_id" value="<?= (int)$sc['id'] ?>">
    <div class="table-wrap">
    <table class="grid iscritti">
      <thead>
        <tr><th class="num">#</th><th>Cognome</th><th>Nome</th><th>Telefono</th><th>Scad. certificato</th><th>Stato</th><th></th></tr>
      </thead>
      <tbody>
      <?php for ($i = 0; $i < $cap; $i++):
          $is  = $per_posto[$i] ?? null;
          $p   = $is ? ($pmap[(int)$is['persona_id']] ?? null) : null;
          [$st] = cert_stato($p['cert'] ?? '');
          $ro  = $editabile ? '' : ' readonly';
      ?>
        <tr class="riga <?= $p ? 'piena' : 'vuota' ?>">
          <td class="num"><?= $i + 1 ?></td>
          <td><input name="riga[<?= $i ?>][cognome]" value="<?= h($p['cognome'] ?? '') ?>" list="anagrafica" autocomplete="off" class="js-cognome"<?= $ro ?>>
              <input type="hidden" name="riga[<?= $i ?>][persona_id]" value="<?= (int)($p['id'] ?? 0) ?>" class="js-pid">
              <input type="hidden" name="riga[<?= $i ?>][nuova]" value="" class="js-nuova"></td>
          <td><input name="riga[<?= $i ?>][nome]" value="<?= h($p['nome'] ?? '') ?>" autocomplete="off" class="js-nome"<?= $ro ?>></td>
          <td><input name="riga[<?= $i ?>][telefono]" value="<?= h($p['telefono'] ?? '') ?>" inputmode="tel" autocomplete="off" class="js-tel"<?= $ro ?>></td>
          <td><input type="date" name="riga[<?= $i ?>][cert]" value="<?= h($p['cert'] ?? '') ?>" class="js-cert"<?= $ro ?>></td>
          <td><?php if ($p): ?><span class="badge <?= h($st) ?>"><?= h(CERT_LABEL[$st]) ?></span><?php endif; ?>
              <?php if ($p && !empty($altri[(int)$p['id']])): ?><a class="tag link" href="persone.php?p=<?= (int)$p['id'] ?>" title="Iscritta anche a: <?= h(implode(', ', $altrove[(int)$p['id']])) ?>">+<?= (int)$altri[(int)$p['id']] ?></a><?php endif; ?>
              <span class="nota js-nota" hidden></span></td>
          <td class="row-actions">
            <?php if ($p): ?><a class="btn sm" href="persone.php?p=<?= (int)$p['id'] ?>" title="Apri la scheda">Scheda</a><?php endif; ?>
            <?php if ($editabile): ?><button type="button" class="btn sm js-svuota" title="Svuota la riga">✕</button><?php endif; ?>
          </td>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
    </div>
    <?php if ($editabile): ?>
      <div class="actions sticky">
        <button class="btn primary">Salva elenco</button>
        <span class="muted small">Le righe svuotate vengono disiscritte; la scheda anagrafica resta.</span>
      </div>
    <?php endif; ?>
  </form>
</section>

<datalist id="anagrafica">
  <?php foreach ($data['persone'] as $p): ?>
    <option value="<?= h($p['cognome']) ?>" data-id="<?= (int)$p['id'] ?>"></option>
  <?php endforeach; ?>
</datalist>
<?php
$rubrica = [];
foreach ($data['persone'] as $p) {
    $rubrica[] = [
        'id'       => (int)$p['id'],
        'nome'     => $p['nome'],
        'cognome'  => $p['cognome'],
        'telefono' => $p['telefono'] ?? '',
        'cert'     => $p['cert'] ?? '',
        'altrove'  => $altrove[(int)$p['id']] ?? [],   // altri sottocorsi in cui compare
    ];
}
?>
<script>window.ANAGRAFICA = <?= json_encode($rubrica, JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>
<?php layout_footer(); ?>
