
const RAWG_API_KEY = "f4f2fa1fd08d4e50af218e7c439bc13a"; 
const RAWG_BASE_URL = "https://api.rawg.io/api";

function rawgImage(url, fallback = "https://placehold.co/300x400/1a1f29/e8a33d?text=Sem+imagem") {
  return url || fallback;
}

function checkRawgKey() {
  if (!RAWG_API_KEY || RAWG_API_KEY === "SUA_CHAVE_AQUI") {
    throw new Error("Coloque sua chave da RAWG em rawg-api.js antes de usar a API.");
  }
}

/**
 * Busca uma lista de jogos populares (usado na Home).
 * @param {number} pageSize - quantos jogos trazer (padrão 12)
 * @returns {Promise<Array>} lista de jogos
 */
async function getPopularGames(pageSize = 12) {
  checkRawgKey();
  const url = `${RAWG_BASE_URL}/games?key=${RAWG_API_KEY}&ordering=-added&page_size=${pageSize}`;
  const res = await fetch(url);
  if (!res.ok) throw new Error("Falha ao buscar jogos populares");
  const data = await res.json();
  return data.results;
}

/**
 * Busca jogos por nome (usado na barra de busca).
 * @param {string} query - termo digitado pelo usuário
 */
async function searchGames(query, pageSize = 10) {
  checkRawgKey();
  const url = `${RAWG_BASE_URL}/games?key=${RAWG_API_KEY}&search=${encodeURIComponent(query)}&page_size=${pageSize}`;
  const res = await fetch(url);
  if (!res.ok) throw new Error("Falha na busca de jogos");
  const data = await res.json();
  return data.results;
}

/**
 * Busca os detalhes completos de um jogo (usado em game.html).
 * @param {number|string} gameId - id do jogo no RAWG
 */
async function getGameDetails(gameId) {
  checkRawgKey();
  const url = `${RAWG_BASE_URL}/games/${gameId}?key=${RAWG_API_KEY}`;
  const res = await fetch(url);
  if (!res.ok) throw new Error("Falha ao buscar detalhes do jogo");
  return res.json();
}

/**
 * Busca as screenshots de um jogo (usado em game.html).
 */
async function getGameScreenshots(gameId) {
  checkRawgKey();
  const url = `${RAWG_BASE_URL}/games/${gameId}/screenshots?key=${RAWG_API_KEY}`;
  const res = await fetch(url);
  if (!res.ok) throw new Error("Falha ao buscar screenshots");
  const data = await res.json();
  return data.results;
}


async function getTopRatedGames(pageSize = 12) {
  checkRawgKey();
  const url = `${RAWG_BASE_URL}/games?key=${RAWG_API_KEY}&ordering=-rating&page_size=${pageSize}`;
  const res = await fetch(url);
  if (!res.ok) throw new Error("Falha ao buscar jogos mais bem avaliados");
  const data = await res.json();
  return data.results;
}
