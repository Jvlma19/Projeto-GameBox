<?php
require_once __DIR__ . '/app-core.php';
gameboxEnableJsonApiErrors();
gameboxStartSession();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

$account = gameboxRequireActiveAccount($pdo, true);

$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$igdbId = filter_var($data['igdb_id'] ?? null, FILTER_VALIDATE_INT);
$nome = trim($data['nome'] ?? '');
$imagem = trim($data['imagem'] ?? '');
$status = $data['status'] ?? 'quero_jogar';
$validos = ['jogando','quero_jogar','zerado','abandonado'];

if (!$igdbId || !$nome || !in_array($status,$validos,true)) {
    http_response_code(400);
    echo json_encode(['erro'=>'Dados inválidos.']);
    exit;
}

$stmt=$pdo->prepare('INSERT INTO jogos(igdb_id,nome,imagem) VALUES(?,?,?)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),imagem=VALUES(imagem)');
$stmt->execute([$igdbId,$nome,$imagem]);

$stmt=$pdo->prepare('SELECT id FROM jogos WHERE igdb_id=?');
$stmt->execute([$igdbId]);
$jogoId=$stmt->fetchColumn();

$stmt=$pdo->prepare('INSERT INTO biblioteca(usuario_id,jogo_id,status) VALUES(?,?,?)
ON DUPLICATE KEY UPDATE status=VALUES(status)');
$stmt->execute([$_SESSION['usuario_id'],$jogoId,$status]);

echo json_encode(['ok'=>true]);
?>