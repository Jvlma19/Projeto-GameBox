<?php
function gameboxStartSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure', '1');
    session_start();
}

function gameboxClearAuthenticationSession(): void {
    unset(
        $_SESSION['usuario_id'],
        $_SESSION['usuario_nome'],
        $_SESSION['usuario_foto'],
        $_SESSION['2fa_verificado'],
        $_SESSION['2fa_verificado_versao'],
        $_SESSION['2fa_pendente_id'],
        $_SESSION['2fa_pendente_challenge'],
        $_SESSION['2fa_configuracao_id'],
        $_SESSION['2fa_configuracao_challenge'],
        $_SESSION['csrf_token'],
        $_SESSION['2fa_dev_code']
    );
}

function gameboxEnableJsonApiErrors(): void {
    static $enabled = false;
    if ($enabled) return;
    $enabled = true;

    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ob_start();
    $GLOBALS['json_api_response_sent'] = false;

    set_exception_handler(static function (Throwable $error): void {
        error_log('Unhandled GameBox API exception: ' . $error);
        gameboxJsonApiErrorResponse(500, 'Erro interno ao processar a solicitação. Confira o log do PHP.');
    });

    register_shutdown_function(static function (): void {
        if ($GLOBALS['json_api_response_sent']) return;
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;

        error_log(sprintf('Fatal GameBox API error: %s in %s:%d', $error['message'], $error['file'], $error['line']));
        gameboxJsonApiErrorResponse(500, 'Erro interno no servidor. Confira o log do PHP.');
    });
}

