<?php
require_once __DIR__ . '/app-core.php';
gameboxStartSession();
require __DIR__ . '/db.php';
$account = gameboxRequireActiveAccount($pdo);

$uid = (int)$_SESSION['usuario_id'];
$stmt = $pdo->prepare('SELECT id,nome,email,foto_perfil,criado_em FROM usuarios WHERE id=?');
$stmt->execute([$uid]);
$usuario = $stmt->fetch();
if (!$usuario) { session_destroy(); header('Location: login.php'); exit; }

$csrf = gameboxCsrfToken();
$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!gameboxValidateCsrf()) {
        $erro = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $upload = $_FILES['foto_perfil'] ?? null;
        if (!is_array($upload) || !is_int($upload['error'] ?? null) || !is_string($upload['tmp_name'] ?? null)) {
            $erro = 'Selecione uma imagem válida para enviar.';
        } elseif ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
            $erro = 'A imagem deve ter no máximo 3 MB.';
        } elseif ($upload['error'] !== UPLOAD_ERR_OK) {
            $erro = $upload['error'] === UPLOAD_ERR_NO_FILE
                ? 'Selecione uma imagem para enviar.'
                : 'O envio da imagem falhou. Tente novamente.';
        } elseif (!is_uploaded_file($upload['tmp_name'])) {
            $erro = 'O arquivo recebido não é um upload válido.';
        } else {
            clearstatcache(true, $upload['tmp_name']);
            $fileSize = filesize($upload['tmp_name']);
            if (!is_int($fileSize) || $fileSize < 1 || $fileSize > 3 * 1024 * 1024) {
                $erro = 'A imagem deve ter no máximo 3 MB.';
            } elseif (!class_exists('finfo')) {
                error_log('GameBox avatar upload rejected: Fileinfo extension is unavailable.');
                $erro = 'Não foi possível validar o tipo da imagem neste servidor.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($upload['tmp_name']);
                $imageInfo = @getimagesize($upload['tmp_name']);
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

                if (!is_array($imageInfo) || !isset($imageInfo[0], $imageInfo[1], $imageInfo['mime'])
                    || !is_string($mime) || $imageInfo['mime'] !== $mime || !isset($extensions[$mime])) {
                    $erro = 'Use uma imagem JPG, PNG ou WebP válida.';
                } elseif ($imageInfo[0] < 1 || $imageInfo[1] < 1
                    || $imageInfo[0] > 4096 || $imageInfo[1] > 4096
                    || $imageInfo[0] * $imageInfo[1] > 16777216) {
                    $erro = 'A imagem tem dimensões muito grandes. Reduza-a e tente novamente.';
                } else {
                    $projectRoot = realpath(__DIR__);
                    $uploadsRoot = $projectRoot === false ? '' : $projectRoot . DIRECTORY_SEPARATOR . 'uploads';
                    $directory = $uploadsRoot . DIRECTORY_SEPARATOR . 'avatars';
                    $expectedDirectory = $directory;

                    if ($projectRoot === false || is_link($uploadsRoot) || is_link($directory)) {
                        error_log('GameBox avatar upload rejected: upload directory is missing or is a symbolic link.');
                        $erro = 'Não foi possível preparar o armazenamento da foto.';
                    } else {
                        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
                            error_log('GameBox avatar upload failed: cannot create upload directory.');
                            $erro = 'Não foi possível preparar o armazenamento da foto.';
                        }

                        $resolvedDirectory = $erro === '' ? realpath($directory) : false;
                        $directoryMode = $resolvedDirectory === false || DIRECTORY_SEPARATOR === '\\'
                            ? 0
                            : @fileperms($resolvedDirectory);
                        $unsafeDirectoryPermissions = DIRECTORY_SEPARATOR !== '\\'
                            && ($directoryMode === false || ($directoryMode & 0022) !== 0);
                        if ($erro === '' && ($resolvedDirectory === false
                            || strcasecmp($resolvedDirectory, $expectedDirectory) !== 0
                            || !is_writable($resolvedDirectory)
                            || $unsafeDirectoryPermissions)) {
                            error_log('GameBox avatar upload failed: upload directory is outside the expected path or not writable.');
                            $erro = 'O diretório de fotos não está disponível para gravação.';
                        }
                    }

                    if ($erro === '') {
                        $destination = '';
                        $destinationHandle = false;
                        try {
                            for ($attempt = 0; $attempt < 3; $attempt++) {
                                $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                                $candidate = $resolvedDirectory . DIRECTORY_SEPARATOR . $fileName;
                                $reservation = @fopen($candidate, 'x+b');
                                if ($reservation !== false) {
                                    $destination = $candidate;
                                    $destinationHandle = $reservation;
                                    break;
                                }
                            }
                        } catch (Random\RandomException $exception) {
                            error_log('GameBox avatar upload failed: secure filename generation failed.');
                        }

                        if ($destination === '') {
                            error_log('GameBox avatar upload failed: could not reserve a unique destination.');
                            $erro = 'Não foi possível preparar o arquivo da foto. Tente novamente.';
                        } else {
                            $sourceHandle = @fopen($upload['tmp_name'], 'rb');
                            $copiedBytes = $sourceHandle === false
                                ? false
                                : @stream_copy_to_stream($sourceHandle, $destinationHandle, 3 * 1024 * 1024 + 1);
                            $copyCompleted = $copiedBytes === $fileSize && fflush($destinationHandle);
                            if (is_resource($sourceHandle)) {
                                fclose($sourceHandle);
                            }
                            fclose($destinationHandle);

                            if (!$copyCompleted) {
                                if (is_file($destination) && !@unlink($destination)) {
                                    error_log('GameBox avatar upload cleanup failed after copy error.');
                                }
                                error_log('GameBox avatar upload failed: copying the validated upload failed.');
                                $erro = 'Não foi possível salvar a foto.';
                            } else {
                                $permissionsSet = DIRECTORY_SEPARATOR === '\\' || @chmod($destination, 0644);
                                if (!$permissionsSet || !is_readable($destination)) {
                                    if (is_file($destination) && !@unlink($destination)) {
                                        error_log('GameBox avatar cleanup failed after permission error.');
                                    }
                                    error_log('GameBox avatar upload failed: saved image permissions are unsafe.');
                                    $erro = 'Não foi possível definir permissões seguras para a foto.';
                                } else {
                                    $fileName = basename($destination);
                                    $photoPath = 'uploads/avatars/' . $fileName;
                                    try {
                                        $update = $pdo->prepare('UPDATE usuarios SET foto_perfil=? WHERE id=?');
                                        $update->execute([$photoPath, $uid]);
                                        $_SESSION['usuario_foto'] = $photoPath;

                                        $previousPhoto = is_string($usuario['foto_perfil']) ? $usuario['foto_perfil'] : '';
                                        if (preg_match('~\Auploads/avatars/([a-f0-9]{32}\.(?:jpg|png|webp))\z~D', $previousPhoto, $matches)) {
                                            $previousFile = $resolvedDirectory . DIRECTORY_SEPARATOR . $matches[1];
                                            if (is_file($previousFile) && !@unlink($previousFile)) {
                                                error_log('GameBox avatar cleanup failed: could not remove the previous profile image.');
                                            }
                                        }
                                        header('Location: perfil.php?foto=atualizada');
                                        exit;
                                    } catch (PDOException $exception) {
                                        if (is_file($destination) && !@unlink($destination)) {
                                            error_log('GameBox avatar cleanup failed after database update error.');
                                        }
                                        error_log('GameBox avatar upload failed: profile image database update failed.');
                                        $erro = 'Não foi possível atualizar a foto. Tente novamente.';
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

$list = $pdo->prepare('SELECT j.igdb_id,j.nome,j.imagem,b.status,b.nota,b.comentario,b.favorito,b.adicionado_em FROM biblioteca b JOIN jogos j ON j.id=b.jogo_id WHERE b.usuario_id=? ORDER BY b.adicionado_em DESC');
$list->execute([$uid]);
$jogos = $list->fetchAll();
$favoritos = array_values(array_filter($jogos, static fn($jogo) => (bool)$jogo['favorito']));
$avaliacoes = array_values(array_filter($jogos, static fn($jogo) => $jogo['nota'] !== null || $jogo['comentario']));
$inicial = strtoupper(substr($usuario['nome'], 0, 1));
if (isset($_GET['foto'])) $mensagem = 'Foto de perfil atualizada.';
?>
<!doctype html><html lang="pt-BR"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= gameboxEscapeHtml($usuario['nome']) ?> — GameBox</title><link rel="stylesheet" href="style.css"></head>
<body>
<nav class="nav"><div class="container nav-inner">
	<a href="index.html" class="logo">Game<span>Box</span></a>
	<div class="nav-links"><a href="index.html">Início</a><a href="descobrir.php">Descobrir</a><a href="diario.php">Diário</a><a href="dois-fatores.php">Segurança / 2FA</a></div>
	<div class="nav-right"><a class="auth-link" href="logout.php">Sair</a></div>
</div></nav>
<main class="container profile-page">
	<section class="profile-head">
		<?php if ($usuario['foto_perfil']): ?><img class="avatar-lg" src="<?= gameboxEscapeHtml($usuario['foto_perfil']) ?>" alt="Foto de <?= gameboxEscapeHtml($usuario['nome']) ?>">
		<?php else: ?><div class="avatar-lg avatar-letter" aria-label="Avatar de <?= gameboxEscapeHtml($usuario['nome']) ?>"><?= gameboxEscapeHtml($inicial) ?></div><?php endif; ?>
		<div><span class="eyebrow">SUA CONTA</span><h1><?= gameboxEscapeHtml($usuario['nome']) ?></h1><p class="auth-subtitle"><?= gameboxEscapeHtml($usuario['email']) ?></p></div>
	</section>

	<section class="profile-settings" aria-labelledby="photo-heading">
		<div><h2 id="photo-heading">Foto de perfil</h2><p class="muted">JPG, PNG ou WebP, até 3 MB.</p></div>
		<form method="post" enctype="multipart/form-data" class="avatar-form">
			<input type="hidden" name="csrf_token" value="<?= gameboxEscapeHtml($csrf) ?>">
			<label class="btn" for="profile-photo">Escolher imagem</label>
			<input id="profile-photo" class="visually-hidden" type="file" name="foto_perfil" accept="image/jpeg,image/png,image/webp" required>
			<button class="btn btn-primary" type="submit">Salvar foto</button>
		</form>
		<?php if ($mensagem): ?><p class="form-feedback success"><?= gameboxEscapeHtml($mensagem) ?></p><?php endif; ?>
		<?php if ($erro): ?><p class="form-feedback error"><?= gameboxEscapeHtml($erro) ?></p><?php endif; ?>
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
			<?php foreach ($favoritos as $jogo): ?><?php if ($jogo['igdb_id']): ?><a class="game-card" href="game.html?id=<?= (int)$jogo['igdb_id'] ?>"><?php else: ?><div class="game-card"><?php endif; ?>
				<div class="cover"><img src="<?= gameboxEscapeHtml($jogo['imagem'] ?: 'https://placehold.co/300x400') ?>" alt="Capa de <?= gameboxEscapeHtml($jogo['nome']) ?>" loading="lazy"></div>
				<div class="title"><?= gameboxEscapeHtml($jogo['nome']) ?></div><?php if ($jogo['nota'] !== null): ?><div class="year">★ <?= gameboxEscapeHtml($jogo['nota']) ?>/5</div><?php endif; ?>
			<?php if ($jogo['igdb_id']): ?></a><?php else: ?></div><?php endif; ?><?php endforeach; ?>
		</div><?php endif; ?>
	</section>

	<section>
		<div class="section-head"><h2>Minhas avaliações e comentários</h2></div>
		<?php if (!$avaliacoes): ?><p class="empty-state">Suas avaliações e comentários aparecerão aqui.</p>
		<?php else: ?><div class="review-list">
			<?php foreach ($avaliacoes as $avaliacao): ?><article class="review-card">
				<div class="review-card-head"><?php if ($avaliacao['igdb_id']): ?><a href="game.html?id=<?= (int)$avaliacao['igdb_id'] ?>"><?= gameboxEscapeHtml($avaliacao['nome']) ?></a><?php else: ?><span><?= gameboxEscapeHtml($avaliacao['nome']) ?></span><?php endif; ?><?php if ($avaliacao['nota'] !== null): ?><span class="diary-rating">★ <?= gameboxEscapeHtml($avaliacao['nota']) ?>/5</span><?php endif; ?></div>
				<?php if ($avaliacao['comentario']): ?><p><?= nl2br(gameboxEscapeHtml($avaliacao['comentario'])) ?></p><?php endif; ?>
			</article><?php endforeach; ?>
		</div><?php endif; ?>
	</section>

	<section>
		<div class="section-head"><h2>Minha biblioteca</h2></div>
		<?php if (!$jogos): ?><p class="empty-state">Adicione jogos pela página Descobrir.</p>
		<?php else: ?><div class="game-grid">
			<?php foreach ($jogos as $jogo): ?><?php if ($jogo['igdb_id']): ?><a class="game-card" href="game.html?id=<?= (int)$jogo['igdb_id'] ?>"><?php else: ?><div class="game-card"><?php endif; ?>
				<div class="cover"><img src="<?= gameboxEscapeHtml($jogo['imagem'] ?: 'https://placehold.co/300x400') ?>" alt="Capa de <?= gameboxEscapeHtml($jogo['nome']) ?>" loading="lazy"></div>
				<div class="title"><?= gameboxEscapeHtml($jogo['nome']) ?></div><div class="year"><?= gameboxEscapeHtml(str_replace('_', ' ', $jogo['status'])) ?></div>
			<?php if ($jogo['igdb_id']): ?></a><?php else: ?></div><?php endif; ?><?php endforeach; ?>
		</div><?php endif; ?>
	</section>
</main>
<script src="app.js"></script></body></html>