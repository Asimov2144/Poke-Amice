# App 端投稿 API 参考

> 目的：给 App 端实现"和网页端一致的投稿模板"提供一份可以直接照着写表单的参考。覆盖三个服务——
> `game_gallery`（本仓库）、`event`（今日宝事，`/www/wwwroot/pokeamice.com/event`）、`tour`（打卡旅图，
> `/www/wwwroot/pokeamice.com/tour`）。三者物理上是三个独立的 Node/PHP 服务，各自的数据库也是分开的，
> 但共用同一套登录身份（见「统一鉴权」）。
>
> 每个端点下面标了「普通用户」还是「仅管理员」——管理员判断统一是
> `username.toLowerCase() === 'admin'`，或者该账号在 event 的 `preferences` 里 `role === 'admin'`
> （tour 本地也会同步一份 `role` 字段，语义一致，见开发规格 §20.1）。

---

## 0. 统一鉴权

三个服务现在共用同一套登录身份（2026-08-19 event/tour 账号合并之后，event 是唯一权威来源，详见
`pokeamice_game_gallery_research_system.md` §20.1）。

```http
Authorization: Bearer <token>
```

- `<token>` 由 `POST /event/api/login` 或 `POST /event/api/register` 签发，格式是
  `base64url(JSON{username,exp}).base64url(HMAC-SHA256(payload, secret))`，7 天过期。
- 三个服务都是**本地重新计算 HMAC 校验**这个 token，不会在请求时互相调用——App 只需要登录一次、
  存一个 token，三个服务的接口都能用同一个 token 调。
- game_gallery 的匿名/游客场景额外支持 `X-GG-Guest-Id: <uuid v4>`（游客身份，见下方 game_gallery
  部分）；event、tour 目前没有游客模式，未登录一律 401。

登录/注册（App 只需要接这一套，tour 的 `/auth/login`、`/auth/register` 内部也是转发到这里）：

```http
POST /event/api/register
Content-Type: application/json

{ "username": "string，必填", "password": "string，至少 8 位", "email": "string，必填，需先验证" }
```

```http
POST /event/api/login
Content-Type: application/json

{ "username": "string", "password": "string" }
```

两者成功都返回 `{ success: true, token: "...", preferences: {...} }`。注册前需要先
`POST /event/api/send-code` 给邮箱发验证码，再 `POST /event/api/verify-code` 校验，流程和 game_gallery
`mark.js` 里的 `eventApi('send-code', ...)` / `eventApi('verify-code', ...)` 一致，可以直接抄。

---

## 1. game_gallery：截图标注 / 勘误举报

Base URL：`https://pokeamice.com/game_gallery/api/v1/`

### 1.1 标注截图（Case，普通用户）

这是 Phase 1 的核心投稿流程——给截图打宝可梦/人物/地点标签。完整流程见开发规格 §4/§11，这里只列
App 要用的两个端点：

```http
GET /tagging/next_case.php?game={可选，games_progress.php 返回的 canonical game 名}
```
响应 `data`：
```json
{
  "card": { "id": 12345, "game": "宝可梦 朱", "text": "OCR 文本", "thumb_url": "...", "preview_url": "..." },
  "missing_fields": ["宝可梦", "人物"],
  "stats": { "xp": 45, "level": 1, "streak": 7, "best_streak": 7 }
}
```
`card` 为 `null` 表示这个范围暂时没有候选（对应「换一部作品或者晚点再来」）。

```http
POST /tagging/submit_case.php
Content-Type: application/json

{
  "screenshot_id": 12345,
  "fields": [
    { "category": "宝可梦", "answer_kind": "present", "answers": [{ "raw_input": "皮卡丘" }] },
    { "category": "人物", "answer_kind": "none_of_above" }
  ]
}
```
- `answer_kind` 三选一：`present`（填了具体名字，`answers` 必填且 ≤8 条）、`none_of_above`（"这张图没有"）、
  `skip`（跳过）。
- 响应 `data.xp_gained` 是这次拿到的经验，`data.stats` 是更新后的等级/连击。

### 1.2 举报无效截图 / 提交勘误（普通用户）

