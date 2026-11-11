const IGDB_IMAGE_FALLBACK = "https://placehold.co/300x400/1a1f29/e8a33d?text=Sem+imagem";

function igdbImage(url, size = "t_cover_big", fallback = IGDB_IMAGE_FALLBACK) {
  if (!url) return fallback;
  const fullUrl = url.startsWith("//") ? `https:${url}` : url;
  return fullUrl.replace(/t_[a-z0-9]+(?=\/)/, size);
}

async function fetchIgdb(operation, params = {}) {
  const response = await fetch("igdb-proxy.php", {
    method: "POST",
    cache: "no-store",
    headers: {
      "Content-Type": "application/json",
      "Accept": "application/json"
    },
    body: JSON.stringify({ operation, ...params })
  });
  const body = await response.text();
  let data;
  try {
    data = JSON.parse(body);
  } catch {
    throw new Error(`O proxy IGDB retornou conteúdo que não é JSON (HTTP ${response.status}). Confira a configuração do PHP/Apache.`);
  }
  if (!response.ok) {
    throw new Error(data.erro || "Não foi possível consultar a IGDB.");
  }
  if (!Array.isArray(data)) {
    throw new Error("A resposta da IGDB está em formato inesperado. Confira o proxy PHP.");
  }
  return data;
}

async function getPopularGames(pageSize = 12) {
  return fetchIgdb("popular", { limit: pageSize });
}

async function searchGames(queryText, pageSize = 10) {
  return fetchIgdb("search", { query: queryText, limit: pageSize });
}

async function getGameDetails(gameId) {
  const games = await fetchIgdb("details", { id: gameId });
  return games[0] || null;
}

async function getGameScreenshots(gameId) {
  return fetchIgdb("screenshots", { id: gameId });
}

async function getTopRatedGames(pageSize = 12) {
  return fetchIgdb("top-rated", { limit: pageSize });
}

const FONT_SCALES = [0.8, 0.9, 1, 1.1, 1.2, 1.3];
const THEME_STORAGE_KEY = "gamebox-theme";
const FONT_SCALE_STORAGE_KEY = "gamebox-font-scale";

function readPreference(key, fallback) {
  try {
    return localStorage.getItem(key) || fallback;
  } catch {
    return fallback;
  }
}

function initializeAccessibility() {
  const root = document.documentElement;
  const storedTheme = readPreference(THEME_STORAGE_KEY, "dark");
  const theme = storedTheme === "light" ? "light" : "dark";
  const storedScale = Number(readPreference(FONT_SCALE_STORAGE_KEY, "1"));
  let scaleIndex = FONT_SCALES.indexOf(storedScale);
  if (scaleIndex < 0) scaleIndex = FONT_SCALES.indexOf(1);

  const main = document.querySelector("main");
  if (main) {
    if (!main.id) main.id = "main-content";
    main.tabIndex = -1;
    const skipLink = document.createElement("a");
    skipLink.className = "skip-link";
    skipLink.href = `#${main.id}`;
    skipLink.textContent = "Pular para o conteúdo";
    document.body.prepend(skipLink);
  }

  function applyTheme(nextTheme) {
    root.dataset.theme = nextTheme;
    try {
      localStorage.setItem(THEME_STORAGE_KEY, nextTheme);
    } catch {
      // Keep the selected theme for this page even when storage is unavailable.
    }
    document.querySelectorAll(".theme-toggle").forEach(button => {
      button.setAttribute("aria-label", `Ativar tema ${nextTheme === "dark" ? "claro" : "escuro"}`);
      button.title = `Ativar tema ${nextTheme === "dark" ? "claro" : "escuro"}`;
      button.setAttribute("aria-pressed", String(nextTheme === "light"));
      button.textContent = nextTheme === "dark" ? "☼" : "☾";
    });
  }

  function applyFontScale() {
    const scale = FONT_SCALES[scaleIndex];
    root.style.setProperty("--font-scale", String(scale));
    try {
      localStorage.setItem(FONT_SCALE_STORAGE_KEY, String(scale));
    } catch {
      // The current page still receives the requested font size.
    }
    document.querySelectorAll(".font-decrease").forEach(button => {
      button.disabled = scaleIndex === 0;
    });
    document.querySelectorAll(".font-increase").forEach(button => {
      button.disabled = scaleIndex === FONT_SCALES.length - 1;
    });
    document.querySelectorAll(".accessibility-status").forEach(status => {
      status.textContent = scale === 1
        ? "Tamanho padrão do texto."
        : `${scale < 1 ? "Texto reduzido" : "Texto ampliado"} para ${Math.round(scale * 100)}%.`;
    });
  }

  root.dataset.theme = theme;
  root.style.setProperty("--font-scale", String(FONT_SCALES[scaleIndex]));

  document.querySelectorAll(".nav-inner").forEach(nav => {
    let navRight = nav.querySelector(".nav-right");
    if (!navRight) {
      navRight = document.createElement("div");
      navRight.className = "nav-right";
      nav.appendChild(navRight);
    }
    if (navRight.querySelector(".accessibility-tools")) return;

    const tools = document.createElement("div");
    tools.className = "accessibility-tools";
    tools.setAttribute("role", "group");
    tools.setAttribute("aria-label", "Opções de acessibilidade");
    tools.innerHTML = `
      <button class="font-decrease" type="button" aria-label="Diminuir tamanho do texto" title="Diminuir tamanho do texto">A−</button>
      <button class="font-increase" type="button" aria-label="Aumentar tamanho do texto" title="Aumentar tamanho do texto">A+</button>
      <button class="theme-toggle" type="button"></button>
      <span class="accessibility-status" role="status" aria-live="polite"></span>
    `;

    tools.querySelector(".font-decrease").addEventListener("click", () => {
      scaleIndex = Math.max(0, scaleIndex - 1);
      applyFontScale();
    });
    tools.querySelector(".font-increase").addEventListener("click", () => {
      scaleIndex = Math.min(FONT_SCALES.length - 1, scaleIndex + 1);
      applyFontScale();
    });
    tools.querySelector(".theme-toggle").addEventListener("click", () => {
      applyTheme(root.dataset.theme === "dark" ? "light" : "dark");
    });

    navRight.prepend(tools);
  });

  applyTheme(theme);
  applyFontScale();
}

