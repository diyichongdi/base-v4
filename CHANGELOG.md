# changelog — 基地 Digital Market v4

> 修复时间：2026-08-26  
> 修复人：opencode  

---

## 第二轮重构：卡片压缩 + TodoList 全项落地 · 第十二轮 (2026-10-07)

用户确认三项决策：**版心 1200px 不变、只压卡片**；**通讯顶部栏两者都要（共享导航吸顶 + 会话内头部吸顶）**；**18 个静态弹窗本轮全量动态化**。本轮完成 P0–P3 全部 7 项：

| 项 | 改动 | 文件 |
|---|---|---|
| **第二次重构·卡片压紧** | 版心保持 1200px，压缩各模块卡片内边距/间距/弹窗宽度（login/注册 680px 卡片已两列化；auth-card 94vh 落地）；IM 三栏密度收紧 | `assets/app.css`、`im/chat.css` |
| **P0 IM 双偏移修复** | 删除 `chat.css body{padding-top:var(--im-nav-h)}`（与共享导航 sticky 62px 双叠加，导致面板下移、输入区被推出视口）；复核 `calc(100vh-…)` 族；桌面截图复验输入区完整可见 | `im/chat.css` |
| **IM 顶部栏置顶** | 共享导航吸顶复验（app.css sticky）；会话内 `.im-main-header` 补 sticky 规则，滚动时标题栏固定 | `im/chat.css` |
| **P1 导航栏国际化** | `inc/header.php` 全部导航/子项/aria-label 包 `L()`；补 nav_market/nav_im/nav_forum/nav_darkweb/nav_sell/nav_deposit/nav_withdraw/nav_arbitration/nav_mariana/nav_armory/nav_forum_home/nav_new_post/nav_console/nav_wallet/nav_orders/nav_admin/nav_logout 等 zh/en 键 | `inc/header.php`、`lang/zh.php`、`lang/en.php` |
| **P1 建群提交加固** | saveGroup 防抖/提交中禁用中态；成功 toast「群组已创建」；创建后自动切换群组列表并刷新（三连击实测仅建 1 群） | `im/js/chat.js` |
| **P2 死代码清理** | 删除 chat.css `.chat-login-wrap` 登录屏整族、`.navbar.im-navbar` 全族、`#loginScreen` 规则、body padding-top 族（花括号 915→697 配平） | `im/chat.css` |
| **P2 弹窗全量动态化（本轮核心）** | chat.php 18 个静态弹窗模板全部删除（726→427 行）；chat.js 新增 `IM` 统一管理器（`IM.open/close/closeAll/el`，懒创建+缓存+Esc 栈式关闭+遮罩点关）；18 个构建器 `IM._build[modalId]` 按需生成（宽度/居中经 `IM._width` 注入外层 `.im-modal`）；全部 53 处开关调用改 `IM.open/IM.close`；`bindModalBtn/bindModalEvent` 事件委托绑定（懒元素安全）；修复「转发/收藏/分组/设备/资料/用户卡/秘密会话/贴纸/GIF/语音/联系人在首开时 populate 早于弹窗创建」的隐性 TypeError；gif 表情面板移入构建器 | `im/chat.php`、`im/js/chat.js` |
| **P2 去冗余** | 汉堡抽屉商城/市场、我的订单经核对无重复渲染（R11 已净）；「返回桌面」与 logo（登录态已指 dashboard.php）合并 → 移除 IM 侧栏 chatDesktopBtn 及绑定（保留防御性绑定） | `im/chat.php`、`im/js/chat.js` |
| **P3 登录微调** | `.auth-card` 88vh→94vh 落地（+注册两列），登录页 900px 视口整卡可见无需滚动 | `assets/app.css` |

**顺带修复**：消息回应按钮 `onclick="window._chatReactMsg(event,'id')"` 参数顺序错误（event 被当作 msgId → dbGet 失败、回应面板永不弹出）→ 改 `_chatReactMsg('id')`；重建误删的常驻面板（emojiPicker/stickerPicker/reactionPicker/chatToast + 新增 emoji_picker i18n 键），补 `chat-hidden` 默认态（此前重建后两面板常显）。

**门禁**：`php -l` 全树 50/50 0 错；`node --check` app.js/chat.js 0 错；CSS 配平 app.css 613/613、chat.css 697/697、styles.css 1501/1501；HTTP 探针 8 路由（/ 200、login 200、im/chat 302→login、market/mall 302、forum 200、dark_web 200、wallet 302、app.css 200）；浏览器实测 14 个弹窗/面板（群/频道/发帖/邀请/转发/收藏/设备/资料/用户卡/秘密会话/贴纸/GIF/语音/联系人/分组 + 表情/贴纸面板 + 斜杠命令 6 项 + 回应 12 项 + 建群防抖三连击仅建 1 群）全部正常、控制台 0 错误；Edge headless 桌面截图：登录页 94vh 整卡可见、IM 三栏+统一导航+输入区完整。**迭代项**：infoPanel 默认随 renderDefaultInfo 开启（移动端会盖住聊天区，属既有行为待评估）；chat.js 深层动态中文串 i18n 仍延后。

