<?php
require_once __DIR__ . '/app-core.php';
gameboxStartSession();
require __DIR__ . '/db.php';
$erro = '';
$csrf = gameboxCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim(is_string($_POST['nome'] ?? null) ? $_POST['nome'] : '');
    $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
    $confirmar = is_string($_POST['confirmar'] ?? null) ? $_POST['confirmar'] : '';

    if (!gameboxValidateCsrf()) $erro = 'Sua sessão expirou. Atualize a página e tente novamente.';
    elseif (gameboxTextLength($nome) < 3 || gameboxTextLength($nome) > 50) $erro = 'O nome precisa ter entre 3 e 50 caracteres.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erro = 'Digite um e-mail válido.';
    elseif (strlen($senha) < 6) $erro = 'A senha precisa ter pelo menos 6 caracteres.';
    elseif ($senha !== $confirmar) $erro = 'As senhas não coincidem.';
    else {
        $check = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? OR nome_normalizado = LOWER(TRIM(?)) LIMIT 1');
        $check->execute([$email, $nome]);

        if ($check->fetch()) $erro = 'Esse e-mail ou nome de usuário já está cadastrado.';
        else {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            try {
                $stmt = $pdo->prepare('INSERT INTO usuarios (nome,email,senha) VALUES (?,?,?)');
                $stmt->execute([$nome,$email,$hash]);
                session_regenerate_id(true);
                gameboxClearAuthenticationSession();
                $_SESSION['usuario_id'] = (int)$pdo->lastInsertId();
                $_SESSION['usuario_nome'] = $nome;
                $_SESSION['usuario_foto'] = null;
                gameboxCsrfToken();
                header('Location: index.html');
                exit;
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }
                $erro = 'Esse e-mail ou nome de usuário já está cadastrado.';
            }
        }
    }
}
?>
<!doctype html><html lang="pt-BR"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Criar conta — GameBox</title><link rel="stylesheet" href="style.css"></head>
<body>
<nav class="nav"><div class="container nav-inner">
<a href="index.html" class="logo">Game<span>Box</span></a>
<div class="nav-links"><a href="index.html">Início</a><a href="login.php">Entrar</a></div>
</div></nav>
<main class="auth-page"><section class="auth-card">
<div class="auth-brand">Game<span>Box</span></div><h1>Criar sua conta</h1>
<p class="auth-subtitle">Monte seu perfil e comece a registrar seus jogos.</p>
<?php if ($erro): ?><p class="auth-message" style="color:var(--error)"><?=gameboxEscapeHtml($erro)?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= gameboxEscapeHtml($csrf) ?>">
<label>Nome de usuário</label><input class="auth-input" name="nome" minlength="3" required>
<label>E-mail</label><input class="auth-input" name="email" type="email" required>
<label>Senha</label><input class="auth-input" name="senha" type="password" minlength="6" required>
<label>Confirmar senha</label><input class="auth-input" name="confirmar" type="password" minlength="6" required>
<button class="btn btn-primary auth-button" type="submit">Criar conta</button>
</form>
<p class="auth-footer">Já tem uma conta? <a href="login.php">Entrar</a></p>
</section></main><script src="app.js"></script></body></html>