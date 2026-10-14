<?php
declare(strict_types=1);

function layout_header(string $titolo, string $attivo = ''): void
{
    $u    = current_user();
    $voci = [
        'index.php'       => 'Dashboard',
        'calendario.php'  => 'Calendario',
        'iscritti.php'    => 'Iscritti',
        'persone.php'     => 'Anagrafica',
        'corsi.php'       => 'Corsi',
    ];
    if (is_admin()) {
        $voci['utenti.php'] = 'Utenti';
        $voci['admin.php']  = 'Admin';
    }
    ?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titolo) ?> · <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=1">
<style>:root{--accent:<?= h(ACCENTO) ?>}</style>
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php"><span class="dot"></span><?= h(APP_NAME) ?></a>
  <nav>
    <?php foreach ($voci as $file => $label): ?>
      <a href="<?= h($file) ?>"<?= $attivo === $file ? ' class="on"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="me">
    <span><?= h($u['nome'] ?? '') ?><?= ($u['ruolo'] ?? '') === 'admin' ? ' <b class="tag">admin</b>' : '' ?></span>
    <a class="exit" href="logout.php">Esci</a>
  </div>
</header>
<main>
<?php
    $f = flash();
    if ($f) {
        echo '<div class="flash ' . h($f['type']) . '">' . h($f['msg']) . '</div>';
    }
}

function layout_footer(): void
{
    ?>
</main>
<footer class="foot"><?= h(APP_NAME) ?><?= CONTATTI !== '' ? ' · ' . h(CONTATTI) : '' ?></footer>
<script src="assets/app.js?v=1"></script>
</body>
</html>
<?php
}