## 全站统一布局重建 · 第十一轮 (2026-10-07)

按用户 /goal 决策执行：**保留 4 卡片风格主题 + 暗色开关，全站统一 1200px 布局骨架，IM 完全并入全站 chrome**。用户已确认 4 项决策（IM 保持三栏 / 全部统一到 1200px / IM 导航与登录完全统一 / 深层 i18n 与汉堡菜单延后）。

| 项 | 改动 | 文件 |
|---|---|---|
| **版心统一** | 暗网导航 1400→1200px、管理后台 1080→1200px、IM `.chat-full` 1180→1200px，全站统一 1200px 体系 | `dark_web_nav.php`、`admin/index.php`、`im/chat.css` |
| **IM 顶部导航并入全站 header** | chat.php 删除自建导航/自定义 head/背景光斑，改为 `require inc/header.php`（同一套导航+主题下拉+语言切换+汉堡抽屉+用户菜单）；`styles.css` 停载（由 app.css 接管），仅保留 `im/chat.css`；页尾改走 `inc/footer.php`（加载 app.js） | `im/chat.php`、`inc/header.php` |
| **共享 header 扩展** | 新增 `$extraCss`/`$extraHeadJS` 扩展位（IM 语言持久化 IIFE 迁入）；主题 IIFE 补 fx→theme 映射（frost→aqua/aurora→aurora/particles→midnight/blueprint→kodachi，`dataset.theme` 同步，chat.css 按 [data-theme] 取色无闪烁）；市场/论坛补独立 `.nav-caret` 箭头 | `inc/header.php`、`assets/app.css` |
| **导航下拉 caret 感知升级（全站）** | app.js 下拉 handler 升级为 caret 感知：有箭头项链接直达+箭头展开；无下拉项（即时通讯/暗网导航）直接放行——修复「首击无反应、二次才跳转」全站性问题；chat.php 内联 handler 移除 | `assets/app.js` |
| **移除 IM 独立登录屏** | `chat-login-wrap`（本地登录/注册/设备/句子密钥 UI）整块移除；chat.js 相应清理：doLogin 去 loginScreen 引用、doLogout 一律跳主站 `../logout.php`、session 失效分支改跳主站 `../login.php?next=im/chat.php`、死绑定（auth 标签/密码/设备/句子/注册 handler）删除 | `im/chat.php`、`im/js/chat.js` |
| **死代码清理** | chat.php 内联旧主题同步 IIFE（选择器 `.navbar.im-navbar` 失效必然早退）与移动端 `.navbar.im-navbar` 规则移除（由 app.css 抽屉接管） | `im/chat.php` |

**门禁**：`php -l` 全树 45/45 0 错；`node --check` app.js/chat.js 0 错；CSS 花括号配平 app.css 613/613、chat.css 915/915、styles.css 1501/1501；HTTP 探针——未登录 IM/后台/商城 302→login、其余 200，登录态 admin/index.php 200（1200px）、dashboard/mall/wallet/forum 200、dark_web_nav 1200px；Edge headless 视觉验证——IM 三栏（列表/消息/详情）+ 全站统一导航渲染正常、登录屏消失。**迭代项**：chat.js 深层动态中文串 i18n、全站移动端汉堡菜单细化，按用户决策延后。

## 会话视图 A 应用 + 登录页/IM 主题/语言/排版四连修 · 第十轮 (Verified 2026-08-31)

按用户确认优先级（问题1→4）逐项修补 IM/登录，会话视图选型「各 Demo 独立样板 → 选定后并入正式 IM」：