```http
POST /corrections.php
Content-Type: application/json

{
  "screenshot_id": 12345,
  "category": "OCR文字错误 | 剧情匹配错误 | 截图信息错误 | 无效截图 | 其他问题",
  "suggested_text": "string，可选（和 description 至少填一个）",
  "description": "string，可选（和 suggested_text 至少填一个）",
  "contact": "string，可选"
}
```
限流 20 次/小时/客户端。5 分钟内对同一张截图提交相同内容会被判重复（返回同一条记录的 id，不会重复
入库）。"无效截图"是给"台词没显示完/画面不完整"这类不适合收录的截图用的分类，`mark.js` 里
"标记无效截图"按钮走的就是这个端点。

### 1.3 核验已有标注（普通用户）

```http
GET /tagging/next_verify.php?category={宝可梦|人物|地点|剧情}
POST /tagging/submit_verify.php
{ "screenshot_id": 12345, "category": "...", "target_type": "...", "target_key": "...", "claim_text": "...", "vote": "正确|错误|不确定" }
```
`target_type`/`target_key`/`claim_text` 都从 `next_verify.php` 的响应里原样带回去，App 不需要自己
构造。

### 1.4 排行榜 / 作品进度（只读，不需要投稿但配合展示用）

```http
GET /tagging/leaderboard.php          （可选带鉴权，带了会多返回 data.me 自己的排名）
GET /tagging/games_progress.php       （不需要鉴权）
```

### 1.5 目前没有的：投稿新截图

game_gallery 现在**没有**"上传一张新截图"的接口——所有截图来自维护者本地 OCR 流水线离线导入。方案
已经在开发规格 §18.2 记录（沿用 tour `/upload` 那种 base64→本地磁盘的思路），但这次决定暂不做。
App 端如果要加"投稿截图"功能，需要先把那个方案定下来（写在 §18.2 的三个开放问题）再排期。

---

## 2. tour（打卡旅图）：打卡投稿 / 标记去过 / 地点管理

Base URL：`https://pokeamice.com/tour/api/`（或 `/api/`，两个前缀等价）

### 2.1 上传图片（普通用户，投稿打卡前先传图）

```http
POST /upload
Authorization: Bearer <token>
Content-Type: application/json

{ "image": "data:image/png;base64,...." }
```
- 只认 `png|jpeg|jpg|webp|gif`，≤5MB。
- **一次只能传一张**——App 端要发多图，就循环调用这个接口，把每次返回的 `url` 收集起来，
  再整体塞进下面 `POST /submissions` 的 `images` 数组。这是网页端 `SubmissionModal.tsx` 的实际
  做法，不是接口限制导致的将就写法，App 端应该照抄这个模式。
- 响应：`{ "url": "/tour/uploads/img_....png" }`。

### 2.2 打卡投稿（普通用户，核心"投稿"流程）

```http
POST /submissions
Authorization: Bearer <token>
Content-Type: application/json

{
  "placeId": "string，必填，对应 GET /custom-places 或官方地点列表里的 id",
  "description": "string，必填",
  "date": "YYYY-MM-DD，可选，不填默认今天",
  "images": ["/tour/uploads/xxx.png", "..."],
  "tags": ["string", "..."],
  "pokemons": ["string", "..."]
}
```
- `tags`/`pokemons` 在网页端是"逗号分隔的输入框，前端 split 成数组"（`tags.split(',').map(t=>t.trim())`），
  接口本身要的是数组——App 端不需要照抄逗号分隔这个 UI 细节，直接做成多选/chip 输入更好，只要最终
  传数组就行。
- 限流：每人每天 10 条（`429 Daily submission limit reached (10/day)`）。
- 响应 `201`，返回创建好的完整投稿对象（含 `id`、`author`、`authorAvatar`、`createdAt`）。
- 作者信息（`author`/`authorId`/`authorAvatar`）由服务端从 token 解出来的身份自动填，不需要客户端传。

```http
PUT    /submissions/:id     （仅作者本人或管理员，改 description/date/images/tags/pokemons）
DELETE /submissions/:id     （仅作者本人或管理员）
GET    /submissions         （公开，不需要鉴权，列出全部投稿，App 首页/列表页用这个）
```

