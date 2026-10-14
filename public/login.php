<?php
require_once __DIR__ . '/inc/config.php';

if (!ha_utenti()) {
    redirect('setup.php');
}
if (current_user()) {
    redirect('index.php');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (login(post('username'), post('password'))) {
        $r = get('r', 'index.php');
        redirect(preg_match('~^[a-z0-9_.\-]+\.php(\?.*)?$~i', $r) ? $r : 'index.php');
    }
    $err = 'Credenziali non valide.';
}
$f = flash();

// messaggi che arrivano da una pagina che ha appena chiuso la sessione (solo codici noti)
const MSG_NOTI = [
    'ripristino' => 'Archivio ripristinato: una copia dei dati precedenti è nella sottocartella backup/ dei dati. Accedi con le credenziali contenute nel file caricato.',
];
if ($f === null && isset(MSG_NOTI[get('msg')])) {
    $f = ['msg' => MSG_NOTI[get('msg')], 'type' => 'warn'];
}
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accedi · <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=1">
<style>:root{--accent:<?= h(ACCENTO) ?>}</style>
</head>
<body class="centered">
<form class="card auth" method="post">
  <h1><span class="dot"></span><?= h(APP_NAME) ?></h1>
  <?= csrf_field() ?>
  <?php if ($f): ?><div class="flash <?= h($f['type']) ?>"><?= h($f['msg']) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <label>Username<input name="username" required autofocus autocomplete="username"></label>
  <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn primary" type="submit">Accedi</button>
</form>
</body>
</html>
