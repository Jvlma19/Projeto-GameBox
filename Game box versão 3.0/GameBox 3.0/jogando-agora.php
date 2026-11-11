<?php
require_once __DIR__ . '/app-core.php';
gameboxEnableJsonApiErrors();
gameboxStartSession();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['logado' => false, 'jogos' => []]);
    exit;
}
$account = gameboxRequireActiveAccount($pdo, true);

$stmt = $pdo->prepare(
    "SELECT j.igdb_id, j.nome, j.imagem, b.nota, b.comentario, b.adicionado_em
     FROM biblioteca b
     JOIN jogos j ON j.id = b.jogo_id
     WHERE b.usuario_id = ? AND b.status = 'jogando' AND j.igdb_id IS NOT NULL
     ORDER BY b.adicionado_em DESC"
);
$stmt->execute([(int)$_SESSION['usuario_id']]);
echo json_encode(['logado' => true, 'jogos' => $stmt->fetchAll()]);
