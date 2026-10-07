# Requirements — 基地 Digital Market v4 (2026-08-26)

> 优先级标记：🔴 紧急 / 🟠 高 / 🟡 中 / 🟢 低  
> 分类标记：🔒 安全 / 💰 财务 / 🧠 逻辑 / 🎨 布局 / 🗑️ 冗余 / ✨ 体验

---

## A. IM 服务器模式 — 紧急

| ID | 问题 | 文件 | 优先级 |
|---|---|---|---|
| A.1 | `window.AQUA_CHAT_API` 全站无人设置 → IM 走 localStorage 本地模式，服务器/多人消息全不通 | `im/chat.php` (或 `im/init.php`) | 🔴 |
| A.2 | `im/api/index.php` `require novel.php`, `require userdata.php` — **文件缺失** → 500 fatal | `im/api/` | 🔴 |
| A.3 | `adminBroadcastHistory` DOM 缺失 → 广播历史功能报错 | `im/chat.php`, `im/js/chat.js` | 🟠 |

**任务**：
1. `im/chat.php` 添加 `window.AQUA_CHAT_API = '/im/api/'` (或 `im/init.php` 输出)
2. 创建 `im/api/novel.php` 和 `im/api/userdata.php` — 至少 stub 占位，避免 fatal
3. `chat.php` 添加 `adminBroadcastHistory` 元素 + 关联 JS

---

## B. 安全漏洞

| ID | 问题 | 文件 | 优先级 |
|---|---|---|---|
| B.1 | 默认管理员账号硬编码 `admin / Admin@2026` | `security.php` `ensureAdminAccount()` | 🔴 紧急 |
| B.2 | IP 可伪造 → 限流/会话绑定失效 | `security.php` `getClientIP()` | 🔴 紧急 |
| B.3 | 仲裁可重复执行 → 双倍赔付 | `market/disputes.php` | 🔴 紧急 |
| B.4 | 部署方式不一致 → DB 文件可下载 | `router.php` vs `.htaccess` | 🔴 紧急 |
| B.5 | 买家胜诉扣卖家自有余额而非托管金 | `market/disputes.php` `resolve_buyer` | 🔴 紧急 |
| B.5 | 下单竞态 → 余额/库存负数 | `market/buy.php` | 🔴 紧急 |
| B.6 | 充值 TxID 无唯一性校验 | `market/deposit.php` | 🟠 高 |
| B.7 | 存储型 XSS（页脚） | `inc/footer.php` `save_footer` | 🟡 中 |
| B.8 | 免密登录凭据可爆破 | `forum/*`, `market/*` | 🟡 中 |
| B.9 | 快速账号每请求被重命名 | `security.php` `migrateDB()` | 🟡 中 |
| B.10 | 验证码弱 (SVG 明文 + rand) | `captcha.php` | 🟡 中 |
| B.11 | IM 管理开关是空壳 | `im/chat.php`, `im/api/index.php` | 🟡 中 |
| B.12 | 黑产内容合规风险 | `armory.php`, `lang/*` | 🟡 合规 |
| B.13 | 内置账号名预测 | `security.php` | 🟢 低 |
| B.14 | 记住我 token 写了不读 | `login.php`, `logout.php` | 🟢 低 |

---

## C. 财务 / 订单逻辑

| ID | 问题 | 文件 | 优先级 |
|---|---|---|---|
| C.1 | 仲裁无状态守卫 (resolve_* 不检查 status) | `market/disputes.php` | 🔴 紧急 |
| C.2 | 买家胜诉记账错误 (托管语义) | `market/disputes.php` `resolve_buyer` | 🔴 紧急 |
| C.3 | 下单竞态 (SELECT 后 UPDATE 无 WHERE) | `market/buy.php` | 🔴 紧急 |
| C.4 | 充值 TxID 可重复 | `market/deposit.php` | 🟠 高 |
| C.5 | 买家可直接购买自己的商品 | `market/buy.php` | 🟠 高 |
| C.6 | 确认收货可在未发货时执行 | `market/orders.php` | 🟠 高 |
| C.7 | 自动放款无后台任务 (cron/队列) | `market/init.php` | 🟠 高 |
| C.8 | 充值收款地址是占位符 | `market/init.php` | 🔴 紧急 |
| C.9 | 钱包交易记录不完整 (purchase/sale/refund 从不写入) | `market/buy.php`, `wallet.php` | 🟡 中 |

---

## D. 架构 / 代码冗余

