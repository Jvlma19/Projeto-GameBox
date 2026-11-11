<?php
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_once __DIR__ . '/app-core.php';
    gameboxEnableJsonApiErrors();
}
require_once __DIR__ . '/app-core.php';
gameboxStartSession();
require 'db.php';
$isApiRequest = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isApiRequest) {
    header('Content-Type: application/json; charset=utf-8');
}
$account = gameboxRequireActiveAccount($pdo, $isApiRequest);
if (($account['papel'] ?? '') !== 'master') {
    http_response_code(403);
    if ($isApiRequest) {
        echo json_encode(['erro' => 'Acesso restrito ao usuário master.']);
    } else {
        exit('Acesso restrito ao usuário master.');
    }
    exit;
}

if ($isApiRequest) {
    if (!gameboxValidateCsrf()) {
        http_response_code(400);
        echo json_encode(['erro' => 'Solicitação inválida ou sessão expirada.']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $data = is_array($data) ? $data : [];
    $action = $data['action'] ?? '';
    $targetId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);

    if (in_array($action, ['block_user', 'unblock_user'], true)) {
        if (!$targetId || $targetId === (int)$account['id']) {
            http_response_code(400);
            echo json_encode(['erro' => 'Usuário inválido para esta ação.']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE usuarios SET bloqueado=? WHERE id=? AND papel='usuario'");
        $stmt->execute([$action === 'block_user' ? 1 : 0, $targetId]);
        if (!$stmt->rowCount()) {
            http_response_code(404);
            echo json_encode(['erro' => 'Usuário não encontrado ou sem alteração.']);
            exit;
        }
    } elseif (in_array($action, ['hide_comment', 'show_comment', 'delete_comment'], true)) {
        if (!$targetId) {
            http_response_code(400);
            echo json_encode(['erro' => 'Comentário inválido.']);
            exit;
        }
        if ($action === 'delete_comment') {
            $stmt = $pdo->prepare('UPDATE biblioteca SET comentario=NULL,comentario_oculto=0 WHERE id=? AND comentario IS NOT NULL');
            $stmt->execute([$targetId]);
        } else {
            $stmt = $pdo->prepare('UPDATE biblioteca SET comentario_oculto=? WHERE id=? AND comentario IS NOT NULL');
            $stmt->execute([$action === 'hide_comment' ? 1 : 0, $targetId]);
        }
        if (!$stmt->rowCount()) {
            http_response_code(404);
            echo json_encode(['erro' => 'Comentário não encontrado ou sem alteração.']);
            exit;
        }
    } else {
        http_response_code(400);
        echo json_encode(['erro' => 'Ação administrativa inválida.']);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

$users = $pdo->query("SELECT id,nome,email,bloqueado,criado_em FROM usuarios WHERE papel='usuario' ORDER BY criado_em DESC")->fetchAll();
$comments = $pdo->query(
    'SELECT b.id AS comentario_id,b.comentario,b.comentario_oculto,b.adicionado_em,u.nome AS autor,j.nome AS jogo
     FROM biblioteca b
     JOIN usuarios u ON u.id=b.usuario_id
     JOIN jogos j ON j.id=b.jogo_id
     WHERE b.comentario IS NOT NULL AND b.comentario<>""
     ORDER BY b.adicionado_em DESC
     LIMIT 100'
)->fetchAll();
$csrf = gameboxCsrfToken();
$h = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Painel master — GameBox</title><link rel="stylesheet" href="style.css"></head><body>
<nav class="nav"><div class="container nav-inner"><a href="index.html" class="logo">Game<span>Box</span></a><div class="nav-links"><a href="perfil.php">Perfil</a></div><div class="nav-right"><a class="auth-link" href="logout.php">Sair</a></div></div></nav>
<main class="container admin-page">
  <header class="admin-hero"><span class="eyebrow">ADMINISTRAÇÃO</span><h1>Painel master</h1><p>Gerencie acesso de usuários e modere comentários da comunidade.</p></header>
  <p id="admin-feedback" class="form-feedback" role="status" aria-live="polite"></p>
  <section><div class="section-head"><h2>Usuários (<?= count($users) ?>)</h2></div>
    <?php if (!$users): ?><p class="empty-state">Não há usuários cadastrados para gerenciar.</p><?php else: ?>
    <div class="admin-list"><?php foreach ($users as $user): ?><article class="admin-card">
      <div><strong><?= $h($user['nome']) ?></strong><div class="muted"><?= $h($user['email']) ?></div><small><?= (int)$user['bloqueado'] ? 'Bloqueado' : 'Ativo' ?> · <?= $h($user['criado_em']) ?></small></div>
      <button class="btn <?= (int)$user['bloqueado'] ? '' : 'btn-primary' ?>" type="button" data-admin-action="<?= (int)$user['bloqueado'] ? 'unblock_user' : 'block_user' ?>" data-admin-id="<?= (int)$user['id'] ?>"><?= (int)$user['bloqueado'] ? 'Desbloquear' : 'Bloquear' ?></button>
    </article><?php endforeach; ?></div><?php endif; ?>
  </section>
  <section><div class="section-head"><h2>Comentários (últimos 100)</h2></div>
    <?php if (!$comments): ?><p class="empty-state">Ainda não há comentários para moderar.</p><?php else: ?>
    <div class="admin-list"><?php foreach ($comments as $comment): ?><article class="admin-card admin-comment">
      <div><strong><?= $h($comment['jogo']) ?></strong><div class="muted">Por <?= $h($comment['autor']) ?> · <?= $h($comment['adicionado_em']) ?><?= (int)$comment['comentario_oculto'] ? ' · Oculto' : '' ?></div><p><?= nl2br($h($comment['comentario'])) ?></p></div>
      <div class="admin-actions">
        <button class="btn" type="button" data-admin-action="<?= (int)$comment['comentario_oculto'] ? 'show_comment' : 'hide_comment' ?>" data-admin-id="<?= (int)$comment['comentario_id'] ?>"><?= (int)$comment['comentario_oculto'] ? 'Mostrar' : 'Ocultar' ?></button>
        <button class="btn btn-danger" type="button" data-admin-action="delete_comment" data-admin-id="<?= (int)$comment['comentario_id'] ?>">Excluir comentário</button>
      </div>
    </article><?php endforeach; ?></div><?php endif; ?>
  </section>
</main>
<script>
window.gameBoxAdminCsrf=<?= json_encode($csrf) ?>;
document.querySelectorAll('[data-admin-action]').forEach(button=>button.addEventListener('click',async()=>{
  const action=button.dataset.adminAction,id=Number(button.dataset.adminId);
  if((action==='delete_comment'||action==='block_user')&&!confirm(action==='delete_comment'?'Excluir este comentário permanentemente?':'Bloquear este usuário?'))return;
  button.disabled=true;
  const feedback=document.querySelector('#admin-feedback');
  try{
    const response=await fetch('admin.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':window.gameBoxAdminCsrf},body:JSON.stringify({action,id,csrf_token:window.gameBoxAdminCsrf})});
    const result=await response.json();
    if(!response.ok)throw new Error(result.erro||'Não foi possível aplicar a alteração.');
    feedback.textContent='Alteração salva.';
    if(action==='delete_comment')button.closest('.admin-card').remove();
    else if(action==='block_user'||action==='unblock_user')window.location.reload();
    else window.location.reload();
  }catch(error){feedback.textContent=error.message;button.disabled=false;}
}));
</script><script src="app.js"></script></body></html>
