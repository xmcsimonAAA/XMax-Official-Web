# XMax Official Website

The official XMax website, built on ThinkCMF 6 and ThinkPHP 6.

## Requirements

- PHP 7.4 or newer
- MySQL 5.7 or newer
- Composer
- PHP extensions: `curl`, `dom`, `json`, `libxml`, `mbstring`, `pdo_mysql`
- URL rewriting enabled, with the web root pointed to `public/`

## Local Configuration

Install PHP dependencies and create a local environment file:

```bash
composer install
cp .env.example .env
```

Fill in the database credentials, application auth code, Twelve Data keys, and the monitored SEC contact address in `.env`. Never commit `.env` or real credentials.

Uploaded media, runtime data, logs, installed-state files, and dependencies are intentionally excluded from Git. Pulling an update onto an existing server will not remove those ignored files. A new server must restore `public/upload/` from the media backup and install dependencies with Composer.

## Database

Run all migrations after installing or pulling a release that contains new migrations:

```bash
php think migrate:run
```

## SEC And GlobeNewswire Sync

The website can automatically publish every XMax SEC filing and copy complete XMax Inc. GlobeNewswire releases into the news section. See [EXTERNAL_CONTENT_SYNC.md](EXTERNAL_CONTENT_SYNC.md) for configuration, initial synchronization, cron schedules, and operational behavior.

## Deployment Workflow

On the server, preserve `.env`, `public/upload/`, and `data/runtime/`, then run:

```bash
git pull --ff-only
composer install --no-dev --optimize-autoloader
php think migrate:run
```

Clear the ThinkCMF cache from the admin panel after template or menu changes.

## Security

This repository is public. Do not commit database exports, `.env`, API keys, private certificates, logs, runtime caches, or uploaded business files. If a secret is committed accidentally, rotate it immediately; deleting it in a later commit is not sufficient.
