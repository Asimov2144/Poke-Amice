(() => {
  if (window.GalleryAnalytics) return;
  const disabled = () => navigator.doNotTrack === '1' || navigator.globalPrivacyControl === true || navigator.webdriver === true;
  let memoryVisitor = '', memorySession = null, queue = [], timer = 0;
  const uuid = () => crypto.randomUUID();
  const owner = () => {
    try {
      const option = new URLSearchParams(location.search).get('poke_owner');
      if (option === '1') localStorage.setItem('poke_analytics_owner', '1');
      else if (option === '0') localStorage.removeItem('poke_analytics_owner');
      return localStorage.getItem('poke_analytics_owner') === '1';
    } catch { return false; }
  };
  const identity = () => {
    const now = Date.now();
    const day = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Tokyo' }).format(now);
    let visitor, session;
    try {
      visitor = JSON.parse(localStorage.getItem('gallery_visitor_day') || 'null');
      if (!visitor || visitor.day !== day) { visitor = { day, id: uuid() }; localStorage.setItem('gallery_visitor_day', JSON.stringify(visitor)); }
    } catch { memoryVisitor ||= uuid(); visitor = { id: memoryVisitor }; }
    try { session = JSON.parse(sessionStorage.getItem('gallery_session') || 'null'); } catch { session = memorySession; }
    if (!session || now - session.at > 1800000) session = { id: uuid(), at: now };
    session.at = now; memorySession = session;
    try { sessionStorage.setItem('gallery_session', JSON.stringify(session)); } catch {}
    return { visitorId: visitor.id, sessionId: session.id };
  };
  const flush = () => {
    clearTimeout(timer); timer = 0;
    if (!queue.length) return;
    if (disabled() || owner()) { queue = []; return; }
    let batch;
    try {
      const events = queue.splice(0, 12);
      const source = document.referrer ? new URL(document.referrer).hostname : 'direct';
      batch = JSON.stringify({ ...identity(), source, events });
      fetch('/game_gallery/collect.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: batch, credentials: 'omit', keepalive: true }).catch(() => {});
    } catch {}
    if (queue.length) timer = setTimeout(flush, 250);
  };
  const track = (kind, fields = {}) => {
    try {
      if (disabled() || owner()) return '';
      const id = uuid();
      queue.push({ id, kind, page: location.pathname, ...fields });
      if (queue.length >= 12) flush();
      else if (!timer) timer = setTimeout(flush, 250);
      return id;
    } catch { return ''; }
  };
  window.GalleryAnalytics = Object.freeze({ track, enabled: () => !disabled() && !owner(),
    search(query, game, reason) {
      const q = Array.from(String(query || '').replace(/\s+/g, ' ').trim()).slice(0, 80).join('');
      return track('search_submit', { q, game, reason });
    },
    result(searchId, fields) { if (searchId) track('search_result', { searchId, ...fields }); }
  });
  addEventListener('pagehide', flush);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flush(); });
  document.addEventListener('click', event => {
    const shot = event.target.closest?.('a.shot[data-id]');
    if (shot && /^[1-9]\d*$/.test(shot.dataset.id)) track('screenshot_open', { screenshotId: Number(shot.dataset.id), reason: 'scene' });
  }, { capture: true });
  track('page_view');
})();
