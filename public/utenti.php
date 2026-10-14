<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/layout.php';

$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $azione = post('azione');

    if ($azione === 'save') {
        $id     = (int)post('id', '0');
        $nome   = post('nome');
        $user   = post('username');
        $ruolo  = post('ruolo') === 'admin' ? 'admin' : 'istruttore';
        $attivo = post('attivo') === '1';
        $pass   = post('password');

        $err = '';
        if ($nome === '' || $user === '') {
            $err = 'Nome e username sono obbligatori.';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $user)) {
            $err = 'Username: 3-32 caratteri tra lettere, numeri, punto, trattino.';
        } elseif ($pass !== '' && mb_strlen($pass) < 8) {
            $err = 'La password deve avere almeno 8 caratteri.';
        } elseif ($id === 0 && $pass === '') {
            $err = 'Imposta una password per il nuovo utente.';
        } elseif ($id === (int)$me['id'] && (!$attivo || $ruolo !== 'admin')) {
            $err = 'Non puoi togliere a te stesso i permessi di amministratore.';
        }
        if ($err !== '') {
            flash($err, 'err');
            redirect('utenti.php' . ($id ? '?u=' . $id : '?u=new'));
        }

        $msg = store_update(function (array &$data) use ($id, $nome, $user, $ruolo, $attivo, $pass) {
            foreach ($data['users'] as $u) {
                if (mb_strtolower($u['username']) === mb_strtolower($user) && (int)$u['id'] !== $id) {
                    return ['Username già in uso.', 'err'];
                }
            }
            $i = $id > 0 ? index_by_id($data['users'], $id) : null;
            if ($i !== null) {
                $data['users'][$i]['nome']     = $nome;
                $data['users'][$i]['username'] = $user;
                $data['users'][$i]['ruolo']    = $ruolo;
                $data['users'][$i]['attivo']   = $attivo;
                if ($pass !== '') {
                    $data['users'][$i]['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
                }
                return ['Utente aggiornato.', 'ok'];
            }
            $data['users'][] = [
                'id'            => next_id($data, 'users'),
                'username'      => $user,
                'nome'          => $nome,
                'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'ruolo'         => $ruolo,
                'attivo'        => $attivo,
                'creato'        => date('Y-m-d H:i'),
            ];
            return ['Utente creato.', 'ok'];
        });
        flash($msg[0], $msg[1]);
        redirect('utenti.php');
    }

    if ($azione === 'delete') {
        $id = (int)post('id', '0');
        if ($id === (int)$me['id']) {
            flash('Non puoi eliminare te stesso.', 'err');
            redirect('utenti.php');
        }
        $msg = store_update(function (array &$data) use ($id) {
            foreach ($data['sottocorsi'] as $s) {
                if ((int)$s['istruttore_id'] === $id) {
                    return ['Utente assegnato a dei sottocorsi: disattivalo oppure cambia istruttore prima.', 'err'];
                }
            }
            $i = index_by_id($data['users'], $id);
            if ($i !== null) {
                array_splice($data['users'], $i, 1);
            }
            return ['Utente eliminato.', 'ok'];
        });
        flash($msg[0], $msg[1]);
        redirect('utenti.php');
    }
    redirect('utenti.php');
}

$data = store_read();
$edit = null;
if (get('u') !== '') {
    $edit = get('u') === 'new'
        ? ['id' => 0, 'nome' => '', 'username' => '', 'ruolo' => 'istruttore', 'attivo' => true]
        : find_by_id($data['users'], (int)get('u'));
}

layout_header('Utenti', 'utenti.php');
?>
<div class="page-head">
  <h1>Utenti</h1>
  <a class="btn primary" href="utenti.php?u=new">+ Nuovo utente</a>
</div>
<p class="muted small">L'istruttore vede tutto ma modifica solo gli iscritti dei sottocorsi in cui è indicato come istruttore.</p>

<?php if ($edit): ?>
<form class="card form-inline" method="post">
  <h2><?= $edit['id'] ? 'Modifica utente' : 'Nuovo utente' ?></h2>
  <?= csrf_field() ?>
  <input type="hidden" name="azione" value="save">
  <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
  <label>Nome e cognome<input name="nome" value="<?= h($edit['nome']) ?>" required autofocus></label>
  <label>Username<input name="username" value="<?= h($edit['username']) ?>" required autocomplete="off"></label>
  <label>Ruolo
    <select name="ruolo">
      <option value="istruttore"<?= $edit['ruolo'] === 'istruttore' ? ' selected' : '' ?>>Istruttore</option>
      <option value="admin"<?= $edit['ruolo'] === 'admin' ? ' selected' : '' ?>>Amministratore</option>
    </select>
  </label>
  <label><?= $edit['id'] ? 'Nuova password (vuoto = invariata)' : 'Password (min. 8)' ?>
    <input type="password" name="password" autocomplete="new-password"<?= $edit['id'] ? '' : ' required' ?>></label>
  <label class="check"><input type="checkbox" name="attivo" value="1"<?= !empty($edit['attivo']) ? ' checked' : '' ?>> Può accedere</label>
  <div class="actions"><button class="btn primary">Salva</button><a class="btn" href="utenti.php">Annulla</a></div>
</form>
<?php endif; ?>

<section class="card">
  <div class="table-wrap">
  <table class="grid">
    <thead><tr><th>Nome</th><th>Username</th><th>Ruolo</th><th>Sottocorsi</th><th>Stato</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($data['users'] as $u):
        $n = count(array_filter($data['sottocorsi'], static function ($s) use ($u) { return (int)$s['istruttore_id'] === (int)$u['id']; })); ?>
      <tr<?= empty($u['attivo']) ? ' class="dim"' : '' ?>>
        <td><b><?= h($u['nome']) ?></b><?= (int)$u['id'] === (int)$me['id'] ? ' <span class="tag">tu</span>' : '' ?></td>
        <td><?= h($u['username']) ?></td>
        <td><?= $u['ruolo'] === 'admin' ? '<b class="tag">admin</b>' : 'istruttore' ?></td>
        <td><?= $n ?></td>
        <td><?= !empty($u['attivo']) ? 'attivo' : '<span class="tag off">disattivato</span>' ?></td>
        <td class="row-actions">
          <a class="btn sm" href="utenti.php?u=<?= (int)$u['id'] ?>">Modifica</a>
          <?php if ((int)$u['id'] !== (int)$me['id']): ?>
          <form method="post" onsubmit="return confirm('Eliminare <?= h($u['nome']) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="azione" value="delete">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="btn sm danger">Elimina</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php layout_footer(); ?>
