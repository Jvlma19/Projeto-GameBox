<?php
session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['erro'=>'Faça login para adicionar jogos.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$rawgId = filter_var($data['rawg_id'] ?? null, FILTER_VALIDATE_INT);
$nome = trim($data['nome'] ?? '');
$imagem = trim($data['imagem'] ?? '');
$status = $data['status'] ?? 'quero_jogar';
$validos = ['jogando','quero_jogar','zerado','abandonado'];

if (!$rawgId || !$nome || !in_array($status,$validos,true)) {
    http_response_code(400);
    echo json_encode(['erro'=>'Dados inválidos.']);
    exit;
}

$stmt=$pdo->prepare('INSERT INTO jogos(rawg_id,nome,imagem) VALUES(?,?,?)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),imagem=VALUES(imagem)');
$stmt->execute([$rawgId,$nome,$imagem]);

$stmt=$pdo->prepare('SELECT id FROM jogos WHERE rawg_id=?');
$stmt->execute([$rawgId]);
$jogoId=$stmt->fetchColumn();

$stmt=$pdo->prepare('INSERT INTO biblioteca(usuario_id,jogo_id,status) VALUES(?,?,?)
ON DUPLICATE KEY UPDATE status=VALUES(status)');
$stmt->execute([$_SESSION['usuario_id'],$jogoId,$status]);

echo json_encode(['ok'=>true]);
?>