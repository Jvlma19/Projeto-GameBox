<?php
$host = getenv('GAMEBOX_DB_HOST') ?: 'localhost';
$port = getenv('GAMEBOX_DB_PORT') ?: '3306';
$databaseName = getenv('GAMEBOX_DB_NAME') ?: 'gamebox';
$username = getenv('GAMEBOX_DB_USER') ?: 'root';
$password = getenv('GAMEBOX_DB_PASS');
$password = $password === false ? '' : $password;
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$databaseName;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    error_log('GameBox database connection failed: ' . $e->getMessage());
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $isJsonRequest = in_array($script, ['sessao.php','acoes-jogo.php','estado-jogo.php','comentarios.php','jogando-agora.php','salvar-jogo.php'], true)
        || ($script === 'admin.php' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
    if ($isJsonRequest) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['erro'=>'Banco de dados indisponível. Confira as configurações do GameBox.']));
    }
    exit('Não foi possível conectar ao banco GameBox. Confira se o MySQL está ligado, se as credenciais estão corretas e se o banco foi importado.');
}
?>