# IM / 登录 修补计划（本地持久化，防上下文裁剪）

> 用户反馈（追加2）：「你有没有搞清楚即时通讯中每一个框的上下界？底部/顶部导航栏位置？已经完全交错！」 → **根因定位**：chat.css 存在**两套竞争布局系统**绑定同一 DOM：`.chat-*`（卡片，`.chat-full`@107） vs `.im-*` JS兼容块@1387 —— 其中 `.im-app`@1392 强制 `position:fixed;height:100vh;inset:0`，且 `.im-sidebar/.im-main/.im-info-panel` 覆盖卡片边框/圆角。因 `.im-*` 在文件更后，按同优先级**后写覆盖**，导致 app 被渲染成 100vh 全屏 fixed（无视 sticky 导航/边距）+ 内部半套 `.chat-*` → 框界完全交错。
> **决定性修复**：chat.css 末尾追加 `AUTHORITATIVE LAYOUT FIX` 块，用 `#chatApp .im-*` 选择器重述卡片布局（`position:static`、`height:calc(100vh - var(--im-nav-h,62px)-28px)`、三栏各自圆角玻璃卡片+gap），末位获胜。


> 用户反馈（追加）：**「demo 的三栏与重构后的即时通讯完全不符，demo 很好，但即时通讯很烂，严格按 demo」** → 已确认范围「视觉+三栏结构对齐，保留全部功能」并落定，见下方「三栏对齐 Demo A」段。

## 三栏对齐 Demo A（2026-08-31 追加）
- **右侧资料栏始终可见/有内容**：`openChat` 不再 `closeInfoPanel()`；新增 `renderSelectedInfo()`（选会话→按 type 自动渲染 showGroupInfo/showContactInfo/showChannelInfo/兜底卡）；登录时 `renderDefaultInfo()` 渲染当前用户 profile（昵称/@id/角色/签名/ID），右侧栏不再空白
- **三栏拆独立卡片**：`.chat-full` 去掉整体玻璃盒/边框/阴影，改 `display:flex;gap:14px`；`.chat-sidebar`/`.chat-main`/`.chat-info-panel` 各自独立圆角玻璃卡片（radius-lg + border + shadow + overflow:hidden），与 Demo A 的三张 `.col` 一致
- **命令菜单重定位**：`.chat-cmd-menu` 移入 `.chat-input-area`（容器已 `position:relative`），避免被新加 `.chat-main{overflow:hidden}` 裁剪，`/` 命令栏正常弹出
- 相关：`chat.php`、`js/chat.js`（renderSelectedInfo/renderDefaultInfo）、`chat.css`（.chat-full/.chat-sidebar/.chat-main/.chat-info-panel）
- 遗留：文件传输助手/机器人用兜底卡渲染；视觉细节待浏览器目测微调


> 生成：2026-08-31 · 用户选定：M3 三版 Demo 中 **选用 A（中栏·三栏视图）**
> 推进方式：按顺序依次做，不区分大小；把计划本地存储，防上下文裁剪；时刻更新 todo 进度。
>
> 目录：`v4\im\`（IM）、`v4\assets\app.css`（认证页）、`v4\login.php`。
> 门禁：`php -l` XML 全树 → `node --check im/js/chat.js` → CSS 花括号 delta=0 → HTTP 冒烟（会话桥）。
> 服务器：`Start-Process php -S 127.0.0.1:8080 router.php`（WorkingDirectory=v4）。PowerShell 5.1 不支持 `??`。
> 测试账号：`admin/Admin@Test1`(4)、`90000001/Pass@userA`(27)、`90000002/Pass@userB`(28)；会话用 `mk_sess` 服务端持久。

---

## 里程碑状态
- [x] M3 · 会话视图 A/B/C 三个独立 Demo（`v4\im\demos\`），已交付对比
- [x] 选用 **A（双栏·中栏、三栏视图）** 应用到正式 IM —— 布局本就是三栏，修复了 **问题4.1 内部滚动高度体系**（`.chat-full` 由 `min-height` 改固定 `height:min(720px,82vh)`，使 `.chat-messages` 内部滚动、`.chat-main` 高度受约束、输入区贴底；三栏并列正常）
- [x] **问题1 登录/注册页**：`.auth-box` 440→680px；`.auth-card` 高度封顶 `max-height:min(88vh,1000px)`+内滚动+垂直居中；注册表单 `.auth-form-grid` 两列压缩（原因：430px 单列堆 Login+注册+设备+密钥+验证码+记住我 必溢出）

## 待做（问题2 开始 → 4 结束，按序）—— 已全部完成 ✅

### 问题2 · IM 主题明暗 ✅
- 移除侧栏冗余「切换主题」弹层（`#chatThemePopover`/`chatThemeBtn`/`.im-theme-switcher`），主题唯一入口=顶栏 4 卡片风格+暗色开关（chat.js 原 `CHAT_DARK_THEMES`/`applyChatTheme` 弹层逻辑替换为 `syncImThemeFromFx()`：MutationObserver 观测 `data-fx-active` 同步 `dataset.theme` 适配 chat.css `[data-theme]`）
- 统一持久化键：停止写冲突的 `aqua-theme`，仅 `bm-cardfx`+`bm-fxdark`（与主站一致，不再切站跳变）；chat.php 内联 theme IIFE 去掉 `aqua-theme` 写入与已删 `applyChatTheme` 调用
- 四风格明暗随 fx-dark 系统（与全站一致）；移除后 IM 主题行为与主站相同
- 涉及：chat.php、chat.js、chat.css

### 问题3 · IM 语言切换（大规模）✅（服务端 chrome + 基础设施；JS 动态字符串标迭代）
- 新增 nav/im 语言键到 `lang/zh.php`、`lang/en.php`（nav_mall/nav_im/cat_chats/chat_search_ph/device_mgmt/cmd_* 等 30+ 键）
- chat.php 顶栏导航、卡片风格下拉、侧栏 tabs/按钮标题/搜索占位、命令菜单、主标题、输入占位 全部接 `L()`
- **F.6 持久化**：`im-lang` localStorage + 首载回放（无 `lang=` 参数时 `window.location.replace('?lang=…')`）；`data-set-lang` 点击写 localStorage
- 前端字典：注入 `window.AQUA_I18N`（select_chat/type_message/logout/confirm_logout 等）供 JS 使用
- **迭代阶段（未做，数千字机械替换高风险）**：chat.js 内大量 toast/confirm 硬编码、chat_bots.py、api 错误提示 —— 建议后续单独回合经 AQUA_I18N 字典逐条替换

### 问题4 · IM 排版 ✅
- [x] 4.1 截头去尾：`.chat-full` 固定 `height:min(720px,82vh)` + `.chat-main` 内滚、输入区贴底
- [x] 4.2 侧栏冗余主题按钮随问题2移除；退出登录 confirm 接 AQUA_I18N.confirm_logout（原已有 confirm）
- [x] 4.3 顶部下拉首击：市场/论坛加 `.nav-caret` 独立箭头（链接点击直达、箭头独立展开）；无箭头项（用户/主题）保持首击展开；重写 chat.php 内联下拉 handler
- [x] 4.4 斜杠命令栏：`showCommandMenu` 内联 `display:block` 被元素自带 `chat-hidden`（`display:none !important`）覆盖 → show/hideCommandMenu 改切 `chat-hidden` 类；`.chat-main` 加 `position:relative` 使菜单锚定输入区上方

## 收尾
- [x] PLAN 更新
- [ ] REQUIREMENTS.md 同步（已过期）
- [ ] CHANGELOG 补第十轮
