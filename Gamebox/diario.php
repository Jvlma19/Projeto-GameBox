<?php
session_start(); require 'db.php';
if (!isset($_SESSION['usuario_id'])) { header('Location: login.php'); exit; }
$uid=(int)$_SESSION['usuario_id'];
$stmt=$pdo->prepare('SELECT j.rawg_id,j.nome,j.imagem,b.status,b.nota,b.comentario,b.favorito,b.adicionado_em FROM biblioteca b JOIN jogos j ON j.id=b.jogo_id WHERE b.usuario_id=? ORDER BY b.adicionado_em DESC');
$stmt->execute([$uid]); $jogos=$stmt->fetchAll();
$counts=['jogando'=>0,'quero_jogar'=>0,'zerado'=>0,'abandonado'=>0];
foreach($jogos as $j) if(isset($counts[$j['status']])) $counts[$j['status']]++;
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Diário — GameBox</title><link rel="stylesheet" href="style.css"></head><body>
<nav class="nav"><div class="container nav-inner"><a href="index.html" class="logo">Game<span>Box</span></a><div class="nav-links"><a href="index.html">Início</a><a href="descobrir.php">Descobrir</a><a class="active" href="diario.php">Diário</a></div><div class="nav-right"><a class="auth-link" href="perfil.php">Perfil</a><a class="auth-link" href="logout.php">Sair</a></div></div></nav>
<main class="container diary-page"><div class="section-head" style="margin-top:40px"><div><h1>Meu diário</h1><p class="auth-subtitle">Seus jogos, status, notas e comentários em um só lugar.</p></div></div>
<div class="stat-row diary-stats"><div class="stat"><div class="num"><?=array_sum($counts)?></div><div class="label">jogos</div></div><div class="stat"><div class="num"><?=$counts['jogando']?></div><div class="label">jogando</div></div><div class="stat"><div class="num"><?=$counts['zerado']?></div><div class="label">zerados</div></div><div class="stat"><div class="num"><?=$counts['quero_jogar']?></div><div class="label">quero jogar</div></div></div>
<?php if(!$jogos): ?><div class="empty-state"><h2>Seu diário está vazio</h2><p>Abra <a href="descobrir.php">Descobrir</a> e comece a adicionar jogos.</p></div><?php else: ?>
<div class="diary-list"><?php foreach($jogos as $j): ?><article class="diary-card"><a class="diary-cover" href="game.html?id=<?=intval($j['rawg_id'])?>"><img src="<?=h($j['imagem'] ?: 'https://placehold.co/300x400') ?>" alt="<?=h($j['nome'])?>"></a><div class="diary-content"><div class="diary-top"><div><a class="diary-title" href="game.html?id=<?=intval($j['rawg_id'])?>"><?=h($j['nome'])?></a><div class="diary-meta"><?=h(str_replace('_',' ',ucfirst($j['status'])))?><?= $j['favorito'] ? ' · ♥ Favorito' : '' ?></div></div><?php if($j['nota']!==null): ?><span class="diary-rating">★ <?=h($j['nota'])?></span><?php endif; ?></div><?php if($j['comentario']): ?><p><?=nl2br(h($j['comentario']))?></p><?php else: ?><p class="muted">Nenhum comentário registrado.</p><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?></main><script src="app.js"></script></body></html>
