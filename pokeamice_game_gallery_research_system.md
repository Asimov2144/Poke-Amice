# PokeAmice Game Gallery 人工标注游戏化系统开发规格 (v2)

> 目标：把 Game Gallery 的人工标注、人工复核和疑难样本处理,从"后台填表"改造成一套轻量、可持续、强调资料调查感的 Research / Investigation / Archive 系统。
>
> 核心对象：Screenshot、Pokémon、Character、Location、Annotation、Case、Session、Game Progress。

---

## 0. v2 修订说明

v1（本文档的上一版）按"成熟众包科研平台"的规格来写：三层角色、Reviewer 专业分工、Consensus Engine、Expedition、Badge、Community Mission、Research Report、Active Learning、AI 视觉证据……每个方向单独看都合理，但和当前代码的实际状态放在一起看，落差很大：

- `label_submissions` / `verify_submissions` 里的 `status` 字段**从写入起就一直是 `'pending'`**，仓库里没有任何代码把它更新成 confirmed/rejected，也没有任何流程把确认结果用起来——v1 设想的整套 Annotation 生命周期，现状是零基础。
- `site.sqlite3` 是只读的，由本地离线 OCR pipeline 重新生成（见 `DEPLOY.md`），标注系统不能指望实时写回它。
- 现在没有任何视觉识别模型在看截图猜 Pokémon/人物——`gg_pokemon_resolve` 只是**文本模糊匹配名字字典**，不是从图像做识别。v1 里大量 "AI clues 63%" 的 UI 现状下做不出来。
- `event/` 是 Node + TypeScript + better-sqlite3，`game_gallery/` 是纯 PHP + 三个独立 SQLite 文件（`site.sqlite3` 只读、`tagging.sqlite3`、`corrections.sqlite3`，彼此不能跨库 JOIN）。v1 直接甩出 TS `interface`，没有说清楚这套系统该落在哪个进程里。

**v2 的立场：继续沿用现有 PHP + `api/v1/tagging/*.php` + `tagging.sqlite3` 这一套（不新起 Node 服务，不做技术栈迁移）**，用最小改动把"提交的数据从来没被消费过"这个根本问题解决掉，再叠加游戏化，而不是先搭一整套角色/竞赛系统。

本轮讨论确认了三点产品需求，已经写入本版：

1. **不强制单字段模式**：宝可梦 / 人物 / 地点 不必分开单独标注，一张截图缺哪几个字段，同一张卡片上一起问（§4）。
2. **答题按 10 题一轮**，轮次结束展示战绩、榜单，并更新该作品的标注进度（§5）。
3. **标注前先选喜欢的作品**，按作品筛选待标截图，而不是全库随机抽（§6）。

另外明确一点：**裁剪 MVP 范围不再以"日活太低"为理由**（目前尚未公测，前期用量小是正常的）。裁剪的理由改成三条更客观的标准：

- 这个功能是否需要现在还不存在的基础设施（Consensus job、Reliability 分数、AI 视觉模型）；
- 这个功能是否依赖真实使用数据先积累一段时间才有意义（比如 Reviewer 权限门槛、周期排行榜的"周"这个概念）；
- 这个功能是否可以先用现有代码 0 成本拼出一个简化版验证方向，再决定要不要精修。

---

## 1. 产品目标（不变）

Game Gallery 需要持续完成人工标注与复核：截图中的 Pokémon / 人物 / 地点、`need_review` 疑难截图、已有标注的人工复核。

系统不应是：

```text
截图 → 填 Pokémon → 填人物 → 填地点 → 保存
```

而应是：

```text
选一部喜欢的作品
→ 发现待调查的档案
→ 一次性把看得出来的线索都标上
→ 十题一轮，轮次结算看到自己和大家的进度
→ 社区形成共识
→ 档案被确认，站内立刻能看到
→ 作品完成度推进
```

核心产品定位不变：

> **用户不是在替数据库做标注，而是在调查和修复自己喜欢的那部 Pokémon 游戏的档案。**

---

## 2. 设计原则（保留 v1 全部质量优先原则）

### 2.1 产品语言

沿用 Research / Investigation / Case / Archive / Reliability 这套语言，不包装成捕捉、对战、金币农场。

### 2.2 数据质量优先于活跃度（不变，仍是最重要的一条）

- 主要奖励延迟到标注被确认之后发放；
- Research EXP 与 Reliability 分离；
- "无法判断 / 证据不足"必须是合法结果——现有 UI 的"这张图没有" / "跳过" / "不确定"已经是这个语义，直接复用；
- AI 是证据提供者，不是最终事实来源（但现状没有 AI 视觉证据，这条先按下不表，见 §12 Phase 3）；
- 排行榜不能只看提交量，也不能是唯一玩法入口（见 §5）。

---

## 3. 核心循环（改为 Session 化）