### 2.3 标记"我去过这里"（普通用户，2026-08-19 新增）

和 2.2 的"打卡投稿"是两回事——投稿是发一条带图文的帖子，这个是单纯的书签式标记，用来在个人主页
展示"去过的地点"列表，不需要写描述/传图。

```http
POST   /api/places/:placeId/visit     标记去过（幂等，重复调用不报错）
DELETE /api/places/:placeId/visit     取消标记
GET    /api/me/visited-places         列出自己标记过的地点：{ "places": [{ "placeId": "...", "createdAt": "..." }] }
```

### 2.4 邮箱补齐（仅限从 tour 迁移过来、当初没填邮箱的账号）

```http
GET  /auth/me
Authorization: Bearer <token>
```
响应里 `needsEmail: true` 时，App 应该提示用户补邮箱：

```http
POST /auth/complete-email
Authorization: Bearer <token>
{ "email": "user@example.com" }
```

### 2.5 管理员接口

```http
POST   /custom-places        新增一个自定义打卡点（管理员）
PUT    /custom-places/:id    编辑（管理员；id 不存在时会当新增处理）
DELETE /custom-places/:id    删除（管理员）
```
请求体字段：
```json
{
  "id": "string，可选，不填自动生成 custom_place_<timestamp>",
  "name": "string",
  "category": "string，主分类",
  "categories": ["string", "..."],
  "level": 1,
  "coords": [纬度, 经度],
  "address": "string，可选",
  "tags": ["string", "..."],
  "pokemons": ["string", "..."],
  "titleLink": "string，可选，跳转链接",
  "categoryIconUrl": "string，可选",
  "detailIcon": "string，可选",
  "detailIconSize": [64, 64]
}
```

```http
GET  /auth/users              列出所有账号（管理员）
POST /auth/generate-code      生成一个邀请码（管理员），响应 { "code": "8位十六进制" }
GET  /auth/codes              列出所有邀请码（管理员）
```

> **地点投稿的缺口**：`POST /custom-places` 现在是管理员专属，没有"普通用户投稿新打卡点 → 等审核"
> 这条路径（这正是你说的"tour 中出现新的宝可梦打卡点的投稿"）。方案记在开发规格 §18.4：建议加一张
> `place_proposals`（或类似命名）表存 `status='pending'`，普通用户 `POST` 进去，管理员審核通过后再
> 写入正式的 `custom_places`，复用 `activation_codes`/`submissions` 已有的"表 + 状态字段"模式，不用
> 引入新机制。**这一条目前没有实现**，App 端如果现在要做"投稿新地点"，只能先接管理员这条（如果
> App 也有管理员模式），或者等这个方案排期。

---

## 3. event（今日宝事）：投稿宝事 / 标记参与过

Base URL：`https://pokeamice.com/event/api/`

### 3.1 上传图片（普通用户，投稿宝事前先传图）

```http
POST /upload-image
Authorization: Bearer <token>
Content-Type: multipart/form-data

字段名 "image"：图片文件（jpeg/png/webp/gif）
```
或者不传文件、改传一个已有图片的直链，服务端会帮忙抓取转存：
```http
POST /upload-image
Authorization: Bearer <token>
Content-Type: application/json

{ "imageUrl": "https://..." }
```
- 限流 20 次/小时。上传目标是腾讯云 COS（不是本地磁盘，这点和 tour 的 `/upload` 不一样）。
- 响应：`{ "success": true, "url": "https://<bucket>.cos.<region>.myqcloud.com/pokecal/xxx.jpg" }`。
- 需要提前配置好 `TENCENT_COS_*` 环境变量，没配置会返回 `400`。

### 3.2 投稿一个宝事（普通用户，核心"投稿"流程）