| 项 | 修复 | 文件 |
|---|---|---|
| **会话视图 A 并入（问题4.1）** | 正式 IM 本为三栏/中栏；`.chat-full` 由 `min-height` 改固定 `height:min(720px,82vh)` + `.chat-main` 内滚，`.chat-messages` 内部滚动、输入区贴底、侧栏滚动，修复截头去尾/整体滑不动 | `im/chat.css` |
| **问题1 登录/注册页** | `.auth-box` 440→680px；卡片 `max-height:min(88vh,1000px)`+内滚+垂直居中；注册表单 `.auth-form-grid` 两列（≥560px），昵称+两列密码/两列验证码+整行提交，修复溢出 | `login.php`, `assets/app.css` |
| **问题2 IM 主题明暗** | 移除侧栏冗余「切换主题」弹层（与右上导航重复）；统一持久化键（停写冲突的 `aqua-theme`，仅 `bm-cardfx`+`bm-fxdark`）；主题由顶栏 4 卡片风格+暗色开关唯一驱动；chat.js 主题逻辑替换为 `syncImThemeFromFx()` MutationObserver | `im/chat.php`, `im/js/chat.js`, `im/chat.css` |
| **问题3 IM 语言切换** | 新增 nav/im 语言键到 `lang/zh.php`/`lang/en.php`（30+ 键）；chat.php 顶栏导航/风格下拉/侧栏 tabs/按钮标题/搜索占位/命令菜单/主标题/输入占位 全接 `L()`；**F.6 localStorage 持久化**（`im-lang`+首载回放 `?lang=`）；注入 `window.AQUA_I18N` 前端字典（含 confirm_logout） | `im/chat.php`, `lang/*.php`, `im/js/chat.js` |
| **问题4 IM 排版** | 4.1 见上；4.2 退出确认接 `AQUA_I18N.confirm_logout`、侧栏主题冗余随问题2移；4.3 市场/论坛加独立箭头 `.nav-caret`（链接点击直达+箭头独立展开，首击不再被展开吞）；4.4 斜杠命令栏根因修复——`showCommandMenu` 内联 display 被元素自带 `chat-hidden`(`display:none !important`) 覆盖，show/hide 改切 class，另 `.chat-main` 加 `position:relative` 锚定 | `im/chat.php`, `im/js/chat.js`, `im/chat.css` |

**门禁**：`php -l` 全树 0 错；`node --check im/js/chat.js` OK；chat.css/app.css/styles.css 花括号 delta=0；chat.php(zh/en)、login.php、demos/index.php HTTP 200；zh 含「即时通讯」、en 含 "Instant Messaging"。**迭代项**：chat.js/bots/api 深层动态中文串转 `AQUA_I18N` 待后续单独回合。

---