```text
                        选一部作品（可选，可选"随便看看"）
                                │
                                ▼
                        Investigation Session
                        （连续 10 个 Case）
                                │
              ┌─────────────────┼─────────────────┐
              ▼                 ▼                 ▼
          Case #1           Case #2      ...   Case #10
      （同屏 1~3 个字段）  （同屏 1~3 个字段）
              │                 │                 │
              └─────────────────┼─────────────────┘
                                ▼
                          Round Complete
                                │
              ┌─────────────────┼─────────────────┐
              ▼                 ▼                 ▼
          本轮战绩            排行榜           该作品进度更新
        （+XP / 连击）    （复用 leaderboard.php）  （完成度条 +N%）
                                │
                                ▼
                      再来一轮 / 换一部作品 / 结束
```

和 v1 的最大区别：v1 把 "Quick Research"（单字段快问快答）和 "Research Case"（多字段档案卡）当成两条并列的路径；v2 只保留一条路径——**Case 本身就是快问快答，只是字段数量随截图缺什么而变**，不再需要用户先选"我要标 Pokémon 还是标人物"。

---

## 4. 关键改动一：同屏多字段 Case

### 4.1 现状问题

现在 `mark.php` 强制用户先选一个分类（宝可梦/人物/地点），进入答题后**只会**问这一个分类，即使同一张截图另外两个字段也是空的，也要等用户换分类重新进来才会被问到。对一张信息量很大的截图（比如主角 + NPC + 地标同框）来说，这是把一次调查拆成了三次。

### 4.2 新流程

后端为每个身份挑选下一张截图时，不再按"分类"过滤，而是按"截图"过滤：

1. 在已选定的作品范围内，找一张该身份还没在**任一**字段（宝可梦/人物/地点）回答过、且至少一个字段还缺证据的截图；
2. 计算这张截图当前缺哪些字段（缺的定义：`glossary_matches` 里没有该分类的记录，**并且**该身份自己也没提交过）；
3. 把缺的字段（1~3 个）一次性放进同一张卡片，每个字段各自一个小问题块（复用现有的 chip 输入 + 联想下拉组件）；
4. 用户可以逐个字段填，也可以某个字段点"这张图没有"，字段之间互不阻塞；
5. 一次提交，`POST` body 里带上这张截图本轮回答的所有字段。

### 4.3 卡片示例

```text
CASE · 《宝可梦 朱》 #38274

图中出现了哪些宝可梦？
[皮卡丘] [+ 输入或选择]                [这张图没有]

图中出现了哪些人物？
[竹兰] [+ 输入或选择]                  [这张图没有]

（地点已由社区确认：双叶镇 ✓，本题不再询问）

[跳过这张] ................................. [确认提交]
```

只缺一个字段的截图，卡片就只显示一个问题块——和现在的单字段体验一致，不会让"简单截图"变得啰嗦。

### 4.4 接口改动

复用现有 `submit_label.php` 的单字段处理逻辑，改成按数组循环：

```http
POST api/v1/tagging/submit_case.php
{
  "screenshot_id": 38274,
  "fields": [
    { "category": "宝可梦", "answer_kind": "present", "answers": [{ "raw_input": "皮卡丘" }] },
    { "category": "人物",   "answer_kind": "present", "answers": [{ "raw_input": "竹兰" }] }
  ]
}
```

服务端对 `fields` 数组里每一项复用 `submit_label.php` 已经写好的 canonical 解析（`gg_pokemon_resolve` / `gg_glossary_resolve`）和 `label_submissions` 写入逻辑，一次请求里循环调用即可，不需要重写这部分。`gg_award_identity` 按字段数量分别计经验，连击数按"这次提交里有多少个字段给了有效答案"累加，而不是按 case 数累加，避免用户为了刷连击专挑单字段截图。

`next_label.php` 也要相应改成 `next_case.php`：查询逻辑从"某分类下未回答"变成"某截图上有几个分类缺证据"，`gg_pick_label_candidate` 需要改成一次查出三个分类各自的覆盖情况（一次查询里用 `CASE WHEN` 分别统计三个分类是否有 `glossary_matches` 证据，而不是像现在一样按单一 `category` 参数过滤）。

核验模式（现有的"核验模式"分类里含"剧情"）继续保留独立入口，本轮不合并进 Case——核验的对象是已有声明（glossary claim / 众包答案），语义和"给截图打新标签"不一样，强行合并会让卡片同时出现"判断对错"和"输入新标签"两种交互，增加认知负担。留到 Phase 1.5 视效果再决定要不要合并。

---

## 5. 关键改动二：10 题一轮 + 轮次结算

### 5.1 为什么要分轮

现在的答题是无限滚动：答完一题立刻加载下一题，没有自然的停顿点。10 题一轮给用户一个"打完这把"的完整体验，也是排行榜、进度条这些反馈自然出现的时机——不用每答一题就弹一次战绩，减少打扰。

