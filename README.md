# 基地 - Base

一个功能完整的多模块 PHP Web 应用：统一的用户体系、市场商城、论坛、即时通讯、管理后台，内置中英文双语与响应式界面。

## 功能特性

### 1. 用户系统（全站统一账号）
- 注册 / 登录 / 退出（登录页支持注册面板：`login.php?panel=register`）
- 多语言支持（中文/英文），切换即时生效并通过 localStorage 持久化
- 验证码保护、CSRF 防护、密码强度检查、记住我、会话安全
- 管理员账号：无管理员时由 `ensureAdminAccount()` 自动创建
  （可用环境变量 `ADMIN_INIT_USERNAME` / `ADMIN_INIT_PASSWORD` 预置；未设置则生成随机密码）
- 全局封禁：论坛、IM 与主站共享同一账号状态，封禁/退出全站即时生效

### 2. 市场商城
- 商品浏览 / 搜索 / 分类筛选
- 发布商品、购买（余额或积分）、订单管理与状态流转
- 充值（多币种地址）与提现，钱包交易记录
- 纠纷仲裁、公告栏

### 3. 论坛模块
- 使用统一账号（不再独立注册/登录，forum 作者直接引用主站用户）
- 板块分类、发帖、嵌套评论、置顶 / 锁定、点赞
- 后台帖子管理（分页）、多语言板块名

### 4. 工具展示页（登录后）
- 马里亚纳网、黑客军械库等展示页面，统一充值跳转

### 5. 即时通讯 (IM)
- 全站统一账号桥（`im/init.php`）：以主站用户 ID 为 IM 用户，首次自动建档并发 token
- API 每次请求实时核对主站封禁 / 角色 / 昵称，主站退出时撤销并失效
- 实时消息、对话记录、未读提示、消息上报、管理员广播、机器人账号、文件/图片上传
- 附数据空间（Dataspace）演示模块（独立登录示例）

### 6. 管理后台
- 帖子、用户、商品、订单、资金（充值/提现）、公告、仲裁全量管理
- 列表分页 + 总数统计，响应式标签页

### 7. 安全特性
- Argon2id 密码哈希、验证码、CSRF 令牌、速率限制、单引号绑定参数防注入、XSS 输出消毒、会话加固

## 安装说明

### 环境要求
- PHP 7.4+（扩展：`sqlite3`、`pdo_sqlite`、`gd`、`mbstring`）
- Apache / Nginx / PHP 内置服务器

### 安装步骤

1. 上传文件到服务器
2. 确保目录可写：根目录（数据库 `database/users.db`）、`forum/`（`forum/forum.db`）、`im/data/`（`im/data/app.db` 与上传目录）
3. 访问 `index.php` 开始使用，数据库与演示数据自动初始化（运行期数据库已由 `.gitignore` 排除，不影响干净部署）

使用 PHP 内置服务器开发：
```
php -S 0.0.0.0:8000 router.php
```

### 默认账号
系统首次访问时会自动创建数据库；管理员账号由 `ensureAdminAccount()` 按环境变量自动生成。

## 文件结构

```
├── index.php          # 主页（含公告栏、统计）
├── login.php          # 登录 / 注册面板（?panel=register）
├── logout.php         # 退出（全站会话 + IM token 撤销）
├── dashboard.php      # 用户控制台
├── wallet.php         # 钱包 / 交易记录
├── captcha.php        # 验证码生成
├── security.php       # 安全核心库（会话/语言/DB/权限）
├── dark_web_nav.php   # 顶部导航 + 卡片布局
├── router.php         # PHP 内置服务器路由
├── admin/index.php    # 管理后台
├── assets/            # 样式 / 脚本 / 粒子画布
├── lang/              # 语言文件（zh.php / en.php）
├── forum/             # 论坛模块（init / index / category / post / new-post，
│                      #   login|register|logout 为统一账号 SSO 桥）
├── market/            # 市场模块（mall / buy / sell / orders / deposit / withdraw /
│                      #   disputes / mariana / armory）
├── im/                # IM 模块（chat.php + lib/ + api/ + js/ + data/）
└── database/          # 主站运行期数据库（不入库）
```

## 使用说明

### 语言切换
右上角「中 / EN」按钮即点即切换，选择会写入 localStorage，下次访问自动沿用。

### 论坛使用
1. 访问 `forum/index.php`，使用统一账号（未登录自动跳转主站）
2. 发帖、嵌套评论、点赞

### 商城使用
1. 浏览 / 搜索商品
2. 充值（多币种）或余额支付
3. 购买、查看订单、发起/处理纠纷

### IM 使用
1. 进入聊天页面（未登录回主站登录页）
2. 通过用户 ID 查找并发起对话

## 安全建议

1. 部署使用 HTTPS
2. 定期备份 `database/`、`forum/`、`im/data/` 目录
3. 通过 `ADMIN_INIT_PASSWORD` 预设管理员强密码
4. 勿将运行期数据库、上传内容提交到公开仓库

## 技术栈

- PHP 7.4+（SQLite3 / PDO）
- 原生 JavaScript（无第三方运行时依赖，IM 已由 Node 版迁移为 PHP 实现）
- 自研 Aqua 主题（CSS 变量 + 响应式断点）、FontAwesome / Bootstrap Icons

## 许可证

仅供学习研究使用，请勿用于非法目的。