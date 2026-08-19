<?php declare(strict_types=1); header('Cache-Control: no-cache, must-revalidate'); ?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="description" content="宝可梦友会 Game Snapshot：可通过 OCR 台词、地点、角色与术语搜索的宝可梦游戏截图资料库。">
  <meta name="theme-color" content="#f5f2eb">
  <link rel="icon" href="assets/snap3-logo.png" type="image/png" sizes="128x128">
  <link rel="apple-touch-icon" href="assets/snap3-logo.png">
  <title>宝可梦友会 Game Snapshot｜游戏截图搜索</title>
  <link rel="preconnect" href="https://gallery.pokeamice.com" crossorigin>
  <link rel="stylesheet" href="assets/gallery.css?v=27">
</head>
<body>
  <header class="topbar">
    <a class="brand" href="./" aria-label="返回 Game Snapshot 首页"><img class="brand-logo" src="assets/snap3-logo.png" alt=""><span>宝可梦友会 · Game Snapshot</span></a>
    <div class="topbar-actions">
      <nav class="site-nav" aria-label="宝可梦友会站点导航">
        <a href="https://pokeamice.com/" target="_blank" rel="noopener noreferrer"><img src="/icon/asimov/logo.png" alt=""><span><small>主站</small>宝可梦友会</span></a>
        <a href="https://pokeamice.com/event/" target="_blank" rel="noopener noreferrer"><img src="/event/app-icon-192.png" alt=""><span><small>Event</small>今日宝事</span></a>
        <a href="https://pokeamice.com/tour/" target="_blank" rel="noopener noreferrer"><img src="/tour/app-icon-192.png" alt=""><span><small>Tour</small>打卡旅图</span></a>
      </nav>
      <span class="topbar-divider" aria-hidden="true"></span>
      <a class="author-link" href="https://weibo.com/u/6306512172" target="_blank" rel="noopener noreferrer" aria-label="在微博查看作者 Asimov2144">
        <img class="author-avatar" src="https://raw.githubusercontent.com/PMDCollab/SpriteCollab/master/portrait/0026/0001/Normal.png" alt="阿罗拉雷丘" referrerpolicy="no-referrer">
        <span class="author-copy"><small>作者</small><strong>Asimov2144</strong></span>
      </a>
      <button class="about-button" id="aboutButton" type="button">关于</button>
    </div>
  </header>

  <main>
    <section class="hero" aria-labelledby="heroTitle">
      <p class="eyebrow">宝可梦友会 · 游戏截图语料库</p>
      <h1 id="heroTitle"><span class="pkmn-word" aria-label="PKMN"><span class="pkmn-p" aria-hidden="true">P</span><span class="pkmn-k" aria-hidden="true">K</span><span class="pkmn-m" aria-hidden="true">M</span><span class="pkmn-n" aria-hidden="true">N</span></span> Game <em>Snapshot</em></h1>
      <p class="intro">科学的力量好厉害！听说只要上传游戏截图，就可以跨越语言在想要的时候找到记忆里的那个画面。</p>
      <form class="search" id="searchForm" role="search">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m21 21-4.35-4.35m2.1-5.4a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z"/></svg>
        <input id="searchInput" type="search" enterkeyhint="search" autocomplete="off" placeholder="输入任意语言的台词、角色或地点…" aria-label="跨语言搜索游戏截图">
        <button class="clear-search" id="clearSearch" type="button" aria-label="清空搜索">×</button>
        <kbd>⌘ K</kbd>
      </form>
      <div class="filters" id="gameFilters" aria-label="按游戏筛选"></div>
    </section>

    <section class="results" aria-live="polite" aria-busy="true">
      <div class="result-head">
        <p id="resultStatus">正在整理图库…</p>
        <div class="result-actions"><label class="sort-label">排序 <select id="sortSelect" aria-label="排序方式"><option value="random">随机漫游</option><option value="story">剧情优先</option><option value="terms">术语最多</option><option value="newest">最新收录</option><option value="oldest">最早收录</option><option value="id_asc">截图 ID 正序</option><option value="id_desc">截图 ID 倒序</option></select></label><label class="sort-label" id="expandEvolutionLabel" hidden><input type="checkbox" id="expandEvolutionToggle"> 含进化/退化家族</label><button id="resetButton" class="text-button" type="button" hidden>清除筛选</button></div>
      </div>
      <div class="gallery" id="gallery"></div>
      <div class="load-state" id="loadState"><span class="spinner"></span><span>载入截图</span></div>
      <div id="sentinel" aria-hidden="true"></div>
    </section>
  </main>

  <dialog class="lightbox" id="lightbox">
    <button class="modal-close" id="modalClose" aria-label="关闭">×</button>
    <div class="lightbox-grid">
      <div class="lightbox-image-wrap" id="modalImageWrap"><button class="story-nav story-prev" id="storyPrev" type="button" aria-label="上一张剧情截图">‹</button><img id="modalImage" alt="" decoding="async"><button class="story-nav story-next" id="storyNext" type="button" aria-label="下一张剧情截图">›</button></div>
      <aside class="detail-panel">
        <p class="detail-game" id="modalGame"></p>
        <h2>识别文本</h2>
        <p class="ocr-text" id="modalText"></p>
        <div class="tag-list" id="modalTags"></div>
        <div class="detail-block" id="submissionsBlock" hidden><h3>投稿版本</h3><div class="submission-list" id="modalSubmissions"></div></div>
        <div class="detail-block" id="glossaryBlock" hidden><h3>术语</h3><div id="modalGlossary"></div></div>
        <div class="detail-block" id="communityBlock" hidden><h3>社区标注</h3><div class="tag-list" id="modalCommunity"></div></div>
        <div class="detail-block" id="corpusBlock" hidden><h3>语料关联</h3><div id="modalCorpus"></div></div>
        <div class="detail-block" id="translationsBlock" hidden><h3>多语言对照</h3><div class="translation-controls"><label>语言一<select id="translationLeft" aria-label="选择第一种对照语言"></select></label><span aria-hidden="true">⇄</span><label>语言二<select id="translationRight" aria-label="选择第二种对照语言"></select></label></div><div class="translation-list" id="modalTranslations"></div></div>
        <div class="detail-block" id="comparisonBlock" hidden><h3>朱／紫版本对比</h3><div id="modalComparison"></div></div>
        <div class="detail-block" id="contextBlock" hidden><h3>剧情上下文</h3><div class="story-context" id="modalContext"></div></div>
        <div class="detail-block" id="sequenceBlock" hidden><h3>前后剧情</h3><div class="story-sequence" id="modalSequence"></div></div>
        <div class="detail-meta" id="modalMeta"></div>
        <div class="detail-actions"><button class="copy-button" id="copyButton" type="button">复制识别文本</button><button class="correction-button" id="correctionButton" type="button">提交勘误</button></div>
        <div class="detail-block correction-form-wrap" id="correctionBlock" hidden>
          <h3>提交勘误</h3>
          <p class="form-hint">如果 OCR 或截图信息有误，请告诉我们需要修正的地方。</p>
          <form id="correctionForm" class="correction-form">
            <label>问题类型<select id="correctionCategory"><option>OCR文字错误</option><option>剧情匹配错误</option><option>截图信息错误</option><option>无效截图</option><option>其他问题</option></select></label>
            <label>建议修正后的文字<textarea id="correctionSuggested" rows="3" maxlength="2000" placeholder="例如：把识别出的文字改成……"></textarea></label>
            <label>问题说明<textarea id="correctionDescription" rows="3" maxlength="2000" placeholder="可补充具体错误位置、正确读法或其他信息"></textarea></label>
            <label>联系方式（可选）<input id="correctionContact" type="text" maxlength="200" placeholder="邮箱、社交账号等"></label>
            <p class="form-status" id="correctionStatus" role="status" aria-live="polite"></p>
            <div class="form-actions"><button class="text-button" id="correctionCancel" type="button">取消</button><button class="submit-button" type="submit">提交反馈</button></div>
          </form>
        </div>
      </aside>
    </div>
  </dialog>

  <dialog class="about" id="aboutDialog">
    <button class="modal-close" id="aboutClose" aria-label="关闭">×</button>
    <p class="eyebrow">ABOUT THE ARCHIVE</p>
    <h2>宝可梦友会 Game Snapshot</h2>
    <p>这里收录游戏截图及其 OCR 文本。输入画面中的只言片语，即可定位对应截图；点击图片可查看完整识别文本与相关术语。</p>
    <a class="summary-button" id="markButton" href="mark.php">人工标注 →</a>
    <button class="summary-button" id="correctionsButton" type="button">查看勘误汇总</button>
    <p class="about-author">由 <a href="https://weibo.com/u/6306512172" target="_blank" rel="noopener noreferrer">Asimov2144</a> 制作维护 · <a href="mailto:asimov2144@gmail.com">联系作者</a></p>
  </dialog>
  <dialog class="corrections" id="correctionsDialog">
    <button class="modal-close" id="correctionsClose" aria-label="关闭">×</button>
    <p class="eyebrow">CORRECTION LOG</p>
    <h2>勘误汇总</h2>
    <p class="summary-intro">这里集中展示大家反馈的 OCR 与截图信息问题，后续会根据反馈修正资料。</p>
    <div class="summary-stats" id="correctionStats"></div>
    <div class="correction-list" id="correctionList"><p class="summary-loading">正在载入勘误记录…</p></div>
  </dialog>
  <script src="assets/gallery.js?v=26" defer></script>
</body>
</html>
