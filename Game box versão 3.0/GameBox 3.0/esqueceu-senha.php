<?php
require_once __DIR__ . '/app-core.php';
gameboxStartSession();
require __DIR__ . '/db.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$csrf = gameboxCsrfToken();
$erro = '';
$mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');

    if (!gameboxValidateCsrf()) {
        $erro = 'Sua sessão expirou. Atualize a página e tente novamente.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Digite um e-mail válido.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $usuarioId = $stmt->fetchColumn();

        if ($usuarioId !== false) {
            $recent = $pdo->prepare('SELECT id FROM password_reset_tokens WHERE usuario_id=? AND criado_em > DATE_SUB(NOW(), INTERVAL 1 MINUTE) LIMIT 1');
            $recent->execute([(int)$usuarioId]);

            if (!$recent->fetchColumn()) {
                $token = bin2hex(random_bytes(32));
                $url = gameboxPasswordResetUrl($token);

                if ($url !== null) {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('DELETE FROM password_reset_tokens WHERE usuario_id=?')->execute([(int)$usuarioId]);
                        $save = $pdo->prepare('INSERT INTO password_reset_tokens (usuario_id,token_hash,expira_em) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 60 MINUTE))');
                        $save->execute([(int)$usuarioId, hash('sha256', $token)]);
                        $pdo->commit();

                        gameboxSendPasswordResetEmail($email, $url);
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $exception;
                    }
                }
            }
        }

        $mensagem = 'Se este e-mail estiver cadastrado, enviaremos um link para redefinir sua senha. Verifique também a pasta de spam.';
    }
}
$h = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="pt-BR"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recuperar senha — GameBox</title><link rel="stylesheet" href="style.css"></head>
<body>
<nav class="nav"><div class="container nav-inner">
<a href="index.html" class="logo">Game<span>Box</span></a>
<div class="nav-links"><a href="index.html">Início</a><a href="login.php">Entrar</a></div>
</div></nav>
<main class="auth-page"><section class="auth-card">
<div class="auth-brand">Game<span>Box</span></div><h1>Recuperar senha</h1>
<p class="auth-subtitle">Informe o e-mail da sua conta para receber um link seguro de redefinição.</p>
<?php if ($erro): ?><p class="auth-message" role="alert" style="color:var(--error)"><?= $h($erro) ?></p><?php endif; ?>
<?php if ($mensagem): ?><p class="auth-message" role="status"><?= $h($mensagem) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
<label for="email">E-mail</label><input class="auth-input" id="email" name="email" type="email" autocomplete="email" required>
<button class="btn btn-primary auth-button" type="submit">Enviar link de recuperação</button>
</form>
<p class="auth-footer"><a href="login.php">Voltar ao login</a></p>
</section></main><script src="app.js"></script></body></html>