### 5.2 客户端状态即可，MVP 不需要新的服务端"session"实体

`mark.js` 里维护一个本地计数器（`state.roundAnswered`），每完成一个 Case（无论几个字段）计数 +1；到 10 时，暂停加载下一题，展示轮次结算面板：

```text
ROUND COMPLETE
本轮 10 个 Case · 23 次有效标注

+186 经验    连击最高 14

排行榜
#1 asimov2144   Lv.5  612 经验
#2 你           Lv.2  231 经验     ← 高亮当前用户
#3 游客·770D    Lv.1   45 经验

《宝可梦 朱》标注进度
■■■■■□□□□□ 52% → 55%（本轮 +3%）

[再来一轮]   [换一部作品]   [返回首页]
```

排行榜直接复用已经上线的 `api/v1/tagging/leaderboard.php`（总 XP 榜，含 `data.me`），不需要新接口——只是把入口从"页头常驻按钮"改成"轮次结算时自动展示"（页头的 `#leaderboardButton` 可以保留，作为随时查看的补充入口，两者不冲突）。

v1 §19 提醒过"不要只看总提交量/总 XP 的永久榜"，这一点仍然成立，但排行榜的**维度**问题（要不要拆成周榜/分类榜）和排行榜的**出现时机**问题是两件事——这次只解决出现时机。多维度榜单列入 Phase 1.5（见 §12），需要真实数据跑一段时间才知道"本周 Mystery Solver"这类维度是否真的有人关心。

### 5.3 轮次不是强制的

10 题只是一个"建议节奏"，不是限制——用户在第 3 题关掉页面完全允许，未完成的轮次不计入战绩，下次进来从新的一轮开始，不做"连续答题掉线清零"式的压力设计（沿用 v1 §17 的态度）。

---

## 6. 关键改动三：先选作品，按作品筛选

### 6.1 为什么

`site.sqlite3` 里目前有 13 部作品，规模差异很大（`Pokémon LEGENDS Z-A` 18532 张，`宝可梦 明亮珍珠` 1239 张）。每个人喜欢的作品不一样，被随机分到不熟悉的作品截图，会认不出人物/地点，答"其他"或跳过的比例更高，体验也更差。先选作品能让用户标自己真正认得出的内容，回答质量也会更高（间接帮到 §7 的共识效率）。

### 6.2 实现

Mode 选择页在"标注模式 / 核验模式"之上加一层"选择作品"：

```text
选择要调查的作品

[Pokémon LEGENDS Z-A]   62% 已标注
[宝可梦 紫]              48% 已标注
[宝可梦传说 阿尔宙斯]     71% 已标注
...
[随便看看 · 全部作品混合]
```

这一步复用 `api/v1/meta.php` 里已经有的 `games` 聚合（`api_canonical_game` 分组、`screenshots`/`recognized` 计数），只需要新增一个端点把"标注覆盖率"也算进去：

```http
GET api/v1/tagging/games_progress.php
```

返回每个 `api_canonical_game()` 分组下的：

```json
{
  "game": "宝可梦 紫",
  "screenshots": 11129,
  "fields_total": 33387,        // screenshots * 3（宝可梦/人物/地点）
  "fields_covered": 16022,      // 三个分类里任一有 glossary_matches 或 confirmed_annotations 的字段数
  "progress": 0.48
}
```

这就是 v1 §14 Expedition 的**极简版**——只保留"按作品"这一个维度，不做 region / chapter / character / pokemon 的多层级聚合（那些留到 Phase 2，见 §12）。`next_case.php` 接受一个可选的 `game` 参数，传了就只在该作品范围内挑候选，不传退回全库随机（"随便看看"）。

### 6.3 进度数字怎么算才不虚

MVP 阶段还没有共识管线，`fields_covered` 暂时只能用"有没有 `glossary_matches` 证据 **或** 有没有任何一条 `label_submissions`（不论是否确认）"这种偏乐观的口径，并在 UI 上用"待确认"和"已确认"两种颜色区分（比如已确认的用实心，仅有人提交但未确认的用斜纹）。等 §7 的 consensus job 跑起来之后，进度条直接切换成"仅计入 confirmed_annotations"的严格口径——这是一个数据口径的平滑升级，不需要改前端结构，只需要后端把 `progress` 字段的计算方式换掉。

---

## 7. 数据怎么"生效"：确认管线设计

这是 v1 最大的空白，也是这次必须先想清楚的部分。现状：`label_submissions` / `verify_submissions` 写进去之后**没有任何消费者**，纯粹是个只增不减的日志表。

### 7.1 新表：`confirmed_annotations`（写在 `tagging.sqlite3`，不动 `site.sqlite3`）