| ID | 问题 | 文件/目录 | 优先级 |
|---|---|---|---|
| D.1 | `MARKET_DB_FILE`/`IM_DB_FILE`/`FORUM_DB_FILE` 未使用 + 0 字节死文件 | `security.php`, `database/` | 🟠 高 |
| D.2 | `im/init.php` 全是死代码 (messages 增删改从未调用) | `im/init.php` | 🟡 中 |
| D.3 | `service/` 目录缺失 (后台服务管理配置没有前台展示页) | `service/` | 🟡 中 |
| D.4 | `im/chat.css.bak` 备份残留 | `im/` | 🟢 低 |
| D.5 | 死函数 `forumUserIsAdmin()`, `forumCreateQuickUser($username)` | `forum/*` | 🟢 低 |
| D.6 | 死字段 `is_deleted` (从未置 1), `purchase` 收入分支 | `forum_posts`, `wallet.php` | 🟡 中 |
| D.7 | 三套重复账号体系 无 SSO | `users/`, `forum/`, `im/` | 🟡 中 |
| D.8 | 仓库含真实运行数据 (users.db, app.db, forum.db) | `database/`, `im/data/`, `forum/` | 🟡 中 |
| D.9 | README 与代码脱节 | `README.md` | 🟢 低 |
| D.10 | 散落内联 `style="..."` 与 `--d:.04s` | 全站 | 🟢 低 |
| D.11 | 软删/硬删语义混乱 | `forum/*` | 🟡 中 |

---

## E. 布局 / 移动端

| ID | 问题 | 文件 | 优先级 |
|---|---|---|---|
| E.1 | 全站缺少移动端汉堡菜单 | `assets/app.css` | 🔴 紧急 |
| E.2 | 断点断层 (641–899/901–1199 收口不足) | `assets/app.css`, `im/chat.css` | 🟠 高 |
| E.3 | hover 失效 (CSS 变量含分号) | `dark_web_nav.php:124,136` | 🟢 低 |
| E.4 | 管理后台 tab 无响应式折叠 + 列表无分页 | `admin/index.php` | 🟡 中 |
| E.5 | `grid-4` 在 900–1200px 挤压 | `assets/app.css` | 🟡 中 |
| E.6 | 两套设计体系并存 (app.css vs im/styles.css/chat.css) | `assets/`, `im/` | 🟡 中 |
| E.7 | 登录/注册卡片无移动端视口高度适配 | `assets/app.css` | 🟡 中 |
| E.8 | 文本溢出防护不完整 | 全站 | 🟢 低 |

---

## F. 体验 / UI 统一

| ID | 问题 | 优先级 |
|---|---|---|
| F.1 | 公告栏重复/中英混排 | 🟡 中 |
| F.2 | 数字/金额展示不统一 (货币符号/小数位/格式) | 🟡 中 |
| F.3 | 空状态/错误提示风格不统一 | 🟡 中 |
| F.4 | 粒子画布性能开销 (canvas+指针过多) | 🟡 中 |
| F.5 | favicon/品牌图标缺失/不一致 | 🟢 低 |
| F.6 | 语言切换非即时/未持久化到 localStorage | 🟢 低 |

---

## 执行建议 (Execution Order)

| 阶段 | 任务 | 说明 | 状态 |
|---|---|---|---|
| **Phase 0** 🔴 | A.1–A.3 | 修复 IM 服务器接通 | ✅ 已完成 |
| **Phase 0** 🔴 | A.4 | `im/init.php` 被误删 → IM 聊天页 500/空白 | ✅ 已恢复 |
| **Phase 0** 🔴 | A.5 | `data/bookmarks.html` 被误删 → 暗网导航空白 | ✅ 已恢复 |
| **Phase 1** 🔴 | B.1–B.7, C.1–C.8 | 安全 & 财务漏洞 | ✅ 已完成 |
| **Phase 2** 🟠 | D.1–D.3, E.1–E.3, E.4 | 架构 & 布局 | ⏳ 待处理 |
| **Phase 3** 🟡 | F.1–F.6, D.4–D.11 | 优化 & 清理 | ⏳ 待处理 |

**重要教训**：`im/init.php` 和 `data/bookmarks.html` 不是 dead code/file — `im/init.php` 被 `im/chat.php` require，`data/bookmarks.html` 被 `dark_web_nav.php` server-side 读取。删除前必须 grep 跨目录引用。

---

## 验收标准

1. **IM**: 打开 `im/chat.php` 控制台 → `window.AQUA_CHAT_API` 非空且指向 `/im/api/`；`/api/novel` 和 `/api/userdata` 返回 200 而非 500。
2. **安全**: 仲裁 POST 重复提交第二次 → 提示"已经处理"，不重复变更余额；登录 11 次失败 → 封 IP。
3. **财务**: 并发 10 用户同时下单最后 1 件库存 → 只有 1 人成功扣款；卖家余额不被扣到 0 以下。
4. **布局**: 640px 以下 → 汉堡菜单出现、导航折叠为汉堡图标；tab 栏滚动。
5. **代码**: `php -l` 全部文件通过；没有 0 字节 `database/*.db`（除 `users.db`）；`im/chat.css.bak` 删除。
