<div align="center">

# 云雀 AI 检测助手

**基于腾讯云 EdgeOne Makers 内置「朱雀」AI 文本检测模型构建的 AIGC 内容检测工具**

由 **沧州虎王科技** 开发并开源 · 纯 PHP 实现 · 无需数据库 · 单文件级部署

[![PHP](https://img.shields.io/badge/PHP-%E2%89%A57.4-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Database](https://img.shields.io/badge/Database-None-success)](#)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](#许可证)
[![Platform](https://img.shields.io/badge/Platform-Apache%20%7C%20Nginx-informational)](#部署指南)

</div>

---

## 📖 目录

- [项目简介](#项目简介)
- [功能特性](#功能特性)
- [界面预览](#界面预览)
- [技术栈](#技术栈)
- [目录结构](#目录结构)
- [环境要求](#环境要求)
- [快速开始](#快速开始)
- [获取腾讯云 API Key](#获取腾讯云-api-key)
- [核心机制说明](#核心机制说明)
- [配置说明](#配置说明)
- [API 接口文档](#api-接口文档)
- [腾讯朱雀模型调用说明](#腾讯朱雀模型调用说明)
- [部署指南](#部署指南)
- [安全说明](#安全说明)
- [常见问题 FAQ](#常见问题-faq)
- [贡献指南](#贡献指南)
- [许可证](#许可证)
- [免责声明](#免责声明)
- [致谢](#致谢)

---

## 项目简介

**云雀 AI 检测助手**（以下简称「本项目」）是 **沧州虎王科技** 基于腾讯云 **EdgeOne Makers** 内置模型 `@makers/zhuque-text`（朱雀 AI 文本检测）二次开发的开源工具，用于判断一段文本是否由 AI 生成，并给出整体置信度、风险占比与分段明细。

> 官方能力文档：<https://cloud.tencent.com/document/product/1552/137539>

本项目在官方“调用一次模型”的基础上，补全了**面向真实生产使用**的一整套能力：

- 面向终端用户的**检测面板**（文本 / 图像、日夜间皮肤、响应式）
- 面向管理员的**后台系统**（多 Token 池、自动切换、自动监控、网站设置、个性化、开发记录）
- **无数据库**、纯 JSON 存储，最低成本即可部署

适合自建内容审核、AIGC 识别演示、教学研究等场景。**检测结果仅供参考，请勿作为唯一判定依据。**

---

## 功能特性

### 🖥 前台检测面板

- **文本检测**：单条检测 / 批量检测（每行一条），返回整体置信度、风险占比、人工 / AI / 疑似 AI 占比
- **分段明细**：可关闭“合并段落”，逐段展示各段类型与置信度
- **图像检测**：点击 / 拖拽上传或粘贴图片 URL，需在后台配置图像检测接口（官方模型仅支持文本，见下文）
- **日/夜/跟随系统**：三态皮肤切换，默认跟随系统并记忆在 `localStorage`
- **响应式**：手机 / 平板 / PC 自适应，移动端导航折叠、栅格重排
- **个性化**：站点标题、品牌文字、LOGO emoji、主题主色、背景样式、公告横幅、页脚、自定义 CSS、展示开关均由后台控制

### 🔑 多 Token 池与自动容灾

- 后台可添加**多个** Makers API Key，检测时**自动轮询**
- 遇 `HTTP 401 / 403 / 429`（鉴权失败 / 限流）自动切换到下一个可用 Token 重试
- 单个 Token 连续失败达阈值后进入**冷却期**，到点自动恢复
- **一键检测可用性**：批量真实探测全部 Token 状态
- **添加前测试**：新增 Token 前可先做一次连通性测试
- 完整 Key 支持**明文 / 掩码切换**与**一键复制**

### 🛰 Token 自动监控

- 通过 `cron` 定时触发（`cron/monitor.php?key=密钥`）或后台「立即检测」手动触发
- 官方返回“额度不足”或累计用量达每月免费额度 → **自动禁用**
- 持续 `401/403` 鉴权无效超阈值 → **自动删除**无效 Token（`429` 限流与网络错误不计入）
- 记录每次运行日志、健康分布（健康 / 冷却 / 停用）、上次 / 下次运行时间
- 监控**总开关 / 运行间隔 / 免费额度 / 自动删除 / 删除阈值**均可在后台可视化配置

### 🛠 后台管理模块

| 模块 | 说明 |
|---|---|
| 总览 | Token 数量、可用数、累计调用、健康概览 |
| 提交明细 | 每次提交的时间 / IP / 类型 / 文本长度与预览 / 判定 / 消耗 token / 使用 Token / 成败 |
| 按 IP 汇总 | 按 IP、按日汇总调用与 token 消耗，可过滤 |
| Token 监控 | 健康状态表、立即检测、监控日志、cron URL、监控密钥与监控配置 |
| Token 池 | 增删改查、启停、测试、一键检测、查看 / 复制完整 Key |
| 运行设置 | 失败阈值、冷却时长、默认合并段落、贡献服务开关等 |
| 网站设置 | 站点标题 / 品牌文字 / 副标题 / 简介 / 页脚 / 图像检测接口与数量 |
| 个性化 | 主题主色、背景样式、公告、页脚附加、自定义 CSS、展示开关 |
| 安全设置 | 后台密码修改 |

### 🤝 其它

- **Token 贡献服务**：面向访客的公开提交页，引导用户贡献自己的 Makers Key；后端先做连通性测试，通过后入池，并带格式校验、同 IP 冷却、单 IP 配额、池子上限等防滥用限制
- **历史记录**：服务端保存最近 200 条检测记录，可查看 / 清空
- **无数据库**：全部数据以 JSON 文件存储于 `data/`，迁移仅需拷贝目录
- **HTTPS 证书处理**：内置 `cacert.pem`，并对证书校验失败做可控降级重试，兼容缺少 CA 的 PHP 环境

---

## 界面预览

> 仓库暂未内置截图。你可以将界面截图放入 `docs/screenshots/`，再解除下方注释即可在 README 中展示。

<!--
![前台检测面板](docs/screenshots/home.png)
![检测结果](docs/screenshots/result.png)
![后台管理](docs/screenshots/admin.png)
-->

| 页面 | 建议截图文件名 |
|---|---|
| 前台检测面板 | `docs/screenshots/home.png` |
| 检测结果展示 | `docs/screenshots/result.png` |
| 后台 · Token 监控 | `docs/screenshots/admin-monitor.png` |
| 后台 · Token 池 | `docs/screenshots/admin-tokens.png` |

---

## 技术栈

| 类别 | 选型 |
|---|---|
| 后端 | 原生 PHP（无框架、无 Composer 依赖） |
| 存储 | JSON 文件（`data/`，无数据库） |
| HTTP | cURL（含自定义 CA / 证书策略） |
| 前端 | 原生 HTML / CSS / JavaScript（无构建步骤） |
| 主题 | CSS 变量 + `prefers-color-scheme` + `localStorage` |
| 鉴权 | PHP Session + `password_hash`(bcrypt) |
| 计划任务 | 系统 crontab 触发 `cron/monitor.php` |

---

## 目录结构

```text
.
├── index.php                 # 前台检测面板（文本 / 图像）
├── contribute.php            # 前台「贡献 Token」页面
├── config.php                # 全局常量配置（API 地址、额度、监控、SSL 等）
├── cacert.pem                # 内置 CA 证书包（启用 HTTPS 校验）
├── .htaccess                 # Apache 安全规则（禁止直读 data/、lib/、config.php）
├── nginx.htaccess            # Nginx 部署安全片段（需手动合并）
│
├── admin/                    # 后台系统
│   ├── index.php             # 后台外壳 / 路由（?m=模块）
│   ├── login.php             # 后台登录（首次设置密码）
│   ├── modules/              # 各功能模块
│   │   ├── dashboard.php     # 总览
│   │   ├── records.php       # 提交明细
│   │   ├── records_ip.php    # 按 IP 汇总
│   │   ├── monitor.php       # Token 监控
│   │   ├── tokens.php        # Token 池
│   │   ├── runtime.php       # 运行设置
│   │   ├── site.php          # 网站设置
│   │   ├── persona.php       # 个性化
│   │   └── security.php      # 安全设置
│   └── partials/             # 公共头 / 导航 / 尾
│
├── api/                      # 后端接口
│   ├── detect.php            # 文本检测（单条 / 批量，自动切换 Token）
│   ├── detect_image.php      # 图像检测（走可配置接口）
│   ├── history.php           # 历史记录（读公开 / 清空需登录）
│   ├── config.php            # 旧版单 Key 配置（需登录）
│   ├── contribute.php        # Token 贡献提交
│   ├── tokens.php            # Token 池 / 设置 / 改密（需登录）
│   ├── monitor.php           # 监控运行 / 状态 / 日志 / 总览（需登录）
│   └── records.php           # 开发记录查询 / 导出 / 清空（需登录）
│
├── cron/
│   └── monitor.php           # 定时监控触发器（?key=密钥）
│
├── lib/                      # 核心类库
│   ├── ZhuqueClient.php      # 朱雀网关客户端（多 Token 自动切换）
│   ├── ImageClient.php       # 图像检测客户端
│   ├── TokenPool.php         # Token 池存储 / 轮询 / 冷却 / 用量
│   ├── Monitor.php           # 自动监控逻辑
│   ├── History.php           # 历史记录
│   ├── Records.php           # 开发记录（按 IP）
│   ├── Contrib.php           # Token 贡献（限流 / 配额）
│   └── Auth.php              # 后台会话鉴权
│
├── assets/
│   ├── css/{style.css, admin.css}
│   └── js/{app.js, admin.js, theme.js}
│
└── data/                     # 运行时生成（需可写，禁止 Web 直读）
    ├── settings.json         # 后台设置（含密码哈希、监控密钥等）
    ├── tokens.json           # Token 池（含明文 Key，务必保护）
    ├── history.json          # 历史记录
    ├── records.json          # 开发记录
    └── monitor_log.json      # 监控日志
```

---

## 环境要求

- **PHP >= 7.4**（推荐 8.x）
- PHP 扩展：`curl`、`json`、`openssl`；推荐 `mbstring`（用于安全截断中文）
- Web 服务器：**Apache** 或 **Nginx**（生产环境，见[部署指南](#部署指南)）
- `data/` 目录**可写**（运行时会自动创建）

> ⚠️ **不要使用 PHP 内置服务器（`php -S`）对外生产部署**：它不解析 `.htaccess`，会导致 `data/` 下的明文 Token、密码哈希被直接下载。

---

## 快速开始

```bash
# 1. 将项目放入 Web 根目录（或子目录）
#    例如 Apache: /var/www/html/yunque

# 2. 确保 data/ 可写
mkdir -p data && chmod 755 data

# 3. （本地开发仅限）可用内置服务器快速预览
php -S 127.0.0.1:8000
```

打开 `http://你的域名/` 进入前台面板，点击右上角 **「后台管理」**：

1. 首次进入需**设置后台密码**；
2. 在「Token 池」添加一个或多个 **Makers API Key**；
3. 回到前台输入文本，点击 **开始检测** 即可。

---

## 获取腾讯云 API Key

1. 登录 **EdgeOne 控制台**；
2. 进入 **Makers → Models → API Key**；
3. 点击 **创建 API Key**，填写名称并保存（请妥善保管，仅显示一次）；
4. 将 Key 粘贴到本项目后台「Token 池」。

> 免费额度：每个 Key **每月 50 万 token**（以官方回复的 `makers_models_usage.total_tokens` 扣减为准）。  
> 官方接口当前**仅支持文本检测**；图像检测需另行联系腾讯云开通企业版，或自行在后台配置兼容的图像检测接口。

---

## 核心机制说明

### Token 自动切换

- 每次检测按**轮询**取下一个可用 Token（启用且不在冷却期）；
- 返回 `401 / 403 / 429` 时记一次失败并**自动切换**下一个可用 Token 重试；
- 连续失败达阈值（后台可配，默认 3 次）进入**冷却期**（默认 300 秒），到期自动恢复；
- 业务类错误（如内容不过审）直接返回，不消耗切换额度。

### 自动监控

- `cron` 定时访问 `cron/monitor.php?key=监控密钥`，或后台点「立即检测」；
- 额度用尽 → 自动禁用；持续 `401/403` → 达到阈值自动删除；
- 后台可设置监控**总开关 / 间隔 / 额度 / 自动删除 / 阈值**；
- 每次运行写入 `data/monitor_log.json` 并生成健康分布。

### Token 贡献（防滥用）

- 格式严格校验（`sk-` 开头 48 位十六进制）；
- 同 IP 冷却 `CONTRIB_COOLDOWN_SEC` 秒、单 IP 最多 `CONTRIB_MAX_PER_IP` 个、池子上限 `CONTRIB_POOL_LIMIT` 个；
- 提交前先做连通性测试，通过后入池并去重。

---

## 配置说明

主要常量集中在 `config.php`（多数运行参数亦可在后台可视化调整并持久化到 `data/settings.json`）：

| 常量 | 默认 | 说明 |
|---|---|---|
| `ZHUQUE_API_URL` | `https://ai-gateway.edgeone.link/v1/providers/zhuque-text/classify` | 朱雀网关地址（**请勿修改**） |
| `ZHUQUE_TIMEOUT` | `60` | 请求超时（秒） |
| `HISTORY_LIMIT` | `200` | 历史记录最多保留条数 |
| `RECORD_LIMIT` | `5000` | 开发记录最多保留条数 |
| `FREE_MONTHLY_QUOTA` | `500000` | 单 Token 每月免费额度（token），达上限自动禁用 |
| `MONITOR_AUTO_DELETE` | `true` | 是否自动删除持续无效的 Token |
| `MONITOR_DELETE_AFTER_FAILS` | `4` | 连续鉴权无效多少次后删除 |
| `CONTRIB_POOL_LIMIT` | `30` | 池子 Token 数量上限 |
| `CONTRIB_MAX_PER_IP` | `3` | 单 IP 最多贡献数 |
| `CONTRIB_COOLDOWN_SEC` | `300` | 同 IP 提交最小间隔（秒） |
| `ZHUQUE_SSL_VERIFY_PEER` | `true` | 是否校验对端 HTTPS 证书 |
| `ZHUQUE_CA_BUNDLE` | `./cacert.pem` | 自定义 CA 证书包路径 |
| `ZHUQUE_SSL_INSECURE_FALLBACK` | `true` | 校验失败且缺 CA 时是否降级重试一次 |

> 🔐 敏感项如监控密钥、Token 池 Key、后台密码哈希均保存在 `data/`，**务必确保其不可被 Web 直接访问**，并建议将 `data/` 移出站点根目录或使用服务器规则拦截。

---

## API 接口文档

所有接口返回 JSON。管理类接口需后台登录（Session）。

### 公开接口

| 方法 | 路径 | 说明 |
|---|---|---|
| `POST` | `/api/detect.php` | 文本检测。请求体 `{ "text": "内容", "is_merge": true }` 或批量 `{ "texts": ["a","b"], "is_merge": true }` |
| `POST` | `/api/detect_image.php` | 图像检测。请求体 `{ "images": [ {"url":"https://..."} 或 {"data":"<base64>"} ] }`（需后台配置图像接口） |
| `GET` | `/api/history.php` | 读取最近历史记录 |
| `POST` | `/api/contribute.php` | 提交 Makers Key 贡献入池。请求体 `{ "key": "sk-...", "name": "备注" }` |

### 管理接口（需登录）

| 方法 | 路径 | 动作 |
|---|---|---|
| `POST` | `/api/tokens.php` | `list` / `add` / `update` / `toggle` / `poke` / `remove` / `test` / `test_all` / `test_key` / `settings_get` / `settings_set` / `password_set` |
| `POST` | `/api/monitor.php` | `run` / `status` / `log` / `clear_log` / `overview` |
| `POST` | `/api/records.php` | `list` / `export` / `clear` |
| `POST` | `/api/history.php` | `{ "action": "clear" }`（清空历史，需登录） |
| `POST` | `/api/config.php` | `save` / `status`（旧版单 Key，需登录） |

### 定时任务

```bash
# 每 30 分钟执行一次 Token 监控（key 为后台「Token 监控」中配置的监控密钥）
*/30 * * * * curl -s "https://你的域名/cron/monitor.php?key=你的监控密钥" >/dev/null
```

---

## 腾讯朱雀模型调用说明

本项目通过腾讯云 **EdgeOne Makers** 网关调用内置模型 `@makers/zhuque-text`。

**请求**

```http
POST https://ai-gateway.edgeone.link/v1/providers/zhuque-text/classify
Authorization: Bearer <MAKERS_MODELS_KEY>
Content-Type: application/json

{ "text": "待检测文本", "is_merge": true }
```

**响应（关键字段）**

| 字段 | 说明 |
|---|---|
| `status` | 推理状态，`success` 表示成功 |
| `softmax_confidence` | 整体置信度（越大越可能命中风险） |
| `ratio_confidence` | 整体风险内容占比 |
| `labels_ratio` | 各类型占比，`"0"`=人工、`"1"`=AI、`"2"`=疑似 AI（取值 0~1） |
| `segment_labels` | 各分段类型、置信度、位置 |
| `usage.total_tokens` | 朱雀模型自身用量（与免费额度核算无关） |
| `makers_models_usage.total_tokens` | **本次实际扣减的 Makers 免费额度 token 数**（以此为准） |
| `msg` | 错误信息（正常为空） |

---

## 部署指南

### Apache

1. 确保站点允许 `.htaccess` 生效（`AllowOverride All`）；
2. 仓库自带 `.htaccess` 已禁止访问 `data/`、`lib/`、`config.php`、`cacert.pem`，并附带安全响应头；
3. 重启 Apache。

### Nginx

Nginx **不读取 `.htaccess`**，请将 `nginx.htaccess` 中的片段合并到站点 `server { }` 中：

```nginx
location ~ ^/(data|lib)/ { deny all; return 404; }
location = /config.php  { deny all; return 404; }
location ~* \.(json|pem)$ { deny all; return 404; }
autoindex off;
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
```

```bash
nginx -t && nginx -s reload
```

### 部署自检

```bash
# 以下均应返回 403 / 404；若返回 200 说明敏感数据可被直接下载，存在严重风险
curl -I https://你的域名/data/settings.json
curl -I https://你的域名/data/tokens.json
curl -I https://你的域名/config.php
curl -I https://你的域名/lib/ZhuqueClient.php
```

### 目录权限

```bash
chmod 755 -R .
chmod 775 data          # 需可写
# 强烈建议：将 data/ 移出站点根目录，或由 Web 服务器显式 deny
```

---

## 安全说明

本项目已做基础安全加固，但部署时仍须注意：

- ✅ 管理接口（`tokens` / `monitor` / `records` / `config` / `history` 清空）**需后台登录**
- ✅ 定时任务使用**监控密钥**并按 `hash_equals` 恒时比较
- ✅ 后台密码 `bcrypt` 哈希存储，登录后 `session_regenerate_id`
- ✅ 前端渲染统一转义；图片检测不在服务器本地请求用户提供的 URL（无本地 SSRF）
- ✅ 无 `eval/system/exec` 等危险函数
- ⚠️ **必须**使用 Apache/Nginx 规则拦截 `data/`、`lib/`、`config.php`、`cacert.pem`（内置服务器不生效）
- ⚠️ 设置**强后台密码**（建议 ≥12 位），并全程启用 **HTTPS**
- ⚠️ 历史记录当前为**服务端共享**存储，若面向多用户公开部署，建议改为浏览器本地存储或加访问控制
- ⚠️ 当前改态接口依赖 `SameSite=Lax` 缓解 CSRF，如需更强防护可自行加入 CSRF Token

> 若发现安全问题，欢迎提交 Issue 或通过下方联系方式反馈。

---

## 常见问题 FAQ

**Q1：提示“Token 鉴权失败（HTTP 401）”？**  
Key 无效、被删除或复制不完整。请在后台「Token 池」点「测试」验证，或重新获取。

**Q2：提示“额度不足 / 达到免费额度”？**  
该 Key 当月免费额度用尽。监控会自动禁用；可更换新 Key 或在后台查看用量。

**Q3：cron 触发返回 `skipped: interval`？**  
距上次运行不足配置的间隔，属正常跳过；如需立即执行可在后台点「立即检测」。

**Q4：图像检测不可用？**  
官方朱雀模型**仅支持文本**。请在后台「网站设置」填写兼容的图像检测接口地址，否则该功能不可用。

**Q5：`curl` 报 SSL error 60？**  
多为 PHP 环境缺少 CA 证书。项目已内置 `cacert.pem` 并支持可控降级；请确保该文件随项目部署。

**Q6：数据保存在哪里？如何备份？**  
全部在 `data/` 目录（JSON 文件），直接打包备份即可。

---

## 贡献指南

欢迎 Issue 与 Pull Request！

1. Fork 本仓库并新建分支：`git checkout -b feature/your-feature`
2. 提交前请确保：PHP 语法检查通过、未提交任何真实 API Key / `data/` 内容
3. 提交 PR 并说明改动内容与动机

> 请勿将 `data/`、真实 Token、`.env` 等敏感信息提交到仓库。

---

## 许可证

本项目基于 **MIT License** 开源。

```text
MIT License

Copyright (c) 2026 沧州虎王科技

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

> 如需更换为其它许可证，请自行修改本节及仓库 LICENSE 文件。

---

## 免责声明

- 本项目为**开源技术工具**，与腾讯云官方无隶属关系；
- 模型能力、接口可用性与计费以腾讯云官方文档为准；
- **检测结果仅供参考**，不构成任何权威结论，使用者应自行判断并承担相应责任；
- 请遵守腾讯云服务条款及所在地区的法律法规，**不得用于任何非法用途**；
- 因使用本项目造成的任何直接或间接损失，作者与 **沧州虎王科技** 不承担责任。

---

## 致谢

- 感谢 **腾讯云 EdgeOne Makers** 提供朱雀 AI 文本检测能力；
- 感谢所有为本项目提交 Issue / PR 的贡献者。

<div align="center">

**沧州虎王科技** · 用技术解决真实问题

如果这个项目对你有帮助，欢迎点一个 ⭐ Star 支持！

</div>