initializeAccessibility();

function showAlert(message, type = "info") {
  const body = document.body;
  if (!body) return;

  const toast = document.createElement("div");
  toast.className = `custom-alert is-${type}`;
  toast.setAttribute("role", "alert");

  const iconMap = {
    success: "✓",
    error: "!",
    info: "i"
  };

  const titleMap = {
    success: "Sucesso",
    error: "Atenção",
    info: "Aviso"
  };

  toast.innerHTML = `
    <div class="custom-alert__icon" aria-hidden="true">${iconMap[type] || iconMap.info}</div>
    <div class="custom-alert__content">
      <span class="custom-alert__title">${titleMap[type] || titleMap.info}</span>
      <span class="custom-alert__message">${escapeHtml(message)}</span>
    </div>
    <button class="custom-alert__close" type="button" aria-label="Fechar alerta">×</button>
  `;

  const closeButton = toast.querySelector(".custom-alert__close");
  const close = () => {
    toast.classList.add("is-hiding");
    setTimeout(() => toast.remove(), 220);
  };

  closeButton?.addEventListener("click", close);
  body.appendChild(toast);
  setTimeout(close, 3200);
}

document.addEventListener("DOMContentLoaded", () => {
  const search = new URLSearchParams(location.search).get("search")?.trim() || "";
  refreshUserNavigation();
  document.querySelector("#review-form")?.addEventListener("submit", submitReview);
  document.querySelectorAll(".search").forEach(input => {
    input.value = search;
    setupGameSearch(input);
  });

  const homeGrid = document.querySelector("#popular-games");
  if (homeGrid) {
    const heading = homeGrid.previousElementSibling?.querySelector("h2");
    if (heading && search) heading.textContent = `Resultados para "${search}"`;
    loadHomeGames(homeGrid, search);
  }

  const nowPlaying = document.querySelector("#now-playing");
  if (nowPlaying) loadNowPlaying(nowPlaying);

  if (document.querySelector("#discover-popular")) loadDiscover();
  if (document.querySelector("#game-title")) loadGamePage();
});

