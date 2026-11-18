<?php
require_once __DIR__ . '/app-core.php';
gameboxStartSession();
require __DIR__ . '/db.php';

$clearPending = static function (): void {
    unset($_SESSION['2fa_pendente_id'], $_SESSION['2fa_pendente_challenge'], $_SESSION['2fa_dev_code']);
};
$clearSetup = static function (): void {
    unset($_SESSION['2fa_configuracao_id'], $_SESSION['2fa_configuracao_challenge'], $_SESSION['2fa_dev_code']);
};

if (($_GET['acao'] ?? '') === 'verificar') {
    $pendingId = (int)($_SESSION['2fa_pendente_id'] ?? 0);
    $pendingToken = is_string($_SESSION['2fa_pendente_challenge'] ?? null)
        ? $_SESSION['2fa_pendente_challenge']
        : '';
    $pendingStmt = $pdo->prepare(
        'SELECT challenge_hash, purpose, code_hash, expires_at > NOW() AS is_unexpired, attempts
         FROM two_factor_challenges WHERE usuario_id=? LIMIT 1'
    );
    $pendingStmt->execute([$pendingId]);
    $pendingChallenge = $pendingStmt->fetch();
    $pendingTokenHash = preg_match('/^[a-f0-9]{64}$/', $pendingToken)
        ? hash('sha256', $pendingToken)
        : '';

    if ($pendingId < 1 || !$pendingChallenge
        || !is_string($pendingChallenge['challenge_hash'])
        || !hash_equals($pendingChallenge['challenge_hash'], $pendingTokenHash)
        || $pendingChallenge['purpose'] !== 'login'
        || !$pendingChallenge['code_hash']
        || (int)$pendingChallenge['is_unexpired'] !== 1
        || (int)$pendingChallenge['attempts'] >= 5) {
        gameboxInvalidateTwoFactorCode($pdo, $pendingId, $pendingToken);
        $clearPending();
        header('Location: login.php');
        exit;
    }

    $verifyError = '';
    $verifyAllowed = true;
    $verifyCsrf = gameboxCsrfToken();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!gameboxValidateCsrf()) {
            $verifyError = 'Sua sessão expirou. Atualize a página e tente novamente.';
        } else {
            $stmt = $pdo->prepare('SELECT id,nome,foto_perfil,papel,dois_fatores_ativo,dois_fatores_versao,bloqueado FROM usuarios WHERE id=? LIMIT 1');
            $stmt->execute([$pendingId]);
            $pendingUser = $stmt->fetch();

            if (!$pendingUser || !(int)$pendingUser['dois_fatores_ativo'] || (int)$pendingUser['bloqueado'] === 1) {
                gameboxInvalidateTwoFactorCode($pdo, $pendingId, $pendingToken);
                $clearPending();
                $verifyAllowed = false;
                $verifyError = 'Não foi possível validar o acesso. Faça login novamente.';
            } else {
                $code = is_string($_POST['codigo'] ?? null) ? trim($_POST['codigo']) : '';
                $result = gameboxVerifyTwoFactorCode($pdo, $pendingId, $pendingToken, $code, 'login');
                if ($result['status'] === 'valid') {
                    session_regenerate_id(true);
                    $_SESSION['usuario_id'] = (int)$pendingUser['id'];
                    $_SESSION['usuario_nome'] = $pendingUser['nome'];
                    $_SESSION['usuario_foto'] = $pendingUser['foto_perfil'];
                    $_SESSION['2fa_verificado'] = (int)$pendingUser['id'];
                    $_SESSION['2fa_verificado_versao'] = (int)$pendingUser['dois_fatores_versao'];
                    unset(
                        $_SESSION['2fa_pendente_id'],
                        $_SESSION['2fa_pendente_challenge'],
                        $_SESSION['2fa_configuracao_id'],
                        $_SESSION['2fa_configuracao_challenge'],
                        $_SESSION['csrf_token'],
                        $_SESSION['2fa_dev_code']
                    );
                    gameboxCsrfToken();
                    header('Location: ' . ($pendingUser['papel'] === 'master' ? 'admin.php' : 'index.html'));
                    exit;
                }

                if ($result['status'] === 'invalid') {
                    $verifyError = 'Código inválido. Restam ' . (int)$result['attempts_remaining'] . ' tentativa(s).';
                } else {
                    $clearPending();
                    $verifyAllowed = false;
                    $verifyError = $result['status'] === 'expired'
                        ? 'O código expirou. Faça login novamente para solicitar outro.'
                        : 'O limite de tentativas foi atingido ou o código já foi substituído. Faça login novamente.';
                }
            }
        }
    }
    ?>
    <!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verificação — GameBox</title><link rel="stylesheet" href="style.css"></head><body><main class="auth-page"><section class="auth-card"><div class="auth-brand">Game<span>Box</span></div><h1>Verificação em duas etapas</h1><p class="auth-subtitle">Enviamos um código de 6 dígitos para o e-mail cadastrado. O código expira em 5 minutos.</p><?php if ($verifyAllowed && gameboxIsLocalTwoFactorTestMode() && !empty($_SESSION['2fa_dev_code'])): ?><p class="form-feedback" role="status">Modo de teste local: seu código é <strong><?= gameboxEscapeHtml($_SESSION['2fa_dev_code']) ?></strong>.</p><?php endif; ?><?php if ($verifyError): ?><p class="auth-message" role="alert" style="color:var(--error)"><?= gameboxEscapeHtml($verifyError) ?></p><?php endif; ?><?php if ($verifyAllowed): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= gameboxEscapeHtml($verifyCsrf) ?>"><label for="codigo">Código de verificação</label><input class="auth-input" id="codigo" name="codigo" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus><button class="btn btn-primary auth-button" type="submit">Verificar e entrar</button></form><?php endif; ?><p class="auth-footer"><a href="login.php">Cancelar e voltar ao login</a></p></section></main></body></html>
    <?php
    exit;
}