M3：按用户选型偏好「单独拎文件出来单独访问，选定后再应用到正式 IM」，新增 `v4\im\demos\` 独立样板目录，三版会话视图各成独立页面，仅展示/只读示例数据，不触碰正式聊天交互（JS/布局零侵入）。

| 样板 | 文件 | 说明 |
|---|---|---|
| Demo A · 双栏中栏 | `v4\im\demos\demo_a.php` | 左侧会话列表 + 中间消息区 + 右侧资料卡；中栏宽度可调；信息密度最高，列表与消息同屏 |
| Demo B · 全屏覆盖层 | `v4\im\demos\demo_b.php` | 仅会话列表一列，点某会话 → 全屏消息覆盖层（`position:fixed` 覆盖，列表保留底层），返回键/遮罩关闭；仿论坛贴文页，单会话强聚焦 |
| Demo C · 行内联展开 | `v4\im\demos\demo_c.php` | 邮件式收件箱；点某行 → 会话在本行下方行内展开，其余内容自动下移；无独立消息栏，列表最简洁 |

统一：`index.php` 对比入口（三卡三入口，说明差异与各版亮点）；每页顶部 A/B/C 跳转条；登录守卫 `isLoggedIn('user_id')`（未登录 302 → 主站登录并带 `next` 回跳）；复用 `im\styles.css` 玻璃主题变量与 `chat.css` 视觉一致性；Demo 彼此独立、随时可整体移除，选定后再以所选版接入正式 `chat.php`。

**门禁**：`php -l` 全树 0 错；四页登录态 HTTP 200、未登录 302 → `../../login.php?next=im/demos/<page>.php`；无 JS/CSS 校验改动（不新增门禁负担）。M3 态：三版均为独立样板，未并入正式 IM，待用户选型。

---

## 全站统一账号 + IM 论坛化 · 第八轮 (Verified 2026-08-31)

| 问题 | 修复文件 | 说明 |
|---|---|---|
| 论坛独立账号体系（forum_users），与主站/IM 三套账号不通用 | `v4\forum\init.php`, `forum\index.php`, `category.php`, `post.php`, `new-post.php`, `login.php`, `register.php`, `logout.php`, `v4\admin\index.php`, `v4\index.php`(论坛 widget) | **M1 论坛并入**：删除独立 `forum_users`/`forum_auth_methods` 表与迁移；`forum_posts/replies/likes.user_id` 直接 = 主站 `users.id`；`forumDB()` 与主站 `v4/index.php` `ATTACH maindb`（`str_replace("'","''",DB_FILE)`）；查询改 `JOIN maindb.users`；`isForumLoggedIn()=isLoggedIn('user_id')`，`getForumUser()` 查主站 users，`forumLogout` 空；删 rep 更新/avatar/reputation 字段；发帖/登录门禁与链接改指主站 `login.php[?panel=register]&next=`；侧栏卡显示 nick+role+#id；`forum/login|register|logout.php` 三 stub 重写（未登录 302 回主站登录）；admin forum tab 改封禁/解封 `UPDATE users SET banned`、用户列表查主站 users、标题「用户封禁管理（全站账号）」；未登录探针 8 路由均 302 → 主站登录 |
| IM 独立登录体系（密码/设备/长句三合一），伪造独立账号，与主站不一致 | `v4\im\init.php`, `v4\im\chat.php`, `v4\im\js\chat.js`, `v4\im\api\index.php`, `v4\im\lib\seed.php` | **M1·IM 并入**：新增 `im_bridge_main_account()` — 以主站 `users.id` 作 IM 用户 id，首次自动建档（角色映射 admin/arbiter/user），`issue_token()` 签发页面 token，会话内复用并清理 30 天前过期 token，注入 `window.AQUA_CHAT_TOKEN/UID/USERNAME/NICK`；chat.php 顶部未登录守卫 302 → `login.php?next=im/chat.php`；chat.js token 优先取 `AQUA_CHAT_TOKEN`、init 注入主站 uid 会话、401 自动 reload、logout 跳 `../logout.php`；`api_auth()` 封禁旧独立登录接口（403「统一账号已启用」）；`chat_seed()` 改为从主站 users 表同步账号（id=主站 uid），删除 alice/bob/carol 独立演示号，仅留官方机器人 |
| IM 数据库误写主站 users.db（致命：`im\lib\config.php` 用 `!defined('DB_FILE')`，被 `security.php` 抢先定义的 `DB_FILE` 覆盖） | `v4\im\lib\config.php`, `v4\im\lib\db.php` | PHP 常量不可覆盖 → IM 改用独立 `IM_DB_FILE`（`im/data/app.db`），`db()` 打开它；避免 IM 全部数据落入主库 |
| 全站导航分散硬编码、IM 为三栏全屏固定布局，与论坛样式割裂 | `v4\im\chat.css` | **M2 渐进式双栏·文档流**：`.chat-full` 由 `position:fixed` 全屏改文档流限宽玻璃面板（max-width:1180、圆角描边、页面自然滚动、复用全局 header）；移除 chat.css 里强制 `.chat-full` 回退 fixed 的 `!important` 覆盖；侧栏会话列表改为论坛玻璃卡片（`.chat-list-item` 圆角卡 + hover 位移 + active 左侧主题色条），badge 主题色胶囊；info panel 对齐玻璃侧卡；三栏结构 / 全部 DOM id 与 `chat.js` 零改动 |

**门禁**：`php -l` 45/45、`node --check` im/js/chat.js + assets/app.js、CSS 花括号 0 差（chat.css 905 / styles.css 1501 / app.css 562）。HTTP 冒烟：未登录 IM 302→主登录；登录态 200 + token/uid=27 注入；token 授权 `/api/db/users` 通过；旧 `/api/auth` 403；论坛不受影响；主站 logout 正常。IM users 重建后 = 17 主站用户(数字 uid)+admin(4)+arbiter(24)+3 bot（90000001/90000002/Admin@Test1 等与主站一致）。

M3（会话视图 A/B/C Demo）已交付为独立样板目录 `v4\im\demos\`（见第九轮），待选型后接入正式 IM；M4（header/footer 导航 i18n）后续接力；M1 论坛/IM 账号回归经会话桥与主站登录态联动，测试账号沿用 `admin/Admin@Test1`、`90000001/Pass@userA`、`90000002/Pass@userB`。

---

## 审计五件 · 第七轮 (Verified 2026-08-30)

| 问题 | 修复文件 | 说明 |
|---|---|---|
| 充值二维码依赖第三方 `api.qrserver.com`，被 CSP `img-src 'self' data: blob:` 拦截 | `v4\market\deposit.php` + 新增 `v4\assets\qrcode.min.js` | 本地化：vendor davidshimjs qrcode（cdnjs 拷贝）落盘，`<img id="dep-qr">` 改 `<div id="dep-qr" class="dep-qr-wrap">` 容器 + `new QRCode(qrEl,{...correctLevel:M})`，无地址显示占位文案；二维码完全离线生成 |
| GIF 弹窗依赖 GIPHY API（`connect-src` 白名单外 → 搜图必然失败） | `v4\im\chat.php`, `v4\im\js\chat.js` | 移除 GIPHY 搜索与 `GIPHY_KEY`；改为本地 `GIF_EMOJI` 表情面板（点击追加入输入框）+ URL 直发（仅本地/Embed 媒体可行，CSP 不为此放宽）；弹窗标题改「发送 GIF / 图片」 |
| 汇率展示无兜底 | 无需改动 | 复核 `v4\market\deposit.php` / `v4\market\withdraw.php` 已有 `$rate <= 0` 守卫 + `inc/rates.php` 缓存/备用汇率回退，满足审计项 |
| 仲裁胜诉退款不回库存（库存逻辑缺口） | `v4\market\disputes.php` | `resolve_buyer` 增加 `UPDATE products SET stock = stock + :q WHERE id = :pid`（`quantity`/`product_id` 取自订单，兜底 1） |
| 充值 TxID 可重复提交（仅过滤 pending） | `v4\market\deposit.php` | 查重去掉 `status='pending'` 限制，全状态唯一（配合 `transactions.tx_hash` 部分唯一索引），错误文案明示“已存在（处理中或已到账）” |
| i18n 起步（IM/后台） | `v4\im\chat.php`, `v4\lang\zh.php`, `v4\lang\en.php`, `v4\admin\index.php` | 新增 IM 登录（`im_login_title`/`im_independent_note`/`im_username_ph`/`device_quick_login`/`sentence_key_login`/`key_ph` 等）与后台（`admin_dashboard`…`admin_settings` 10 项）语言键；IM 登录页主可见文案换 `L()`；后台标签栏 10 项换 `L()`（注：IM 登录页注册按钮/设备弹窗随第八轮“全站统一账号”删除登录屏而并入废弃，不单独转换） |

---

## 访客体验修复 · 第六轮 (Verified 2026-08-29)

| 问题 | 修复文件 | 说明 |
|---|---|---|
| 三套登录页品牌/版式/账号提示不统一 | `v4\forum\login.php`, `v4\im\chat.php`, `v4\im\chat.css` | 论坛登录：logo 改「基」字 + 标题 `L('forum')` 对齐主站；IM 登录：品牌块改 `.login-brand` 结构（48px 渐变 logo「基」+ h1「即时通讯」）+ `chat-login-note` 账号独立提示；补 `im-auth-switch`/`im-auth-tab` 类名修复 chat.js tab 绑定选择器与 HTML 不匹配的死绑定 |
| 断点断层：grid-4 在 901–1199px 挤压成 2 列 | `v4\assets\app.css` | 新增 `@media (max-width:1200px)` 档：`.grid-4` 3 列过渡，900px 以下再折叠 2 列 |
| IM 移动端侧栏 off-canvas 无任何 JS 触发 → 侧栏永久隐藏，聊天不可用 | `v4\im\chat.css` | 移动端 `.im-sidebar` 由固定+`translateX(-100%)` 改为默认列表屏（`position:relative; width/min-width/max-width:100%; transform:none`），聊天面板覆盖显示、用返回键 `#chatBackBtn` 退回；信息面板保持 JS 驱动的 `show-mobile` 滑入 |
| 暗网导航 hover 无视觉反馈（引用了未定义变量 `--bg-card-hover`） | `v4\dark_web_nav.php` | 根因：`--bg-card-hover` 未在 app.css 定义，hover 赋无效值即无变化；改为类选择器 `.dw-link` + 已定义变量回退（hover 主题色底 + 白字，`:focus-visible` 焦点环 提升键盘可达性），3 处引用全部清掉 |
| favicon 不一致（仅 IM 有，其余页面全缺） | `v4\inc\header.php`, `v4\login.php`, `v4\forum\login.php`, `v4\forum\register.php` | 共享头部接入 `<link rel="icon" ... im/favicon.svg>`，覆盖全站 19 个含 header 页面 + 3 个独立认证页 |
| 文本溢出防护不完整 | `v4\assets\app.css` | `.list-card .lc-title`（论坛/列表标题）、`.user-chip .u-name`（导航用户名）补 `nowrap + ellipsis + max-width` |
| 数字/金额展示不一致（同一币价在两处精度不同） | `v4\wallet.php` | 钱包行情与充值页汇率展示统一为「≥$5 两位小数，否则最多 4 位去尾零」 |

