<?php
session_start();
require 'db.php';
if (!isset($_SESSION['usuario_id'])) { header('Location: login.php'); exit; }

$uid = (int)$_SESSION['usuario_id'];
$stmt = $pdo->prepare('SELECT id,nome,email,foto_perfil,criado_em FROM usuarios WHERE id=?');
$stmt->execute([$uid]);
$usuario = $stmt->fetch();
if (!$usuario) { session_destroy(); header('Location: login.php'); exit; }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
				$erro = 'Sessão expirada. Atualize a página e tente novamente.';
		} elseif (($_FILES['foto_perfil']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
				$erro = 'Selecione uma imagem válida para enviar.';
		} elseif (($_FILES['foto_perfil']['size'] ?? 0) > 3 * 1024 * 1024) {
				$erro = 'A imagem deve ter no máximo 3 MB.';
		} else {
				$arquivo = $_FILES['foto_perfil']['tmp_name'];
				$info = @getimagesize($arquivo);
				$finfo = new finfo(FILEINFO_MIME_TYPE);
				$mime = $finfo->file($arquivo);
				$extensoes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

				if (!$info || $info['mime'] !== $mime || !isset($extensoes[$mime])) {
						$erro = 'Use uma imagem JPG, PNG ou WebP.';
				} else {
						$diretorio = __DIR__ . '/uploads/avatars';
						if (!is_dir($diretorio) && !mkdir($diretorio, 0755, true) && !is_dir($diretorio)) {
								$erro = 'Não foi possível preparar o armazenamento da foto.';
						} else {
								$nomeArquivo = bin2hex(random_bytes(16)) . '.' . $extensoes[$mime];
								$destino = $diretorio . '/' . $nomeArquivo;
								if (!move_uploaded_file($arquivo, $destino)) {
										$erro = 'Não foi possível salvar a foto.';
								} else {
										$fotoAnterior = $usuario['foto_perfil'];
										$fotoNova = 'uploads/avatars/' . $nomeArquivo;
										$update = $pdo->prepare('UPDATE usuarios SET foto_perfil=? WHERE id=?');
										$update->execute([$fotoNova, $uid]);
										$_SESSION['usuario_foto'] = $fotoNova;
										if ($fotoAnterior && strpos($fotoAnterior, 'uploads/avatars/') === 0) {
												$caminhoAnterior = __DIR__ . '/' . $fotoAnterior;
												if (is_file($caminhoAnterior)) unlink($caminhoAnterior);
										}
										header('Location: perfil.php?foto=atualizada');
										exit;
								}
						}
				}
		}
}

$list = $pdo->prepare('SELECT j.rawg_id,j.nome,j.imagem,b.status,b.nota,b.comentario,b.favorito,b.adicionado_em FROM biblioteca b JOIN jogos j ON j.id=b.jogo_id WHERE b.usuario_id=? ORDER BY b.adicionado_em DESC');
$list->execute([$uid]);
$jogos = $list->fetchAll();
$favoritos = array_values(array_filter($jogos, static fn($jogo) => (bool)$jogo['favorito']));
$avaliacoes = array_values(array_filter($jogos, static fn($jogo) => $jogo['nota'] !== null || $jogo['comentario']));
$h = static fn($valor) => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
$inicial = strtoupper(substr($usuario['nome'], 0, 1));
if (isset($_GET['foto'])) $mensagem = 'Foto de perfil atualizada.';
?>
<!doctype html><html lang="pt-BR"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($usuario['nome']) ?> — GameBox</title><link rel="stylesheet" href="style.css"></head>
<body>
<nav class="nav"><div class="container nav-inner">
	<a href="index.html" class="logo">Game<span>Box</span></a>
	<div class="nav-links"><a href="index.html">Início</a><a href="descobrir.php">Descobrir</a><a href="diario.php">Diário</a></div>
	<div class="nav-right"><a class="auth-link" href="logout.php">Sair</a></div>