```sql
CREATE TABLE confirmed_annotations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    screenshot_id INTEGER NOT NULL,
    category TEXT NOT NULL,              -- 宝可梦 / 人物 / 地点
    canonical TEXT NOT NULL,
    status TEXT NOT NULL,                 -- 'confirmed' | 'conflict'
    support_count INTEGER NOT NULL,       -- 多少个独立身份支持这个答案
    source TEXT NOT NULL,                 -- 'label_submissions' | 'verify_submissions'
    confirmed_at REAL NOT NULL,
    UNIQUE(screenshot_id, category, canonical)
);
CREATE INDEX idx_confirmed_annotations_screenshot ON confirmed_annotations(screenshot_id);
```

### 7.2 Consensus job：`bin/consensus-tagging.php`

和仓库里已有的 `bin/build-pokemon-search-index.php` 同一种形态——一个可以手动跑、也可以配 cron 的 PHP CLI 脚本，不需要常驻进程：

```text
FOR EACH (screenshot_id, category) 出现在 label_submissions 中且 answer_kind='present' 且 status='pending':
    按 canonical 规范化后分组，统计有多少个不同 identity 支持每个答案
    IF 某个答案的独立支持数 >= 2：
        写入/更新 confirmed_annotations(status='confirmed', support_count=N)
        把这些 label_submissions 行的 status 改成 'confirmed'
    IF 同时有两个不同答案都 >= 2 个独立支持：
        写入 confirmed_annotations(status='conflict')
        相关 label_submissions 行 status 改成 'under_review'（留给 Phase 1.5 的复核队列）

FOR EACH verify_submissions 的 (screenshot_id, category, target_type, target_key)：
    "错误" 票数 >= 3 → 复用现有 gg_maybe_file_auto_correction()，不变
    "正确" 票数 >= 3 → 新逻辑：写入 confirmed_annotations(source='verify_submissions', status='confirmed')，
                        相当于给已有 glossary_matches 的证据做背书
```

MVP 阶段"独立支持数 >= 2"不做 Reliability 加权（现在也没有可信的 Reliability 数据），先用最简单的"人数投票"规则，和 v1 §13 的思路一致，只是把加权部分推迟到 Phase 1.5。

### 7.3 双轨消费：确认结果怎么用起来

**快轨（立刻生效，不依赖离线 pipeline）**

`index.php` 的截图详情弹窗（`#modalTags` / `#modalGlossary` 附近）新增一个"社区标注"区块，直接从 `tagging.sqlite3` 的 `confirmed_annotations` 实时读取，展示形式和现有 `.tag` 一致，但加一个小标记区分"官方术语库"和"社区确认"。这一步复用 `tagging_helpers.php` 文件头注释里已经写明的两库不能 JOIN、PHP 侧两步拼接的写法（先查 `tagging.sqlite3` 拿 `screenshot_id → [canonical]`，再喂给 `site.sqlite3` 查询的展示层），不需要新技术。**确认结果当天就能在站内看到**，不用等 OCR 作者手动跑离线 pipeline。

**慢轨（合并进权威数据集，人工把关）**

`confirmed_annotations` 定期（比如下次要发一个类似 `server_db_delta_2026xxxx.zip` 的增量包时）导出成一份 patch，供 OCR 作者在下一次离线 pipeline 重建 `site.sqlite3` 时人工合并进 `glossary_matches`，成为正式、可全文检索的一部分。这一轨**保持人工把关，不自动写入只读库**，符合仓库现有"`site.sqlite3` 是离线产物"的既定约定，不需要为此改动离线 pipeline 的架构。

两条轨道分开的好处：快轨让贡献者当场看到自己的标注"生效了"（游戏化反馈闭环不被离线节奏拖慢），慢轨保证真正进入权威数据集的内容还是经过人工过一遍，不会因为众包投票的噪声污染搜索索引。

---

## 8. 三层参与角色（v1 保留，解锁条件改写清楚）

- **Investigator（调查员）**：默认身份，直接用 Case / Session 流程，无门槛。
- **Researcher（研究员）**：解锁自定义候选输入、bbox 等更复杂操作——这部分现有 chip 输入其实已经支持自由文本，暂不需要单独的"解锁"机制，先合并进 Investigator，等 Phase 2 需要限制自由文本滥用时再拆出来。
- **Reviewer（审核员）**：处理 §7.2 里的 `conflict` 队列。**解锁条件不是"用户等级"，而是"consensus job 已经稳定跑了一段时间、Reliability 分数有意义"**——这是一个数据依赖，不是人数依赖，所以哪怕公测第一天日活很低，只要 Reliability 统计口径就绪，Reviewer 就可以按 Reliability 阈值开放，不用刻意等"用户变多"。Reviewer 队列 UI 直接复用现有"核验模式"的三按钮交互（正确/错误/不确定），素材换成 `confirmed_annotations.status='conflict'` 的候选。