```http
POST /events
Authorization: Bearer <token>
Content-Type: application/json

{
  "title": "string，必填",
  "startDate": "YYYY-MM-DD，必填",
  "endDate": "YYYY-MM-DD，可选，不填等于 startDate",
  "startTime": "HH:mm，可选",
  "endTime": "HH:mm，可选",
  "category": "offline | online | history | ...（看下面「分类参考」）",
  "location": "string，可选，线下活动的地点文字描述",
  "link": "string，可选，官方链接",
  "description": "string，建议填",
  "tags": ["string", "..."],
  "pokemon": ["string", "..."],
  "imageUrls": ["string，来自 3.1 上传接口返回的 url", "..."]
}
```
- 服务端会自动补 `id`、`status: 'published'`、`createdBy`、`createdAt`，不需要客户端传。
- **限流**：非管理员每天最多 10 条（`429`，提示已达每日上限）；管理员不限。
- 分类参考（从现有数据里取的真实取值，不是穷举）：`offline`（线下活动）、`online`（线上活动）、
  `history`（历史上的今天）——具体这个字段的完整枚举建议直接问维护者要一份最新的，这里只是给
  App 端一个大致印象，不要硬编码穷举。

```http
PUT    /events/:id     编辑（作者本人或管理员）
DELETE /events/:id     删除（作者本人或管理员）
GET    /events?start=&end=    公开，按时间范围列出已发布的宝事
```

### 3.3 标记"我参与过这个宝事"（普通用户，2026-08-19 新增）

```http
POST   /events/:id/participate     标记参与过
DELETE /events/:id/participate     取消标记
GET    /me/participated-events     列出自己参与过的宝事（返回完整 event 对象数组，不只是 id）
```
响应 `GET /me/participated-events`：
```json
{
  "events": [ { "id": "...", "title": "...", "startDate": "...", "...": "完整 event 字段" } ],
  "participatedAt": { "event-id-1": "2026-08-19T12:00:00.000Z" }
}
```

### 3.4 管理员接口（和地点/分区管理相关的部分）

```http
GET  /admin/place-groups
POST /admin/place-groups
DELETE /admin/place-groups/:groupId
```
维护"地点分组"（比如把同一个连锁店的多个门店归到一组），字段：`{ id, name, aliases: [], tags: [] }`。

```http
GET  /regions
GET  /regions-with-activities
GET  /places-with-activities
GET  /geocode?q=地址文本
```
这几个是公开只读的地区/地点查询，不是投稿接口，App 端做"选地点"下拉/搜索的时候会用到，放在这里
方便一起看。

```http
GET  /admin/users
```
列出所有账号（管理员）。

```http
POST /admin/migrate-tour-accounts
```
一次性迁移工具（管理员），把 tour 账号并入 event，已经在 2026-08-19 跑过一次，正常情况下 App 端
不需要调用这个。

---

## 4. 小结：投稿类接口一览

```text
game_gallery
  POST /api/v1/tagging/submit_case.php        标注截图（普通用户）
  POST /api/v1/corrections.php                举报勘误/无效截图（普通用户）
  POST /api/v1/tagging/submit_verify.php       核验已有标注（普通用户）
  （投稿新截图：暂无，§18.2 有方案未实现）

tour
  POST /api/upload                             上传单张图片（普通用户，投稿打卡的前置步骤）
  POST /api/submissions                        打卡投稿（普通用户）
  POST /api/places/:placeId/visit              标记去过（普通用户）
  POST /api/custom-places                      新增打卡点（仅管理员）
  POST /api/auth/generate-code                 生成邀请码（仅管理员）
  （普通用户投稿新打卡点：暂无，§18.4 有方案未实现）

event
  POST /event/api/upload-image                 上传图片（普通用户，投稿宝事的前置步骤，落地到腾讯云 COS）
  POST /event/api/events                       投稿宝事（普通用户，每天 10 条上限）
  POST /event/api/events/:id/participate       标记参与过（普通用户）
  POST /event/api/admin/place-groups           管理地点分组（仅管理员）
```

目前唯一没有实现方案的两个缺口：**game_gallery 投稿新截图**（§1.5，§18.2 有方案）、**tour 普通用户
投稿新打卡点**（§2.5 末尾，§18.4 有方案）。event 的图片上传一开始误以为没有，核对代码后确认
`POST /upload-image` 已经存在（走腾讯云 COS），已经在上面订正。需要实现哪个缺口告诉我，我再排期。
