<?php
// Coloque aqui o Client ID e o Client Secret da aplicação Twitch usada pela IGDB.
const IGDB_CLIENT_ID = 'h1zjksz3l11vnrv8l8fdfauyt7rqm3';
const IGDB_CLIENT_SECRET = '35erkwv4t159nk94bhzb48fbtywzto';

ini_set('display_errors', '0');
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-GameBox-IGDB-Proxy: 5');

function igdbJsonError($status, $message) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    echo json_encode(['erro' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (IGDB_CLIENT_ID === 'COLE_SEU_CLIENT_ID_AQUI' ||
    IGDB_CLIENT_SECRET === 'COLE_SEU_CLIENT_SECRET_AQUI' ||
    trim(IGDB_CLIENT_ID) === '' || trim(IGDB_CLIENT_SECRET) === '') {
    igdbJsonError(503, 'Configure IGDB_CLIENT_ID e IGDB_CLIENT_SECRET no início de igdb-proxy.php.');
}

if (($_GET['health'] ?? '') === '1') {
    echo json_encode([
        'proxy_version' => 5,
        'client_id_configurado' => true,
        'client_secret_configurado' => true
    ]);
    exit;
}

function getTwitchAccessToken() {
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'gamebox-igdb-token-' . hash('sha256', __DIR__ . IGDB_CLIENT_ID) . '.json';
    $cached = is_file($cacheFile)
        ? json_decode((string)@file_get_contents($cacheFile), true)
        : null;

    if (is_array($cached) && !empty($cached['access_token'])
        && !empty($cached['expires_at']) && $cached['expires_at'] > time() + 120) {
        return $cached['access_token'];
    }

    $request = curl_init('https://id.twitch.tv/oauth2/token');
    if ($request === false) {
        igdbJsonError(503, 'A extensão cURL do PHP não está disponível.');
    }
    curl_setopt_array($request, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => IGDB_CLIENT_ID,
            'client_secret' => IGDB_CLIENT_SECRET,
            'grant_type' => 'client_credentials'
        ]),
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($request);
    $curlError = curl_error($request);
    $status = (int)curl_getinfo($request, CURLINFO_HTTP_CODE);
    curl_close($request);

    if ($response === false) {
        error_log('Twitch OAuth connection failed: ' . $curlError);
        igdbJsonError(502, 'Não foi possível conectar à autenticação da Twitch.');
    }

    $data = json_decode($response, true);
    if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
        error_log('Twitch OAuth failed with HTTP status ' . $status);
        if ($status === 400 || $status === 401) {
            igdbJsonError(502, 'A Twitch recusou o Client ID ou Client Secret. Confira igdb-proxy.php.');
        }
        igdbJsonError(502, 'A Twitch não conseguiu gerar o token da IGDB (HTTP ' . $status . ').');
    }

    $data['expires_at'] = time() + (int)($data['expires_in'] ?? 3600);
    if (@file_put_contents($cacheFile, json_encode($data), LOCK_EX) === false) {
        error_log('Unable to cache Twitch OAuth token.');
    }

    return $data['access_token'];
}

$data = json_decode(file_get_contents('php://input'), true);
$data = is_array($data) ? $data : [];
$operation = $data['operation'] ?? '';
$limit = filter_var($data['limit'] ?? 12, FILTER_VALIDATE_INT);
$limit = max(1, min($limit ?: 12, 30));
$id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
$gameFields = 'name,cover.url,rating,rating_count,total_rating_count,summary,first_release_date,platforms.name,genres.name,involved_companies.company.name,screenshots.url';

switch ($operation) {
    case 'popular':
        $endpoint = 'games';
        $query = "fields $gameFields; where cover != null & total_rating_count != null; sort total_rating_count desc; limit $limit;";
        break;
    case 'top-rated':
        $endpoint = 'games';
        $query = "fields $gameFields; where cover != null & rating != null & rating_count > 50; sort rating desc; limit $limit;";
        break;
    case 'search':
        $search = trim((string)($data['query'] ?? ''));
        if ($search === '' || strlen($search) > 120) {
            igdbJsonError(400, 'Informe um termo de busca com até 120 caracteres.');
        }
        $search = str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', ' ', ' '], $search);
        $endpoint = 'games';
        $query = "search \"$search\"; fields $gameFields; limit $limit;";
        break;
    case 'details':
        if (!$id || $id < 1) {
            igdbJsonError(400, 'ID de jogo inválido.');
        }
        $endpoint = 'games';
        $query = "fields $gameFields; where id = $id; limit 1;";
        break;
    case 'screenshots':
        if (!$id || $id < 1) {
            igdbJsonError(400, 'ID de jogo inválido.');
        }
        $endpoint = 'screenshots';
        $query = "fields image_id,url,width,height; where game = $id; limit 10;";
        break;
    default:
        igdbJsonError(400, 'Operação IGDB inválida.');
}

$accessToken = getTwitchAccessToken();
$request = curl_init("https://api.igdb.com/v4/$endpoint");
if ($request === false) {
    igdbJsonError(503, 'A extensão cURL do PHP não está disponível.');
}
curl_setopt_array($request, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $query,
    CURLOPT_HTTPHEADER => [
        'Client-ID: ' . IGDB_CLIENT_ID,
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: text/plain'
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20
]);

$response = curl_exec($request);
$curlError = curl_error($request);
$status = (int)curl_getinfo($request, CURLINFO_HTTP_CODE);
curl_close($request);

if ($response === false) {
    error_log('IGDB connection failed: ' . $curlError);
    igdbJsonError(502, 'Não foi possível conectar à IGDB. Verifique a conexão do servidor.');
}

if ($status < 200 || $status >= 300) {
    $details = trim(preg_replace('/\s+/', ' ', strip_tags($response)));
    $details = str_replace([IGDB_CLIENT_ID, IGDB_CLIENT_SECRET, $accessToken], '[oculto]', $details);
    $details = substr($details, 0, 400);
    error_log("IGDB request failed with HTTP status $status: $details");

    if ($status === 401 || $status === 403) {
        igdbJsonError(502, 'A IGDB recusou a autenticação (HTTP ' . $status . '). Confira o Client ID e o Client Secret em igdb-proxy.php.');
    }
    if ($status === 400) {
        igdbJsonError(502, 'A IGDB rejeitou a consulta (HTTP 400).' . ($details !== '' ? ' Detalhes: ' . $details : ''));
    }
    igdbJsonError(502, 'A IGDB respondeu com HTTP ' . $status . '.' . ($details !== '' ? ' Detalhes: ' . $details : ''));
}

header('Cache-Control: public, max-age=300');
$games = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($games)) {
    error_log('IGDB returned a non-JSON response for endpoint ' . $endpoint);
    igdbJsonError(502, 'A IGDB devolveu uma resposta inválida. Tente novamente em instantes.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
echo json_encode($games, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