### Reliability（简化版，先不做贝叶斯模型）

```text
reliability = 该身份被 confirmed 的字段数 / 该身份提交的所有 present 类字段数
```

直接在 `identity_stats` 表加一列 `reliability REAL DEFAULT NULL`（提交数太少时为 NULL，不参与排序/门槛判断，避免新用户因为样本量小被误判），由 `bin/consensus-tagging.php` 顺手算出来更新，不需要新表、不需要 ResearchProfile。

---

## 9. Mystery Case（沿用，先不单独建模型）

现有"核验模式 - 剧情分类"本来就是从 `screenshot_corpus_matches` 里 `needs_review` 的行抽题。MVP 阶段只需要**换文案**，不需要新建 Case/Expedition 数据模型：

```text
疑难调查 #1842

这句台词识别可能有误，需要你帮忙确认
[这段文字是对的]  [有问题，重新看]  [不确定]
```

等 §7 的 conflict 队列跑起来之后，"疑难调查"自然会同时包含"剧情匹配疑难"和"标注冲突疑难"两类，届时再考虑要不要在 UI 上区分类型（Phase 1.5）。

---

## 10. 数据模型（贴合现状的 SQL，取代 v1 的 TS interface）

已存在，不改动：

```text
tagging.sqlite3
  identity_stats(identity_kind, identity_id, xp, level, streak, best_streak,
                 labels_submitted, verifies_submitted, last_active_at, created_at)
  label_submissions(id, screenshot_id, category, canonical, raw_input, match_via,
                     answer_kind, identity_kind, identity_id, status, created_at, updated_at)
  verify_submissions(...)
```

新增：

```sql
-- identity_stats 加一列
ALTER TABLE identity_stats ADD COLUMN reliability REAL;

-- 见 §7.1
CREATE TABLE confirmed_annotations (...);
```

`label_submissions.status` 的取值从"永远是 pending"扩展为：

```text
pending      -- 刚提交，还没被 consensus job 处理过
confirmed    -- 达成共识，已经写进 confirmed_annotations
under_review -- 出现冲突，进入 Reviewer 队列
```

不引入 `superseded` / `rejected` 这两个 v1 定义过的状态——MVP 阶段没有"主动否决"的操作入口，先不做，避免状态机比实际用得上的分支更复杂。

---

## 11. API 草案（延续 `api/v1/tagging/*.php` 命名风格）

```http
GET  api/v1/tagging/games_progress.php                 # §6.2，作品列表 + 标注进度
GET  api/v1/tagging/next_case.php?game={可选}           # §4.4，取下一张多字段 Case
POST api/v1/tagging/submit_case.php                     # §4.4，一次提交多个字段
GET  api/v1/tagging/next_verify.php?category=...        # 不变，核验模式独立入口
POST api/v1/tagging/submit_verify.php                   # 不变
GET  api/v1/tagging/suggest.php?category=...&q=...       # 不变
GET  api/v1/tagging/leaderboard.php                      # 不变，轮次结算时调用
```

`next_label.php` / `submit_label.php` 保留（向后兼容，也方便灰度：先上线 `next_case.php` / `submit_case.php`，观察没问题后再把旧的单字段入口下线），内部实现上 `submit_case.php` 直接循环调用 `submit_label.php` 里已经抽出来的 canonical 解析逻辑，不重复造轮子。

---

## 12. 范围分期

### Phase 1（MVP，本次开发目标）

1. `next_case.php` / `submit_case.php`：同屏多字段 Case（§4）；
2. 10 题一轮 + 轮次结算面板，复用现有 `leaderboard.php`（§5）；
3. `games_progress.php` + 作品选择页（§6）；
4. `confirmed_annotations` 表 + `bin/consensus-tagging.php`（§7.1、§7.2）；
5. 快轨：Gallery 详情弹窗展示"社区标注"（§7.3）；
6. `identity_stats.reliability` 简化计算（§8）；
7. Mystery Case 文案化（§9），不新建数据模型；
8. `need_review → 疑难调查` 入口保留原样。

暂不做（不是因为用户少，是因为下面这些都依赖 Phase 1 先跑出真实数据，或者需要现在不存在的基础设施）：

- Reviewer 专属 UI（依赖 §7.2 conflict 数据先积累）；
- 周期性 / 多维度排行榜（依赖"一周"这个周期跑几轮才知道维度选得对不对）；
- Badge、Daily Research、Community Mission、Research Report；
- 慢轨自动化（合并进 `site.sqlite3` 保持人工，见 §7.3）；
- 核验模式并入 Case（§4.4 已说明原因）。

### Phase 2