async function refreshUserNavigation() {
  try {
    const response = await fetch("sessao.php", { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
    const session = await readJsonResponse(response, "Sessão");
    if (!session.logado || !session.usuario) return;

    document.querySelectorAll(".nav-inner").forEach(nav => {
      let right = nav.querySelector(".nav-right");
      if (!right) {
        right = document.createElement("div");
        right.className = "nav-right";
        nav.appendChild(right);
      }

      right.querySelectorAll('a[href="login.php"],a[href="cadastro.php"]').forEach(link => link.remove());
      let account = right.querySelector(".account-link") || right.querySelector('a[href="perfil.php"]');
      if (!account) {
        account = document.createElement("a");
        right.appendChild(account);
      }
      account.href = "perfil.php";
      account.className = "account-link";
      account.setAttribute("aria-label", `Perfil de ${session.usuario.nome}`);
      account.replaceChildren();

      if (session.usuario.foto_perfil) {
        const image = document.createElement("img");
        image.className = "avatar";
        image.src = session.usuario.foto_perfil;
        image.alt = "";
        account.appendChild(image);
      } else {
        const initial = document.createElement("span");
        initial.className = "nav-avatar-letter";
        initial.textContent = session.usuario.nome.trim().charAt(0).toUpperCase();
        account.appendChild(initial);
      }

      const name = document.createElement("span");
      name.className = "account-name";
      name.textContent = session.usuario.nome;
      account.appendChild(name);

      if (!right.querySelector('a[href="logout.php"]')) {
        const logout = document.createElement("a");
        logout.className = "logout-link";
        logout.href = "logout.php";
        logout.textContent = "Sair";
        right.appendChild(logout);
      }

      if (session.usuario.papel === "master" && !right.querySelector('a[href="admin.php"]')) {
        const admin = document.createElement("a");
        admin.className = "admin-nav-link";
        admin.href = "admin.php";
        admin.textContent = "Painel master";
        right.prepend(admin);
      }
    });
  } catch {
    // A página continua utilizável se a sessão ou o banco estiverem indisponíveis.
  }
}

function setupGameSearch(input) {
  const form = input.closest(".search-form");
  if (!form) return;

  const list = document.createElement("div");
  list.className = "search-suggestions";
  list.id = `search-suggestions-${Math.random().toString(36).slice(2)}`;
  list.setAttribute("role", "listbox");
  list.hidden = true;
  form.appendChild(list);

  input.setAttribute("role", "combobox");
  input.setAttribute("aria-autocomplete", "list");
  input.setAttribute("aria-controls", list.id);
  input.setAttribute("aria-expanded", "false");

  let debounceTimer;
  let activeIndex = -1;
  let requestId = 0;

  function closeSuggestions() {
    list.hidden = true;
    input.setAttribute("aria-expanded", "false");
    input.removeAttribute("aria-activedescendant");
    activeIndex = -1;
  }

  function selectSuggestion(game) {
    window.location.href = `game.html?id=${encodeURIComponent(game.id)}`;
  }

  function setActiveSuggestion(nextIndex) {
    const options = [...list.querySelectorAll("[role='option']")];
    if (!options.length) return;
    activeIndex = (nextIndex + options.length) % options.length;
    options.forEach((option, index) => {
      const selected = index === activeIndex;
      option.setAttribute("aria-selected", String(selected));
      option.classList.toggle("is-active", selected);
    });
    input.setAttribute("aria-activedescendant", options[activeIndex].id);
  }

  input.addEventListener("input", () => {
    clearTimeout(debounceTimer);
    const query = input.value.trim();
    const currentRequest = ++requestId;
    list.replaceChildren();
    closeSuggestions();
    if (query.length < 2) return;

    debounceTimer = setTimeout(async () => {
      try {
        const games = await searchGames(query, 5);
        if (currentRequest !== requestId || input.value.trim() !== query) return;
        if (!games?.length) return;

        games.forEach((game, index) => {
          const option = document.createElement("button");
          option.type = "button";
          option.className = "search-suggestion";
          option.id = `${list.id}-option-${index}`;
          option.setAttribute("role", "option");
          option.setAttribute("aria-selected", "false");

          const cover = document.createElement("img");
          cover.src = igdbImage(game.cover?.url);
          cover.alt = "";
          cover.loading = "lazy";

          const name = document.createElement("span");
          name.className = "search-suggestion-name";
          name.textContent = game.name;

          const year = document.createElement("span");
          year.className = "search-suggestion-year";
          year.textContent = game.first_release_date
            ? String(new Date(game.first_release_date * 1000).getUTCFullYear())
            : "";

          option.append(cover, name, year);
          option.addEventListener("click", () => selectSuggestion(game));
          list.appendChild(option);
        });

        list.hidden = false;
        input.setAttribute("aria-expanded", "true");
      } catch {
        closeSuggestions();
      }
    }, 250);
  });

  input.addEventListener("keydown", event => {
    if (event.key === "ArrowDown" && !list.hidden) {
      event.preventDefault();
      setActiveSuggestion(activeIndex + 1);
    } else if (event.key === "ArrowUp" && !list.hidden) {
      event.preventDefault();
      setActiveSuggestion(activeIndex < 0 ? list.children.length - 1 : activeIndex - 1);
    } else if (event.key === "Enter" && activeIndex >= 0 && !list.hidden) {
      event.preventDefault();
      const option = list.children[activeIndex];
      option?.click();
    } else if (event.key === "Escape") {
      closeSuggestions();
    }
  });

  document.addEventListener("click", event => {
    if (!form.contains(event.target)) closeSuggestions();
  });
}

async function loadHomeGames(grid, search) {
  try { renderGameCards(search ? await searchGames(search,12) : await getPopularGames(12), grid); }
  catch(e){ grid.innerHTML=`<div class="api-error">${escapeHtml(e.message)}</div>`; }
}

async function loadNowPlaying(container) {
  try {
    const response = await fetch("jogando-agora.php", { headers: { Accept: "application/json" } });
    const data = await readJsonResponse(response, "Jogando agora");

    if (!data.logado) {
      container.innerHTML = '<div class="now-playing-empty"><p>Entre para acompanhar os jogos que você está jogando.</p><a class="btn btn-primary" href="login.php">Entrar na conta</a></div>';
      return;
    }

    if (!data.jogos?.length) {
      container.innerHTML = '<div class="now-playing-empty"><p>Nenhum jogo marcado como “jogando” por enquanto.</p><a class="btn" href="descobrir.php">Encontrar um jogo</a></div>';
      return;
    }

    container.innerHTML = data.jogos.map(game => {
      const cover = game.imagem || igdbImage("");
      const link = `game.html?id=${encodeURIComponent(game.igdb_id)}`;
      const started = formatElapsedTime(game.adicionado_em);
      return `<article class="now-playing-card">
        <a class="now-playing-cover" href="${escapeAttribute(link)}"><img src="${escapeAttribute(cover)}" alt="Capa de ${escapeAttribute(game.nome)}" loading="lazy"></a>
        <div class="now-playing-content">
          <span class="now-playing-label"><span aria-hidden="true">●</span> EM ANDAMENTO</span>
          <h3><a href="${escapeAttribute(link)}">${escapeHtml(game.nome)}</a></h3>
          <p>Adicionado ${escapeHtml(started)}</p>
          ${game.nota !== null ? `<div class="stars" aria-label="Nota ${escapeAttribute(game.nota)} de 5">${starsFromRating(game.nota)}</div>` : ""}
          ${game.comentario ? `<p class="now-playing-note">${escapeHtml(game.comentario)}</p>` : ""}
        </div>
      </article>`;
    }).join("");
  } catch (error) {
    container.innerHTML = `<div class="api-error">${escapeHtml(error.message)}</div>`;
  }
}

function formatElapsedTime(dateValue) {
  const timestamp = new Date(String(dateValue).replace(" ", "T"));
  if (Number.isNaN(timestamp.getTime())) return "recentemente";
  const days = Math.max(0, Math.floor((Date.now() - timestamp.getTime()) / 86400000));
  if (days === 0) return "hoje";
  if (days === 1) return "há 1 dia";
  return `há ${days} dias`;
}

async function loadDiscover(){
  const popular=document.querySelector('#discover-popular'), rated=document.querySelector('#discover-rated');
  try {
    const [p,r]=await Promise.all([getPopularGames(12),getTopRatedGames(12)]);
    renderGameCards(p,popular); renderGameCards(r,rated);
  } catch(e) {
    [popular,rated].forEach(g=>g.innerHTML=`<div class="api-error">${escapeHtml(e.message)}</div>`);
  }
}

function renderGameCards(games, grid, heading="") {
  if(!games?.length){grid.innerHTML='<div class="api-error">Nenhum jogo encontrado.</div>';return;}
  grid.innerHTML=heading?`<div class="api-results-title">${escapeHtml(heading)}</div>`:'';
  games.forEach(game=>{
    const card=document.createElement('a'); card.className='game-card'; card.href=`game.html?id=${encodeURIComponent(game.id)}`;
    const year=game.first_release_date?String(new Date(game.first_release_date*1000).getUTCFullYear()):'—';
    card.innerHTML=`<div class="cover"><img src="${escapeAttribute(igdbImage(game.cover?.url))}" alt="Capa de ${escapeAttribute(game.name)}" loading="lazy"></div><div class="title">${escapeHtml(game.name)}</div><div class="year">${escapeHtml(year)}</div>`;
    grid.appendChild(card);
  });
}

async function loadGamePage(){
  const id=new URLSearchParams(location.search).get('id');
  const status=document.querySelector('#api-status');
  if(!id){status.textContent='Jogo não informado.';return;}
  try{
    const game=await getGameDetails(id);
    if(!game){status.textContent='Jogo não encontrado.';return;}
    document.title=`${game.name} — GameBox`;
    document.querySelector('#game-cover').src=igdbImage(game.cover?.url);
    document.querySelector('#game-cover').alt=`Capa de ${game.name}`;
    document.querySelector('#game-title').textContent=game.name;
    const platforms=(game.platforms||[]).slice(0,5).map(platform=>platform.name).filter(Boolean).join(', ');
    const released=game.first_release_date?String(new Date(game.first_release_date*1000).getUTCFullYear()):'Ano não informado';
    const company=game.involved_companies?.[0]?.company?.name||'';
    document.querySelector('#game-meta').textContent=`${company ? `${company} · ` : ''}${platforms||'Plataformas não informadas'} · ${released}`;
    document.querySelector('#game-desc').textContent=game.summary||'Descrição não disponível.';
    document.querySelector('#game-tags').innerHTML=(game.genres||[]).slice(0,5).map(g=>`<span class="tag">${escapeHtml(g.name)}</span>`).join('')||'<span class="tag">Jogo</span>';
    document.querySelector('#game-rating').textContent=starsFromRating((Number(game.rating)||0)/20);
    const rt=document.querySelector('#rating-text'); if(rt) rt.textContent=`${game.rating ? `${Math.round(game.rating)}/100` : '—'} — ${game.total_rating_count||0} avaliações`;
    status.textContent='';
    setupGameActions(game);
    await Promise.all([loadScreenshots(id),loadComments(id)]);
  }catch(e){status.textContent=e.message;console.error(e);}
}

function setupGameActions(game){
  const data={igdb_id:game.id,nome:game.name,imagem:igdbImage(game.cover?.url)};
  const register=document.querySelector('#btn-register'), add=document.querySelector('#btn-add'), fav=document.querySelector('#btn-favorite');
  const reviewForm=document.querySelector('#review-form');
  if(reviewForm) reviewForm.dataset.game=JSON.stringify(data);
  register?.addEventListener('click',()=>openRegisterModal(data));
  add?.addEventListener('click',()=>doGameAction({...data,acao:'adicionar'},add,'✓ Adicionado'));
  fav?.addEventListener('click',async()=>{
    try{const r=await postAction({...data,acao:'favoritar'});fav.classList.toggle('is-active',r.favorito);fav.textContent=r.favorito?'♥ Favoritado':'♡ Favoritar';}
    catch(e){showAlert(e.message, 'error');}
  });
  fetch(`estado-jogo.php?igdb_id=${encodeURIComponent(game.id)}`).then(r=>readJsonResponse(r,'Estado do jogo')).then(r=>{if(r.jogo&&fav){fav.classList.toggle('is-active',!!r.jogo.favorito);fav.textContent=r.jogo.favorito?'♥ Favoritado':'♡ Favoritar';}if(r.jogo&&reviewForm){document.querySelector('#review-rating').value=r.jogo.nota??'';document.querySelector('#review-comment').value=r.jogo.comentario??'';document.querySelector('#review-submit').textContent='Atualizar avaliação';}}).catch(error=>console.error(error));
}

async function doGameAction(data,button,successText){try{await postAction(data);button.textContent=successText;button.classList.add('is-active');}catch(e){showAlert(e.message,'error');}}
async function postAction(data){const res=await fetch('acoes-jogo.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(data)});return readJsonResponse(res,'Salvar jogo ou avaliação');}

async function readJsonResponse(response, requestName) {
  const body = await response.text();
  let data;
  try {
    data = JSON.parse(body);
  } catch {
    const contentType = response.headers.get("content-type") || "desconhecido";
    throw new Error(`${requestName}: o servidor retornou conteúdo inválido (HTTP ${response.status}, ${contentType}). Confira o log do PHP.`);
  }
  if (!response.ok) {
    throw new Error(data.erro || `${requestName}: erro HTTP ${response.status}.`);
  }
  return data;
}

async function submitReview(event){
  event.preventDefault();
  const form=event.currentTarget, message=document.querySelector('#review-message'), button=document.querySelector('#review-submit');
  const game=JSON.parse(form.dataset.game||'null');
  if(!game){message.textContent='As informações do jogo ainda estão carregando.';return;}
  const comment=document.querySelector('#review-comment').value.trim();
  if(!comment){message.textContent='Escreva um comentário antes de publicar.';return;}
  button.disabled=true;
  message.textContent='Salvando sua avaliação...';
  try{
    await postAction({...game,acao:'comentar',nota:document.querySelector('#review-rating').value,comentario:comment});
    message.textContent='Avaliação salva na sua conta.';
    await loadComments(game.igdb_id);
  }catch(error){
    message.textContent=error.message;
    if(error.message.includes('Faça login')){
      const link=document.createElement('a');
      link.href='login.php';
      link.textContent=' Entrar';
      message.appendChild(link);
    }
  }finally{button.disabled=false;}
}

function openRegisterModal(data){
  const modal=document.querySelector('#register-modal'); if(!modal)return;
  modal.classList.add('open'); modal.dataset.game=JSON.stringify(data);
}
function closeRegisterModal(){document.querySelector('#register-modal')?.classList.remove('open');}

async function submitRegister(){
  const modal=document.querySelector('#register-modal'); if(!modal)return;
  const data=JSON.parse(modal.dataset.game||'{}');
  data.acao='registrar'; data.status=document.querySelector('#register-status').value; data.nota=document.querySelector('#register-rating').value; data.comentario=document.querySelector('#register-comment').value;
  const btn=document.querySelector('#register-save'); btn.disabled=true;
  try{await postAction(data);closeRegisterModal();await loadComments(data.igdb_id);showAlert('Jogo registrado no seu diário!', 'success');}catch(e){showAlert(e.message, 'error');}finally{btn.disabled=false;}
}

document.addEventListener('click',e=>{if(e.target.matches('[data-close-modal]')||e.target.id==='register-modal')closeRegisterModal();if(e.target.id==='register-save')submitRegister();});

async function loadComments(id){
  const box=document.querySelector('#game-comments');if(!box)return;
  try{const r=await fetch(`comentarios.php?igdb_id=${encodeURIComponent(id)}`);const data=await readJsonResponse(r,'Comentários');
    if(!data.comentarios?.length){box.innerHTML='<div class="api-loading">Ainda não há comentários para este jogo.</div>';return;}
    box.innerHTML=data.comentarios.map(c=>`<article class="entry comment-entry"><div class="comment-avatar">${c.foto_perfil?`<img src="${escapeAttribute(c.foto_perfil)}" alt="">`:escapeHtml((c.nome||'?')[0].toUpperCase())}</div><div><div class="meta"><strong>${escapeHtml(c.nome)}</strong> · ${escapeHtml(statusLabel(c.status))}</div>${c.nota!==null?`<div class="stars">${starsFromRating(c.nota)}</div>`:''}<p>${escapeHtml(c.comentario)}</p></div></article>`).join('');
  }catch(e){box.innerHTML=`<div class="api-error">${escapeHtml(e.message)}</div>`;}
}
function statusLabel(s){return ({jogando:'jogando',quero_jogar:'quero jogar',zerado:'zerado',abandonado:'abandonado'})[s]||s||'';}

async function loadScreenshots(id){const c=document.querySelector('#game-screenshots');if(!c)return;try{const shots=await getGameScreenshots(id);c.innerHTML=shots.length?shots.slice(0,6).map((s,i)=>`<img src="${escapeAttribute(igdbImage(s.url,'t_screenshot_med','https://placehold.co/480x270/1a1f29/e8a33d?text=Sem+imagem'))}" alt="Screenshot ${i+1}" loading="lazy">`).join(''):'<div class="api-loading">Este jogo não possui screenshots disponíveis.</div>';}catch(e){c.innerHTML=`<div class="api-error">${escapeHtml(e.message)}</div>`;}}
function starsFromRating(r){const v=Number(r)||0,f=Math.round(v);return '★'.repeat(Math.min(f,5))+'☆'.repeat(Math.max(5-f,0));}
function escapeHtml(v){return String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');}
function escapeAttribute(v){return escapeHtml(v);}
