<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['usuario_id'], $_SESSION['usuario_nome'])) {
    echo json_encode(['logado' => false]);
    exit;
}

echo json_encode([
    'logado' => true,
    'usuario' => [
        'id' => (int)$_SESSION['usuario_id'],
        'nome' => $_SESSION['usuario_nome'],
        'foto_perfil' => $_SESSION['usuario_foto'] ?? null,
    ],
]);