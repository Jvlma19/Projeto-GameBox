<?php
session_start();
require 'db.php';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    $stmt = $pdo->prepare('SELECT id,nome,senha,foto_perfil FROM usuarios WHERE email=? LIMIT 1');
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($senha, $usuario['senha'])) $erro = 'E-mail ou senha incorretos.';
    else {
        session_regenerate_id(true);
        $_SESSION['usuario_id'] = (int)$usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];
        $_SESSION['usuario_foto'] = $usuario['foto_perfil'];
        header('Location: index.html');
        exit;
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
<?php if ($erro): ?><p class="auth-message" style="color:var(--error)"><?=htmlspecialchars($erro)?></p><?php endif; ?>
<form method="post">
<label>E-mail</label><input class="auth-input" name="email" type="email" required>
<label>Senha</label><input class="auth-input" name="senha" type="password" required>
<button class="btn btn-primary auth-button" type="submit">Entrar</button>
</form>
<p class="auth-footer">Ainda não tem conta? <a href="cadastro.php">Criar conta</a></p>
</section></main><script src="app.js"></script></body></html>