<?php
require_once __DIR__ . '/app-core.php';
gameboxEnableJsonApiErrors();
gameboxStartSession();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');
$igdbId = filter_var($_GET['igdb_id'] ?? null, FILTER_VALIDATE_INT);
if (!$igdbId) { echo json_encode(['comentarios'=>[]]); exit; }

$stmt = $pdo->prepare('SELECT u.nome, u.foto_perfil, b.nota, b.comentario, b.status, b.adicionado_em FROM biblioteca b JOIN jogos j ON j.id=b.jogo_id JOIN usuarios u ON u.id=b.usuario_id WHERE j.igdb_id=? AND b.comentario IS NOT NULL AND b.comentario<>"" AND b.comentario_oculto=0 ORDER BY b.adicionado_em DESC');
$stmt->execute([$igdbId]);
$comentarios = $stmt->fetchAll();
echo json_encode(['comentarios'=>$comentarios, 'logado'=>isset($_SESSION['usuario_id'])]);
?>
