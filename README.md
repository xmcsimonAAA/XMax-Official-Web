# XMax Official Website

XMAX 官网，基于 ThinkCMF 6 / ThinkPHP 6。

## 分支

- `main`：以同事提供的线上源码快照为基准，已移除源码中的凭据；不包含尚未上线的 SEC / GlobeNewswire 自动同步功能。
- `develop`：从上述正式基准继续开发，包含待验证、待上线的功能。
- `codex/archive-pre-split-20261119`：分支整理前的开发恢复点，不用于部署。

源码来源、与备份的差异、缺失的业务数据和发布步骤见 [BRANCHES.md](BRANCHES.md)。本次只整理本地仓库，没有推送 GitHub 或部署服务器。

## 环境与配置

需要 PHP 7.4+、MySQL 5.7+、Composer，以及 `curl`、`dom`、`json`、`libxml`、`mbstring`、`pdo_mysql` 扩展。Web 根目录指向 `public/`，并启用 URL 重写。

首次安装时，在项目根目录执行：

```bash
composer install --no-scripts
cp .env.example .env
```

填写本机或服务器的数据库连接信息、`DATABASE_AUTHCODE` 和 Twelve Data API 密钥。`.env.example` 只有占位值，真实配置只保存在忽略的 `.env` 中。现有安装请保留原有配置，不要覆盖。

Composer 脚本可能执行数据库迁移；先使用 `--no-scripts` 安装依赖，确认数据库和备份后再按部署流程执行需要的命令。

## 数据与媒体

Git 不保存业务数据库、上传媒体、日志、缓存和依赖目录。首次完整还原需要单独恢复数据库及 `public/upload/`。项目中的 `portal.sql` 和 `thinkcmf.sql` 是安装结构，不是线上业务数据库备份。

在已有服务器上部署时应保留 `.env`、`data/config/database.yml`、`data/install.lock`、`public/.user.ini`、`public/upload/` 和 `data/runtime/`，并使用适合服务器的 Web/PHP 配置。

## 发布

同事的正式部署只跟踪经确认的 `main`，开发时使用 `develop`。先测试并审核 `main...develop` 差异，经批准合并到 `main` 后再推送和部署。任何新迁移都需先备份数据库，并按该功能的部署说明执行。

本地 `main` 来源于提供的快照，尚未和当前服务器重新核验，也未因本次整理而获得部署验收。不要仅凭分支名启用自动部署。

## 敏感信息

不要提交数据库导出、服务器备份、`.env`、API 密钥、私钥、日志和上传业务文件。完整原始备份保持在仓库外；如果密钥曾经公开，应更换密钥。
