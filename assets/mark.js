(() => {
  const $ = (selector, root = document) => root.querySelector(selector);
  const els = {
    identityStatus: $('#identityStatus'), identityAction: $('#identityAction'),
    modeSelect: $('#modeSelect'), categoryPills: document.querySelectorAll('.category-pills'),
    gameGrid: $('#gameGrid'), startCaseMode: $('#startCaseMode'),
    quizShell: $('#quizShell'), quizBack: $('#quizBack'),
    statRound: $('#statRound'), statLevel: $('#statLevel'), statStreak: $('#statStreak'), statXp: $('#statXp'),
    quizCard: $('#quizCard'), quizImage: $('#quizImage'), quizImageWrap: $('#quizImageWrap'), quizImageHint: $('#quizImageHint'), quizGame: $('#quizGame'), quizOcr: $('#quizOcr'),
    caseFields: $('#caseFields'), caseActions: $('#caseActions'), caseSkip: $('#caseSkip'), caseSubmit: $('#caseSubmit'), caseInvalid: $('#caseInvalid'),
    verifyControls: $('#verifyControls'), verifyQuestion: $('#verifyQuestion'), verifyActionsBar: $('#verifyActionsBar'),
    verifyWrong: $('#verifyWrong'), verifyUnsure: $('#verifyUnsure'), verifyCorrect: $('#verifyCorrect'),
    emptyPool: $('#emptyPool'), quizToast: $('#quizToast'),
    roundComplete: $('#roundComplete'), roundStats: $('#roundStats'),
    roundLeaderboardList: $('#roundLeaderboardList'), roundLeaderboardMe: $('#roundLeaderboardMe'),
    roundProgressTitle: $('#roundProgressTitle'), roundProgress: $('#roundProgress'),
    roundContinue: $('#roundContinue'), roundSwitchGame: $('#roundSwitchGame'), roundFinish: $('#roundFinish'),
    authDialog: $('#authDialog'), authClose: $('#authClose'), authTabLogin: $('#authTabLogin'), authTabRegister: $('#authTabRegister'),
    loginForm: $('#loginForm'), loginUsername: $('#loginUsername'), loginPassword: $('#loginPassword'), loginStatus: $('#loginStatus'),
    registerForm: $('#registerForm'), registerUsername: $('#registerUsername'), registerEmail: $('#registerEmail'),
    sendCodeButton: $('#sendCodeButton'), registerCode: $('#registerCode'), registerPassword: $('#registerPassword'), registerStatus: $('#registerStatus'),
    leaderboardButton: $('#leaderboardButton'), leaderboardDialog: $('#leaderboardDialog'), leaderboardClose: $('#leaderboardClose'),
    leaderboardList: $('#leaderboardList'), leaderboardMe: $('#leaderboardMe'),
  };

  const ROUND_SIZE = 10;
  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[c]));

  // 作品图标/主题色：和 gallery.js 里的同名函数保持一致，两边各自一份 IIFE，不共享模块。
  const gameTheme = game => {
    if (game === 'Pokémon LEGENDS Z-A') return { key: 'za', label: 'Pokémon LEGENDS Z-A', icon: 'HOME_Legends_Z-A_icon.png' };
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
  const applyPageTheme = game => { document.body.dataset.gameTheme = game ? gameTheme(game).key : 'default'; };

  const state = {
    mode: '', category: '', card: null, targetType: '', targetKey: '', claimText: '',
    fields: [], // case 模式：[{category, chips:[{raw_input}], noneOfAbove}]
    username: '', identityKind: 'guest',
    game: '', // '' = 随便看看，否则是 games_progress.php 里的 canonical game 名
    roundAnswered: 0, roundXp: 0, roundGameProgressBefore: null,
  };

  // --- 身份：登录复用 event 应用的账号体系（localStorage['poke_auth_token'] 是 event 前端
  // 实际在用的 key，同源共享，所以在 /event/ 登录过的人打开这页会自动已登录），游客用独立的
  // gg_guest_id（不会和 event 的 token 混淆）。
  function getGuestId() {
    let id = localStorage.getItem('gg_guest_id');
    if (!id) {
      id = (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
        const r = Math.random() * 16 | 0;
        return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
      }));
      localStorage.setItem('gg_guest_id', id);
    }
    return id;
  }
  const getToken = () => localStorage.getItem('poke_auth_token') || '';

  async function api(path, options = {}) {
    const headers = Object.assign({ Accept: 'application/json' }, options.headers || {});
    const token = getToken();
    if (token) {
      // 这台服务器的 nginx+PHP-FPM 配置不转发标准 Authorization 头（常见坑），
      // 所以主要靠这个自定义头把 token 带给后端；Authorization 头一起发，
      // 服务端配置以后修好了也不用再改前端。
      headers['X-GG-Auth-Token'] = token;
      headers.Authorization = `Bearer ${token}`;
    } else {
      headers['X-GG-Guest-Id'] = getGuestId();
    }
    if (options.body) headers['Content-Type'] = 'application/json';
    const response = await fetch(path, Object.assign({}, options, { headers }));
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) throw new Error(data.error?.message || '请求失败');
    return data.data;
  }

  async function eventApi(path, body) {
    const response = await fetch(`/event/api/${path}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.success === false) throw new Error(data.error || '请求失败');
    return data;
  }

  function applyStats(stats) {
    if (!stats) return;
    const pulse = el => { el.classList.remove('pulse'); void el.offsetWidth; el.classList.add('pulse'); };
    if (els.statLevel.textContent !== `等级 ${stats.level}`) { els.statLevel.textContent = `等级 ${stats.level}`; pulse(els.statLevel); }
    if (els.statStreak.textContent !== `连击 ${stats.streak}`) { els.statStreak.textContent = `连击 ${stats.streak}`; pulse(els.statStreak); }
    if (els.statXp.textContent !== `经验 ${stats.xp}`) { els.statXp.textContent = `经验 ${stats.xp}`; pulse(els.statXp); }
  }

  let toastTimer;
  function toast(text) {
    els.quizToast.textContent = text;
    els.quizToast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => els.quizToast.classList.remove('show'), 1400);
  }

  // --- 身份状态条 ---
  async function refreshIdentity() {
    const token = getToken();
    if (!token) { setGuestIdentity(); return; }
    try {
      const response = await fetch('/event/api/me', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
      const data = await response.json().catch(() => ({}));
      const username = data.preferences?.username;
      if (!response.ok || !username) throw new Error('invalid');
      state.identityKind = 'event_user';
      state.username = username;
      els.identityStatus.textContent = `已登录：${username}`;
      els.identityStatus.classList.add('logged-in');
      els.identityAction.textContent = '退出';
    } catch (_) {
      localStorage.removeItem('poke_auth_token');
      setGuestIdentity();
    }
  }
  function setGuestIdentity() {
    state.identityKind = 'guest';
    state.username = '';
    els.identityStatus.textContent = '游客模式';
    els.identityStatus.classList.remove('logged-in');
    els.identityAction.textContent = '登录';
    getGuestId();
  }
  els.identityAction.addEventListener('click', () => {
    if (state.identityKind === 'event_user') {
      localStorage.removeItem('poke_auth_token');
      setGuestIdentity();
      toast('已退出登录');
      return;
    }
    els.authDialog.showModal();
  });

  // --- 登录/注册弹窗 ---
  function switchAuthTab(tab) {
    const isLogin = tab === 'login';
    els.authTabLogin.classList.toggle('active', isLogin);
    els.authTabRegister.classList.toggle('active', !isLogin);
    els.loginForm.hidden = !isLogin;
    els.registerForm.hidden = isLogin;
  }
  els.authTabLogin.addEventListener('click', () => switchAuthTab('login'));
  els.authTabRegister.addEventListener('click', () => switchAuthTab('register'));
  els.authClose.addEventListener('click', () => els.authDialog.close());
  els.authDialog.addEventListener('click', e => { if (e.target === els.authDialog) els.authDialog.close(); });

  els.loginForm.addEventListener('submit', async event => {
    event.preventDefault();
    els.loginStatus.className = 'form-status';
    els.loginStatus.textContent = '正在登录…';
    try {
      const data = await eventApi('login', { username: els.loginUsername.value.trim(), password: els.loginPassword.value });
      localStorage.setItem('poke_auth_token', data.token);
      els.loginStatus.className = 'form-status success';
      els.loginStatus.textContent = '登录成功';
      await refreshIdentity();
      setTimeout(() => els.authDialog.close(), 400);
    } catch (error) {
      els.loginStatus.className = 'form-status error';
      els.loginStatus.textContent = error.message;
    }
  });

  let sendCodeCountdown = 0;
  els.sendCodeButton.addEventListener('click', async () => {
    if (sendCodeCountdown > 0) return;
    const email = els.registerEmail.value.trim();
    if (!email) { els.registerStatus.className = 'form-status error'; els.registerStatus.textContent = '请先填写邮箱'; return; }
    els.registerStatus.className = 'form-status';
    els.registerStatus.textContent = '正在发送验证码…';
    try {
      await eventApi('send-code', { email });
      els.registerStatus.className = 'form-status success';
      els.registerStatus.textContent = '验证码已发送，10 分钟内有效';
      sendCodeCountdown = 60;
      const tick = () => {
        if (sendCodeCountdown <= 0) { els.sendCodeButton.disabled = false; els.sendCodeButton.textContent = '发送验证码'; return; }
        els.sendCodeButton.disabled = true;
        els.sendCodeButton.textContent = `${sendCodeCountdown}s 后重发`;
        sendCodeCountdown--;
        setTimeout(tick, 1000);
      };
      tick();
    } catch (error) {
      els.registerStatus.className = 'form-status error';
      els.registerStatus.textContent = error.message;
    }
  });

  els.registerForm.addEventListener('submit', async event => {
    event.preventDefault();
    els.registerStatus.className = 'form-status';
    els.registerStatus.textContent = '正在注册…';
    const email = els.registerEmail.value.trim();
    try {
      await eventApi('verify-code', { email, code: els.registerCode.value.trim() });
      const data = await eventApi('register', {
        username: els.registerUsername.value.trim(),
        password: els.registerPassword.value,
        email,
      });
      localStorage.setItem('poke_auth_token', data.token);
      els.registerStatus.className = 'form-status success';
      els.registerStatus.textContent = '注册成功';
      await refreshIdentity();
      setTimeout(() => els.authDialog.close(), 400);
    } catch (error) {
      els.registerStatus.className = 'form-status error';
      els.registerStatus.textContent = error.message;
    }
  });

  // --- 排行榜（独立弹窗 + 轮次结算面板共用同一套渲染逻辑） ---
  function renderLeaderboardRow(entry) {
    return `<div class="leaderboard-row${entry.is_guest ? ' is-guest' : ''}">
      <span class="leaderboard-rank">#${entry.rank}</span>
      <span class="leaderboard-name">${escapeHtml(entry.display_name)}</span>
      <span class="leaderboard-level">Lv.${entry.level}</span>
      <span class="leaderboard-xp">${entry.xp} 经验</span>
    </div>`;
  }
  async function loadLeaderboardInto(listEl, meEl) {
    listEl.innerHTML = '<p class="summary-loading">正在载入排行榜…</p>';
    meEl.hidden = true;
    try {
      const data = await api('api/v1/tagging/leaderboard.php');
      const entries = data.entries || [];
      listEl.innerHTML = entries.length
        ? entries.map(renderLeaderboardRow).join('')
        : '<p class="summary-empty">还没有人上榜，快去标注几张截图吧。</p>';
      if (data.me && !entries.some(e => e.rank === data.me.rank)) {
        meEl.innerHTML = `<span class="leaderboard-me-label">我的排名</span>${renderLeaderboardRow(data.me)}`;
        meEl.hidden = false;
      }
    } catch (error) {
      listEl.innerHTML = `<p class="summary-empty">${escapeHtml(error.message)}</p>`;
    }
  }
  els.leaderboardButton.addEventListener('click', () => { els.leaderboardDialog.showModal(); loadLeaderboardInto(els.leaderboardList, els.leaderboardMe); });
  els.leaderboardClose.addEventListener('click', () => els.leaderboardDialog.close());
  els.leaderboardDialog.addEventListener('click', e => { if (e.target === els.leaderboardDialog) els.leaderboardDialog.close(); });

  // --- 选择作品 ---
  function gameCardHtml(game, progress, active) {
    const theme = gameTheme(game);
    const pct = Math.round(progress * 100);
    return `<button type="button" class="game-card theme-${theme.key}${active ? ' active' : ''}" data-game="${escapeHtml(game)}">
      <span class="filter-emblem">${originMark(theme)}</span>
      <strong>${escapeHtml(game)}</strong>
      <span class="game-card-bar"><span style="width:${pct}%"></span></span>
      <small>${pct}% 已标注</small>
    </button>`;
  }
  async function loadGamePicker() {
    els.gameGrid.innerHTML = '<p class="summary-loading">正在载入作品列表…</p>';
    try {
      const data = await api('api/v1/tagging/games_progress.php');
      const games = data.games || [];
      const anyCard = `<button type="button" class="game-card game-card-any${state.game === '' ? ' active' : ''}" data-game="">
        <strong>随便看看</strong><small>全部作品混合</small>
      </button>`;
      els.gameGrid.innerHTML = anyCard + games.map(g => gameCardHtml(g.game, g.progress, state.game === g.game)).join('');
    } catch (error) {
      els.gameGrid.innerHTML = `<p class="summary-empty">${escapeHtml(error.message)}</p>`;
    }
  }
  els.gameGrid.addEventListener('click', event => {
    const button = event.target.closest('.game-card');
    if (!button) return;
    state.game = button.dataset.game || '';
    els.gameGrid.querySelectorAll('.game-card').forEach(el => el.classList.toggle('active', el === button));
    applyPageTheme(state.game);
  });
  loadGamePicker();

  // --- 模式选择：标注模式走同屏多字段 Case，核验模式仍按分类选择 ---
  els.startCaseMode.addEventListener('click', () => startQuiz('case', ''));
  els.categoryPills.forEach(group => {
    group.addEventListener('click', event => {
      const button = event.target.closest('.category-pill');
      if (!button) return;
      startQuiz(group.dataset.mode, button.dataset.category);
    });
  });
  els.quizBack.addEventListener('click', () => {
    els.quizShell.hidden = true;
    els.roundComplete.hidden = true;
    els.modeSelect.hidden = false;
  });

  // 点击截图放大到原始分辨率查看细节（比如判断人群里的小角色、远处的地标），
  // 放大后容器变成可滚动，靠原生滚动条平移；再点一次缩回适配容器的大小。
  els.quizImageWrap.addEventListener('click', () => {
    const zoomed = els.quizImageWrap.classList.toggle('zoomed');
    els.quizImageWrap.title = zoomed ? '点击缩小' : '点击放大查看细节';
    els.quizImageHint.textContent = zoomed ? '点击缩小' : '点击放大';
  });

  function questionForField(category) {
    return { '宝可梦': '图中出现了哪些宝可梦？', '人物': '图中出现了哪些人物？', '地点': '这里最可能是哪里？' }[category] || '';
  }
  function questionForVerify(category, claim) {
    const c = escapeHtml(claim);
    return {
      '宝可梦': `这张截图中有「${c}」——对吗？`,
      '人物': `这张截图中出现了「${c}」——对吗？`,
      '地点': `这张截图取景于「${c}」——对吗？`,
      '剧情': `这句台词识别可能有误：「${c}」——这段文字是对的吗？`,
    }[category] || `「${c}」——对吗？`;
  }

  // --- Case 卡片：一屏最多 3 个字段问题块，逐字段维护 chips / "这张图没有" 状态 ---
  function caseFieldHtml(field, index) {
    const chips = field.chips.map((chip, ci) => `<span class="chip">${escapeHtml(chip.raw_input)}<button type="button" data-chip-index="${ci}" aria-label="移除">×</button></span>`).join('');
    return `<div class="case-field${field.noneOfAbove ? ' is-none' : ''}" data-index="${index}">
      <p class="quiz-question">${escapeHtml(questionForField(field.category))}</p>
      <div class="chip-list" data-chip-list>${chips}</div>
      <div class="chip-input-wrap">
        <input type="text" maxlength="60" placeholder="输入名称，或从联想里选一个" autocomplete="off"${field.noneOfAbove ? ' disabled' : ''}>
        <div class="suggest-dropdown" hidden></div>
      </div>
      <button type="button" class="text-button case-field-none">${field.noneOfAbove ? '✓ 这张图没有' : '这张图没有'}</button>
    </div>`;
  }
  function renderCaseFields() {
    els.caseFields.innerHTML = state.fields.map((field, index) => caseFieldHtml(field, index)).join('');
  }
  function fieldEl(index) { return els.caseFields.querySelector(`.case-field[data-index="${index}"]`); }
  function renderFieldChips(index) {
    const el = fieldEl(index);
    if (!el) return;
    el.querySelector('[data-chip-list]').innerHTML = state.fields[index].chips
      .map((chip, ci) => `<span class="chip">${escapeHtml(chip.raw_input)}<button type="button" data-chip-index="${ci}" aria-label="移除">×</button></span>`).join('');
  }
  function addFieldChip(index, rawInput) {
    const field = state.fields[index];
    if (!field || field.noneOfAbove) return;
    const value = rawInput.trim();
    if (!value || field.chips.length >= 8) return;
    if (field.chips.some(c => c.raw_input === value)) return;
    field.chips.push({ raw_input: value });
    renderFieldChips(index);
  }

  els.caseFields.addEventListener('click', event => {
    const chipRemove = event.target.closest('button[data-chip-index]');
    if (chipRemove) {
      const index = Number(chipRemove.closest('.case-field').dataset.index);
      state.fields[index].chips.splice(Number(chipRemove.dataset.chipIndex), 1);
      renderFieldChips(index);
      return;
    }
    const noneButton = event.target.closest('.case-field-none');
    if (noneButton) {
      const index = Number(noneButton.closest('.case-field').dataset.index);
      state.fields[index].noneOfAbove = !state.fields[index].noneOfAbove;
      if (state.fields[index].noneOfAbove) state.fields[index].chips = [];
      const el = fieldEl(index);
      if (el) el.outerHTML = caseFieldHtml(state.fields[index], index);
      return;
    }
    const suggestItem = event.target.closest('.suggest-item');
    if (suggestItem) {
      const fieldDiv = suggestItem.closest('.case-field');
      const index = Number(fieldDiv.dataset.index);
      addFieldChip(index, suggestItem.dataset.name);
      const input = fieldDiv.querySelector('.chip-input-wrap input');
      if (input) input.value = '';
      const dropdown = fieldDiv.querySelector('.suggest-dropdown');
      dropdown.hidden = true; dropdown.innerHTML = '';
    }
  });

  let caseSuggestDebounce;
  els.caseFields.addEventListener('input', event => {
    const input = event.target.closest('.chip-input-wrap input');
    if (!input) return;
    const fieldDiv = input.closest('.case-field');
    const index = Number(fieldDiv.dataset.index);
    const dropdown = fieldDiv.querySelector('.suggest-dropdown');
    clearTimeout(caseSuggestDebounce);
    const q = input.value.trim();
    if (!q) { dropdown.hidden = true; dropdown.innerHTML = ''; return; }
    caseSuggestDebounce = setTimeout(async () => {
      try {
        const params = new URLSearchParams({ category: state.fields[index].category, q });
        const data = await api(`api/v1/tagging/suggest.php?${params}`);
        const items = data.suggestions || [];
        if (!items.length) { dropdown.hidden = true; dropdown.innerHTML = ''; return; }
        dropdown.innerHTML = items.map(item => `<button type="button" class="suggest-item" data-name="${escapeHtml(item.canonical)}">${escapeHtml(item.canonical)}<small>${escapeHtml(item.matched_via)}</small></button>`).join('');
        dropdown.hidden = false;
      } catch (_) { dropdown.hidden = true; dropdown.innerHTML = ''; }
    }, 200);
  });
  els.caseFields.addEventListener('keydown', event => {
    if (event.key !== 'Enter') return;
    const input = event.target.closest('.chip-input-wrap input');
    if (!input) return;
    event.preventDefault();
    const fieldDiv = input.closest('.case-field');
    const index = Number(fieldDiv.dataset.index);
    addFieldChip(index, input.value);
    input.value = '';
    const dropdown = fieldDiv.querySelector('.suggest-dropdown');
    dropdown.hidden = true; dropdown.innerHTML = '';
  });
  document.addEventListener('click', event => {
    if (event.target.closest('.chip-input-wrap')) return;
    els.caseFields.querySelectorAll('.suggest-dropdown').forEach(dd => { dd.hidden = true; dd.innerHTML = ''; });
  });

  function renderCard() {
    const card = state.card;
    els.emptyPool.hidden = !!card;
    els.quizCard.hidden = !card;
    if (!card) return;
    els.quizImage.src = card.preview_url || card.thumb_url;
    els.quizImage.alt = card.text || card.game;
    els.quizImageWrap.classList.remove('zoomed'); // 换下一张截图时重置放大状态
    const theme = gameTheme(card.game);
    els.quizGame.className = `quiz-game theme-${theme.key}`;
    els.quizGame.innerHTML = `<span class="detail-emblem">${originMark(theme)}</span>${escapeHtml(card.game || '')} · #${card.id}`;
    applyPageTheme(card.game);
    els.quizOcr.textContent = card.text || '';

    const isCase = state.mode === 'case';
    els.caseFields.hidden = !isCase;
    els.caseActions.hidden = !isCase;
    els.verifyControls.hidden = isCase;
    els.verifyActionsBar.hidden = isCase;
    if (isCase) {
      renderCaseFields();
    } else {
      els.verifyQuestion.innerHTML = questionForVerify(state.category, state.claimText);
    }
  }

  async function loadNext() {
    els.quizCard.hidden = true;
    els.emptyPool.hidden = true;
    try {
      if (state.mode === 'case') {
        const params = state.game ? new URLSearchParams({ game: state.game }) : new URLSearchParams();
        const data = await api(`api/v1/tagging/next_case.php?${params}`);
        state.card = data.card;
        state.fields = (data.missing_fields || []).map(category => ({ category, chips: [], noneOfAbove: false }));
        applyStats(data.stats);
      } else {
        const data = await api(`api/v1/tagging/next_verify.php?${new URLSearchParams({ category: state.category })}`);
        state.card = data.card;
        state.targetType = data.target_type;
        state.targetKey = data.target_key;
        state.claimText = data.claim_text || '';
        applyStats(data.stats);
      }
      renderCard();
    } catch (error) {
      toast(error.message);
    }
  }

  // --- 十题一轮 ---
  function updateRoundLabel() {
    els.statRound.textContent = `本轮 ${Math.min(state.roundAnswered, ROUND_SIZE)}/${ROUND_SIZE}`;
  }
  async function captureRoundProgressBaseline() {
    if (state.mode !== 'case' || !state.game) { state.roundGameProgressBefore = null; return; }
    try {
      const data = await api('api/v1/tagging/games_progress.php');
      const entry = (data.games || []).find(g => g.game === state.game);
      state.roundGameProgressBefore = entry ? entry.progress : null;
    } catch (_) { state.roundGameProgressBefore = null; }
  }
  async function loadRoundProgress() {
    if (state.mode !== 'case' || !state.game) { els.roundProgressTitle.hidden = true; els.roundProgress.innerHTML = ''; return; }
    els.roundProgressTitle.hidden = false;
    els.roundProgress.innerHTML = '<p class="summary-loading">正在更新进度…</p>';
    try {
      const data = await api('api/v1/tagging/games_progress.php');
      const entry = (data.games || []).find(g => g.game === state.game);
      if (!entry) { els.roundProgress.innerHTML = ''; return; }
      const after = Math.round(entry.progress * 100);
      const before = state.roundGameProgressBefore != null ? Math.round(state.roundGameProgressBefore * 100) : null;
      const delta = before != null && after > before ? `（本轮 +${after - before}%）` : '';
      els.roundProgress.innerHTML = `<div class="progress-bar"><span style="width:${after}%"></span></div><p>${escapeHtml(state.game)} 标注进度 ${after}%${delta}</p>`;
    } catch (error) {
      els.roundProgress.innerHTML = `<p class="summary-empty">${escapeHtml(error.message)}</p>`;
    }
  }
  function showRoundComplete() {
    els.quizCard.hidden = true;
    els.emptyPool.hidden = true;
    els.roundComplete.hidden = false;
    const streakText = els.statStreak.textContent.replace('连击 ', '');
    els.roundStats.innerHTML = `<span><strong>+${state.roundXp}</strong> 经验</span><span><strong>${escapeHtml(streakText)}</strong> 当前连击</span>`;
    loadLeaderboardInto(els.roundLeaderboardList, els.roundLeaderboardMe);
    loadRoundProgress();
  }
  function advanceRound(xpGained) {
    state.roundAnswered++;
    state.roundXp += Math.max(0, xpGained);
    updateRoundLabel();
    if (state.roundAnswered >= ROUND_SIZE) showRoundComplete();
    else loadNext();
  }
  els.roundContinue.addEventListener('click', () => startQuiz(state.mode, state.category));
  els.roundSwitchGame.addEventListener('click', () => {
    els.roundComplete.hidden = true;
    els.quizShell.hidden = true;
    els.modeSelect.hidden = false;
    loadGamePicker();
  });
  els.roundFinish.addEventListener('click', () => { window.location.href = './'; });

  function startQuiz(mode, category) {
    state.mode = mode;
    state.category = category;
    state.roundAnswered = 0;
    state.roundXp = 0;
    updateRoundLabel();
    els.modeSelect.hidden = true;
    els.quizShell.hidden = false;
    els.roundComplete.hidden = true;
    captureRoundProgressBaseline();
    loadNext();
  }

  async function submitCase(skipAll) {
    if (!state.card) return;
    const fields = state.fields.map(field => {
      if (skipAll) return { category: field.category, answer_kind: 'skip' };
      if (field.noneOfAbove) return { category: field.category, answer_kind: 'none_of_above' };
      if (field.chips.length) return { category: field.category, answer_kind: 'present', answers: field.chips.map(c => ({ raw_input: c.raw_input })) };
      return { category: field.category, answer_kind: 'skip' };
    });
    try {
      const data = await api('api/v1/tagging/submit_case.php', {
        method: 'POST',
        body: JSON.stringify({ screenshot_id: state.card.id, fields }),
      });
      applyStats(data.stats);
      toast(data.xp_gained > 0 ? `+${data.xp_gained} 经验` : '已跳过');
      advanceRound(data.xp_gained || 0);
    } catch (error) {
      toast(error.message);
    }
  }
  els.caseSubmit.addEventListener('click', () => submitCase(false));
  els.caseSkip.addEventListener('click', () => submitCase(true));

  // 标记无效截图：比如台词还没显示完就被截了图。复用已有的勘误举报表（correction_reports），
  // 不建新表——"无效截图"就是勘误问题类型的一种，走人工复核这条既有路线，不自动删除截图。
  async function reportInvalidScreenshot() {
    if (!state.card) return;
    try {
      await api('api/v1/corrections.php', {
        method: 'POST',
        body: JSON.stringify({
          screenshot_id: state.card.id,
          category: '无效截图',
          description: '众包标注时举报：画面或台词未完整显示，截图不适合收录。',
        }),
      });
      toast('已举报，感谢帮忙筛选');
      advanceRound(0);
    } catch (error) {
      toast(error.message);
    }
  }
  els.caseInvalid.addEventListener('click', reportInvalidScreenshot);

  async function submitVerify(vote) {
    if (!state.card) return;
    try {
      await api('api/v1/tagging/submit_verify.php', {
        method: 'POST',
        body: JSON.stringify({
          screenshot_id: state.card.id,
          category: state.category,
          target_type: state.targetType,
          target_key: state.targetKey,
          claim_text: state.claimText,
          vote,
        }),
      });
      toast('+8 经验');
      advanceRound(8);
    } catch (error) {
      toast(error.message);
    }
  }
  els.verifyCorrect.addEventListener('click', () => submitVerify('正确'));
  els.verifyWrong.addEventListener('click', () => submitVerify('错误'));
  els.verifyUnsure.addEventListener('click', () => submitVerify('不确定'));

  document.addEventListener('keydown', event => {
    if (els.quizShell.hidden || els.authDialog.open || !els.roundComplete.hidden) return;
    if (event.target.tagName === 'INPUT') return;
    if (state.mode === 'verify') {
      if (event.key === '1') submitVerify('错误');
      else if (event.key === '2') submitVerify('不确定');
      else if (event.key === '3') submitVerify('正确');
    }
  });

  refreshIdentity();
})();
