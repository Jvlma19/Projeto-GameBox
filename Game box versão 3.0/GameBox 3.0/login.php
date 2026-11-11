<?php
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
        $stmt = $pdo->prepare('SELECT id,nome,email,senha,foto_perfil,dois_fatores_ativo,bloqueado FROM usuarios WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if (!$usuario || !password_verify($senha, $usuario['senha'])) {
            $erro = 'E-mail ou senha incorretos.';
        } elseif ((int)$usuario['bloqueado'] === 1) {
            $erro = 'Esta conta foi bloqueada. Entre em contato com a administração.';
        } elseif ((int)$usuario['dois_fatores_ativo'] === 1) {
            $codigo = (string)random_int(100000, 999999);
            if (!gameboxSendTwoFactorCode((string)$usuario['email'], $codigo)) {
                $erro = 'Não foi possível enviar o código. Verifique a configuração de e-mail do servidor e tente novamente.';
            } else {
                session_regenerate_id(true);
                unset($_SESSION['usuario_id'], $_SESSION['usuario_nome'], $_SESSION['usuario_foto']);
                $_SESSION['2fa_pendente_id'] = (int)$usuario['id'];
                $_SESSION['2fa_pendente_hash'] = password_hash($codigo, PASSWORD_DEFAULT);
                $_SESSION['2fa_pendente_em'] = time();
                $_SESSION['2fa_pendente_tentativas'] = 0;
                header('Location: dois-fatores.php?acao=verificar');
                exit;
            }
        } else {
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = (int)$usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];
            $_SESSION['usuario_foto'] = $usuario['foto_perfil'];
            header('Location: index.html');
            exit;
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
<?php if ($erro): ?><p class="auth-message" role="alert" style="color:var(--error)"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<label for="email">E-mail</label><input class="auth-input" id="email" name="email" type="email" autocomplete="email" required>
<label for="senha">Senha</label><input class="auth-input" id="senha" name="senha" type="password" autocomplete="current-password" required>
<button class="btn btn-primary auth-button" type="submit">Entrar</button>
</form>
<p class="auth-footer"><a href="esqueceu-senha.php">Esqueceu a senha?</a></p>
<p class="auth-footer">Ainda não tem uma conta? <a href="cadastro.php">Criar conta</a></p>
</section></main><script src="app.js"></script></body></html>