- 慢轨导出工具（把 `confirmed_annotations` 打包成 OCR 作者可以直接 apply 的 patch）；
- Reviewer 队列 UI 落地；
- 多维度 / 周期排行榜（本周 Mystery Solver / 本周某作品贡献榜等，用 Phase 1 积累的数据决定维度）；
- Expedition 从"按作品"扩展到 region / chapter / character / pokemon 多层级；
- Badge、Daily Research、Community Mission、Research Report。

### Phase 3

- AI 视觉证据（前提：先有一个能输出置信度的 Pokémon/人物视觉识别模型，现状完全没有，这是一个独立的、工作量不小的子项目，不能顺带做）；
- Active Learning 排序队列；
- 区域 / 作品专精 Reviewer；
- Game Gallery × Today / TV 联动；
- 数据质量仪表盘。

---

## 13. 防刷与质量控制（保留 v1 全部结论）

- 延迟奖励：主要奖励在 `confirmed` 之后发放（`confirmed_annotations` 写入时，可以给相关贡献者补发一次 `confirmed` 类型的 XP，机制同 v1 §9 的 `ResearchReward`，但先不单独建表，直接在 consensus job 里调用 `gg_award_identity` 即可）；
- 同一 Case 防重复：`label_submissions` 已经按 `(category, identity_kind, identity_id)` 排除已回答的截图，改造成多字段后需要在 `next_case.php` 的候选查询里同时检查三个分类；
- 不奖励速度竞赛：连击/经验只按"有效答案数"计，不按"答题速度"计（现状已经如此）；
- 异常检测（极端提交速率、大量相同答案）留到 Phase 2，MVP 阶段量还不大，人工抽查足够。

---

## 14. 验收指标

MVP 完成的判定标准：用户能走完这条完整链路——

```text
打开 mark.php
→ 选一部喜欢的作品
→ 连续完成 10 个 Case（部分 Case 同屏回答多个字段）
→ 看到轮次战绩 + 排行榜 + 该作品进度条更新
→ 几分钟后回到 Gallery，在这批截图的详情弹窗里能看到"社区标注"字样
→（后台）consensus job 跑过一轮之后，对应 label_submissions 的 status 从 pending 变成 confirmed
```

数据质量指标（延续 v1 §29，暂不做 review_reversal_rate，因为 Phase 1 还没有 Reviewer 主动否决的操作）：

```text
median_time_per_case          目标 < 15 秒（比单字段稍长，因为可能要填多个字段）
case_completion_rate
agreement_rate                 同一 (screenshot_id, category) 下独立身份的答案一致比例
confirmed_annotation_rate      confirmed / 总 present 提交数
```

---

## 15. 开发任务拆分（重排，只列 Phase 1）

1. **GG-CASE-001** — `next_case.php`：按截图（而非按分类）挑候选，一次算出缺哪些字段；支持可选 `game` 参数。
2. **GG-CASE-002** — `submit_case.php`：循环复用 `submit_label.php` 的 canonical 解析逻辑，按字段数计经验、按有效字段数累计连击。
3. **GG-CASE-003** — `mark.js` 前端改造：Case 卡片支持渲染 1~3 个字段问题块；本地维护 10 题计数器，触发轮次结算面板。
4. **GG-CASE-004** — 轮次结算面板：调用已有 `leaderboard.php`，加一个"本轮 XP / 连击"小结区块。
5. **GG-CASE-005** — `games_progress.php` + 作品选择页：复用 `meta.php` 的 `api_canonical_game()` 分组逻辑，加标注覆盖率计算（§6.3 的"待确认 vs 已确认"双口径）。
6. **GG-CASE-006** — `confirmed_annotations` 表 + `bin/consensus-tagging.php`：先实现 label 字段的两人一致规则；verify 字段的"正确"票数累计复用 `gg_maybe_file_auto_correction()` 的写法新增一个分支。
7. **GG-CASE-007** — `identity_stats.reliability` 列 + consensus job 顺带更新。
8. **GG-CASE-008** — Gallery 详情弹窗"社区标注"区块（快轨展示，§7.3）。
9. **GG-CASE-009** — Mystery Case 文案化（§9），不新建模型，只改 UI 文案和入口。

---

## 16. 长期方向（不变）

> **让 PokeAmice 社区共同调查、确认和补全 Pokémon 游戏历史档案。**

用户上层看到的是：发现、调查、确认、完成、推进作品档案、解决疑难案例；底层是 Human Research + （未来的）AI Evidence + Game Gallery，共同产出 Pokémon Game Archive。v2 和 v1 的目标一致，区别只在于：v2 先把"提交的标注真的会变成看得见的确认结果"这条最基础的链路跑通，再往上叠加更丰富的游戏化和协作机制。

---

## 17. Phase 1 实施状态（2026-08-19，已完成并部署）

§15 列的 9 个任务（GG-CASE-001~009）全部落地，另外这一轮开发过程中追加了三处改动：

