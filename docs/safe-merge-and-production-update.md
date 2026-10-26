# Merge your changes — Git Bash

This merges the whole `perf/instant-mail` branch into `main`: **Reference No, performance changes, and database migrations**.

## 1. Merge on GitHub

Open [the comparison page](https://github.com/okirordan/Appointment-Tracking/compare/main...perf/instant-mail).

1. Confirm **base: main** and **compare: perf/instant-mail**.
2. Click **Create pull request**. Review the changes and any checks.
3. When ready to deploy, click **Merge pull request**, then **Confirm merge**. Stop if GitHub reports conflicts or failed checks.

**Project notes say merging into main triggers automatic deployment.** If enabled, let that finish, then check the Reference No field. Do not run a competing manual deployment.

## 2. If you deploy manually

Open Git Bash in your production application folder:

```bash
git status --short
git branch --show-current
git remote -v
```

Continue only if the working tree is clean, the branch is `main`, and the remote is your Appointment-Tracking repository. Otherwise stop; do not discard production changes.

Back up the production database and current release using your normal process. Save the current revision for recovery:

```bash
git rev-parse HEAD
```

During your maintenance window, run these **one at a time**. Stop if any fails:

```bash
php artisan down
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan migrate:status
```

Review pending migrations. This branch adds database indexes, which may take time. Keep `ATS_INDEXED_MAIL_SEARCH=false` in production `.env` for this release. Then:

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan queue:restart
php artisan up
```

If your hosting uses prebuilt assets, use its normal build pipeline instead of running npm on production. Refresh PHP processes using your hosting procedure if required for OPcache.

## 3. Verify

Open **New Mail**, record a reference, and confirm it appears in the incoming list and mail details.

If deployment fails, keep the site in maintenance mode while investigating or restoring the previous release. Do not force-pull, discard production changes, or run a blanket database rollback.
