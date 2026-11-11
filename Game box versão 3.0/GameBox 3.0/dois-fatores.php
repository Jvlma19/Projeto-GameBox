<?php
require_once __DIR__ . '/app-core.php';
gameboxStartSession();
require __DIR__ . '/db.php';

if (($_GET['acao'] ?? '') === 'verificar') {
    $pendingId = (int)($_SESSION['2fa_pendente_id'] ?? 0);
    $expired = empty($_SESSION['2fa_pendente_em'])
        || time() - (int)$_SESSION['2fa_pendente_em'] > 300
        || empty($_SESSION['2fa_pendente_hash']);
    if ($pendingId < 1 || $expired) {
        unset($_SESSION['2fa_pendente_id'], $_SESSION['2fa_pendente_hash'], $_SESSION['2fa_pendente_em'], $_SESSION['2fa_pendente_tentativas'], $_SESSION['2fa_dev_code']);
        header('Location: login.php');
        exit;
    }

    $verifyError = '';
    $verifyCsrf = gameboxCsrfToken();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!gameboxValidateCsrf()) {
            $verifyError = 'Sua sessão expirou. Atualize a página e tente novamente.';
        } else {
            $stmt = $pdo->prepare('SELECT id,nome,foto_perfil,dois_fatores_ativo,bloqueado FROM usuarios WHERE id=? LIMIT 1');
            $stmt->execute([$pendingId]);
            $pendingUser = $stmt->fetch();
            $code = is_string($_POST['codigo'] ?? null) ? trim($_POST['codigo']) : '';
            $_SESSION['2fa_pendente_tentativas'] = (int)($_SESSION['2fa_pendente_tentativas'] ?? 0) + 1;

            if ((int)$_SESSION['2fa_pendente_tentativas'] > 5) {
                unset($_SESSION['2fa_pendente_id'], $_SESSION['2fa_pendente_hash'], $_SESSION['2fa_pendente_em'], $_SESSION['2fa_pendente_tentativas'], $_SESSION['2fa_dev_code']);
                $verifyError = 'Limite de tentativas atingido. Faça login novamente para receber outro código.';
            } elseif (!$pendingUser || !(int)$pendingUser['dois_fatores_ativo'] || (int)$pendingUser['bloqueado'] === 1) {
                unset($_SESSION['2fa_pendente_id'], $_SESSION['2fa_pendente_hash'], $_SESSION['2fa_pendente_em'], $_SESSION['2fa_pendente_tentativas'], $_SESSION['2fa_dev_code']);
                $verifyError = 'Não foi possível validar o acesso. Faça login novamente.';
            } elseif (!preg_match('/^\d{6}$/', $code) || !password_verify($code, (string)$_SESSION['2fa_pendente_hash'])) {
                $verifyError = 'Código inválido. Confira o e-mail e tente novamente.';
            } else {
                session_regenerate_id(true);
                $_SESSION['usuario_id'] = (int)$pendingUser['id'];
                $_SESSION['usuario_nome'] = $pendingUser['nome'];
                $_SESSION['usuario_foto'] = $pendingUser['foto_perfil'];
                unset($_SESSION['2fa_pendente_id'], $_SESSION['2fa_pendente_hash'], $_SESSION['2fa_pendente_em'], $_SESSION['2fa_pendente_tentativas'], $_SESSION['csrf_token'], $_SESSION['2fa_dev_code']);
                gameboxCsrfToken();
                header('Location: index.html');
                exit;
            }
        }
    }

    $h = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    ?>
    <!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verificação — GameBox</title><link rel="stylesheet" href="style.css"></head><body><main class="auth-page"><section class="auth-card"><div class="auth-brand">Game<span>Box</span></div><h1>Verificação em duas etapas</h1><p class="auth-subtitle">Enviamos um código de 6 dígitos para o e-mail cadastrado. O código expira em 5 minutos.</p><?php if (gameboxIsLocalTwoFactorTestMode() && !empty($_SESSION['2fa_dev_code'])): ?><p class="form-feedback" role="status">Modo de teste local: seu código é <strong><?= $h($_SESSION['2fa_dev_code']) ?></strong>.</p><?php endif; ?><?php if ($verifyError): ?><p class="auth-message" role="alert" style="color:var(--error)"><?= $h($verifyError) ?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= $h($verifyCsrf) ?>"><label for="codigo">Código de verificação</label><input class="auth-input" id="codigo" name="codigo" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus><button class="btn btn-primary auth-button" type="submit">Verificar e entrar</button></form><p class="auth-footer"><a href="login.php">Cancelar e voltar ao login</a></p></section></main></body></html>
    <?php
    exit;
}

$account = gameboxRequireActiveAccount($pdo);
$uid = (int)$_SESSION['usuario_id'];
$stmt = $pdo->prepare('SELECT nome,email,dois_fatores_ativo FROM usuarios WHERE id=?');
$stmt->execute([$uid]);
$usuario = $stmt->fetch();
if (!$usuario) { header('Location: logout.php'); exit; }