```text
GG-CASE-001  next_case.php              done
GG-CASE-002  submit_case.php            done
GG-CASE-003  mark.js 多字段卡片渲染      done
GG-CASE-004  轮次结算面板 + leaderboard  done
GG-CASE-005  games_progress.php + 选作品 done
GG-CASE-006  confirmed_annotations +
             bin/consensus-tagging.php  done（含单元测试：一致确认/冲突两条路径都验证过）
GG-CASE-007  identity_stats.reliability done
GG-CASE-008  Gallery 详情"社区标注"      done
GG-CASE-009  疑难调查文案化              done
```

追加改动（不在原任务拆分里，是这一轮测试反馈里发现的）：

- **无效截图举报**：Case 卡片新增"标记无效截图"按钮，复用现有 `correction_reports` 表新增一个"无效截图"分类，走人工复核，不新建数据模型。
- **作品图标 + 主题色**：`mark.js` 复用了 `gallery.js` 的 `gameTheme()`/`originMark()`，选作品卡片和答题卡片都带上官方图标，页面背景色跟随当前作品变化。
- **答题卡片横向布局**：宽屏下图片和问题区左右分栏（≥900px），两栏各自用 `max-height` 卡在一屏内，操作按钮不会因为字段数量变化或翻页而移动位置；窄屏退回单栏堆叠 + sticky 底部按钮。这一处经过三轮用户反馈调整（先是按钮位置固定但图片被挤小，再是图片单独一行但要来回滚动，最后定型为宽屏双栏但都限制在一屏高度内），目前的版本没有实机截图验证，建议实际用一下确认观感。

顺带修了一个和 Phase 1 无关、但同一轮测试中发现的真实 bug：Gallery 主搜索"含进化/退化家族"勾选后基本看不到效果——根因是 `api.php` 里有两层排序（`match_tier_flag` 强制本体优先、`rankSql` 按原始查询词是否逐字出现在 OCR 文本里排序）叠加，热门宝可梦的本体命中数常常超过一整页，家族结果永远排不到前面。两层排序都改成只在没有解析出宝可梦时生效，现在勾选后家族命中会正常混在结果里，并带上"进化/退化家族"角标。这个修复和标注系统本身无关，只是记录在这里避免以后误以为是 v2 范围内的东西。

---

## 18. App 端功能对齐

这次开发的所有后端端点都是纯 JSON API（PHP `api/v1/tagging/*.php`），鉴权方式和网页版 `mark.php` 完全一样：`Authorization: Bearer <event token>` 或 `X-GG-Guest-Id: <uuid>`，CORS 已经开了 `*`。这意味着 App 端要做的大多数事情**不需要新增后端代码，直接调用现有端点即可**。下面按你提到的三个方向分别说明现状。

### 18.1 标注 / 排行榜 / 进度——已经齐全，App 可以直接接

```text
GET  api/v1/tagging/games_progress.php
GET  api/v1/tagging/next_case.php?game={可选}
POST api/v1/tagging/submit_case.php
GET  api/v1/tagging/next_verify.php?category=...
POST api/v1/tagging/submit_verify.php
GET  api/v1/tagging/suggest.php?category=...&q=...
GET  api/v1/tagging/leaderboard.php
```

App 端只需要照着这套端点实现一遍 UI（游戏选择卡片、同屏多字段问答、十题一轮、结算面板），数据格式和网页版共用同一套 `label_submissions`/`identity_stats`/`confirmed_annotations`，两端的进度、经验、排行榜是同一份数据，不会各算各的。`next_case.php` 返回的 `missing_fields` 数组、`submit_case.php` 要求的 `fields` 数组结构，App 端按同样的 JSON 形状实现即可，细节见 `api/v1/README.md` 和 `api/v1/openapi.yaml`。

### 18.2 投稿截图——暂缓（2026-08-19 决定）

下面这套方案先记录着，**暂时不实现**——已确认这次不需要。等以后要做的时候再回来看这一节。

现状：game_gallery 没有任何"用户上传新截图"的入口。所有截图来自 Asimov2144 本地 OCR pipeline 手动导入 `data/site.sqlite3`（见 `DEPLOY.md`），没有面向普通用户的写路径。这个功能需要从零做。

参考同一个仓库里 `tour/server.ts` 已经在用的模式（base64 图片 → 校验 MIME/大小 → 存本地磁盘，不走 Tencent COS）——`game_gallery` 服务器本来就不该持有 COS 密钥（`DEPLOY.md` 明确写了这一条），所以新截图不适合直接写腾讯云，落地方案：

```text
新表 pending_screenshot_submissions（tagging.sqlite3）
  id, identity_kind, identity_id, game, note,
  file_path, file_hash, width, height,
  status('pending'|'imported'|'rejected'), created_at

新端点 POST api/v1/screenshots/submit.php
  body: { game, image: "data:image/...;base64,...", note? }
  校验 MIME + 5MB 上限（参考 tour 的限制）→ 存到 data/pending_uploads/
  → 写一行 pending 记录 → 返回 submission id
```

