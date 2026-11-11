<?php
require_once __DIR__ . '/app-core.php';
gameboxStartSession();
require __DIR__ . '/db.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$h = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$token = is_string($_POST['token'] ?? null)
    ? $_POST['token']
    : (is_string($_GET['token'] ?? null) ? $_GET['token'] : '');
$tokenValido = preg_match('/^[a-f0-9]{64}$/', $token) === 1;
$csrf = gameboxCsrfToken();
$erro = '';
$concluido = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
    $confirmar = is_string($_POST['confirmar'] ?? null) ? $_POST['confirmar'] : '';

    if (!gameboxValidateCsrf()) {
        $erro = 'Sua sessão expirou. Atualize a página e tente novamente.';
    } elseif (strlen($senha) < 6) {
        $erro = 'A senha precisa ter pelo menos 6 caracteres.';
    } elseif ($senha !== $confirmar) {
        $erro = 'As senhas não coincidem.';
    } elseif (!$tokenValido) {
        $erro = 'Este link de redefinição é inválido ou expirou. Solicite outro link.';
    } else {
        $pdo->beginTransaction();
        try {
            $lookup = $pdo->prepare('SELECT usuario_id FROM password_reset_tokens WHERE token_hash=? AND expira_em > NOW() LIMIT 1 FOR UPDATE');
            $lookup->execute([hash('sha256', $token)]);
            $usuarioId = $lookup->fetchColumn();

            if ($usuarioId === false) {
                $pdo->rollBack();
                $erro = 'Este link de redefinição é inválido ou expirou. Solicite outro link.';
            } else {
                $update = $pdo->prepare('UPDATE usuarios SET senha=? WHERE id=?');
                $update->execute([password_hash($senha, PASSWORD_DEFAULT), (int)$usuarioId]);
                $pdo->prepare('DELETE FROM password_reset_tokens WHERE usuario_id=?')->execute([(int)$usuarioId]);
                $pdo->commit();
                $concluido = true;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
}

$tokenValido = $tokenValido && !$concluido;
if ($tokenValido && !$concluido) {
    $check = $pdo->prepare('SELECT id FROM password_reset_tokens WHERE token_hash=? AND expira_em > NOW() LIMIT 1');
    $check->execute([hash('sha256', $token)]);
    $tokenValido = (bool)$check->fetchColumn();
}
?>
<!doctype html><html lang="pt-BR"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Redefinir senha — GameBox</title><link rel="stylesheet" href="style.css"></head>
<body>
<main class="auth-page"><section class="auth-card">
<div class="auth-brand">Game<span>Box</span></div><h1>Redefinir senha</h1>
<?php if ($concluido): ?>
<p class="auth-message" role="status">Sua senha foi alterada. Agora você já pode entrar com a nova senha.</p>
<p class="auth-footer"><a href="login.php">Ir para o login</a></p>
<?php elseif (!$tokenValido): ?>
<p class="auth-message" role="alert" style="color:var(--error)"><?= $h($erro ?: 'Este link de redefinição é inválido ou expirou. Solicite outro link.') ?></p>
<p class="auth-footer"><a href="esqueceu-senha.php">Solicitar novo link</a></p>
<?php else: ?>
<p class="auth-subtitle">Crie uma nova senha para sua conta. O link é válido por 60 minutos e pode ser usado uma única vez.</p>
<?php if ($erro): ?><p class="auth-message" role="alert" style="color:var(--error)"><?= $h($erro) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
<input type="hidden" name="token" value="<?= $h($token) ?>">
<label for="senha">Nova senha</label><input class="auth-input" id="senha" name="senha" type="password" minlength="6" autocomplete="new-password" required>
<label for="confirmar">Confirmar nova senha</label><input class="auth-input" id="confirmar" name="confirmar" type="password" minlength="6" autocomplete="new-password" required>
<button class="btn btn-primary auth-button" type="submit">Alterar senha</button>
</form>
<?php endif; ?>
</section></main>
</body></html>
