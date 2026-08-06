# External Content Synchronization

This integration publishes:

- every XMax Inc. SEC filing, linking the website entry directly to the official SEC filing page;
- XMax Inc. GlobeNewswire releases as website articles containing the complete release body.

The sync is idempotent. Each source item has a unique external ID, so rerunning a command does not create duplicate articles. Existing articles that were manually edited, unpublished, or deleted are protected from automatic replacement.

## Prerequisites

- PHP 7.4 or newer with `curl`, `dom`, `libxml`, `mbstring`, and `pdo_mysql` extensions;
- database credentials already configured for ThinkCMF;
- outbound HTTPS access to `data.sec.gov`, `www.sec.gov`, `www.globenewswire.com`, and `ml.globenewswire.com`.

Before deploying, take a database backup. Then run the portal migration from the project root:

```bash
php think migrate:run -a portal
```

The migration creates the prefixed `portal_external_content` table and expands `portal_post.post_content` to `MEDIUMTEXT`.

## Configuration

Defaults are in `data/config/external_content.php`. The existing site category IDs are used by default:

- SEC parent category: `3`; a child category is created automatically for each filing year;
- GlobeNewswire news category: `2`.

The values can be overridden in `.env`:

```dotenv
EXTERNAL_CONTENT_AUTHOR_ID=1
EXTERNAL_CONTENT_SEC_CATEGORY_PARENT_ID=3
EXTERNAL_CONTENT_GLOBE_CATEGORY_ID=2
EXTERNAL_CONTENT_SEC_FROM_YEAR=1994
EXTERNAL_CONTENT_USER_AGENT="XMax Inc. Website Sync XMaxIR@icrinc.com"
```

The SEC requires a descriptive User-Agent with organization and contact information. Replace the default contact if another monitored address should be used.

## Commands

Run both sources:

```bash
php think portal:sync-external
```

Run one source:

```bash
php think portal:sync-external --source=sec
php think portal:sync-external --source=globenewswire
```

The normal SEC run imports every form type available from the SEC submissions API. To force a supplemental-file backfill as well (for filings older than the recent submissions window):

```bash
php think portal:sync-external --source=sec --backfill-all
```

For a controlled first production check, limit each source to the newest three records:

```bash
php think portal:sync-external --limit=3
```

The limit is only for the current execution. A later unrestricted run imports the remaining records.

## Cron

Use the absolute PHP and project paths on the production server. A practical schedule is:

```cron
*/15 * * * * cd /var/www/xmax.com && /usr/bin/php think portal:sync-external --source=sec >> data/runtime/log/external-content-sec.log 2>&1
7,37 * * * * cd /var/www/xmax.com && /usr/bin/php think portal:sync-external --source=globenewswire >> data/runtime/log/external-content-globe.log 2>&1
```

Each source has a non-blocking file lock, so overlapping runs exit instead of publishing concurrently. A nonzero command exit status means at least one item or source failed. Item-level status and the latest error are stored in `portal_external_content`.

After the portal admin menu is refreshed, the status page is available under Portal > External Content Sync. It is read-only; retries should be performed through the CLI so cron and manual runs share the same lock and logging behavior.

## Operational Notes

- SEC content is not copied; `post_source` is the official filing detail URL used by the current SEC list template.
- GlobeNewswire article HTML is extracted from the server-rendered article body and sanitized before storage. Scripts, forms, iframes, styles, and unsafe URL schemes are removed.
- GlobeNewswire images remain hosted by GlobeNewswire in this version. The website template constrains them responsively.
- If an administrator edits a synchronized article, a later upstream content change is recorded as `manual` and will not overwrite it.
- If an administrator unpublishes or deletes a synchronized article, the sync records `protected` and will not republish it.
