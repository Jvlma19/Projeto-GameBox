<?php
require_once __DIR__ . '/app-core.php';
gameboxEnableJsonApiErrors();
gameboxStartSession();
require __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['usuario_id'], $_SESSION['usuario_nome'])) {
    echo json_encode(['logado' => false, 'csrf_token' => gameboxCsrfToken()]);
    exit;
}

$account = gameboxRequireActiveAccount($pdo, true);

echo json_encode([
    'logado' => true,
    'csrf_token' => gameboxCsrfToken(),
    'usuario' => [
        'id' => (int)$_SESSION['usuario_id'],
        'nome' => $_SESSION['usuario_nome'],
        'foto_perfil' => $_SESSION['usuario_foto'] ?? null,
        'papel' => $account['papel'],
    ],
]);