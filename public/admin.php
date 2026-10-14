<?php
require_once __DIR__ . '/inc/config.php';

$me = require_admin();

/* ---------- download: devono uscire prima di qualsiasi HTML ---------- */
$scarica = get('scarica');
if ($scarica === 'json') {
    $nome = 'archivio-' . preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower(APP_NAME)) . '-' . date('Ymd-Hi') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nome . '"');
    readfile(DATA_FILE);
    exit;
}
if ($scarica === 'csv') {
    $data = store_read();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="anagrafica-' . date('Ymd-Hi') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                 // BOM: Excel apre l'UTF-8 correttamente
    fputcsv($out, ['Cognome', 'Nome', 'Telefono', 'Scadenza certificato', 'Stato', 'Corsi'], ';', '"', '');
    $persone = $data['persone'];
    usort($persone, static function ($a, $b) {
        return [mb_strtolower($a['cognome']), mb_strtolower($a['nome'])]
           <=> [mb_strtolower($b['cognome']), mb_strtolower($b['nome'])];
    });
    foreach ($persone as $p) {
        [$st] = cert_stato($p['cert'] ?? '');
        $corsi = [];
        foreach (corsi_di_persona($data, (int)$p['id']) as $v) {
            $corsi[] = label_corso($v['corso'], $v['sottocorso']) . ' (' . orario_label($v['sottocorso']) . ')';
        }
        fputcsv($out, [$p['cognome'], $p['nome'], $p['telefono'] ?? '',
                       data_it($p['cert'] ?? ''), CERT_LABEL[$st], implode(' | ', $corsi)], ';', '"', '');
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/inc/layout.php';

/* ---------- azioni ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $azione = post('azione');

    if ($azione === 'impostazioni') {
        $nome    = post('nome');
        $accento = post('accento');
        $nuove = [
            'nome'        => $nome !== '' ? mb_substr($nome, 0, 60) : 'Gestionale Palestra',
            'contatti'    => mb_substr(post('contatti'), 0, 160),
            'cert_giorni' => max(1, min(365, (int)post('cert_giorni', '30'))),
            'accento'     => preg_match('/^#[0-9a-f]{6}$/i', $accento) ? mb_strtolower($accento) : '#8b5cf6',
        ];
        store_update(function (array &$data) use ($nuove) {
            $data['impostazioni'] = array_merge(impostazioni_default(), $nuove);
        });
        flash('Impostazioni salvate.');
        redirect('admin.php');
    }

    if ($azione === 'pulisci') {
        $tolte = store_update(function (array &$data) {
            $persone_ok = [];
            foreach ($data['persone'] as $p) {
                $persone_ok[(int)$p['id']] = true;
            }
            $sc = map_by_id($data['sottocorsi']);
            $viste = [];
            $tenute = [];
            $n = 0;
            foreach ($data['iscrizioni'] as $is) {
                $pid  = (int)$is['persona_id'];
                $scid = (int)$is['sottocorso_id'];
                $key  = $scid . '-' . $pid;
                $ok = isset($persone_ok[$pid])
                   && isset($sc[$scid])
                   && (int)$is['posto'] < (int)$sc[$scid]['capienza']
                   && !isset($viste[$key]);
                if ($ok) {
                    $viste[$key] = true;
                    $tenute[]    = $is;
                } else {
                    $n++;
                }
            }
            $data['iscrizioni'] = $tenute;
            return $n;
        });
        flash($tolte > 0 ? "Rimosse $tolte iscrizioni difettose." : 'Niente da correggere.', $tolte > 0 ? 'ok' : 'warn');
        redirect('admin.php');
    }

    if ($azione === 'ripristina') {
        $f = $_FILES['archivio'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('Nessun file ricevuto (o file troppo grande).', 'err');
            redirect('admin.php');
        }
        $nuovo = json_decode((string)file_get_contents($f['tmp_name']), true);
        $attese = ['users', 'corsi', 'sottocorsi', 'persone', 'iscrizioni'];
        $valido = is_array($nuovo);
        foreach ($attese as $k) {
            if (!isset($nuovo[$k]) || !is_array($nuovo[$k])) {
                $valido = false;
            }
        }
        if (!$valido || !$nuovo['users']) {
            flash('Il file non è un archivio valido di questo gestionale.', 'err');
            redirect('admin.php');
        }
        if (!is_dir(BACKUP_DIR)) {
            mkdir(BACKUP_DIR, 0775, true);
        }
        if (!@copy(DATA_FILE, BACKUP_DIR . '/prima-del-ripristino-' . date('Ymd-His') . '.json')) {
            flash('Non riesco a salvare la copia di sicurezza in ' . BACKUP_DIR . ': ripristino annullato.', 'err');
            redirect('admin.php');
        }
        store_cache(null, true);
        store_save($nuovo + store_default());
        // la sessione muore col ripristino (gli utenti sono quelli del file):
        // il messaggio non puo' viaggiare in sessione, lo passo come codice nell'url
        logout();
        redirect('login.php?msg=ripristino');
    }
    redirect('admin.php');
}

/* ---------- lettura ---------- */
$data = store_read();
$imp  = impostazioni();

$peso    = file_exists(DATA_FILE) ? filesize(DATA_FILE) : 0;
$ultimo  = file_exists(DATA_FILE) ? date('d/m/Y H:i', (int)filemtime(DATA_FILE)) : '—';
$scrivibile = is_writable(DATA_DIR) && (!file_exists(DATA_FILE) || is_writable(DATA_FILE));

/* l'archivio e' dentro o fuori dalla parte pubblica del sito? */
$norm = static function ($p) { return rtrim(str_replace(DIRECTORY_SEPARATOR, '/', (string)$p), '/'); };
$dati_path  = $norm(realpath(DATA_DIR) ?: DATA_DIR);
$docroot    = $norm(realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '');
$dentro_web = $docroot !== '' && strpos($dati_path . '/', $docroot . '/') === 0;

/* controllo integrita': iscrizioni che puntano nel vuoto o fuori posto */
$persone_ok = map_by_id($data['persone']);
$scmap      = map_by_id($data['sottocorsi']);
$difetti    = ['persona' => 0, 'sottocorso' => 0, 'capienza' => 0, 'doppie' => 0];
$viste      = [];
foreach ($data['iscrizioni'] as $is) {
    $pid  = (int)$is['persona_id'];
    $scid = (int)$is['sottocorso_id'];
    if (!isset($persone_ok[$pid])) {
        $difetti['persona']++;
        continue;
    }
    if (!isset($scmap[$scid])) {
        $difetti['sottocorso']++;
        continue;
    }
    if ((int)$is['posto'] >= (int)$scmap[$scid]['capienza']) {
        $difetti['capienza']++;
        continue;
    }
    $k = $scid . '-' . $pid;
    if (isset($viste[$k])) {
        $difetti['doppie']++;
    }
    $viste[$k] = true;
}
$n_difetti = array_sum($difetti);

$senza_corsi = 0;
foreach ($data['persone'] as $p) {
    if (!corsi_di_persona($data, (int)$p['id'])) {
        $senza_corsi++;
    }
}

$backup = is_dir(BACKUP_DIR) ? glob(BACKUP_DIR . '/*.json') : [];

layout_header('Amministrazione', 'admin.php');
?>
<div class="page-head">
  <h1>Amministrazione</h1>
  <span class="muted small">Solo gli amministratori vedono questa pagina.</span>
</div>

<div class="cols">
  <section class="card">
    <h2>Identità</h2>
    <form class="form-inline" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="azione" value="impostazioni">
      <label class="wide">Nome della palestra
        <input name="nome" value="<?= h($imp['nome']) ?>" maxlength="60" required>
        <span class="muted small">Compare in alto in ogni pagina, nel titolo della scheda del browser e su tutti i fogli stampati.</span>
      </label>
      <label class="wide">Contatti da stampare
        <input name="contatti" value="<?= h($imp['contatti']) ?>" maxlength="160"
               placeholder="Via Roma 1, Modena · 059 123456 · info@palestra.it">
        <span class="muted small">Riga facoltativa in calce alle pagine e in testa ai fogli di stampa.</span>
      </label>
      <label>Preavviso certificati
        <input type="number" name="cert_giorni" min="1" max="365" value="<?= (int)$imp['cert_giorni'] ?>">
        <span class="muted small">giorni prima della scadenza</span>
      </label>
      <label>Colore
        <input type="color" name="accento" value="<?= h($imp['accento']) ?>">
      </label>
      <div class="actions"><button class="btn primary">Salva impostazioni</button></div>
    </form>
  </section>

  <section class="card">
    <h2>Archivio</h2>
    <table class="grid">
      <tbody>
        <tr><td>Corsi</td><td><b><?= count($data['corsi']) ?></b></td>
            <td>Sottocorsi</td><td><b><?= count($data['sottocorsi']) ?></b></td></tr>
        <tr><td>Schede persona</td><td><b><?= count($data['persone']) ?></b></td>
            <td>Iscrizioni</td><td><b><?= count($data['iscrizioni']) ?></b></td></tr>
        <tr><td>Utenti</td><td><b><?= count($data['users']) ?></b></td>
            <td>Senza corsi</td><td><b><?= $senza_corsi ?></b></td></tr>
        <tr><td>Dimensione</td><td><b><?= number_format($peso / 1024, 1, ',', '.') ?> KB</b></td>
            <td>Ultimo salvataggio</td><td><b><?= h($ultimo) ?></b></td></tr>
        <tr><td>Cartella dati</td><td colspan="3">
            <?= $scrivibile ? '<span class="badge ok">scrivibile</span>' : '<span class="badge scaduto">NON scrivibile: i salvataggi falliranno</span>' ?>
            <?= $dentro_web
                ? '<span class="badge scadenza">dentro la document root</span>'
                : '<span class="badge ok">fuori dalla document root</span>' ?>
            <span class="muted small">PHP <?= h(PHP_VERSION) ?></span>
            <div class="muted small"><code><?= h($dati_path) ?></code></div>
            <?php if ($dentro_web): ?>
              <div class="muted small">Raggiungibile via web: la protegge solo <code>.htaccess</code>, che vale su Apache.
                Spostandola fuori dalla document root la protezione non dipende più dalla configurazione del server.</div>
            <?php endif; ?>
        </td></tr>
      </tbody>
    </table>
    <div class="row-actions mt">
      <a class="btn" href="admin.php?scarica=json">Scarica backup (.json)</a>
      <a class="btn" href="admin.php?scarica=csv">Scarica anagrafica (.csv)</a>
    </div>
    <p class="muted small">Il backup è l'intero archivio, credenziali comprese: conservalo al sicuro.</p>
  </section>
</div>

<div class="cols">
  <section class="card">
    <h2>Controllo integrità</h2>
    <?php if ($n_difetti === 0): ?>
      <p class="muted">Nessuna anomalia: tutte le <?= count($data['iscrizioni']) ?> iscrizioni puntano a persone e sottocorsi esistenti.</p>
    <?php else: ?>
      <ul class="muted small">
        <?php if ($difetti['persona']): ?><li><?= $difetti['persona'] ?> iscrizioni verso schede persona che non esistono più</li><?php endif; ?>
        <?php if ($difetti['sottocorso']): ?><li><?= $difetti['sottocorso'] ?> iscrizioni verso sottocorsi eliminati</li><?php endif; ?>
        <?php if ($difetti['capienza']): ?><li><?= $difetti['capienza'] ?> iscrizioni su posti oltre la capienza attuale</li><?php endif; ?>
        <?php if ($difetti['doppie']): ?><li><?= $difetti['doppie'] ?> doppioni della stessa persona nello stesso sottocorso</li><?php endif; ?>
      </ul>
      <form method="post" onsubmit="return confirm('Rimuovere <?= $n_difetti ?> iscrizioni difettose? Le schede anagrafiche non vengono toccate.')">
        <?= csrf_field() ?>
        <input type="hidden" name="azione" value="pulisci">
        <button class="btn danger">Correggi (<?= $n_difetti ?>)</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Ripristino</h2>
    <form method="post" enctype="multipart/form-data" class="picker"
          onsubmit="return confirm('Il ripristino sostituisce TUTTI i dati attuali con quelli del file. Una copia dei dati di adesso viene salvata nella sottocartella backup/. Procedere?')">
      <?= csrf_field() ?>
      <input type="hidden" name="azione" value="ripristina">
      <input type="file" name="archivio" accept=".json,application/json" required>
      <button class="btn danger">Ripristina da file</button>
    </form>
    <p class="muted small">Sostituisce l'intero archivio con il contenuto di un backup .json.
      Al termine viene chiusa la sessione, perché anche gli utenti sono quelli del file.
      <?php if ($backup): ?><br>Copie di sicurezza in <code><?= h(basename(DATA_DIR)) ?>/backup/</code>: <?= count($backup) ?>.<?php endif; ?></p>

    <h2 class="mt">Scorciatoie</h2>
    <div class="row-actions">
      <a class="btn" href="utenti.php">Utenti e ruoli</a>
      <a class="btn" href="corsi.php">Corsi e sottocorsi</a>
    </div>
  </section>
</div>
<?php layout_footer(); ?>
