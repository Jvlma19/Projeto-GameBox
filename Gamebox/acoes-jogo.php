<?php
session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['erro'=>'Faça login para usar sua biblioteca.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$rawgId = filter_var($data['rawg_id'] ?? null, FILTER_VALIDATE_INT);
$nome = trim($data['nome'] ?? '');
$imagem = trim($data['imagem'] ?? '');
$acao = $data['acao'] ?? '';
$status = $data['status'] ?? 'quero_jogar';
$nota = ($data['nota'] ?? '') === '' ? null : (float)$data['nota'];
$comentario = trim($data['comentario'] ?? '');

if (!$rawgId || !$nome || !in_array($acao, ['adicionar','registrar','favoritar','comentar'], true)) {
    http_response_code(400);
    echo json_encode(['erro'=>'Dados inválidos.']); exit;
}

$stmt = $pdo->prepare('INSERT INTO jogos(rawg_id,nome,imagem) VALUES(?,?,?) ON DUPLICATE KEY UPDATE nome=VALUES(nome),imagem=VALUES(imagem)');
$stmt->execute([$rawgId, $nome, $imagem]);
$stmt = $pdo->prepare('SELECT id FROM jogos WHERE rawg_id=?');
$stmt->execute([$rawgId]);
$jogoId = (int)$stmt->fetchColumn();
$uid = (int)$_SESSION['usuario_id'];

if ($acao === 'favoritar') {
    $stmt = $pdo->prepare('SELECT favorito FROM biblioteca WHERE usuario_id=? AND jogo_id=?');
    $stmt->execute([$uid, $jogoId]);
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->prepare('INSERT INTO biblioteca(usuario_id,jogo_id,status,favorito) VALUES(?,?,?,1)')->execute([$uid,$jogoId,'quero_jogar']);
        $fav = 1;
    } else {
        $fav = $row['favorito'] ? 0 : 1;
        $pdo->prepare('UPDATE biblioteca SET favorito=? WHERE usuario_id=? AND jogo_id=?')->execute([$fav,$uid,$jogoId]);
    }
    echo json_encode(['ok'=>true,'favorito'=>(bool)$fav]); exit;
}

if (!in_array($status, ['jogando','quero_jogar','zerado','abandonado'], true)) $status = 'quero_jogar';
if ($nota !== null) $nota = max(0, min(5, $nota));

if ($acao === 'comentar') {
    if ($comentario === '') {
        http_response_code(400);
        echo json_encode(['erro'=>'Escreva um comentário antes de publicar.']); exit;
    }
    if (strlen($comentario) > 12000) {
        http_response_code(400);
        echo json_encode(['erro'=>'O comentário excede o limite permitido.']); exit;
    }
    $stmt = $pdo->prepare('INSERT INTO biblioteca(usuario_id,jogo_id,status,nota,comentario) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE nota=VALUES(nota),comentario=VALUES(comentario),adicionado_em=CURRENT_TIMESTAMP');
    $stmt->execute([$uid,$jogoId,'quero_jogar',$nota,$comentario]);
    echo json_encode(['ok'=>true]); exit;
}

if ($acao === 'adicionar') {
    $stmt = $pdo->prepare('INSERT INTO biblioteca(usuario_id,jogo_id,status) VALUES(?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)');
    $stmt->execute([$uid,$jogoId,'quero_jogar']);
} else {
    $stmt = $pdo->prepare('INSERT INTO biblioteca(usuario_id,jogo_id,status,nota,comentario) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),nota=VALUES(nota),comentario=VALUES(comentario)');
    $stmt->execute([$uid,$jogoId,$status,$nota,$comentario ?: null]);
}

echo json_encode(['ok'=>true]);
?>