function gameboxJsonApiErrorResponse(int $status, string $message): void {
    if (!empty($GLOBALS['json_api_response_sent'])) return;
    $GLOBALS['json_api_response_sent'] = true;
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['erro' => $message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function gameboxBase32Encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $char) $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function gameboxBase32Decode(string $value): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value));
    $bits = '';
    foreach (str_split($value) as $char) {
        $position = strpos($alphabet, $char);
        if ($position !== false) $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function gameboxTotp(string $secret, ?int $timestamp = null): string {
    $counter = intdiv($timestamp ?? time(), 30);
    $key = gameboxBase32Decode($secret);
    $binaryCounter = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $binaryCounter, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function gameboxVerifyTotp(string $secret, string $code): bool {
    if (!preg_match('/^\d{6}$/', $code)) return false;
    for ($offset = -1; $offset <= 1; $offset++) {
        if (hash_equals(gameboxTotp($secret, time() + ($offset * 30)), $code)) return true;
    }
    return false;
}

function gameboxTextLength(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function gameboxTextSlice(string $value, int $start, int $length): string {
    return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
}

function gameboxEscapeHtml(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function gameboxCsrfToken(): string {
    if (!isset($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
        || !preg_match('/^[a-f0-9]{64}$/', $_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function gameboxValidateCsrf(): bool {
    $sessionToken = $_SESSION['csrf_token'] ?? null;
    $received = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    return is_string($sessionToken)
        && is_string($received)
        && hash_equals($sessionToken, $received);
}

function gameboxIsLocalRequest(): bool {
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $serverName = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
    $isLoopback = in_array($remoteAddress, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
    $isLocalHost = in_array($serverName, ['localhost', '127.0.0.1', '::1'], true);
    return $isLoopback && $isLocalHost;
}

function gameboxIsLocalTwoFactorTestMode(): bool {
    return getenv('GAMEBOX_2FA_DEV_MODE') === '1' && gameboxIsLocalRequest();
}

function gameboxSendTwoFactorCode(string $email, string $code, int $ttlSeconds = 300): bool {
    if (gameboxIsLocalTwoFactorTestMode()) {
        $_SESSION['2fa_dev_code'] = $code;
        error_log('GameBox 2FA is using the localhost-only development code display.');
        return true;
    }

    $from = getenv('GAMEBOX_MAIL_FROM');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[0-9]{6}$/D', $code)
        || $ttlSeconds < 60 || $ttlSeconds > 3600) {
        error_log('GameBox 2FA email not sent: recipient or code is invalid.');
        return false;
    }
    if (!is_string($from) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        error_log('GameBox 2FA email not sent: GAMEBOX_MAIL_FROM is missing or invalid.');
        return false;
    }
    if (!function_exists('mail')) {
        error_log('GameBox 2FA email not sent: PHP mail() is disabled or unavailable.');
        return false;
    }

    $subject = 'Codigo de acesso - GameBox';
    $expirationMinutes = (int)ceil($ttlSeconds / 60);
    $unit = $expirationMinutes === 1 ? 'minuto' : 'minutos';
    $message = "Seu codigo de verificacao do GameBox e: {$code}\r\n\r\n"
        . "Ele expira em {$expirationMinutes} {$unit}. Se voce nao tentou entrar, ignore este e-mail.";
    $headers = [
        'From: GameBox <' . $from . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];
    if (!@mail($email, $subject, $message, implode("\r\n", $headers))) {
        error_log('GameBox 2FA email delivery failed.');
        return false;
    }
    return true;
}

function gameboxIssueTwoFactorCode(PDO $pdo, int $userId, string $email, string $purpose, int $ttlSeconds): array {
    if ($userId < 1 || !in_array($purpose, ['login', 'activation'], true) || $ttlSeconds < 60 || $ttlSeconds > 3600) {
        throw new InvalidArgumentException('Invalid GameBox two-factor challenge parameters.');
    }

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO two_factor_challenges (usuario_id) VALUES (?)
             ON DUPLICATE KEY UPDATE usuario_id=usuario_id'
        );
        $insert->execute([$userId]);

        $stmt = $pdo->prepare(
            'SELECT last_sent_at, request_window_started_at, sends_in_window,
                    COALESCE(TIMESTAMPDIFF(SECOND, last_sent_at, NOW()), 60) AS seconds_since_send,
                    TIMESTAMPDIFF(SECOND, request_window_started_at, NOW()) AS window_age
             FROM two_factor_challenges WHERE usuario_id=? FOR UPDATE'
        );
        $stmt->execute([$userId]);
        $limits = $stmt->fetch();
        $secondsSinceSend = (int)$limits['seconds_since_send'];
        $windowAge = (int)$limits['window_age'];
        $sendsInWindow = (int)$limits['sends_in_window'];

        if ($limits['last_sent_at'] !== null && $secondsSinceSend < 60) {
            $pdo->rollBack();
            return ['status' => 'rate_limited', 'wait_seconds' => 60 - $secondsSinceSend];
        }
        if ($windowAge < 900 && $sendsInWindow >= 5) {
            $pdo->rollBack();
            return ['status' => 'rate_limited', 'wait_seconds' => max(1, 900 - $windowAge)];
        }

        $resetWindow = $windowAge >= 900;
        $sendCount = $resetWindow ? 1 : $sendsInWindow + 1;
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challengeToken = bin2hex(random_bytes(32));
        $challengeHash = hash('sha256', $challengeToken);
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        $update = $pdo->prepare(
            'UPDATE two_factor_challenges
             SET challenge_hash=?, code_hash=?, purpose=?, created_at=NOW(),
                 expires_at=DATE_ADD(NOW(), INTERVAL ' . $ttlSeconds . ' SECOND),
                 attempts=0, last_sent_at=NOW(),
                 request_window_started_at=IF(?, NOW(), request_window_started_at),
                 sends_in_window=?
             WHERE usuario_id=?'
        );
        $update->execute([$challengeHash, $codeHash, $purpose, $resetWindow ? 1 : 0, $sendCount, $userId]);

        try {
            $sent = gameboxSendTwoFactorCode($email, $code, $ttlSeconds);
        } catch (Throwable $exception) {
            error_log('GameBox 2FA email sending raised an exception.');
            $sent = false;
        }

        if (!$sent) {
            unset($_SESSION['2fa_dev_code']);
            $invalidate = $pdo->prepare(
                'UPDATE two_factor_challenges
                 SET challenge_hash=NULL, code_hash=NULL, purpose=NULL, created_at=NULL, expires_at=NULL
                 WHERE usuario_id=? AND challenge_hash=?'
            );
            $invalidate->execute([$userId, $challengeHash]);
            $pdo->commit();
            return ['status' => 'email_failed'];
        }

        $pdo->commit();
        return ['status' => 'sent', 'challenge_token' => $challengeToken];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function gameboxVerifyTwoFactorCode(PDO $pdo, int $userId, string $challengeToken, string $code, string $purpose): array {
    if ($userId < 1 || !preg_match('/^[a-f0-9]{64}$/', $challengeToken)
        || !in_array($purpose, ['login', 'activation'], true)) {
        return ['status' => 'stale'];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT challenge_hash, code_hash, purpose, expires_at, attempts,
                    expires_at > NOW() AS is_unexpired
             FROM two_factor_challenges WHERE usuario_id=? FOR UPDATE'
        );
        $stmt->execute([$userId]);
        $challenge = $stmt->fetch();
        $tokenHash = hash('sha256', $challengeToken);

        if (!$challenge || !is_string($challenge['challenge_hash'])
            || !hash_equals($challenge['challenge_hash'], $tokenHash)
            || $challenge['purpose'] !== $purpose) {
            $pdo->rollBack();
            return ['status' => 'stale'];
        }

        if ((int)$challenge['is_unexpired'] !== 1 || !is_string($challenge['code_hash'])) {
            $invalidate = $pdo->prepare(
                'UPDATE two_factor_challenges
                 SET challenge_hash=NULL, code_hash=NULL, purpose=NULL, created_at=NULL, expires_at=NULL
                 WHERE usuario_id=? AND challenge_hash=?'
            );
            $invalidate->execute([$userId, $tokenHash]);
            $pdo->commit();
            return ['status' => 'expired'];
        }

        $attempts = (int)$challenge['attempts'];
        if ($attempts >= 5) {
            $pdo->rollBack();
            return ['status' => 'attempts_exceeded'];
        }

        if (preg_match('/^[0-9]{6}$/D', $code) && password_verify($code, $challenge['code_hash'])) {
            $consume = $pdo->prepare(
                'UPDATE two_factor_challenges
                 SET challenge_hash=NULL, code_hash=NULL, purpose=NULL, created_at=NULL, expires_at=NULL
                 WHERE usuario_id=? AND challenge_hash=?'
            );
            $consume->execute([$userId, $tokenHash]);
            $pdo->commit();
            return ['status' => 'valid'];
        }

        $attempts++;
        if ($attempts >= 5) {
            $failed = $pdo->prepare(
                'UPDATE two_factor_challenges
                 SET attempts=?, challenge_hash=NULL, code_hash=NULL, purpose=NULL, created_at=NULL, expires_at=NULL
                 WHERE usuario_id=? AND challenge_hash=?'
            );
            $failed->execute([$attempts, $userId, $tokenHash]);
            $pdo->commit();
            return ['status' => 'attempts_exceeded'];
        }

        $failed = $pdo->prepare(
            'UPDATE two_factor_challenges SET attempts=? WHERE usuario_id=? AND challenge_hash=?'
        );
        $failed->execute([$attempts, $userId, $tokenHash]);
        $pdo->commit();
        return ['status' => 'invalid', 'attempts_remaining' => 5 - $attempts];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function gameboxInvalidateTwoFactorCode(PDO $pdo, int $userId, string $challengeToken): void {
    if ($userId < 1 || !preg_match('/^[a-f0-9]{64}$/', $challengeToken)) {
        return;
    }
    $stmt = $pdo->prepare(
        'UPDATE two_factor_challenges
         SET challenge_hash=NULL, code_hash=NULL, purpose=NULL, created_at=NULL, expires_at=NULL
         WHERE usuario_id=? AND challenge_hash=?'
    );
    $stmt->execute([$userId, hash('sha256', $challengeToken)]);
}

function gameboxPasswordResetUrl(string $token): ?string {
    $configuredUrl = getenv('GAMEBOX_APP_URL');
    if (is_string($configuredUrl) && $configuredUrl !== '') {
        $parts = parse_url($configuredUrl);
        if (!is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            error_log('GameBox password reset link not created: GAMEBOX_APP_URL is invalid.');
            return null;
        }
        $baseUrl = rtrim($configuredUrl, '/');
    } else {
        $host = (string)($_SERVER['SERVER_NAME'] ?? '');
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $host = '[' . $host . ']';
        } elseif (!preg_match('/^[a-zA-Z0-9.-]+$/', $host)) {
            error_log('GameBox password reset link not created: server name is invalid.');
            return null;
        }

        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $https ? 'https' : 'http';
        $port = (int)($_SERVER['SERVER_PORT'] ?? ($https ? 443 : 80));
        $portSuffix = ($https && $port !== 443) || (!$https && $port !== 80) ? ':' . $port : '';
        $directory = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
        $path = $directory === '/' || $directory === '.' ? '' : rtrim($directory, '/');
        $baseUrl = $scheme . '://' . $host . $portSuffix . $path;
    }

    return $baseUrl . '/redefinir-senha.php?token=' . rawurlencode($token);
}

function gameboxSendPasswordResetEmail(string $email, string $resetUrl): bool {
    $from = getenv('GAMEBOX_MAIL_FROM');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($from) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        error_log('GameBox password reset email not sent: recipient or GAMEBOX_MAIL_FROM is invalid.');
        return false;
    }
    if (!function_exists('mail')) {
        error_log('GameBox password reset email not sent: PHP mail() is disabled or unavailable.');
        return false;
    }

    $subject = 'Redefinicao de senha - GameBox';
    $message = "Recebemos uma solicitacao para redefinir sua senha do GameBox.\r\n\r\n"
        . "Acesse o link abaixo em ate 60 minutos para criar uma nova senha:\r\n{$resetUrl}\r\n\r\n"
        . "Se voce nao solicitou a redefinicao, ignore este e-mail.";
    $headers = [
        'From: GameBox <' . $from . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];
    if (!mail($email, $subject, $message, implode("\r\n", $headers))) {
        error_log('GameBox password reset email delivery failed.');
        return false;
    }
    return true;
}

function gameboxRequireActiveAccount(PDO $pdo, bool $jsonResponse = false): ?array {
    $userId = (int)($_SESSION['usuario_id'] ?? 0);
    if ($userId < 1) {
        if ($jsonResponse) {
            http_response_code(401);
            echo json_encode(['erro' => 'Faça login para continuar.']);
        } else {
            header('Location: login.php');
        }
        exit;
    }

    $stmt = $pdo->prepare('SELECT id,nome,papel,bloqueado,dois_fatores_ativo,dois_fatores_versao FROM usuarios WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $account = $stmt->fetch();
    if (!$account || (int)$account['bloqueado'] === 1) {
        unset($_SESSION['usuario_id'], $_SESSION['usuario_nome'], $_SESSION['usuario_foto'], $_SESSION['2fa_verificado'], $_SESSION['2fa_verificado_versao']);
        session_regenerate_id(true);
        if ($jsonResponse) {
            http_response_code(403);
            echo json_encode(['erro' => 'Esta conta está bloqueada ou indisponível.']);
        } else {
            header('Location: login.php?bloqueado=1');
        }
        exit;
    }
    if ((int)$account['dois_fatores_ativo'] === 1
        && ((int)($_SESSION['2fa_verificado'] ?? 0) !== $userId
            || (int)($_SESSION['2fa_verificado_versao'] ?? -1) !== (int)$account['dois_fatores_versao'])) {
        unset($_SESSION['usuario_id'], $_SESSION['usuario_nome'], $_SESSION['usuario_foto'], $_SESSION['2fa_verificado'], $_SESSION['2fa_verificado_versao']);
        session_regenerate_id(true);
        if ($jsonResponse) {
            http_response_code(401);
            echo json_encode(['logado' => false, 'erro' => 'Conclua a verificação em duas etapas para continuar.']);
        } else {
            header('Location: login.php?2fa=necessario');
        }
        exit;
    }
    return $account;
}
