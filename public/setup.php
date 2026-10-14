<?php
require_once __DIR__ . '/inc/config.php';

// Disponibile solo finche' non esiste alcun utente.
if (ha_utenti()) {
    redirect('login.php');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nome  = post('nome');
    $user  = post('username');
    $pass  = post('password');
    $pass2 = post('password2');

    if ($nome === '' || $user === '') {
        $err = 'Nome e username sono obbligatori.';
    } elseif (mb_strlen($pass) < 8) {
        $err = 'La password deve avere almeno 8 caratteri.';
    } elseif ($pass !== $pass2) {
        $err = 'Le due password non coincidono.';
    } else {
        store_update(function (array &$data) use ($nome, $user, $pass) {
            if (count($data['users']) > 0) {
                return;
            }
            $data['users'][] = [
                'id'            => next_id($data, 'users'),
                'username'      => $user,
                'nome'          => $nome,
                'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'ruolo'         => 'admin',
                'attivo'        => true,
                'creato'        => date('Y-m-d H:i'),
            ];
        });
        flash('Amministratore creato. Ora puoi accedere.');
        redirect('login.php');
    }
}
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configurazione · <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=1">
<style>:root{--accent:<?= h(ACCENTO) ?>}</style>
</head>
<body class="centered">
<form class="card auth" method="post">
  <h1>Prima configurazione</h1>
  <p class="muted">Crea l'utente amministratore. Questa pagina si disattiva da sola subito dopo.</p>
  <?= csrf_field() ?>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <label>Nome e cognome<input name="nome" value="<?= h(post('nome')) ?>" required autofocus></label>
  <label>Username<input name="username" value="<?= h(post('username')) ?>" required autocomplete="username"></label>
  <label>Password (min. 8 caratteri)<input type="password" name="password" required autocomplete="new-password"></label>
  <label>Ripeti password<input type="password" name="password2" required autocomplete="new-password"></label>
  <button class="btn primary" type="submit">Crea amministratore</button>
</form>
</body>
</html>
