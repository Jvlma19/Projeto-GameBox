<?php
require_once __DIR__ . '/app-core.php';
gameboxEnableJsonApiErrors();
gameboxStartSession();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['usuario_id'], $_SESSION['usuario_nome'])) {
    echo json_encode(['logado' => false]);
    exit;
}

$stmt = $pdo->prepare('SELECT papel,bloqueado FROM usuarios WHERE id=? LIMIT 1');
$stmt->execute([(int)$_SESSION['usuario_id']]);
$account = $stmt->fetch();
if (!$account || (int)$account['bloqueado'] === 1) {
    unset($_SESSION['usuario_id'], $_SESSION['usuario_nome'], $_SESSION['usuario_foto']);
    session_regenerate_id(true);
    echo json_encode(['logado' => false]);
    exit;
}

echo json_encode([
    'logado' => true,
    'usuario' => [
        'id' => (int)$_SESSION['usuario_id'],
        'nome' => $_SESSION['usuario_nome'],
        'foto_perfil' => $_SESSION['usuario_foto'] ?? null,
        'papel' => $account['papel'],
    ],
]);