$account = gameboxRequireActiveAccount($pdo);
$uid = (int)$_SESSION['usuario_id'];
$stmt = $pdo->prepare('SELECT nome,email,dois_fatores_ativo FROM usuarios WHERE id=?');
$stmt->execute([$uid]);
$usuario = $stmt->fetch();
if (!$usuario) {
    header('Location: logout.php');
    exit;
}

$csrf = gameboxCsrfToken();
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!gameboxValidateCsrf()) {
        $erro = 'Sessão expirada. Atualize a página.';
    } elseif (isset($_POST['iniciar'])) {
        if ((int)$usuario['dois_fatores_ativo'] === 1) {
            $erro = 'A autenticação em duas etapas já está ativada.';
        } else {
            $challenge = gameboxIssueTwoFactorCode($pdo, $uid, (string)$usuario['email'], 'activation', 600);
            if ($challenge['status'] === 'sent') {
                $_SESSION['2fa_configuracao_id'] = $uid;
                $_SESSION['2fa_configuracao_challenge'] = $challenge['challenge_token'];
                header('Location: dois-fatores.php?configurar=1');
                exit;
            }
            $erro = $challenge['status'] === 'rate_limited'
                ? 'Um código foi enviado recentemente. Aguarde ' . (int)$challenge['wait_seconds'] . ' segundos antes de solicitar outro.'
                : 'Não foi possível enviar o código. Verifique a configuração de e-mail do servidor e tente novamente.';
        }
    } elseif (isset($_POST['ativar'])) {
        $setupToken = is_string($_SESSION['2fa_configuracao_challenge'] ?? null)
            ? $_SESSION['2fa_configuracao_challenge']
            : '';
        $setupId = (int)($_SESSION['2fa_configuracao_id'] ?? 0);
        if ($setupId !== $uid || $setupToken === '') {
            $erro = 'O código expirou ou foi substituído. Solicite um novo para ativar o 2FA.';
        } else {
            $code = is_string($_POST['codigo'] ?? null) ? trim($_POST['codigo']) : '';
            $result = gameboxVerifyTwoFactorCode($pdo, $uid, $setupToken, $code, 'activation');
            if ($result['status'] === 'valid') {
                $pdo->prepare('UPDATE usuarios SET dois_fatores_ativo=1,dois_fatores_versao=dois_fatores_versao+1,dois_fatores_segredo=NULL WHERE id=?')->execute([$uid]);
                $_SESSION['2fa_verificado'] = $uid;
                $version = $pdo->prepare('SELECT dois_fatores_versao FROM usuarios WHERE id=?');
                $version->execute([$uid]);
                $_SESSION['2fa_verificado_versao'] = (int)$version->fetchColumn();
                $clearSetup();
                header('Location: dois-fatores.php?ativado=1');
                exit;
            }
            if ($result['status'] === 'invalid') {
                $erro = 'Código inválido. Restam ' . (int)$result['attempts_remaining'] . ' tentativa(s).';
            } else {
                gameboxInvalidateTwoFactorCode($pdo, $uid, $setupToken);
                $clearSetup();
                $erro = $result['status'] === 'expired'
                    ? 'O código expirou. Solicite um novo para ativar o 2FA.'
                    : 'O limite de tentativas foi atingido ou o código já foi substituído. Solicite outro.';
            }
        }
    } elseif (isset($_POST['desativar'])) {
        $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
        $senhaStmt = $pdo->prepare('SELECT senha FROM usuarios WHERE id=?');
        $senhaStmt->execute([$uid]);
        if (!password_verify($senha, (string)$senhaStmt->fetchColumn())) {
            $erro = 'Senha incorreta. O 2FA continua ativado.';
        } else {
            $pdo->prepare('UPDATE usuarios SET dois_fatores_ativo=0,dois_fatores_versao=dois_fatores_versao+1,dois_fatores_segredo=NULL WHERE id=?')->execute([$uid]);
            $pdo->prepare(
                'UPDATE two_factor_challenges
                 SET challenge_hash=NULL, code_hash=NULL, purpose=NULL, created_at=NULL, expires_at=NULL
                 WHERE usuario_id=?'
            )->execute([$uid]);
            unset($_SESSION['2fa_verificado'], $_SESSION['2fa_verificado_versao']);
            $clearSetup();
            $clearPending();
            header('Location: dois-fatores.php?desativado=1');
            exit;
        }
    }
}