---

## 访客体验修复 · 第五轮 (Verified 2026-08-29)

| 问题 | 修复文件 | 说明 |
|---|---|---|
| 触屏设备无法展开导航下拉（导航/用户/主题仅绑 `:hover`） | `v4\assets\app.css`, `v4\assets\app.js`, `v4\im\chat.css`, `v4\im\chat.php` | 三处下拉统一支持 `:focus-within` + `.is-open`；`@media (hover:none)` 下禁用 hover 直开；app.js 与 chat.php 底部注入点击切换（首击展开、连击/非折叠项正常跟链、外点/Esc 关闭），桌面 hover 行为不变 |
| 登录后永远跳 dashboard，无法回到触发页面 | `v4\login.php`, `v4\security.php`, `v4\inc\header.php`, `v4\market\*\*.php`(9个门禁), `v4\forum\login.php`, `v4\forum\new-post.php`, `v4\forum\post.php`, `v4\forum\index.php`, `v4\im\chat.php` | 新增 `cleanRedirectTarget()`/`redirectTarget()` 同源回跳校验；`?next=` 单次会话存储，登录/注册成功回跳；全站导航“登录/注册”按钮、市场 9 个受保护页面门禁、论坛登录/注册/发帖门禁与登录框均携带 `next=` |
| 论坛账号与主站/IM 不通用但无任何提示 | `v4\forum\login.php` | 登录页注明“论坛账号独立：与主站 / 即时通讯不通用”；论坛登录同样支持 `?next=` 回跳 |
| 免密快捷登录（设备/长句密钥）主次混淆 | `v4\login.php`, `v4\assets\app.css` | 登录页“其他登录方式”下方增加说明 `.auth-alt-note`（明示免密语义）；弱化视觉层级为次级入口 |
| IM 侧栏破坏性按钮与普通按钮混排 | `v4\im\chat.php`, `v4\im\chat.css` | 侧栏操作新增 `.sep` 分隔线；退出按钮 `is-danger`：常显错误色、hover 红底 + inset 描边；`confirm()` 确认保留（chat.js 全部破坏性操作已有） |
| IM 登录页“忘记密码”死链接 `href="#"` | `v4\im\chat.php`, `v4\im\chat.css` | 改为静默提示文本 `im-link-muted`（title 说明可用设备/长句密钥找回），不再指向空锚点 |
| dashboard 假数据（未读消息/我的帖子永远 0） | `v4\dashboard.php` | 替换为真实计数：我的商品（`products.seller_id`）、历史订单（全部 buyer 订单）；待处理订单沿用 |
| dashboard 马里亚纳网/黑客军械库副标题复制“逛商城” | `v4\dashboard.php` | 改用自己的描述 `mariana_desc`/`armory_desc` |
| dashboard 欢迎语显示助记昵称（与全站数字 ID 不一致） | `v4\dashboard.php` | 欢迎语统一显示数字 ID |
| 安全提示卡“2FA”标签与内容（密码强度）不符 | `v4\dashboard.php` | 标签改为“强密码” |

