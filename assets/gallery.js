(() => {
  const $ = (selector, root = document) => root.querySelector(selector);
  const els = {
    gallery: $('#gallery'), status: $('#resultStatus'), load: $('#loadState'), sentinel: $('#sentinel'),
    form: $('#searchForm'), input: $('#searchInput'), clear: $('#clearSearch'), filters: $('#gameFilters'), reset: $('#resetButton'),
    modal: $('#lightbox'), modalClose: $('#modalClose'), image: $('#modalImage'), imageWrap: $('#modalImageWrap'), prev: $('#storyPrev'), next: $('#storyNext'), game: $('#modalGame'), text: $('#modalText'),
    tags: $('#modalTags'), submissions: $('#modalSubmissions'), submissionsBlock: $('#submissionsBlock'), glossary: $('#modalGlossary'), glossaryBlock: $('#glossaryBlock'), corpus: $('#modalCorpus'),
    corpusBlock: $('#corpusBlock'), community: $('#modalCommunity'), communityBlock: $('#communityBlock'), translations: $('#modalTranslations'), translationsBlock: $('#translationsBlock'), translationLeft: $('#translationLeft'), translationRight: $('#translationRight'), comparison: $('#modalComparison'), comparisonBlock: $('#comparisonBlock'), context: $('#modalContext'), contextBlock: $('#contextBlock'), sequence: $('#modalSequence'), sequenceBlock: $('#sequenceBlock'), meta: $('#modalMeta'), copy: $('#copyButton'), correctionButton: $('#correctionButton'), correctionBlock: $('#correctionBlock'), correctionForm: $('#correctionForm'), correctionCategory: $('#correctionCategory'), correctionSuggested: $('#correctionSuggested'), correctionDescription: $('#correctionDescription'), correctionContact: $('#correctionContact'), correctionStatus: $('#correctionStatus'), correctionCancel: $('#correctionCancel'), about: $('#aboutDialog'), corrections: $('#correctionsDialog'), correctionStats: $('#correctionStats'), correctionList: $('#correctionList'), sort: $('#sortSelect'), expandEvolutionLabel: $('#expandEvolutionLabel'), expandEvolutionToggle: $('#expandEvolutionToggle')
  };
  const state = { q: '', game: '', sort: 'random', expandEvolution: false, seed: Math.floor(Math.random() * 2147483645) + 1, page: 1, more: true, loading: false, request: 0, total: 0 };
  const detailCache = new Map();
  let activeSequence = [];
  let activeTranslations = [];
  let activeDetailId = 0;
  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const gameTheme = game => {
    if (game === 'Pokémon LEGENDS Z-A') return { key: 'za', label: 'Pokémon LEGENDS Z-A', icon: 'HOME_Legends_Z-A_icon.png' };
    if (game === 'Pokémon Pokopia') return { key: 'pokopia', label: 'Pokémon Pokopia', icon: 'Pokopia_icon_v2.png' };
    if (game === 'Let\'s Go, Pikachu!') return { key: 'lets-go-pikachu', label: 'Pokémon: Let\'s Go, Pikachu!', icon: 'HOME_Let\'s_Go_Pikachu_icon.png' };
    if (game === '宝可梦传说 阿尔宙斯') return { key: 'arceus', label: '宝可梦传说 阿尔宙斯', icon: 'HOME_Legends_Arceus_icon.png' };
    if (game === 'Pokémon LEGENDS アルセウス') return { key: 'arceus', label: 'Pokémon LEGENDS アルセウス', icon: 'HOME_Legends_Arceus_icon.png' };
    if (game === '宝可梦 明亮珍珠') return { key: 'pearl', label: '宝可梦 明亮珍珠', icon: 'HOME_Shining_Pearl_icon.png' };
    if (game === '宝可梦 朱') return { key: 'scarlet', label: '宝可梦 朱', icon: 'HOME_Scarlet_icon.png' };
    if (game === 'ポケットモンスター スカーレット') return { key: 'scarlet', label: '宝可梦 朱', icon: 'HOME_Scarlet_icon.png' };
    if (game === '宝可梦 紫') return { key: 'violet', label: '宝可梦 紫', icon: 'HOME_Violet_icon.png' };
    if (game === '宝可梦 剑') return { key: 'sword', label: '宝可梦 剑', icon: 'HOME_Sword_icon.png' };
    if (game === 'ポケットモンスター ソード') return { key: 'sword', label: 'ポケットモンスター ソード', icon: 'HOME_Sword_icon.png' };
    if (game === 'NINTENDO 64 Nintendo Classics') return { key: 'n64', label: 'Nintendo 64' };
    return { key: 'default', label: '作品标记' };
  };
  const originMark = theme => {
    if (theme.icon) return `<img class="origin-mark" src="icon/${theme.icon}" alt="${theme.label}" loading="lazy" decoding="async">`;
    if (theme.key === 'n64') return `<span class="origin-mark origin-text" aria-label="Nintendo 64">N</span>`;
    return `<span class="origin-mark origin-text" aria-label="作品标记">P</span>`;
  };
  const storedLanguage = key => { try { return localStorage.getItem(key) || ''; } catch (_) { return ''; } };
  const storeLanguage = (key, value) => { try { localStorage.setItem(key, value); } catch (_) {} };
  // 空结果 = 图库还没补到，不是台词不存在（EXP-0104）：给语料库出口和「请求补完」，并记录落空。
  const CORPUS_URL = 'https://abcboy101.github.io/poke-corpus/';
  const wantedKey = (q, game) => `gg-wanted:${game}|${q}`;
  const postWanted = async (action, q, game) => {
    const response = await fetch(new URL(`api.php?action=${action}`, location.href), {
      method: 'POST', keepalive: true,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ q, game })
    });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.error || '提交失败');
    return data;
  };
  function renderEmptyState() {
    const { q, game } = state;
    if (!q) {
      els.gallery.innerHTML = '<div class="empty-state"><strong>当前图库尚未收录这个作品的截图</strong>换个作品看看，或清除筛选。</div>';
      return;
    }
    const requested = storedLanguage(wantedKey(q, game)) === '1';
    const corpusHref = `${CORPUS_URL}#${new URLSearchParams({ query: q, run: 'true' })}`;
    els.gallery.innerHTML = `<div class="empty-state wanted-state">
      <strong>当前图库尚未收录这个场景</strong>
      <p>图库目前只收录了部分作品的截图，搜不到不代表这句台词不存在。可以换个更短的词再试，或者去语料库查原文。</p>
      <div class="wanted-actions">
        <a class="wanted-action primary" href="${escapeHtml(corpusHref)}" target="_blank" rel="noopener noreferrer">去语料库继续搜索</a>
        <button class="wanted-action" type="button" data-wanted-request${requested ? ' disabled' : ''}>${requested ? '已请求补完' : '请求补完'}</button>
        ${game ? '<button class="wanted-action" type="button" data-wanted-clear>清除作品筛选再试</button>' : ''}
      </div>
      <p class="wanted-note" role="status">${requested ? '已记下，补图时会优先考虑这一条。' : ''}</p>
    </div>`;
    const root = els.gallery.firstElementChild;
    const note = root.querySelector('.wanted-note');
    root.querySelector('[data-wanted-request]').addEventListener('click', async event => {
      const button = event.currentTarget;
      button.disabled = true; note.textContent = '正在提交…';
      try {
        await postWanted('request_completion', q, game);
        storeLanguage(wantedKey(q, game), '1');
        button.textContent = '已请求补完'; note.textContent = '已记下，补图时会优先考虑这一条。';
      } catch (error) {
        button.disabled = false; note.textContent = `${error.message}，请稍后再试`;
      }
    });
    root.querySelector('[data-wanted-clear]')?.addEventListener('click', () => { state.game = ''; applyPageTheme(''); setActiveFilter(); syncCompactHero(); updateUrl(); load(true); });
    postWanted('log_miss', q, game).catch(() => {});
  }
  function renderTranslationPair() {
    if (activeTranslations.length < 2) return;
    let left = els.translationLeft.value;
    let right = els.translationRight.value;
    if (left === right) {
      const alternative = activeTranslations.find(x => x.language !== left);
      if (alternative) { right = alternative.language; els.translationRight.value = right; }
    }
    storeLanguage('game_gallery_translation_left', left);
    storeLanguage('game_gallery_translation_right', right);
    const selected = [left, right].map(language => activeTranslations.find(x => x.language === language)).filter(Boolean);
    els.translations.innerHTML = selected.map(x => `<div class="translation-row" lang="${escapeHtml(x.language)}"><span>${escapeHtml(x.label)}</span><p>${escapeHtml(x.text)}</p></div>`).join('');
  }
  function setTranslations(translations) {
    activeTranslations = translations || [];
    if (activeTranslations.length < 2) return;
    const languages = activeTranslations.map(x => x.language);
    const optionHtml = activeTranslations.map(x => `<option value="${escapeHtml(x.language)}">${escapeHtml(x.label)}</option>`).join('');
    els.translationLeft.innerHTML = optionHtml;
    els.translationRight.innerHTML = optionHtml;
    const savedLeft = storedLanguage('game_gallery_translation_left');
    const savedRight = storedLanguage('game_gallery_translation_right');
    const left = languages.includes(savedLeft) ? savedLeft : (languages.includes('zh-Hans') ? 'zh-Hans' : languages[0]);
    const rightPreference = languages.includes(savedRight) && savedRight !== left ? savedRight : ['ja', 'en', 'zh-Hant'].find(x => languages.includes(x) && x !== left);
    els.translationLeft.value = left;
    els.translationRight.value = rightPreference || languages.find(x => x !== left) || languages[0];
    renderTranslationPair();
  }
  const applyPageTheme = game => { document.body.dataset.gameTheme = game ? gameTheme(game).key : 'default'; };
  const api = async params => {
    const url = new URL('api.php', location.href);
    Object.entries(params).forEach(([key, value]) => value !== '' && url.searchParams.set(key, value));
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.error || '载入失败');
    return data;
  };

  function updateUrl() {
    const url = new URL(location.href);
    state.q ? url.searchParams.set('q', state.q) : url.searchParams.delete('q');
    state.game ? url.searchParams.set('game', state.game) : url.searchParams.delete('game');
    state.sort !== 'story' ? url.searchParams.set('sort', state.sort) : url.searchParams.delete('sort');
    state.expandEvolution ? url.searchParams.set('expand_evolution', '1') : url.searchParams.delete('expand_evolution');
    history.replaceState(null, '', url);
  }

  function card(item) {
    const button = document.createElement('button');
    const theme = gameTheme(item.game);
    button.type = 'button'; button.className = `card theme-${theme.key}`; button.dataset.id = item.id;
    const ratio = item.width && item.height ? `${item.width}/${item.height}` : '16/9';
    button.style.setProperty('--ratio', ratio);
    const badges = `${item.match_tier === 'family' ? '<span class="quality-badge quality-badge-family">进化/退化家族</span>' : ''}${Number(item.story_score) >= .72 ? '<span class="quality-badge">剧情</span>' : ''}${Number(item.term_count) ? `<span class="quality-badge">${Number(item.term_count)} 术语</span>` : ''}${Number(item.variant_count) > 1 ? `<span class="quality-badge">${Number(item.variant_count)} 份投稿</span>` : ''}`;
    button.innerHTML = `<div class="card-media"><div class="game-emblem" title="${theme.label}">${originMark(theme)}</div><div class="quality-badges">${badges}</div><img src="${escapeHtml(item.thumb_url || item.preview_url)}" alt="${escapeHtml(item.text || item.game)}" loading="lazy" decoding="async"></div><div class="card-body"><div class="card-game"><span class="mini-emblem" title="${theme.label}">${originMark(theme)}</span>${escapeHtml(item.game)}<span class="archive-label">${escapeHtml(item.archive_label || '')}</span></div><p class="card-text${item.text ? '' : ' empty'}">${escapeHtml(item.text || '未识别到文字')}</p></div>`;
    const img = $('img', button);
    const reveal = () => img.classList.add('loaded');
    img.addEventListener('load', reveal, { once: true });
    img.addEventListener('error', reveal, { once: true });
    if (img.complete) reveal();
    button.addEventListener('click', () => openDetail(item.id));
    return button;
  }

  async function load(reset = false) {
    if (state.loading || (!state.more && !reset)) return;
    if (reset) { state.page = 1; state.more = true; els.gallery.replaceChildren(); }
    state.loading = true; els.load.hidden = false; els.load.innerHTML = '<span class="spinner"></span><span>载入截图</span>';
    els.gallery.closest('.results').setAttribute('aria-busy', 'true');
    const request = ++state.request;
    try {
      const data = await api({ action: 'list', q: state.q, game: state.game, sort: state.sort, expand_evolution: state.expandEvolution ? '1' : '', seed: state.seed, page: state.page, limit: 36 });
      if (request !== state.request) return;
      data.items.forEach(item => els.gallery.append(card(item)));
      state.total += data.items.length; state.more = data.hasMore; state.page++;
      const context = [state.q && `“${state.q}”`, state.game].filter(Boolean).join(' · ');
      els.status.textContent = context ? `${context} · 已显示 ${els.gallery.children.length} 张` : `已显示 ${els.gallery.children.length} 张截图`;
      els.reset.hidden = !(state.q || state.game);
      els.expandEvolutionLabel.hidden = !data.resolvedPokemon;
      if (data.resolvedPokemon) els.expandEvolutionLabel.lastChild.textContent = ` 含「${data.resolvedPokemon.pokemon_name}」进化/退化家族`;
      if (!els.gallery.children.length) renderEmptyState();
      els.load.hidden = !state.more;
    } catch (error) {
      els.load.hidden = false; els.load.innerHTML = `<span>${escapeHtml(error.message)}，点击重试</span>`; els.load.onclick = () => load(false);
      els.status.textContent = '载入遇到问题';
    } finally {
      if (request === state.request) state.loading = false;
      els.gallery.closest('.results').setAttribute('aria-busy', 'false');
    }
  }

  async function loadMeta() {
    try {
      const data = await api({ action: 'meta' });
      const all = document.createElement('button'); all.className = 'filter active'; all.type = 'button'; all.textContent = `全部 ${Number(data.stats.total).toLocaleString()}`; all.dataset.game = '';
      els.filters.append(all);
      data.games.forEach(row => { const theme = gameTheme(row.game); const b = document.createElement('button'); b.type = 'button'; b.className = `filter theme-${theme.key}`; b.dataset.game = row.game; b.innerHTML = `<span class="filter-emblem" title="${theme.label}">${originMark(theme)}</span><span>${escapeHtml(row.game)}</span><small>${Number(row.count).toLocaleString()}</small>`; b.title = `${row.game} · ${theme.label}`; els.filters.append(b); });
      setActiveFilter();
    } catch (_) { els.filters.hidden = true; }
  }

  function setActiveFilter() { document.querySelectorAll('.filter').forEach(el => el.classList.toggle('active', el.dataset.game === state.game)); }
  function syncCompactHero() { document.body.classList.toggle('compact-results', !!(state.q || state.game)); }
  function applySearch() { const value = els.input.value.trim(); if (value === state.q) return; state.q = value; els.form.classList.toggle('has-value', !!value); syncCompactHero(); state.total = 0; updateUrl(); load(true); }
  let debounce;
  els.input.addEventListener('input', () => { els.form.classList.toggle('has-value', !!els.input.value); clearTimeout(debounce); debounce = setTimeout(applySearch, 360); });
  els.form.addEventListener('submit', event => { event.preventDefault(); clearTimeout(debounce); applySearch(); });
  els.clear.addEventListener('click', () => { els.input.value = ''; applySearch(); els.input.focus(); });
  els.filters.addEventListener('click', event => { const button = event.target.closest('.filter'); if (!button || button.dataset.game === state.game) return; state.game = button.dataset.game; applyPageTheme(state.game); setActiveFilter(); syncCompactHero(); updateUrl(); load(true); });
  els.reset.addEventListener('click', () => { state.q = ''; state.game = ''; state.expandEvolution = false; els.expandEvolutionToggle.checked = false; els.expandEvolutionLabel.hidden = true; applyPageTheme(''); els.input.value = ''; els.form.classList.remove('has-value'); setActiveFilter(); syncCompactHero(); updateUrl(); load(true); });
  els.sort.addEventListener('change', () => { state.sort = els.sort.value; if (state.sort === 'random') state.seed = Math.floor(Math.random() * 2147483645) + 1; updateUrl(); load(true); });
  els.expandEvolutionToggle.addEventListener('change', () => { state.expandEvolution = els.expandEvolutionToggle.checked; updateUrl(); load(true); });

  async function openDetail(id) {
    const firstOpen = !els.modal.open;
    if (firstOpen) { els.modal.showModal(); els.image.removeAttribute('src'); els.game.textContent = '正在载入…'; els.text.textContent = ''; }
    else els.imageWrap.classList.add('switching');
    document.body.style.overflow = 'hidden';
    els.prev.disabled = true; els.next.disabled = true;
    try {
      let item = detailCache.get(String(id));
      if (!item) { ({ item } = await api({ action: 'detail', id })); detailCache.set(String(id), item); }
      activeDetailId = Number(item.id);
      const revealImage = () => els.imageWrap.classList.remove('switching');
      els.image.addEventListener('load', revealImage, { once: true });
      els.image.src = item.preview_url || item.thumb_url; els.image.alt = item.text || item.game;
      if (els.image.complete) revealImage();
      const theme = gameTheme(item.game); els.modal.querySelector('.detail-panel').className = `detail-panel theme-${theme.key}`; els.game.innerHTML = `<span class="detail-emblem" title="${theme.label}">${originMark(theme)}</span>${escapeHtml(item.game)} <span class="archive-label">${escapeHtml(item.archive_label || '')}</span>`; els.text.textContent = item.text || '未识别到文字';
      els.tags.replaceChildren(); els.submissionsBlock.hidden = true; els.submissions.replaceChildren(); els.glossaryBlock.hidden = true; els.communityBlock.hidden = true; els.community.replaceChildren(); els.corpusBlock.hidden = true; els.translationsBlock.hidden = true; els.translations.replaceChildren(); els.comparisonBlock.hidden = true; els.comparison.replaceChildren(); els.contextBlock.hidden = true; els.context.replaceChildren(); els.sequenceBlock.hidden = true; els.sequence.replaceChildren();
      els.correctionBlock.hidden = true; els.correctionForm.reset(); els.correctionStatus.textContent = '';
      els.tags.innerHTML = item.tags.map(tag => `<span class="tag">${escapeHtml(tag)}</span>`).join('');
      if (item.submission_variants?.length > 1) { els.submissionsBlock.hidden = false; els.submissions.innerHTML = item.submission_variants.map(x => `<button class="submission-item${Number(x.id) === Number(item.id) ? ' current' : ''}" type="button" data-id="${Number(x.id)}"><img src="${escapeHtml(x.thumb_url || x.preview_url)}" loading="lazy" alt=""><span><strong>${escapeHtml(x.contributor || '未标注')}</strong><small>#${Number(x.id)}${Number(x.id) === Number(item.id) ? ' · 当前' : ''}</small></span></button>`).join(''); }
      if (item.glossary.length) { els.glossaryBlock.hidden = false; els.glossary.innerHTML = item.glossary.map(x => `<div class="term-row"><b>${escapeHtml(x.canonical)}</b> · ${escapeHtml(x.category)} <span class="score">${escapeHtml(x.language)}</span></div>`).join(''); }
      if (item.community_tags?.length) { els.communityBlock.hidden = false; els.community.innerHTML = item.community_tags.map(x => `<span class="tag tag-community" title="社区标注 · ${Number(x.support_count)} 人确认">${escapeHtml(x.canonical)}<small>${escapeHtml(x.category)}</small></span>`).join(''); }
      if (item.corpus.length) { els.corpusBlock.hidden = false; els.corpus.innerHTML = item.corpus.map(x => `<div class="corpus-row">${escapeHtml(x.text)}<br><span class="score">${escapeHtml(x.collection)} · ${escapeHtml(x.language)} · ${(Number(x.score) * 100).toFixed(0)}%${x.match_status === 'needs_review' ? ' · 待审' : ''}</span></div>`).join(''); }
      if (item.translations?.length > 1) { els.translationsBlock.hidden = false; setTranslations(item.translations); }
      if (['宝可梦 朱', '宝可梦 紫'].includes(item.game)) {
        els.comparisonBlock.hidden = false;
        const targetGame = item.game === '宝可梦 朱' ? '宝可梦 紫' : '宝可梦 朱';
        if (item.version_matches?.length) {
          const candidates = item.version_matches.map((x, index) => `<button class="compare-candidate" type="button" data-id="${Number(x.id)}"><img src="${escapeHtml(x.thumb_url || x.preview_url)}" loading="lazy" alt="${escapeHtml(x.game)}"><span>${escapeHtml(x.game)} · #${Number(x.id)}${index ? ' · 候选 ' + (index + 1) : ''}</span></button>`).join('');
          els.comparison.innerHTML = `<div class="compare-pair"><div><img src="${escapeHtml(item.thumb_url || item.preview_url)}" alt="${escapeHtml(item.game)}"><span>${escapeHtml(item.game)} · #${Number(item.id)}</span></div>${candidates}</div>${item.version_matches.length > 1 ? '<p class="compare-note">规范化台词存在多个画面，请按缩略图选择对应版本。</p>' : '<p class="compare-note">依据忽略空格与换行后的 OCR 台词匹配。</p>'}`;
        } else {
          els.comparison.innerHTML = `<div class="missing-version"><strong>${escapeHtml(targetGame)} · 待补截图</strong><span>参考 ${escapeHtml(item.game)} #${Number(item.id)} 的剧情文本</span><p>${escapeHtml(item.text || '当前截图没有可用的 OCR 文本')}</p><button class="copy-missing" type="button" data-copy-text="${escapeHtml(item.text || '')}"${item.text ? '' : ' disabled'}>复制待补文本</button></div>`;
        }
      }
      if (item.storyContext?.length) {
        els.contextBlock.hidden = false;
        els.context.innerHTML = item.storyContext.map(line => {
          const screens = line.screenshots || [];
          const links = screens.map(x => `<button type="button" class="context-screen" data-id="${Number(x.id)}" title="${escapeHtml(x.source_game || x.game)} #${Number(x.id)}"><img src="${escapeHtml(x.thumb_url || x.preview_url)}" loading="lazy" alt=""><span>#${Number(x.id)}</span></button>`).join('');
          const media = screens.length ? links : `<button type="button" class="context-copy" data-copy-text="${escapeHtml(line.text)}">缺图 · 复制文本</button>`;
          return `<div class="context-line${line.current ? ' current' : ''}"><div class="context-text"><small>第 ${Number(line.line_no)} 行${line.current ? ' · 当前' : ''}</small><p>${escapeHtml(line.text)}</p></div><div class="context-media">${media}</div></div>`;
        }).join('');
      }
      activeSequence = item.storySequence || [];
      if (activeSequence.length > 1) { els.sequenceBlock.hidden = false; els.sequence.innerHTML = activeSequence.map(x => `<button class="sequence-item${Number(x.id) === Number(item.id) ? ' current' : ''}" type="button" data-id="${Number(x.id)}" title="${escapeHtml(x.text)}"><img src="${escapeHtml(x.thumb_url || x.preview_url)}" loading="lazy" alt=""><span>#${Number(x.line_no)} · ${escapeHtml(x.text || '剧情截图')}</span></button>`).join(''); }
      const position = activeSequence.findIndex(x => Number(x.id) === activeDetailId);
      els.prev.disabled = position <= 0;
      els.next.disabled = position < 0 || position >= activeSequence.length - 1;
      [activeSequence[position - 1], activeSequence[position + 1]].filter(Boolean).forEach(x => { const preload = new Image(); preload.src = x.preview_url || x.thumb_url; });
      els.meta.innerHTML = `${escapeHtml(item.source_game || item.game)} · ${escapeHtml(item.archive_label || '')}<br>贡献者：${escapeHtml(item.contributor || '未标注')}<br>${escapeHtml(item.filename)}<br>${Number(item.width) || '—'} × ${Number(item.height) || '—'} · #${item.id}`;
      els.copy.dataset.text = item.text || '';
    } catch (error) { els.imageWrap.classList.remove('switching'); els.game.textContent = '载入失败'; els.text.textContent = error.message; }
  }
  async function submitCorrection(event) {
    event.preventDefault();
    if (!activeDetailId) return;
    const submit = els.correctionForm.querySelector('[type="submit"]');
    submit.disabled = true;
    els.correctionStatus.className = 'form-status';
    els.correctionStatus.textContent = '正在提交…';
    try {
      const response = await fetch(new URL('api.php?action=submit_correction', location.href), {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({
          screenshot_id: activeDetailId,
          category: els.correctionCategory.value,
          suggested_text: els.correctionSuggested.value,
          description: els.correctionDescription.value,
          contact: els.correctionContact.value
        })
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || '提交失败');
      els.correctionStatus.className = 'form-status success';
      els.correctionStatus.textContent = data.duplicate ? '这条反馈已经提交过了，感谢提醒。' : '感谢反馈，已加入勘误汇总。';
      els.correctionSuggested.value = ''; els.correctionDescription.value = ''; els.correctionContact.value = '';
    } catch (error) {
      els.correctionStatus.className = 'form-status error';
      els.correctionStatus.textContent = error.message;
    } finally {
      submit.disabled = false;
    }
  }
  function closeModal(dialog) { dialog.close(); if (!els.modal.open && !els.about.open && !els.corrections.open) document.body.style.overflow = ''; }
  els.modalClose.addEventListener('click', () => closeModal(els.modal));
  els.modal.addEventListener('click', e => { if (e.target === els.modal) closeModal(els.modal); });
  els.copy.addEventListener('click', async () => { await navigator.clipboard.writeText(els.copy.dataset.text || ''); const old = els.copy.textContent; els.copy.textContent = '已复制'; setTimeout(() => els.copy.textContent = old, 1200); });
  els.correctionButton.addEventListener('click', () => { els.correctionBlock.hidden = false; els.correctionStatus.textContent = ''; els.correctionBlock.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); els.correctionSuggested.focus(); });
  els.correctionCancel.addEventListener('click', () => { els.correctionBlock.hidden = true; els.correctionStatus.textContent = ''; });
  els.correctionForm.addEventListener('submit', submitCorrection);
  els.sequence.addEventListener('click', event => { const button = event.target.closest('.sequence-item'); if (button) openDetail(button.dataset.id); });
  els.submissions.addEventListener('click', event => { const button = event.target.closest('.submission-item'); if (button && !button.classList.contains('current')) openDetail(button.dataset.id); });
  els.comparison.addEventListener('click', async event => {
    const candidate = event.target.closest('.compare-candidate');
    if (candidate) { openDetail(candidate.dataset.id); return; }
    const copy = event.target.closest('.copy-missing');
    if (copy && !copy.disabled) { await navigator.clipboard.writeText(copy.dataset.copyText || ''); const old = copy.textContent; copy.textContent = '已复制'; setTimeout(() => copy.textContent = old, 1200); }
  });
  els.context.addEventListener('click', async event => {
    const screen = event.target.closest('.context-screen');
    if (screen) { openDetail(screen.dataset.id); return; }
    const copy = event.target.closest('.context-copy');
    if (copy) { await navigator.clipboard.writeText(copy.dataset.copyText || ''); const old = copy.textContent; copy.textContent = '已复制'; setTimeout(() => copy.textContent = old, 1200); }
  });
  els.translationLeft.addEventListener('change', renderTranslationPair);
  els.translationRight.addEventListener('change', renderTranslationPair);
  function moveStory(direction) { const position = activeSequence.findIndex(x => Number(x.id) === activeDetailId); const target = activeSequence[position + direction]; if (target) openDetail(target.id); }
  els.prev.addEventListener('click', () => moveStory(-1));
  els.next.addEventListener('click', () => moveStory(1));
  let touchStartX = 0;
  els.imageWrap.addEventListener('touchstart', event => { touchStartX = event.changedTouches[0].clientX; }, { passive: true });
  els.imageWrap.addEventListener('touchend', event => { const distance = event.changedTouches[0].clientX - touchStartX; if (Math.abs(distance) > 50) moveStory(distance < 0 ? 1 : -1); }, { passive: true });
  function formatCorrectionDate(timestamp) {
    const date = new Date(Number(timestamp) * 1000);
    return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat('zh-CN', { dateStyle: 'medium' }).format(date);
  }
  function renderCorrections(data) {
    const stats = data.stats || {};
    els.correctionStats.innerHTML = '<span><strong>' + Number(stats.total || 0).toLocaleString() + '</strong> 条待处理反馈</span><span><strong>' + Number(stats.screenshots || 0).toLocaleString() + '</strong> 张涉及截图</span>';
    if (!data.reports?.length) {
      els.correctionList.innerHTML = '<div class="summary-empty">暂时还没有勘误反馈。</div>';
      return;
    }
    els.correctionList.innerHTML = data.reports.map(report => {
      const original = report.original_text ? '<div class="report-field"><small>当前 OCR</small><p>' + escapeHtml(report.original_text) + '</p></div>' : '';
      const suggested = report.suggested_text ? '<div class="report-field"><small>建议修正</small><p>' + escapeHtml(report.suggested_text) + '</p></div>' : '';
      const description = report.description ? '<div class="report-field"><small>问题说明</small><p>' + escapeHtml(report.description) + '</p></div>' : '';
      return '<article class="correction-entry"><div class="correction-entry-head"><span class="correction-category">' + escapeHtml(report.category) + '</span><time>' + escapeHtml(formatCorrectionDate(report.created_at)) + '</time></div><button class="correction-screenshot" type="button" data-id="' + Number(report.screenshot_id) + '"><img src="' + escapeHtml(report.thumb_url || report.preview_url) + '" loading="lazy" alt=""><span><strong>' + escapeHtml(report.game) + ' · #' + Number(report.screenshot_id) + '</strong><small>' + escapeHtml(report.filename) + '</small></span></button>' + original + suggested + description + '</article>';
    }).join('');
  }
  async function loadCorrections() {
    els.correctionStats.innerHTML = ''; els.correctionList.innerHTML = '<p class="summary-loading">正在载入勘误记录…</p>';
    try { renderCorrections(await api({ action: 'corrections' })); }
    catch (error) { els.correctionList.innerHTML = '<div class="summary-empty">' + escapeHtml(error.message) + '</div>'; }
  }
  $('#aboutButton').addEventListener('click', () => { els.about.showModal(); document.body.style.overflow = 'hidden'; });
  $('#aboutClose').addEventListener('click', () => closeModal(els.about));
  els.about.addEventListener('click', e => { if (e.target === els.about) closeModal(els.about); });
  $('#correctionsButton').addEventListener('click', () => { closeModal(els.about); els.corrections.showModal(); document.body.style.overflow = 'hidden'; loadCorrections(); });
  $('#correctionsClose').addEventListener('click', () => closeModal(els.corrections));
  els.corrections.addEventListener('click', e => {
    if (e.target === els.corrections) { closeModal(els.corrections); return; }
    const button = e.target.closest('.correction-screenshot');
    if (button) { closeModal(els.corrections); openDetail(button.dataset.id); }
  });
  document.addEventListener('keydown', e => { if (els.modal.open && e.key === 'ArrowLeft') { e.preventDefault(); moveStory(-1); } else if (els.modal.open && e.key === 'ArrowRight') { e.preventDefault(); moveStory(1); } else if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); els.input.focus(); } });
  new IntersectionObserver(entries => entries[0].isIntersecting && load(false), { rootMargin: '700px' }).observe(els.sentinel);

  const initial = new URLSearchParams(location.search); state.q = initial.get('q') || ''; state.game = initial.get('game') || ''; state.sort = ['random','story','terms','newest','oldest','id_asc','id_desc'].includes(initial.get('sort')) ? initial.get('sort') : 'random'; state.expandEvolution = initial.get('expand_evolution') === '1'; els.sort.value = state.sort; els.expandEvolutionToggle.checked = state.expandEvolution; els.input.value = state.q; els.form.classList.toggle('has-value', !!state.q); applyPageTheme(state.game); syncCompactHero();
  loadMeta(); load(true);
})();
