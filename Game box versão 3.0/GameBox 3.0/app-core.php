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

function gameboxCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function gameboxValidateCsrf(): bool {
    $received = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    return is_string($received) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $received);
}

function gameboxIsLocalTwoFactorTestMode(): bool {
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $serverName = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
    $isLoopback = in_array($remoteAddress, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
    $isLocalHost = in_array($serverName, ['localhost', '127.0.0.1', '::1'], true);
    return getenv('GAMEBOX_2FA_DEV_MODE') === '1' && $isLoopback && $isLocalHost;
}

function gameboxSendTwoFactorCode(string $email, string $code): bool {
    if (gameboxIsLocalTwoFactorTestMode()) {
        $_SESSION['2fa_dev_code'] = $code;
        error_log('GameBox 2FA is using the localhost-only development code display.');
        return true;
    }

    $from = getenv('GAMEBOX_MAIL_FROM');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
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
    $message = "Seu codigo de verificacao do GameBox e: {$code}\r\n\r\n"
        . "Ele expira em 5 minutos. Se voce nao tentou entrar, ignore este e-mail.";
    $headers = [
        'From: GameBox <' . $from . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];
    if (!mail($email, $subject, $message, implode("\r\n", $headers))) {
        error_log('GameBox 2FA email delivery failed.');
        return false;
    }
    return true;
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

    $stmt = $pdo->prepare('SELECT id,nome,papel,bloqueado FROM usuarios WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $account = $stmt->fetch();
    if (!$account || (int)$account['bloqueado'] === 1) {
        unset($_SESSION['usuario_id'], $_SESSION['usuario_nome'], $_SESSION['usuario_foto']);
        session_regenerate_id(true);
        if ($jsonResponse) {
            http_response_code(403);
            echo json_encode(['erro' => 'Esta conta está bloqueada ou indisponível.']);
        } else {
            header('Location: login.php?bloqueado=1');
        }
        exit;
    }
    return $account;
}