---

## IM 界面修复 · 第四轮 (Verified 2026-08-27)

| 问题 | 修复文件 | 说明 |
|---|---|---|
| 搜索框无效（只能过滤当前分类，且按名称 `.toLowerCase()` 对无 `name` 项抛错） | `v4\im\js\chat.js` | `renderChatList` 全局搜索：输入时跨“聊天/群组/频道”全部类型过滤、忽略当前 tab、正则空态提示“未找到相关结果”；加 `i.name` 空值守卫；无结果显示说明；清除输入恢复当前分类 |
| 搜索框交互缺失 | `v4\im\chat.php`, `v4\im\chat.css`, `v4\im\js\chat.js` | 补 `.im-sidebar-search` 样式（此前完全未定义）；新增放大镜图标 + 清除按钮 `#chatSearchClear`；消息级搜索补“未找到匹配的消息”空态并恢复 |
| 左侧栏无法拖拽调宽（固定/默认 280–320px） | `v4\im\chat.php`, `v4\im\chat.css` | `.chat-sidebar`/`.im-sidebar` 改为 `var(--sidebar-width, 280px)`，min 200 / max 520；新增 `.chat-resizer` 分隔条 + pointer 拖拽（移动端禁用），宽度持久化到 `localStorage('im-sidebar-w')` |
| 空状态“选择一个聊天”两行错位（system 消息悬顶部） | `v4\im\chat.css` | `.chat-messages > .chat-msg-system:only-child { margin:auto; }` 垂直水平居中 |
| IM 与全站主题统一（背景/菜单/主题名） | `v4\im\chat.php`, `v4\im\chat.css`, `v4\im\js\chat.js`, `v4\im\styles.css` | 移除旧 `aurora-sky/liquid-bg`，接入全站 `.orb-bg` 4 光斑 + `bootZoom/float*` 动效；导航主题下拉改为与全站一致的“卡片风格”（雾面玻璃/极光流光/星域粒子/工程蓝图）+ 暗色开关，联动 `data-fx-active`/`fx-dark`/`bm-cardfx`/`bm-fxdark`/`aqua-theme`；`applyChatTheme` 不再删除 `data-theme`，导出至 `window` 供导航同步侧栏弹窗选中态；删除 styles.css 重复的 `body::before/::after` 光晕 |

---

## 已修复 (Previously Fixed — Verified 2026-08-26)

### IM 聊天界面崩溃
| 问题 | 修复文件 | 说明 |
|---|---|---|
| `chat.js` `groupCatAddBtn`/`groupCatClose` 引用 DOM 不存在 → `TypeError` 崩溃 `init()`，后续所有 `$()` 绑定静默失败 | `v4\im\js\chat.js` | 添加 null check；补齐 `chat.php` 中缺失的 HTML 元素 |
| 聊天头部布局失效 | `v4\im\chat.css` | `.chat-main-header-top` ↔ `.im-main-header-top` 等别名 |
| `.chat-full` 高度被裁剪 | `v4\im\chat.css` | `height:100vh` → `calc(100vh - 48px)` |
| emoji/贴纸选择器定位脱节 | `v4\im\chat.css` | `position:absolute` → `position:fixed; bottom:130px; left:50%; transform:translateX(-50%)` |
| z-index 层级混乱 | `v4\im\chat.css` | pickers:500 < modals:1000 < toast:2000 < topnav:2500 < theme-toggle:2501 |
| 移动端 topnav 遮挡 | `v4\im\chat.css` | `inset:0` → `inset:48px 0 0 0` |
| 主题系统两套并行 | `v4\im\js\chat.js` | `toggleSiteTheme()` 委托 `applyChatTheme()`；`syncThemeToggleBtn()` 同步按钮 |
| 缺失 HTML 元素 | `v4\im\chat.php` | `secretChatModal`，`groupCatModal`，`chatCmdMenu` 等补齐 |