$csrf = gameboxCsrfToken();
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!gameboxValidateCsrf()) {
        $erro = 'Sessão expirada. Atualize a página.';
    } elseif (isset($_POST['iniciar'])) {
        if ((int)$usuario['dois_fatores_ativo'] === 1) {
            $erro = 'A autenticação em duas etapas já está ativada.';
        } else {
            $codigo = (string)random_int(100000, 999999);
            if (!gameboxSendTwoFactorCode((string)$usuario['email'], $codigo)) {
                $erro = 'Não foi possível enviar o código. Verifique a configuração de e-mail do servidor e tente novamente.';
            } else {
                $_SESSION['2fa_configuracao_id'] = $uid;
                $_SESSION['2fa_configuracao_hash'] = password_hash($codigo, PASSWORD_DEFAULT);
                $_SESSION['2fa_configuracao_em'] = time();
                $_SESSION['2fa_configuracao_tentativas'] = 0;
                header('Location: dois-fatores.php?configurar=1');
                exit;
            }
        }
    } elseif (isset($_POST['ativar'])) {
        $validSetup = (int)($_SESSION['2fa_configuracao_id'] ?? 0) === $uid
            && isset($_SESSION['2fa_configuracao_em'], $_SESSION['2fa_configuracao_hash'])
            && time() - (int)$_SESSION['2fa_configuracao_em'] <= 600;
        if (!$validSetup) {
            $erro = 'O código expirou. Solicite um novo para ativar o 2FA.';
        } else {
            $_SESSION['2fa_configuracao_tentativas'] = (int)($_SESSION['2fa_configuracao_tentativas'] ?? 0) + 1;
            $codigo = is_string($_POST['codigo'] ?? null) ? trim($_POST['codigo']) : '';
            if ((int)$_SESSION['2fa_configuracao_tentativas'] > 5) {
                unset($_SESSION['2fa_configuracao_id'], $_SESSION['2fa_configuracao_hash'], $_SESSION['2fa_configuracao_em'], $_SESSION['2fa_configuracao_tentativas']);
                unset($_SESSION['2fa_dev_code']);
                $erro = 'Limite de tentativas atingido. Solicite um novo código.';
            } elseif (!preg_match('/^\d{6}$/', $codigo) || !password_verify($codigo, (string)$_SESSION['2fa_configuracao_hash'])) {
                $erro = 'Código inválido. Confira o e-mail e tente novamente.';
            } else {
                $pdo->prepare('UPDATE usuarios SET dois_fatores_ativo=1,dois_fatores_segredo=NULL WHERE id=?')->execute([$uid]);
                unset($_SESSION['2fa_configuracao_id'], $_SESSION['2fa_configuracao_hash'], $_SESSION['2fa_configuracao_em'], $_SESSION['2fa_configuracao_tentativas']);
                unset($_SESSION['2fa_dev_code']);
                header('Location: dois-fatores.php?ativado=1');
                exit;
            }
        }
    } elseif (isset($_POST['desativar'])) {
        $senhaStmt = $pdo->prepare('SELECT senha FROM usuarios WHERE id=?');
        $senhaStmt->execute([$uid]);
        if (!password_verify((string)($_POST['senha'] ?? ''), (string)$senhaStmt->fetchColumn())) {
            $erro = 'Senha incorreta. O 2FA continua ativado.';
        } else {
            $pdo->prepare('UPDATE usuarios SET dois_fatores_ativo=0,dois_fatores_segredo=NULL WHERE id=?')->execute([$uid]);
            unset($_SESSION['2fa_dev_code']);
            header('Location: dois-fatores.php?desativado=1');
            exit;
        }
    }
}
$stmt = $pdo->prepare('SELECT nome,email,dois_fatores_ativo FROM usuarios WHERE id=?');
$stmt->execute([$uid]);
$usuario = $stmt->fetch();
$h = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$setupPending = (int)($_SESSION['2fa_configuracao_id'] ?? 0) === $uid
    && !empty($_SESSION['2fa_configuracao_hash'])
    && time() - (int)($_SESSION['2fa_configuracao_em'] ?? 0) <= 600;
if (!$setupPending && (int)($_SESSION['2fa_configuracao_id'] ?? 0) === $uid) {
    unset($_SESSION['2fa_configuracao_id'], $_SESSION['2fa_configuracao_hash'], $_SESSION['2fa_configuracao_em'], $_SESSION['2fa_configuracao_tentativas'], $_SESSION['2fa_dev_code']);
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Segurança — GameBox</title><link rel="stylesheet" href="style.css"></head><body><nav class="nav"><div class="container nav-inner"><a href="index.html" class="logo">Game<span>Box</span></a><div class="nav-links"><a href="perfil.php">Meu perfil</a></div></div></nav><main class="container profile-page"><section class="auth-card">
<?php if ((int)$usuario['dois_fatores_ativo'] === 1): ?><p>O 2FA por e-mail está <strong>ativado</strong> para sua conta.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><label for="senha">Confirme sua senha para desativar</label><input class="auth-input" id="senha" name="senha" type="password" autocomplete="current-password" required><button class="btn" type="submit" name="desativar">Desativar 2FA</button></form>
<?php elseif ($setupPending): ?><h2>Confirme seu e-mail</h2><p>Enviamos um código de 6 dígitos para o endereço cadastrado. Ele expira em 10 minutos.</p><?php if (gameboxIsLocalTwoFactorTestMode() && !empty($_SESSION['2fa_dev_code'])): ?><p class="form-feedback" role="status">Modo de teste local: seu código é <strong><?= $h($_SESSION['2fa_dev_code']) ?></strong>.</p><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><label for="codigo">Código de verificação</label><input class="auth-input" id="codigo" name="codigo" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required><button class="btn btn-primary" type="submit" name="ativar">Confirmar e ativar</button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><button class="btn" type="submit" name="iniciar">Enviar outro código</button></form>
<?php else: ?><p>O 2FA está desativado. Ao ativá-lo, o login exigirá sua senha e um código enviado por e-mail.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><button class="btn btn-primary" type="submit" name="iniciar">Configurar 2FA por e-mail</button></form><?php endif; ?><p class="auth-footer"><a href="perfil.php">Voltar ao perfil</a></p></section></main></body></html>
