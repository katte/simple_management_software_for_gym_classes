<?php
declare(strict_types=1);

/** Utente in sessione, ricaricato dall'archivio ad ogni richiesta. */
function current_user(): ?array
{
    static $cached = false;
    static $user   = null;
    if ($cached) {
        return $user;
    }
    $cached = true;
    $id     = $_SESSION['uid'] ?? null;
    if ($id === null) {
        return $user = null;
    }
    $data = store_read();
    $u    = find_by_id($data['users'], $id);
    if (!$u || empty($u['attivo'])) {
        unset($_SESSION['uid']);
        return $user = null;
    }
    return $user = $u;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php?r=' . urlencode(basename($_SERVER['REQUEST_URI'] ?? 'index.php')));
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if (($u['ruolo'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Accesso riservato agli amministratori.');
    }
    return $u;
}

function is_admin(): bool
{
    $u = current_user();
    return $u && ($u['ruolo'] ?? '') === 'admin';
}

/**
 * Regola dei permessi: l'admin fa tutto,
 * l'istruttore legge tutto ma modifica solo i sottocorsi che tiene lui.
 */
function puo_modificare(array $sottocorso): bool
{
    if (is_admin()) {
        return true;
    }
    $u = current_user();
    return $u && (int)($sottocorso['istruttore_id'] ?? 0) === (int)$u['id'];
}

/**
 * Una scheda anagrafica e' modificabile dall'admin e dagli istruttori
 * che hanno quella persona in almeno uno dei propri sottocorsi.
 */
function puo_modificare_persona(array $data, int $persona_id): bool
{
    if (is_admin()) {
        return true;
    }
    $u = current_user();
    if (!$u) {
        return false;
    }
    foreach (corsi_di_persona($data, $persona_id) as $voce) {
        if ((int)$voce['sottocorso']['istruttore_id'] === (int)$u['id']) {
            return true;
        }
    }
    return false;
}

function login(string $username, string $password): bool
{
    $data = store_read();
    foreach ($data['users'] as $u) {
        if (mb_strtolower($u['username']) === mb_strtolower(trim($username))) {
            if (!empty($u['attivo']) && password_verify($password, $u['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$u['id'];
                return true;
            }
            return false;
        }
    }
    // hashing fittizio: tempo di risposta uniforme anche con username inesistente
    password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30M1Mn5.p1D2');
    return false;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}

function ha_utenti(): bool
{
    $data = store_read();
    return count($data['users']) > 0;
}