### Phase 0 — IM 服务器模式 (2026-08-26)
| 问题 | 修复文件 | 说明 |
|---|---|---|
| `window.AQUA_CHAT_API` 全站无人设置 → IM 永远走本地 IndexedDB 模式 | `v4\im\chat.php` | 添加 `<script>window.AQUA_CHAT_API = '/api';</script>` |
| `im/api/index.php` require `novel.php` / `userdata.php` — 文件不存在 → 500 fatal | `v4\im/api/novel.php`, `v4\im/api/userdata.php` | 新建 stub，提供 `novel_route()` / `userdata_route()`，至少返回 ok_res |
| `adminBroadcastHistory` DOM 缺失 | `v4\im\chat.php` | 在 admin 面板添加 `<div id="adminBroadcastHistory">` |

### Phase 1 — 安全 & 财务 (2026-08-26)
| 问题 | 修复文件 | 说明 |
|---|---|---|
| 默认管理员账号硬编码 `admin / Admin@2026` | `v4\security.php` | 改为从 `ADMIN_INIT_PASSWORD` env 读取；未设置时生成随机密码并记录到 error_log |
| IP 伪造绕过限流/会话绑定 | `v4\security.php` `getClientIP()` | 仅在 `TRUSTED_PROXIES` 配置的代理之后信任 `X-Forwarded-*`；开发环境下始终使用 `REMOTE_ADDR` |
| 仲裔可重复执行 → 双倍赔付 | `v4\market/disputes.php` | `resolve_buyer/seller/reject_dispute` 添加 `in_array($d['status'], ['open','investigating'])` 守卫 |
| 买家胜诉扣卖家自有余额（托管语义错误） | `v4\market/disputes.php` `resolve_buyer` | 移除 `seller balance - total_price` 逻辑；只退款给买家 |
| 购买竞态 → 余额/库存负数 | `v4\market/buy.php` | `BEGIN IMMEDIATE` + `WHERE balance >= :amount` / `WHERE stock >= :quantity`；检查 `rowCount` |
| 自买自己商品 | `v4\market/buy.php` | 添加 `seller_id != buyer_id` 检查 |
| 充值 TxID 无唯一性校验 | `v4\market/deposit.php` | 添加重复 tx_hash 检查；`transactions` 表加 `UNIQUE(tx_hash)` 约束 |
| 存储型 XSS（页脚） | `v4\security.php` `sanitizeHtml()` | 新建 HTML 白名单过滤器；`inc/footer.php` 输出前过滤 |
| 部署方式不一致 → DB 可下载 | `v4\router.php` | 添加敏感路径/文件扩展/隐藏文件拦截 |

### 后台仲裁管理
- **问题**：点击"仲裁管理"没反应（`?tab=disputes` 被 `$allowed` 过滤重置为 `dashboard`）
- **修复**：`admin/index.php:16` — `$allowed` 数组添加 `'disputes'`

### 服务模块清理
- 删除 `v4\service/` 目录下全部文件 (`all.php`, `hacker-team.php`, `kidnap.php`, `account.php`)
- 清除 `inc/header.php`, `inc/footer.php`, `im/chat.php`, `dashboard.php` 中的服务链接引用

### demo 文件清理
- 删除 `v4\demo-cards.php`, `v4\demo-freecity.php`, `v4\test_output.html`
- 删除空目录 `v4\data/`, `v4\logs/`

---

## 未修复 / 新发现 (Pending — Critical)

### IM 服务器模式未接通
- **`window.AQUA_CHAT_API` 全站无人设置**：`im/js/chat.js` 检查它走 IndexedDB 本地模式；没有任何 PHP 页面设置此变量 → IM 永远处于本地模式，服务器/多人消息全不通。
- **`adminBroadcastHistory` DOM 缺失**：广播历史功能仍报错。
- **`im/api/` 缺件**：`index.php` 内部 `require` 的 `novel.php`、`userdata.php` 文件**不存在** → 一旦接通 API，`/api/novel`、`/api/userdata` 直接 500 fatal。

