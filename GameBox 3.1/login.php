<?php
const GAMEBOX_LOGIN_MAX_ACCOUNT_IP_FAILURES = 5;
const GAMEBOX_LOGIN_MAX_IP_FAILURES = 60;
const GAMEBOX_LOGIN_WINDOW_MINUTES = 15;

function gameboxLoginThrottleMessage(int $waitMinutes): string {
    $unit = $waitMinutes === 1 ? 'minuto' : 'minutos';
    return 'Muitas tentativas de login. Aguarde aproximadamente '
        . $waitMinutes . ' ' . $unit . ' e tente novamente.';
}

require_once __DIR__ . '/app-core.php';
gameboxStartSession();
require __DIR__ . '/db.php';
$erro = '';
$csrf = gameboxCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';

    if (!gameboxValidateCsrf()) {
        $erro = 'Sua sessão expirou. Atualize a página e tente novamente.';
    } else {
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $packedIp = filter_var($remoteIp, FILTER_VALIDATE_IP) ? inet_pton($remoteIp) : false;
        $clientIp = $packedIp === false ? false : inet_ntop($packedIp);

        if (!is_string($clientIp) || $clientIp === '') {
            error_log('GameBox login rejected because the client IP address was invalid.');
            $erro = 'Não foi possível verificar a origem da solicitação. Tente novamente.';
        } else {
            $accountHash = hash('sha256', strtolower($email));
            $lockName = 'gb_login_' . substr(hash('sha256', $clientIp), 0, 54);
            // Serialize attempts from this IP so concurrent requests cannot bypass the limits.
            $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 2)');
            $lockStmt->execute([$lockName]);

            if ((int)$lockStmt->fetchColumn() !== 1) {
                $erro = 'O login está recebendo muitas solicitações. Aguarde alguns segundos e tente novamente.';
            } else {
                $usuarioAutenticado = null;
                try {
                    $window = GAMEBOX_LOGIN_WINDOW_MINUTES;
                    $accountAttemptsStmt = $pdo->prepare(
                        'SELECT COUNT(*) AS failures,
                                COALESCE(GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(),
                                    DATE_ADD(MAX(attempted_at), INTERVAL ' . $window . ' MINUTE))), 0) AS retry_seconds
                         FROM login_attempts
                         WHERE account_hash=? AND ip_address=?
                           AND attempted_at >= DATE_SUB(NOW(), INTERVAL ' . $window . ' MINUTE)'
                    );
                    $accountAttemptsStmt->execute([$accountHash, $clientIp]);
                    $accountAttempts = $accountAttemptsStmt->fetch();

                    $ipAttemptsStmt = $pdo->prepare(
                        'SELECT COUNT(*) AS failures,
                                COALESCE(GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(),
                                    DATE_ADD(MAX(attempted_at), INTERVAL ' . $window . ' MINUTE))), 0) AS retry_seconds
                         FROM login_attempts
                         WHERE ip_address=?
                           AND attempted_at >= DATE_SUB(NOW(), INTERVAL ' . $window . ' MINUTE)'
                    );
                    $ipAttemptsStmt->execute([$clientIp]);
                    $ipAttempts = $ipAttemptsStmt->fetch();

                    $accountFailureCount = (int)$accountAttempts['failures'];
                    $ipFailureCount = (int)$ipAttempts['failures'];
                    if ($accountFailureCount >= GAMEBOX_LOGIN_MAX_ACCOUNT_IP_FAILURES) {
                        $waitMinutes = max(1, (int)ceil((int)$accountAttempts['retry_seconds'] / 60));
                        $erro = gameboxLoginThrottleMessage($waitMinutes);
                    } elseif ($ipFailureCount >= GAMEBOX_LOGIN_MAX_IP_FAILURES) {
                        $waitMinutes = max(1, (int)ceil((int)$ipAttempts['retry_seconds'] / 60));
                        $erro = gameboxLoginThrottleMessage($waitMinutes);
                    } else {
                        $stmt = $pdo->prepare('SELECT id,nome,email,senha,foto_perfil,papel,dois_fatores_ativo,bloqueado FROM usuarios WHERE email=? LIMIT 1');
                        $stmt->execute([$email]);
                        $usuario = $stmt->fetch();

                        if (!$usuario || !password_verify($senha, $usuario['senha'])) {
                            $saveAttempt = $pdo->prepare('INSERT INTO login_attempts (account_hash,ip_address) VALUES (?,?)');
                            $saveAttempt->execute([$accountHash, $clientIp]);

                            $cleanup = $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 100');
                            $cleanup->execute();

                            if ($accountFailureCount + 1 >= GAMEBOX_LOGIN_MAX_ACCOUNT_IP_FAILURES
                                || $ipFailureCount + 1 >= GAMEBOX_LOGIN_MAX_IP_FAILURES) {
                                $erro = gameboxLoginThrottleMessage(GAMEBOX_LOGIN_WINDOW_MINUTES);
                            } else {
                                $erro = 'E-mail ou senha incorretos.';
                            }
                        } elseif ((int)$usuario['bloqueado'] === 1) {
                            $erro = 'Esta conta foi bloqueada. Entre em contato com a administração.';
                        } else {
                            $clearAttempts = $pdo->prepare('DELETE FROM login_attempts WHERE account_hash=? AND ip_address=?');
                            $clearAttempts->execute([$accountHash, $clientIp]);
                            $usuarioAutenticado = $usuario;
                        }
                    }
                } finally {
                    $releaseLock = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $releaseLock->execute([$lockName]);
                }

                if ($usuarioAutenticado !== null && (int)$usuarioAutenticado['dois_fatores_ativo'] === 1) {
                    session_regenerate_id(true);
                    gameboxClearAuthenticationSession();
                    $csrf = gameboxCsrfToken();
                    $challenge = gameboxIssueTwoFactorCode(
                        $pdo,
                        (int)$usuarioAutenticado['id'],
                        (string)$usuarioAutenticado['email'],
                        'login',
                        300
                    );
                    if ($challenge['status'] === 'rate_limited') {
                        $erro = 'Um código foi enviado recentemente. Aguarde '
                            . (int)$challenge['wait_seconds'] . ' segundos antes de solicitar outro.';
                    } elseif ($challenge['status'] !== 'sent') {
                        $erro = 'Não foi possível enviar o código. Verifique a configuração de e-mail do servidor e tente novamente.';
                    } else {
                        $_SESSION['2fa_pendente_id'] = (int)$usuarioAutenticado['id'];
                        $_SESSION['2fa_pendente_challenge'] = $challenge['challenge_token'];
                        header('Location: dois-fatores.php?acao=verificar');
                        exit;
                    }
                } elseif ($usuarioAutenticado !== null) {
                    session_regenerate_id(true);
                    gameboxClearAuthenticationSession();
                    $_SESSION['usuario_id'] = (int)$usuarioAutenticado['id'];
                    $_SESSION['usuario_nome'] = $usuarioAutenticado['nome'];
                    $_SESSION['usuario_foto'] = $usuarioAutenticado['foto_perfil'];
                    $csrf = gameboxCsrfToken();
                    header('Location: ' . ($usuarioAutenticado['papel'] === 'master' ? 'admin.php' : 'index.html'));
                    exit;
                }
            }
        }
    }
}
?>
<!doctype html><html lang="pt-BR"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Entrar — GameBox</title><link rel="stylesheet" href="style.css"></head>
<body>
<nav class="nav"><div class="container nav-inner">
<a href="index.html" class="logo">Game<span>Box</span></a>
<div class="nav-links"><a href="index.html">Início</a><a href="cadastro.php">Cadastrar</a></div>
</div></nav>
<main class="auth-page"><section class="auth-card">
<div class="auth-brand">Game<span>Box</span></div><h1>Bem-vindo de volta</h1>
<p class="auth-subtitle">Entre para acompanhar seu diário de jogos.</p>
<?php if ($erro): ?><p class="auth-message" role="alert" style="color:var(--error)"><?= gameboxEscapeHtml($erro) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= gameboxEscapeHtml($csrf) ?>">
<label for="email">E-mail</label><input class="auth-input" id="email" name="email" type="email" autocomplete="email" required>
<label for="senha">Senha</label><input class="auth-input" id="senha" name="senha" type="password" autocomplete="current-password" required>
<button class="btn btn-primary auth-button" type="submit">Entrar</button>
</form>
<p class="auth-footer"><a href="esqueceu-senha.php">Esqueceu a senha?</a></p>
<p class="auth-footer">Ainda não tem uma conta? <a href="cadastro.php">Criar conta</a></p>
</section></main><script src="app.js"></script></body></html>
