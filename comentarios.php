<?php
session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');
$rawgId = filter_var($_GET['rawg_id'] ?? null, FILTER_VALIDATE_INT);
if (!$rawgId) { echo json_encode(['comentarios'=>[]]); exit; }

$stmt = $pdo->prepare('SELECT u.nome, u.foto_perfil, b.nota, b.comentario, b.status, b.adicionado_em FROM biblioteca b JOIN jogos j ON j.id=b.jogo_id JOIN usuarios u ON u.id=b.usuario_id WHERE j.rawg_id=? AND b.comentario IS NOT NULL AND b.comentario<>"" ORDER BY b.adicionado_em DESC');
$stmt->execute([$rawgId]);
$comentarios = $stmt->fetchAll();
echo json_encode(['comentarios'=>$comentarios, 'logado'=>isset($_SESSION['usuario_id'])]);
?>