</div></nav>
<main class="container profile-page">
	<section class="profile-head">
		<?php if ($usuario['foto_perfil']): ?><img class="avatar-lg" src="<?= $h($usuario['foto_perfil']) ?>" alt="Foto de <?= $h($usuario['nome']) ?>">
		<?php else: ?><div class="avatar-lg avatar-letter" aria-label="Avatar de <?= $h($usuario['nome']) ?>"><?= $h($inicial) ?></div><?php endif; ?>
		<div><span class="eyebrow">SUA CONTA</span><h1><?= $h($usuario['nome']) ?></h1><p class="auth-subtitle"><?= $h($usuario['email']) ?></p></div>
	</section>

	<section class="profile-settings" aria-labelledby="photo-heading">
		<div><h2 id="photo-heading">Foto de perfil</h2><p class="muted">JPG, PNG ou WebP, até 3 MB.</p></div>
		<form method="post" enctype="multipart/form-data" class="avatar-form">
			<input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>">
			<label class="btn" for="profile-photo">Escolher imagem</label>
			<input id="profile-photo" class="visually-hidden" type="file" name="foto_perfil" accept="image/jpeg,image/png,image/webp" required>
			<button class="btn btn-primary" type="submit">Salvar foto</button>
		</form>
		<?php if ($mensagem): ?><p class="form-feedback success"><?= $h($mensagem) ?></p><?php endif; ?>
		<?php if ($erro): ?><p class="form-feedback error"><?= $h($erro) ?></p><?php endif; ?>
	</section>

	<section class="profile-stats stat-row">
		<div class="stat"><div class="num"><?= count($jogos) ?></div><div class="label">jogos na biblioteca</div></div>
		<div class="stat"><div class="num"><?= count($favoritos) ?></div><div class="label">favoritos</div></div>
		<div class="stat"><div class="num"><?= count($avaliacoes) ?></div><div class="label">avaliações</div></div>
	</section>

	<section>
		<div class="section-head"><h2>Jogos favoritos</h2></div>
		<?php if (!$favoritos): ?><p class="empty-state">Seus jogos favoritos aparecerão aqui.</p>
		<?php else: ?><div class="game-grid">
			<?php foreach ($favoritos as $jogo): ?><a class="game-card" href="game.html?id=<?= (int)$jogo['rawg_id'] ?>">
				<div class="cover"><img src="<?= $h($jogo['imagem'] ?: 'https://placehold.co/300x400') ?>" alt="Capa de <?= $h($jogo['nome']) ?>" loading="lazy"></div>
				<div class="title"><?= $h($jogo['nome']) ?></div><?php if ($jogo['nota'] !== null): ?><div class="year">★ <?= $h($jogo['nota']) ?>/5</div><?php endif; ?>
			</a><?php endforeach; ?>
		</div><?php endif; ?>
	</section>

	<section>
		<div class="section-head"><h2>Minhas avaliações e comentários</h2></div>
		<?php if (!$avaliacoes): ?><p class="empty-state">Suas avaliações e comentários aparecerão aqui.</p>
		<?php else: ?><div class="review-list">
			<?php foreach ($avaliacoes as $avaliacao): ?><article class="review-card">
				<div class="review-card-head"><a href="game.html?id=<?= (int)$avaliacao['rawg_id'] ?>"><?= $h($avaliacao['nome']) ?></a><?php if ($avaliacao['nota'] !== null): ?><span class="diary-rating">★ <?= $h($avaliacao['nota']) ?>/5</span><?php endif; ?></div>
				<?php if ($avaliacao['comentario']): ?><p><?= nl2br($h($avaliacao['comentario'])) ?></p><?php endif; ?>
			</article><?php endforeach; ?>
		</div><?php endif; ?>
	</section>

	<section>
		<div class="section-head"><h2>Minha biblioteca</h2></div>
		<?php if (!$jogos): ?><p class="empty-state">Adicione jogos pela página Descobrir.</p>
		<?php else: ?><div class="game-grid">
			<?php foreach ($jogos as $jogo): ?><a class="game-card" href="game.html?id=<?= (int)$jogo['rawg_id'] ?>">
				<div class="cover"><img src="<?= $h($jogo['imagem'] ?: 'https://placehold.co/300x400') ?>" alt="Capa de <?= $h($jogo['nome']) ?>" loading="lazy"></div>
				<div class="title"><?= $h($jogo['nome']) ?></div><div class="year"><?= $h(str_replace('_', ' ', $jogo['status'])) ?></div>
			</a><?php endforeach; ?>
		</div><?php endif; ?>
	</section>
</main>
<script src="app.js"></script></body></html>