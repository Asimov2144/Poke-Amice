<?php declare(strict_types=1); header('Cache-Control: no-cache, must-revalidate'); ?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="description" content="宝可梦友会 Game Snapshot 人工标注：给截图标记宝可梦／人物／地点，或核验已有标记，答题闯关式的众包协作。">
  <meta name="theme-color" content="#f5f2eb">
  <link rel="icon" href="assets/snap3-logo.png" type="image/png" sizes="128x128">
  <link rel="apple-touch-icon" href="assets/snap3-logo.png">
  <title>人工标注｜宝可梦友会 Game Snapshot</title>
  <link rel="preconnect" href="https://gallery.pokeamice.com" crossorigin>
  <link rel="stylesheet" href="assets/gallery.css?v=27">
  <link rel="stylesheet" href="assets/mark.css?v=7">
</head>
<body class="mark-page">
  <header class="topbar">
    <a class="brand" href="./" aria-label="返回 Game Snapshot 首页"><img class="brand-logo" src="assets/snap3-logo.png" alt=""><span>宝可梦友会 · Game Snapshot</span></a>
    <div class="topbar-actions">
      <nav class="site-nav" aria-label="宝可梦友会站点导航">
        <a href="./"><img src="assets/snap3-logo.png" alt=""><span><small>Gallery</small>返回图库</span></a>
        <a href="https://pokeamice.com/" target="_blank" rel="noopener noreferrer"><img src="/icon/asimov/logo.png" alt=""><span><small>主站</small>宝可梦友会</span></a>
      </nav>
      <span class="topbar-divider" aria-hidden="true"></span>
      <button class="about-button" id="leaderboardButton" type="button">排行榜</button>
      <div class="identity-bar" id="identityBar">
        <span class="identity-status" id="identityStatus">游客模式</span>
        <button class="text-button" id="identityAction" type="button">登录</button>
      </div>
    </div>
  </header>

  <main class="mark-shell">
    <section class="mode-select" id="modeSelect">
      <p class="eyebrow">标记 · 答题闯关</p>
      <h1>帮截图打上人工标记</h1>
      <p class="intro">OCR 认得出文字，认不出画面里的宝可梦、人物和地点。花几秒钟判断一张图，就能让搜索更准。登录或游客模式都可以玩。</p>

      <div class="game-picker">
        <h2>选择要调查的作品</h2>
        <p class="form-hint">挑一部你熟悉的作品，标注质量会更高；也可以不挑，随机看全部作品。</p>
        <div class="game-grid" id="gameGrid"><p class="summary-loading">正在载入作品列表…</p></div>
      </div>

      <div class="mode-grid">
        <article class="mode-card">
          <h2>标注模式</h2>
          <p>一次调查一张截图：缺宝可梦就问宝可梦，缺人物、地点也会一起问，不用分开标三次。</p>
          <button type="button" class="submit-button" id="startCaseMode">开始调查</button>
        </article>
        <article class="mode-card">
          <h2>核验模式</h2>
          <p>判断已有的标记是不是对的。</p>
          <div class="category-pills" data-mode="verify">
            <button type="button" class="category-pill" data-category="宝可梦">宝可梦</button>
            <button type="button" class="category-pill" data-category="人物">人物</button>
            <button type="button" class="category-pill" data-category="地点">地点</button>
            <button type="button" class="category-pill" data-category="剧情">疑难调查</button>
          </div>
        </article>
      </div>
    </section>

    <section class="quiz-shell" id="quizShell" hidden>
      <div class="stats-bar" id="statsBar">
        <button class="text-button" id="quizBack" type="button">‹ 返回选择</button>
        <div class="stats-values">
          <span id="statRound">本轮 0/10</span>
          <span id="statLevel">等级 1</span>
          <span id="statStreak">连击 0</span>
          <span id="statXp">经验 0</span>
        </div>
      </div>

      <div class="quiz-card" id="quizCard" hidden>
        <div class="quiz-image-wrap" id="quizImageWrap" title="点击放大查看细节">
          <img id="quizImage" alt="" decoding="async">
          <span class="quiz-image-hint" id="quizImageHint">点击放大</span>
        </div>
        <div class="quiz-panel">
          <div class="quiz-panel-scroll">
            <p class="quiz-game" id="quizGame"></p>
            <p class="quiz-ocr" id="quizOcr"></p>

            <div class="case-fields" id="caseFields" hidden></div>
            <div class="quiz-verify-controls" id="verifyControls" hidden>
              <p class="quiz-question" id="verifyQuestion"></p>
            </div>
          </div>

          <!-- 操作按钮固定在面板底部（同一屏幕位置），不会因为字段数量变化而上下移动。 -->
          <div class="quiz-actions" id="caseActions" hidden>
            <button class="text-button" id="caseSkip" type="button">跳过这张</button>
            <button class="text-button case-invalid-button" id="caseInvalid" type="button">标记无效截图</button>
            <button class="submit-button" id="caseSubmit" type="button">确认提交</button>
          </div>
          <div class="verify-actions" id="verifyActionsBar" hidden>
            <button class="verify-button verify-wrong" id="verifyWrong" type="button">错误</button>
            <button class="verify-button verify-unsure" id="verifyUnsure" type="button">不确定</button>
            <button class="verify-button verify-correct" id="verifyCorrect" type="button">正确</button>
          </div>
        </div>
      </div>

      <div class="empty-pool" id="emptyPool" hidden>
        <strong>暂时没有更多可以处理的截图了</strong>
        <p>这个范围目前的候选都被你回答过或者投票过啦，换一部作品，或者晚点再来。</p>
      </div>

      <div class="round-complete" id="roundComplete" hidden>
        <p class="eyebrow">ROUND COMPLETE</p>
        <h2>本轮战绩</h2>
        <div class="round-stats" id="roundStats"></div>

        <h3>排行榜</h3>
        <div class="leaderboard-list" id="roundLeaderboardList"><p class="summary-loading">正在载入排行榜…</p></div>
        <div class="leaderboard-me" id="roundLeaderboardMe" hidden></div>

        <h3 id="roundProgressTitle">作品标注进度</h3>
        <div class="round-progress" id="roundProgress"></div>

        <div class="round-actions">
          <button class="submit-button" id="roundContinue" type="button">再来一轮</button>
          <button class="text-button" id="roundSwitchGame" type="button">换一部作品</button>
          <button class="text-button" id="roundFinish" type="button">返回首页</button>
        </div>
      </div>

      <div class="quiz-toast" id="quizToast" aria-hidden="true"></div>
    </section>
  </main>

  <dialog class="auth-dialog" id="authDialog">
    <button class="modal-close" id="authClose" aria-label="关闭">×</button>
    <p class="eyebrow">ACCOUNT</p>
    <h2>登录 / 注册</h2>
    <p class="form-hint">标记功能复用宝可梦友会 App / Event 的账号，登录后进度会保存到账号上；不登录也可以直接以游客身份游玩。</p>
    <div class="auth-tabs">
      <button type="button" class="auth-tab active" id="authTabLogin" data-tab="login">登录</button>
      <button type="button" class="auth-tab" id="authTabRegister" data-tab="register">注册</button>
    </div>
    <form id="loginForm" class="auth-form">
      <label>用户名<input id="loginUsername" type="text" required autocomplete="username"></label>
      <label>密码<input id="loginPassword" type="password" required autocomplete="current-password"></label>
      <p class="form-status" id="loginStatus" role="status" aria-live="polite"></p>
      <button class="submit-button" type="submit">登录</button>
    </form>
    <form id="registerForm" class="auth-form" hidden>
      <label>用户名<input id="registerUsername" type="text" required autocomplete="username"></label>
      <label>邮箱
        <div class="inline-field">
          <input id="registerEmail" type="email" required autocomplete="email">
          <button type="button" class="text-button" id="sendCodeButton">发送验证码</button>
        </div>
      </label>
      <label>邮箱验证码<input id="registerCode" type="text" required autocomplete="one-time-code"></label>
      <label>密码（至少 8 位）<input id="registerPassword" type="password" required minlength="8" autocomplete="new-password"></label>
      <p class="form-status" id="registerStatus" role="status" aria-live="polite"></p>
      <button class="submit-button" type="submit">注册</button>
    </form>
  </dialog>

  <dialog class="leaderboard" id="leaderboardDialog">
    <button class="modal-close" id="leaderboardClose" aria-label="关闭">×</button>
    <p class="eyebrow">TOP CONTRIBUTORS</p>
    <h2>标注排行榜</h2>
    <p class="form-hint">按经验值排名，标注和核验都会计入经验。</p>
    <div class="leaderboard-list" id="leaderboardList"><p class="summary-loading">正在载入排行榜…</p></div>
    <div class="leaderboard-me" id="leaderboardMe" hidden></div>
  </dialog>

  <script src="assets/mark.js?v=6" defer></script>
</body>
</html>
