# LCSC 硬件资产管理 v1.2.0

[![官网](https://img.shields.io/badge/%E5%AE%98%E7%BD%91-lcsc798.pages.dev-5B8DEF?style=flat-square&logo=cloudflare&logoColor=white)](https://lcsc798.pages.dev/)
[![版本](https://img.shields.io/badge/%E7%89%88%E6%9C%AC-v1.2.0-3DA639?style=flat-square)](https://lcsc798.pages.dev/changelog.html)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.0-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-%E2%89%A5%205.7%20%2F%208.x-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?style=flat-square&logo=docker&logoColor=white)](docker-compose.yml)
[![GitHub](https://img.shields.io/badge/GitHub-xiaoxu798%2Flcsc-181717?style=flat-square&logo=github&logoColor=white)](https://github.com/xiaoxu798/lcsc)

面向个人电子爱好者、独立开发者与小微硬件工作室（1~5 人）的**元器件与硬件资产管理系统**。目标是把商城订单、Excel 表格、货架标签等混乱的电子行业数据，自动转换成结构化的硬件资产：物料主数据、分类与库位、库存流水、BOM 项目与成本。

本系统**不是** ERP，**不是**生产制造系统，**不是**采购 / 财务系统。

---

## 官方地址

| 入口 | 地址 |
|---|---|
| 官网 | https://lcsc798.pages.dev/ |
| 二维码配置工具 | https://lcsc798.pages.dev/qr-config.html |
| 更新日志 | https://lcsc798.pages.dev/changelog.html |
| 源码仓库 | https://github.com/xiaoxu798/lcsc |

官网托管在 **Cloudflare Pages**，**暂无自定义域名**，上述 `pages.dev` 地址为当前唯一官方入口。官网只做项目介绍与版本公告，不承载任何业务数据。

访问情况简述（2026-10 现状）：

- **中国大陆**：多数地区与运营商可直连，但速度一般；晚高峰、跨境链路拥塞或个别运营商（移动网络尤甚）可能出现延迟偏高、偶发资源加载失败，刷新页面或更换网络通常可恢复。
- **港澳台 / 东南亚**：一般可正常访问，速度中等。
- **欧美等其他地区**：接入 Cloudflare 就近节点，通常较快。

官网打不开**不影响**本系统的部署与使用：本包内 `README.md` 与 `FEATURE_CHANGES.md` 已包含全部部署与功能说明。

---

> **风险声明**
>
> 本项目全部代码基于 AI（人工智能）辅助生成，虽经多轮调试与优化，但仍可能存在未知的逻辑缺陷、安全漏洞或兼容性问题。使用者应自行评估并承担部署与使用本系统的全部风险，作者不对因使用本系统而导致的任何直接或间接损失（包括但不限于数据丢失、业务中断、安全事故）承担责任。
>
> 建议在生产环境部署前进行完整的本地测试，并定期备份数据库。

---

## 当前版本

| 项 | 值 |
|----|------|
| 版本号 | **v1.2.0** |
| 版本类型 | 正式发行版（Stable Release） |
| 发布日期 | 2026-10-26 |
| 版本代号 | Feature First + Edition Gate |
| 上一发布版 | v1.1.0 |
| 最低 PHP | 8.0（安装向导前置校验） |
| 最低 MySQL | 5.7 / 8.x 或 MariaDB |
| 最低 Composer | 2.0（可选，仅 .xlsx / .xls 导入导出需要） |
| 版本形态 | 双版本：**个人版（personal）** / **团队版（studio）** |

版本形态在安装向导中一次性选择并锁定（`isEditionLocked()`），页面无切换入口；仅可通过 `install.php?reset=1`（管理员会话 + 管理员密码 + 文本 `RESET`）重走版本选择。

> 本版更新内容见 [`CHANGELOG.md`](CHANGELOG.md)；完整功能变更清单见 [`FEATURE_CHANGES.md`](FEATURE_CHANGES.md)。

---

## 核心功能清单

### 1. 物料与库存

- 物料增删改查：商品编号 / 客户编号 / 型号 / 产品名称 / 品牌 / 封装 / 参数 / 库位 / 备注 / 采购链接；5 态状态机（删除为软删除）
- 良品 / 不良品分离计数，支持报损与修复
- 三级低库存预警阈值（单品 > 分类 > 全局）
- 替代料双向互绑与智能推荐；重复物料识别与合并
- 批量操作：分类 / 库位 / 阈值 / 备注 / 删除
- 库存唯一事实源 `stock_log`：手动出入库、扫码出入库、撤销、调整与报损、在线盘点

### 2. 扫码出入库

- 摄像头实时扫码（QR / CODE_128 / CODE_39 / EAN_13 / DATA_MATRIX）与手工输入
- 平台二维码字段自动识别（内置多平台词典，可自定义并保存为模板）
- 入库 / 出库 / 撤销三段串行；连续扫码会话
- 标签打印（二维码 / 条码），需 `can_print`

### 3. BOM 管理

- 项目 5 态生命周期；明细与位号
- BOM 导入（未匹配物料零建主库）；缺料汇总与采购清单 CSV；整单出库（支持多份数）
- 版本管理（draft / released / archived，released 冻结）与版本 diff

### 4. 数据导入

- 平台模板驱动的 4 步向导；字段自动识别与人工修正
- CSV / TSV / XLSX / XLS 多 Sheet 择优；分批处理与行级原子认领；一键回退
- 订单扩展信息归集（收货人 / 快递 / 发票等）

### 5. 成本与资产（成本视图开启时）

- 加权平均成本 + 脏标记增量重算；采购价格历史
- 资产总览与图表、分类分布、高价值 TOP、预警汇总
- 呆滞物料专区（阈值可调、标记已处理、CSV 导出）

### 6. 分类与库位

- 两级分类树（多对多；合并 / 重命名 / 分类阈值）
- 库位树 3 级（团队版；结构化绑定、文本回填、CSV 导入、寻物）

### 7. 治理与运维

- 数据健康中心（分组检测 + 一键修复 + 分页下钻）
- 导出中心、标签打印、待处理中心
- 溯源日志与快照回滚、留存期约束
- 系统设置 / 平台管理 / 用户与子用户 / API Key / 备份恢复 / 定时备份 / 版本升级 / 系统监控

### 8. 双端

- 电脑端：侧栏 + 多标签工作台；移动端：独立入口与框架（工作台 / 扫码 / 查询 / 我的）
- 两端表现层零交叉引用，只共享后端（登录守卫与服务层）

### 9. 部署与安全

- 自带 Apache 安全规则（6 个 `.htaccess`）；Nginx 提供等价配置模板（`deploy/nginx.conf`）
- 密钥（WebDAV 密码 / 第三方 API Key）加密存储；全站写操作留痕（溯源日志）
- 出站服务统一底座（天气 / 一言 / 版本检测），含内网阻断与多源降级

---

## 安装

### 一、安装向导（三步）

把代码部署到 Web 根目录后访问 `install.php`：

1. **环境检测** —— PHP ≥ 8.0、必需扩展（`pdo_mysql` / `mbstring` / `json` / `session` / `fileinfo` / 上传开关）、数据目录可写（`uploads/`、`uploads/import/`、`uploads/thumbs/`、`backups/`）。可选扩展缺失只降级不阻断（`gd` → 缩略图退化为原图；`curl` → 版本检测与 WebDAV 备份不可用；`zip` + `xml` → xlsx 导入导出不可用）。
2. **数据库配置** —— 填写 MySQL 连接信息，自动建库、建表、生成 `config.db.php`。
3. **站点设置** —— 站点名称、管理员账号（用户名 ≥ 3 字符；密码 ≥ 8 位且含 ≥3 类字符）、选择版本；写入 `.installed` 并跳转。

### 二、免向导自动安装

显式设置 `LCSC_AUTOINSTALL=1` 后请求 `install.php`，脚本按环境变量完成建库、建表、创建管理员与版本锁定，输出一行结果；已安装时自动跳过（幂等）。

```bash
LCSC_AUTOINSTALL=1 \
LCSC_DB_HOST=127.0.0.1 LCSC_DB_PORT=3306 LCSC_DB_NAME=lcsc LCSC_DB_USER=root LCSC_DB_PASS=secret \
LCSC_EDITION=studio LCSC_ADMIN_USER=admin LCSC_ADMIN_PASS='Str0ng#Pass' \
php install.php
```

| 环境变量 | 默认值 | 说明 |
|----------|--------|------|
| `LCSC_AUTOINSTALL` | — | 必须显式为 `1` 才启用；已安装时自动跳过 |
| `LCSC_DB_HOST` / `LCSC_DB_PORT` / `LCSC_DB_NAME` / `LCSC_DB_USER` / `LCSC_DB_PASS` | 127.0.0.1 / 3306 / lcsc / root / 空 | 数据库连接（库不存在会自动创建） |
| `LCSC_EDITION` | `studio` | `personal` / `studio`，安装即锁定 |
| `LCSC_ADMIN_USER` / `LCSC_ADMIN_PASS` | admin / — | 库中无管理员时必填；密码强度要求同向导 |
| `LCSC_TZ` | `Asia/Shanghai` | 统一时区（容器默认 UTC 会导致时间与留存 / 呆滞天数判定漂移） |

### 三、需要持久化

`config.db.php`、`.installed`、`uploads/`（含 `uploads/import/`、`uploads/thumbs/`）、`backups/`。代码目录本身只读即可运行。

> `uploads/import/` 存放导入中心上传的原始表格，`uploads/thumbs/` 存放图片缩略图缓存；两者由安装向导 / 运行期自动创建，发行包不预置空目录。`uploads/thumbs/` 可随时删除，下次看图自动重建。
>
> **GD 扩展是可选的**：未安装时缩略图接口自动降级为直接返回原图，页面照常显示，只是图片流量大一些。

### 四、首次配置建议

1. **平台** —— 「系统设置 → 平台管理」确认默认平台（立创商城）与链接模板。
2. **导入模板** —— 「导入中心」确认内置模板字段映射与匹配键，必要时自定义并保存为模板。
3. **分类与库位** —— 建立常用分类树与库位层级（团队版），再开始批量导入。

---

## Docker 部署（可选）

发行包根目录已提供 `docker-compose.yml`、`docker/`、`.env.example`、`.dockerignore`。编排为 `app`（PHP-FPM + 自动安装）→ `web`（Nginx）→ `db`（MySQL 8.0），持久卷 `lcsc_app` / `lcsc_db` / `lcsc_sessions`。

### 快速开始

1. 复制并修改密码：

   ```bash
   cp .env.example .env
   ```

   至少修改 `MYSQL_ROOT_PASSWORD`、`LCSC_ADMIN_PASS`。

2. 构建并启动：

   ```bash
   docker compose up -d --build
   ```

3. 查看首次安装：

   ```bash
   docker compose logs -f app
   ```

   看到 `[autoinstall] 安装完成` 即可。

4. 浏览器打开 `http://<主机>:${LCSC_PORT:-8080}`，用 `.env` 的 `LCSC_ADMIN_USER` / `LCSC_ADMIN_PASS` 登录。

首次启动由 `app` 把镜像代码复制到 `lcsc_app` 卷（`.lcsc-bootstrap` 标记，复制中断自动重试），等待 MySQL 后按 `LCSC_AUTOINSTALL=1` 执行 `install.php`；已安装时跳过。Docker 部署时**代码卷必须可写**（站内在线升级会改代码），与上面「代码目录只读即可运行」的普通部署不同。

> 同一台机器上已有 `lcsc` 项目在运行时，请用 `docker compose -p lcsc-release ...` 并设置不同的 `LCSC_PORT`，避免复用现有容器与数据卷。

### 卷与升级

| 卷 | 内容 | 说明 |
|---|---|---|
| `lcsc_app` | 代码 + `config.db.php` + `.installed` + `uploads/` + `backups/` + `updates/` | 删卷 = 丢数据与配置 |
| `lcsc_db` | MySQL 数据 | 删卷 = 丢数据库 |
| `lcsc_sessions` | PHP session | 可删，删后需重新登录 |

- 不要执行 `docker compose down -v`。
- 重建镜像不会自动替换卷内旧代码；升级走站内在线升级，或备份数据后重建卷。

### 反代与 HTTPS

- 镜像已包含 `ca-certificates`，并在 `docker/php.ini` 设置 `curl.cainfo` / `openssl.cafile`；后台「系统设置 → 出站环境」应显示“HTTPS 出站可用”。
- 宿主机反代需传 `X-Real-IP` / `X-Forwarded-For` / `X-Forwarded-Proto`。推荐 `.env` 设 `LCSC_BIND_IP=127.0.0.1`，由宿主机反代访问 `127.0.0.1:8080`，不要把 8080 直接暴露公网。
- **宝塔/宿主 Nginx 默认生成的 `location ~ \.(js|css)$` 与 `location ~ \.(gif|jpg|jpeg|png|bmp|swf)$` 必须删除**：正则 location 优先于 `location /`，否则 CSS / JS / 图片会从宿主站点目录找并 404。纯反代站点只保留 `location / { proxy_pass http://127.0.0.1:8080; ... }`。

---

## Windows 桌面版（可选）

除源码部署与 Docker 部署外，Windows 用户可直接使用桌面版：启动器内置 MariaDB / PHP-CGI / Nginx 运行时，双击即可运行，无需预先安装 Web 环境。

| 形态 | 说明 |
|---|---|
| 安装包（Setup `.exe`） | 按用户安装，创建桌面 / 开始菜单快捷方式；内置卸载入口（默认保留业务数据，删除数据需二次确认） |
| 便携 ZIP | 解压即用，目录内写 `portable.flag`，数据与程序同目录 |

两种形态的文件与功能完全一致，安装包只多安装 / 卸载外壳。

### 首次使用

1. 启动 `LCSC.exe`，启动器按 数据库 → PHP-CGI → Nginx 顺序自动拉起服务（首次会初始化数据库，耗时较长属正常）。
2. 「概览」页完成首次安装：管理员账号、密码、个人版 / 团队版；安装完成后「打开界面」用系统默认浏览器打开站点。
3. 默认数据根目录为 `%LOCALAPPDATA%\LCSC`；便携版为解压目录。配置、数据库、日志、备份都在该目录内，**备份时整个目录一起备份**。

### 常用能力

- 服务页：启动 / 停止 / 重启、端口与运行时长、各服务日志尾部、错误诊断入口。
- 设置页：HTTP / FastCGI / MySQL 端口、PHP-CGI 实例数、局域网访问、开机自启、关闭时最小化到托盘。
- 维护：数据库快照备份、完整数据导出（数据库 + 附件 + 配置与密钥）、停服升级（失败自动回滚）。
- 端口冲突启动器会自动避让；在设置页修改端口后会重新启动服务并探测端口连通性，失败自动恢复原配置。

> 桌面版使用内置运行时，**不含 Docker 部署文件**。Docker 部署请使用源码发行包（见上一节）。
>
> 以本机或局域网地址（`127.0.0.1`、`192.168.x` 等）访问时，工作台天气按「无 IP」策略回退默认数据。

---

## 部署（Web 服务器配置）

> 承担安全职责的规则必须在部署文档给出等价写法：**改任何 `.htaccess` 或服务器配置，都要同步本节**。

### 1. Web 根目录必须是项目根目录

**不是** `public/`（本项目没有该目录，也不打算引入）—— `app/`、`modules/`、`ui/`、`mobile/` 下的 PHP 都必须能被 Web 请求到：页面入口靠 `?v=` 参数分发，API 靠 `?action=模块.动作`，**不依赖任何 URL 重写规则**。

### 2. Apache —— 开箱即用

随包自带 5 个 `.htaccess`，覆盖全部访问防护：

| 文件 | 作用 |
|---|---|
| `/.htaccess` | 禁目录列表；拦 `sql\|md\|log\|ini\|sh\|bak` 后缀、`config.php`、`config.db.php`、点文件、`json\|lock` 后缀；拒 `dev/`；三个安全头 |
| `/app/.htaccess` | 禁直接访问（`app/api/router.php` 除外，公开 API 入口） |
| `/ui/.htaccess` | 禁直接访问（布局与主题文件只供 `require`） |
| `/uploads/.htaccess` | **禁 PHP 执行**（图片、PDF 正常访问） |
| `/updates/.htaccess` | **整目录拒绝访问**（在线升级工作区：下载包 / staging / 旧版本源文件回滚副本，被读走等于源码泄露） |
| `/deploy/.htaccess` | 禁直接访问（Nginx 配置只供部署者复制） |

要求 `AllowOverride All`（或至少 `AllowOverride FileInfo Options AuthConfig`）。`AllowOverride None` 会让上述规则全部失效。

### 3. Nginx —— 必须引入等价配置

**Nginx 完全不读 `.htaccess`。** 不配等价规则时，`config.db.php` / `.installed` / `dev/` / `*.sql` / `*.md` / `*.log` / `app/` / `ui/` 会失去保护，其中 **`uploads/` 下的 `.php` 可被执行**是最高风险项。

做法：把随包的 `deploy/nginx.conf` 放进 `server {}` 块，按注释替换 `server_name` / `root` / `fastcgi_pass` 三处，复制到 Nginx 配置目录后 `nginx -t` 检查。文件内已标注**三条 `location` 优先级陷阱**并附部署后自检清单（10 条 `curl -I` 及期望状态码）。最容易踩的一条：公开 API 入口必须用**精确匹配** `location = /app/api/router.php`，写成前缀匹配会被 `^~ /app/` 抢先拒掉，表现为「所有 API 一律 403」。

> 该目录自身也被防护（Apache 侧 `deploy/.htaccess` + 根 `.htaccess` 的 `.conf` 后缀规则；Nginx 侧对应 `deny all`），`curl -I .../deploy/nginx.conf` 应返回 **403**。

### 4. PHP 内置服务器（`php -S`）—— 仅本地开发

```bash
php -S 127.0.0.1:8899 -t .
```

同样不读 `.htaccess`，**不要用于部署**。

### 5. HTTPS 与 CA 证书（出站功能）

工作台问候区（天气 / 一言）、版本检测等**出站功能**需要 HTTPS。Windows + 自行编译 PHP 时 `openssl.cafile` / `curl.cainfo` 默认为空，此时所有 HTTPS 出站会失败，而用户看到的只是「天气接口不可用」。

正确做法：下载 `https://curl.se/ca/cacert.pem`，在 `php.ini` 的 `[curl]` 段设 `curl.cainfo`。**不要**为了绕过而关闭证书校验 —— 出站会携带用户 API Key，降级等于把 HTTPS 变成可被中间人篡改的连接。系统设置页「出站环境」有该项自检结论与修法提示。

---

## 运行与运维入口

| 入口 | 用途 |
|------|------|
| `index.php` | 统一页面入口，`index.php?v=xxx` 经 `App\Core\PageRouter` 调度 |
| `mobile/index.php` | 移动端独立入口，`?v=home\|scan\|search\|profile` |
| `api.php` | 统一 API 入口（`app/api/router.php`），唯一格式 `?action=module.method` |
| `external_api.php` | 对外 API 入口，**仅接受 `X-API-Key`** |
| `detail_ajax.php` | 物料详情兼容壳，转发 `api.php?action=detail.*` |
| `print.php` | 标签打印独立入口（需 `can_print`） |
| `install.php` | 安装向导 / `?reset=1` 重置安装锁 |

- **定时备份不依赖 crontab**：`initDB()` 末尾按 `backup_mode` 二选一执行 —— `schedule`（定时备份）由应用内守候进程在 `backup_time` 执行；`on_request`（周期内首次请求补跑）。两者按 `backup_frequency`（`daily` / `weekly`）与 `backup_keep_count` 执行与滚动清理。
- **WebDAV 远程备份**：后台「数据备份」页可保存 WebDAV 配置并上传备份，需 cURL 扩展。

---

## 目录结构

```text
├── app/        公共框架层（core / api / components / Helper / Service）
├── modules/    业务模块（10 个）
├── ui/         公共 UI 层（layouts / components / themes）
├── mobile/     移动端独立框架
├── deploy/     服务器配置模板（Nginx）
├── third_party/ 前端第三方库本地副本（离线 / 内网可用）
├── backups/    备份输出（需可写）
└── uploads/    上传文件（需可写，禁止 PHP 执行）
                ├── import/   导入文件暂存
                └── thumbs/   图片缩略图缓存（可删，自动重建）
```

---

## 第三方组件

| 名称 | 版本 | 用途 | 许可 | 地址 |
|------|------|------|------|------|
| html5-qrcode | 2.3.8 | 摄像头实时扫码（QR / CODE_128 / CODE_39 / EAN_13 / DATA_MATRIX） | Apache-2.0 | https://github.com/mebjas/html5-qrcode |
| qrcode-generator | 2.0.4 | 标签二维码生成 | MIT | https://github.com/kazuhikoarase/qrcode-generator |
| Chart.js | 4.4.0 | 资产统计与趋势图表 | MIT | https://github.com/chartjs/Chart.js |
| PhpSpreadsheet | ^2.0 | Excel / CSV 读取与导出 | LGPL-3.0-or-later | https://github.com/PHPOffice/PhpSpreadsheet |

- 前端库**随包分发**：本地副本在 `third_party/js/`（来源 / 字节数 / SHA-256 见 `third_party/THIRD_PARTY.txt`），**离线 / 内网部署不再依赖 CDN**；Google Fonts 仍在线引用（断网时回落系统字体）。
- 扫码模块的图像预处理容错为自研 JavaScript（Canvas API 多通道预处理）；后端业务模块、导入服务、迁移框架、数据表设计、前端页面与 UI 主题均为自研。
- 所有第三方代码保留原作者署名与协议声明。

开源地址：https://github.com/xiaoxu798/lcsc
开源地址：https://gitee.com/xiaoxu798/lcsc