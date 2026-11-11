<?php
require_once __DIR__ . '/app-core.php';
gameboxStartSession();
require __DIR__ . '/db.php';

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$serverName = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
$isLoopback = in_array($remoteAddress, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
$isLocalHost = in_array($serverName, ['localhost', '127.0.0.1', '::1'], true);
if (!$isLoopback || !$isLocalHost) {
    http_response_code(404);
    exit('Não encontrado.');
}

$erro = '';
$sucesso = '';
$csrf = gameboxCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim(is_string($_POST['nome'] ?? null) ? $_POST['nome'] : '');
    $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
    $confirmar = is_string($_POST['confirmar'] ?? null) ? $_POST['confirmar'] : '';

    if (!gameboxValidateCsrf()) {
        $erro = 'A sessão expirou. Atualize a página e tente novamente.';
    } elseif (strlen($nome) < 3) {
        $erro = 'O nome precisa ter pelo menos 3 caracteres.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Digite um e-mail válido.';
    } elseif ($senha === '' || $senha !== $confirmar) {
        $erro = 'Confira a senha e a confirmação.';
    } else {
        $lock = $pdo->query("SELECT GET_LOCK('gamebox_master_bootstrap', 5)")->fetchColumn();
        if ((int)$lock !== 1) {
            $erro = 'Não foi possível iniciar a configuração. Aguarde e tente novamente.';
        } else {
            try {
                $pdo->beginTransaction();
                $hasMaster = $pdo->query("SELECT id FROM usuarios WHERE papel='master' LIMIT 1")->fetchColumn();
                if ($hasMaster !== false) {
                    $pdo->rollBack();
                    $erro = 'O usuário master já foi configurado. Entre pela página de login.';
                } else {
                    $lookup = $pdo->prepare('SELECT id,nome,email,senha,foto_perfil,bloqueado FROM usuarios WHERE email=? LIMIT 1');
                    $lookup->execute([$email]);
                    $existing = $lookup->fetch();

                    if ($existing) {
                        if (!password_verify($senha, (string)$existing['senha'])) {
                            $pdo->rollBack();
                            $erro = 'Esse e-mail já tem uma conta. Informe a senha atual dessa conta para promovê-la.';
                        } elseif ((int)$existing['bloqueado'] === 1) {
                            $pdo->rollBack();
                            $erro = 'Essa conta está bloqueada. Não é possível promovê-la.';
                        } else {
                            $update = $pdo->prepare("UPDATE usuarios SET papel='master' WHERE id=?");
                            $update->execute([$existing['id']]);
                            $userId = (int)$existing['id'];
                            $nome = (string)$existing['nome'];
                            $foto = $existing['foto_perfil'];
                            $pdo->commit();
                            $sucesso = 'Sua conta existente foi promovida a master.';
                        }
                    } else {
                        if (strlen($senha) < 12) {
                            $pdo->rollBack();
                            $erro = 'Para criar uma conta nova, use uma senha com pelo menos 12 caracteres.';
                        } else {
                            $nameCheck = $pdo->prepare('SELECT id FROM usuarios WHERE nome=? LIMIT 1');
                            $nameCheck->execute([$nome]);
                            if ($nameCheck->fetch()) {
                                $pdo->rollBack();
                                $erro = 'Esse nome de usuário já está em uso.';
                            } else {
                                $insert = $pdo->prepare("INSERT INTO usuarios(nome,email,senha,papel,bloqueado) VALUES(?,?,?,'master',0)");
                                $insert->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
                                $userId = (int)$pdo->lastInsertId();
                                $foto = null;
                                $pdo->commit();
                                $sucesso = 'A conta master foi criada.';
                            }
                        }
                    }

                    if ($sucesso !== '') {
                        session_regenerate_id(true);
                        $_SESSION['usuario_id'] = $userId;
                        $_SESSION['usuario_nome'] = $nome;
                        $_SESSION['usuario_foto'] = $foto;
                        header('Location: admin.php');
                        exit;
                    }
                }
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pdo->query("SELECT RELEASE_LOCK('gamebox_master_bootstrap')");
            }
        }
    }
}

$masterExists = $pdo->query("SELECT id FROM usuarios WHERE papel='master' LIMIT 1")->fetchColumn() !== false;
$h = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Configurar master — GameBox</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="auth-page">
  <section class="auth-card">
    <div class="auth-brand">Game<span>Box</span></div>
    <h1>Configurar usuário master</h1>
    <?php if ($masterExists): ?>
      <p class="auth-subtitle">O master já está configurado. Entre com sua conta pela página de login.</p>
      <a class="btn btn-primary auth-button" href="login.php">Ir para o login</a>
    <?php else: ?>
      <p class="auth-subtitle">A configuração inicial só funciona neste computador. Se já tem uma conta no GameBox, informe o e-mail e a senha atuais para promovê-la.</p>
      <?php if ($erro): ?><p class="auth-message" role="alert" style="color:var(--error)"><?= $h($erro) ?></p><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
        <label for="nome">Nome de usuário</label>
        <input class="auth-input" id="nome" name="nome" minlength="3" autocomplete="username" required>
        <label for="email">E-mail</label>
        <input class="auth-input" id="email" name="email" type="email" autocomplete="email" required>
        <label for="senha">Senha (12 caracteres ou mais para conta nova)</label>
        <input class="auth-input" id="senha" name="senha" type="password" autocomplete="new-password" required>
        <label for="confirmar">Confirme a senha</label>
        <input class="auth-input" id="confirmar" name="confirmar" type="password" autocomplete="new-password" required>
        <button class="btn btn-primary auth-button" type="submit">Criar ou promover conta master</button>
      </form>
      <p class="auth-footer"><a href="login.php">Voltar ao login</a></p>
    <?php endif; ?>
  </section>
</main>
<script src="app.js"></script>
</body>
</html>