这批图片**不直接进 `site.sqlite3`**（只读、离线产物）——和 §7 confirmed_annotations 的"快轨/慢轨"思路一致：提交方立刻看到"已收到，等待收录"，真正收录仍然由 Asimov2144 跑本地 OCR pipeline 时人工/半自动拉取 `data/pending_uploads/` 增量导入，不自动进只读库。

开工前需要你拍板三件事：

1. 每人每日上传上限（`event` 用的是 10 条/天，量级可以参考）；
2. 要不要做重复提交检测（同一张图片内容 hash 一致就拒绝/合并）；
3. 提交后 App 端展示的状态要不要包括"已被导入 site.sqlite3"这一步——如果要，离线 pipeline 那边需要顺手把 `pending_screenshot_submissions.status` 标成 `imported`，这一步现在没人做，需要 Asimov2144 手动配合。

### 18.3 投稿"宝事"——已经有现成 API，不属于 game_gallery 范围

"宝事"是 `event` 模块（今日宝事）里的 Event。提交入口已经存在，不需要新开发：

```text
POST /event/api/events
Authorization: Bearer <event token>   （和 game_gallery 验证的是同一套 token）
```

`event/server.ts` 的 `authenticatedUsername()` 和 game_gallery 的 `gg_event_token_verify()` 认的是同一个 HMAC token 格式，App 端登录一次，两边都能用同一个 token。非管理员用户限流 10 条/天，提交后默认直接 `status: 'published'`（没有像 game_gallery 这样"先 pending 再共识确认"的审核门槛——这是 event 模块既有的设计，这次不建议顺带改动它）。App 端要做的只是照着这个已有接口实现表单，不涉及本仓库（game_gallery）的任何代码改动。

### 18.4 投稿"地点"——需要你先确认指的是哪一个

"地点"在这个生态里实际对应至少三套不同东西，实现方式完全不同，需要你先说清楚要哪个：

1. **game_gallery 的"地点"标注分类**——给已有截图打地点标签，Phase 1 已经支持（`next_case`/`submit_case` 的 `category=地点`），App 直接调用现成端点即可，不需要新开发。
2. **event 模块的地区/地点体系**（`/api/regions`、`/api/geocode`、`/api/place-groups`）——这套是"今日宝事"用来管理地区/地点的，看代码目前更像管理员/编辑向的维护接口，不是普通用户的"投稿"入口，要做成用户投稿需要额外设计审核流程。
3. **tour 模块（打卡旅图）的地点打卡投稿**——`tour/server.ts` 已经有自己独立的一套 `submissions.json` + `POST /upload`（base64 上传、本地磁盘存储），是完全独立于 event 和 game_gallery 的第三个 Node 服务、第三套数据库。

这三个分属不同数据模型、不同数据库，甚至不同技术栈（tour 是独立 Node 服务）。在你确认具体是哪一个之前，不会去动 event 或 tour 的代码——目标不明确时先动手，改错方向的返工成本比现在问清楚要高得多。

### 18.5 小结：API 是否齐全

```text
标注 / 排行榜 / 进度        齐全，App 可直接接（§18.1）
投稿截图                    不齐全，是提案（§18.2），需要你拍板三个开放问题
投稿宝事                    齐全，属于 event 模块现成接口（§18.3）
投稿地点                    取决于你指哪一个（§18.4 三选一，也可能都要）
```

---

## 19. 关于"更新到 git 上"

检查发现 `/www/wwwroot/pokeamice.com/.git` 目录存在但是空的——没有 `HEAD`、没有 `config`、没有 `objects`，`git status`/`git log` 都报"not a git repository"。这不是 `game_gallery` 单独的问题，整个 `pokeamice.com` 目录当前都没有一个可用的 git 仓库在跟踪代码，这次开发涉及的所有改动目前都只在磁盘上，没有版本历史。

在帮你初始化之前需要确认三件事，避免一步走错：

1. 这台服务器上的代码本来是不是就没有用 git 管理（比如用别的方式部署/备份），这次是要**第一次初始化**一个新仓库；
2. 还是本地或别处已经有一个远程仓库，这台服务器**应该 clone 它**、把现在磁盘上的改动合并进去，而不是新建一个和历史无关的孤立仓库（如果是这种情况，直接 `git init` 会导致以后接远程仓库时要处理一堆无意义的冲突，比现在问清楚麻烦得多）；
3. 如果确定是新建，`data/*.sqlite3`、`backups/`、`corpus2.zip`、`server_db_delta_*.zip` 这些数据/备份文件是不是要排除在版本控制之外（`.gitignore`），只跟踪代码——这几个文件加起来体积不小，且本来就是运行时数据，通常不适合进 git。

告诉我怎么选，我就照着做。