### 安全漏洞 (按严重度)
| # | 问题 | 文件 | 严重度 |
|---|---|---|---|
| 1 | 默认管理员账号硬编码 `admin / Admin@2026` | `security.php` `ensureAdminAccount()` | 高 |
| 2 | IP 伪造绕过限流/会话绑定 | `security.php` `getClientIP()` | 高 |
| 3 | 仲裁可重复执行 → 双倍赔付 | `market/disputes.php` | 高 |
| 4 | 买家胜诉记账错误 — 扣卖家自有余额而非托管金 | `market/disputes.php` `resolve_buyer` | 高 |
| 5 | 下单竞态 → 余额/库存扣成负数 | `market/buy.php` | 高 |
| 6 | 充值 TxID 无唯一性校验 | `market/deposit.php` | 高 |
| 7 | 存储型 XSS（页脚） | `inc/footer.php` `save_footer` | 中 |
| 8 | 免密登录凭据可被窃取/爆破 | `forum/*`, `market/*` | 中 |
| 9 | 部署方式不一致 → DB 文件可下载 | `router.php` vs `.htaccess` | 高 |
| 10 | 快速账号每请求重命名 | `security.php` `migrateDB()` | 中 |
| 11 | 内置账号名预测 | `security.php` | 低 |
| 12 | 弱验证码 (SVG 明文+rand) | `captcha.php` | 中 |
| 13 | 黑产内容合规风险 | `armory.php`, `lang/*` | 合规 |
| 14 | IM 管理开关是空壳 | `im/chat.php`, `im/api/index.php` | 中 |

### 逻辑错误
| # | 问题 | 文件 | 分类 |
|---|---|---|---|
| 1 | 仲裁记账错误 — 托管语义破坏 | `market/disputes.php` | 财务 |
| 2 | 仲裁无幂等/状态守卫 | `market/disputes.php` | 财务 |
| 3 | 钱包交易记录不完整 (purchase/sale/refund 从不写入) | `market/buy.php`, `wallet.php` | 财务 |
| 4 | 确认收货可在未发货时执行 | `market/orders.php` | 订单 |
| 5 | 自动放款无后台任务 | `market/init.php` | 订单 |
| 6 | 买家可直接购买自己的商品 | `market/buy.php` | 订单 |
| 7 | 充值收款地址是占位符 | `market/init.php` | 财务 |
| 8 | 订单消息/事件 N+1 | `market/init.php`, `forum/post.php` | 性能 |
| 9 | 事务跨连接 (余额变更 vs 事件写入不同连接) | `market/disputes.php`, `market/buy.php` | 一致性 |
| 10 | settings 表建在 users.db，market.db/mariana.db 是 0 字节死文件 | `security.php`, `market/init.php` | 架构 |
| 11 | 论坛声望无上限 | `forum/*` | 权限 |
| 12 | 论坛删除语义自相矛盾 (软删/硬删并存) | `forum/*` | 逻辑 |
| 13 | 缺少重复提交防护 | `market/*` | 安全/逻辑 |

### 布局问题
| # | 问题 | 文件 | 平台 |
|---|---|---|---|
| 1 | 全站缺少移动端汉堡菜单 | `assets/app.css` | 全站 |
| 2 | 断点断层 (641–899, 901–1199 无细化)【第六轮已部分：grid-4 @1200 过渡】 | `assets/app.css`, `im/chat.css` | 全站 |
| 5 | 管理后台 10 个 tab 无响应式折叠 / 列表无分页 | `admin/index.php` | Admin |
| 7 | 两套设计体系 (app.css + im/styles.css/chat.css) | `assets/`, `im/` | 全站 |
| 8 | 登录/注册卡片无移动端高度适配 | `assets/app.css` | Auth |

### 代码冗余
| # | 问题 | 文件/目录 | 分类 |
|---|---|---|---|
| 1 | 未使用的库常量 (MARKET_DB_FILE, IM_DB_FILE, FORUM_DB_FILE) + 0 字节死文件 | `security.php`, `database/` | 架构 |
| 2 | `im/init.php` 全是死代码 | `im/init.php` | 冗余 |
| 3 | `service/` 目录缺失 | `v4/service/` | 冗余 |
| 4 | `im/chat.css.bak` 备份残留 | `im/` | 文件 |
| 5 | 死函数 `forumUserIsAdmin()`, `forumCreateQuickUser($username)` | `forum/*` | 冗余 |
| 6 | 死字段 `is_deleted`, `purchase` 收入分支 | `forum_posts`, `wallet.php` | 逻辑 |
| 7 | `remember` 记住我功能写了不读 | `login.php`, `logout.php` | 冗余 |
| 8 | 三套重复账号体系 | `users/`, `forum/`, `im/` | 架构 |
| 9 | 仓库含真实运行数据 | `database/`, `im/data/`, `forum/` | 风险 |
| 10 | README 与代码脱节 | `README.md` | 文档 |
| 11 | 散落内联 style | 全站 | 维护性 |

### UI 体验
| # | 问题 | 分类 |
|---|---|---|
| 1 | 文本溢出防护不完整【第六轮已部分：lc-title / u-name】 | 布局 |
| 2 | 公告栏重复/中英混排 | 一致性 |
| 3 | 数字/金额展示不一致【第六轮已部分：行情精度统一】 | 一致性 |
| 4 | 空状态/错误提示风格不统一 | 一致性 |
| 5 | 粒子画布性能开销 | 性能 |
| 7 | 语言切换非即时/未持久化 | 体验 |