$stmt = $pdo->prepare('SELECT nome,email,dois_fatores_ativo FROM usuarios WHERE id=?');
$stmt->execute([$uid]);
$usuario = $stmt->fetch();
$setupToken = is_string($_SESSION['2fa_configuracao_challenge'] ?? null)
    ? $_SESSION['2fa_configuracao_challenge']
    : '';
$setupId = (int)($_SESSION['2fa_configuracao_id'] ?? 0);
$setupPending = false;
if ($setupId === $uid && preg_match('/^[a-f0-9]{64}$/', $setupToken)) {
    $setupStmt = $pdo->prepare(
        'SELECT challenge_hash, purpose, code_hash, expires_at > NOW() AS is_unexpired, attempts
         FROM two_factor_challenges WHERE usuario_id=? LIMIT 1'
    );
    $setupStmt->execute([$uid]);
    $setupChallenge = $setupStmt->fetch();
    $setupPending = $setupChallenge
        && is_string($setupChallenge['challenge_hash'])
        && hash_equals($setupChallenge['challenge_hash'], hash('sha256', $setupToken))
        && $setupChallenge['purpose'] === 'activation'
        && is_string($setupChallenge['code_hash'])
        && (int)$setupChallenge['is_unexpired'] === 1
        && (int)$setupChallenge['attempts'] < 5;
}
if (!$setupPending && $setupId === $uid) {
    gameboxInvalidateTwoFactorCode($pdo, $uid, $setupToken);
    $clearSetup();
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Segurança — GameBox</title><link rel="stylesheet" href="style.css"></head><body><nav class="nav"><div class="container nav-inner"><a href="index.html" class="logo">Game<span>Box</span></a><div class="nav-links"><a href="perfil.php">Meu perfil</a></div></div></nav><main class="container profile-page"><section class="auth-card">
<?php if ($erro): ?><p class="auth-message" role="alert" style="color:var(--error)"><?= gameboxEscapeHtml($erro) ?></p><?php endif; ?>
<?php if ((int)$usuario['dois_fatores_ativo'] === 1): ?><p>O 2FA por e-mail está <strong>ativado</strong> para sua conta.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= gameboxEscapeHtml($csrf) ?>"><label for="senha">Confirme sua senha para desativar</label><input class="auth-input" id="senha" name="senha" type="password" autocomplete="current-password" required><button class="btn" type="submit" name="desativar">Desativar 2FA</button></form>
<?php elseif ($setupPending): ?><h2>Confirme seu e-mail</h2><p>Enviamos um código de 6 dígitos para o endereço cadastrado. Ele expira em 10 minutos.</p><?php if (gameboxIsLocalTwoFactorTestMode() && !empty($_SESSION['2fa_dev_code'])): ?><p class="form-feedback" role="status">Modo de teste local: seu código é <strong><?= gameboxEscapeHtml($_SESSION['2fa_dev_code']) ?></strong>.</p><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= gameboxEscapeHtml($csrf) ?>"><label for="codigo">Código de verificação</label><input class="auth-input" id="codigo" name="codigo" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required><button class="btn btn-primary" type="submit" name="ativar">Confirmar e ativar</button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= gameboxEscapeHtml($csrf) ?>"><button class="btn" type="submit" name="iniciar">Enviar outro código</button></form>
<?php else: ?><p>O 2FA está desativado. Ao ativá-lo, o login exigirá sua senha e um código enviado por e-mail.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= gameboxEscapeHtml($csrf) ?>"><button class="btn btn-primary" type="submit" name="iniciar">Configurar 2FA por e-mail</button></form><?php endif; ?><p class="auth-footer"><a href="perfil.php">Voltar ao perfil</a></p></section></main></body></html>
