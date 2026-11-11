<?php
require_once __DIR__ . '/app-core.php';
gameboxEnableJsonApiErrors();
gameboxStartSession(); require 'db.php'; header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario_id'])) { echo json_encode(['logado'=>false]); exit; }
$account = gameboxRequireActiveAccount($pdo, true);
$igdbId = filter_var($_GET['igdb_id'] ?? null, FILTER_VALIDATE_INT);
$stmt = $pdo->prepare('SELECT b.status,b.nota,b.comentario,b.favorito FROM biblioteca b JOIN jogos j ON j.id=b.jogo_id WHERE b.usuario_id=? AND j.igdb_id=?');
$stmt->execute([$_SESSION['usuario_id'],$igdbId]);
$row=$stmt->fetch();
echo json_encode(['logado'=>true,'jogo'=>$row ?: null]);
?>
