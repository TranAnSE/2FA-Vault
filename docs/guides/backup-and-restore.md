# Backup and Restore Guide

This guide covers two independent backup layers in 2FA-Vault:

1. **Infrastructure backup** (this guide's main focus) — database dumps and
   volume snapshots performed by the operator. The `docker-compose.prod.yml`
   stack ships a `db-backup` sidecar that automates MySQL dumps.
2. **User-level encrypted backups** (different concern) — each user can push
   encrypted `.vault` exports to their own S3 / WebDAV destination via the
   in-app auto-backup feature. See the user docs for that.

The two are **not** substitutes. The infrastructure backup lets the operator
recover the whole instance; the user-level backup lets an individual user
recover their own vault without the operator.

---

## 1. Database backup (MySQL) — automated sidecar

`docker-compose.prod.yml` defines a `db-backup` service using the
[`databack/mysql-backup`](https://hub.docker.com/r/databack/mysql-backup)
image. It runs on a schedule, dumps the configured database into the shared
`mysql-backup` volume as gzipped SQL, and prunes old dumps automatically.

### Enabling the sidecar

The service is gated behind the `backup` profile so it does not start unless
you ask for it. Start the full stack with backups enabled:

```bash
docker compose -f docker-compose.prod.yml --profile backup up -d
```

Without `--profile backup`, only `app`, `mysql`, and `redis` start.

### Tuning the schedule and retention

All knobs are environment variables (set them in `.env`):

| Variable            | Default | Meaning                                            |
| ------------------- | ------- | -------------------------------------------------- |
| `DB_BACKUP_FREQ`    | `1440`  | Minutes between dumps. `1440` = daily, `360` = 6h. |
| `DB_BACKUP_KEEP`    | `7`     | Number of recent dumps to keep before pruning.     |
| `DB_BACKUP_BEGIN`   | `0300`  | First run time of the day, `HHMM` container-local. |

The sidecar connects as `root` using `DB_ROOT_PASSWORD` and dumps the database
named in `DB_DATABASE`. Both come from the same `.env` used by the stack.

### Listing and inspecting dumps

Dumps land in the `mysql-backup` named volume, mounted at `/backup` inside
both `mysql` and `db-backup`. To list them:

```bash
docker compose -f docker-compose.prod.yml exec mysql ls -lh /backup
```

To inspect the contents of a dump without restoring it:

```bash
docker compose -f docker-compose.prod.yml exec mysql \
  zcat /backup/db_backup_2026-07-08T030003Z_2fa_vault.sql.gz | head -n 50
```

### Restoring from a dump

1. Stop the app so no writes race the restore:

   ```bash
   docker compose -f docker-compose.prod.yml stop app
   ```

2. Restore the dump into MySQL (replace the filename):

   ```bash
   docker compose -f docker-compose.prod.yml exec -T mysql \
     sh -c 'zcat /backup/db_backup_2026-07-08T030003Z_2fa_vault.sql.gz | \
            mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
   ```

3. Restart the app:

   ```bash
   docker compose -f docker-compose.prod.yml start app
   ```

---

## 2. App data backup (the `/2fauth` volume)

The `app` container persists state in the `2fa-vault-data` volume, mounted at
`/2fauth` (lowercase — matches `docker/entrypoint.sh`). Depending on your
config this holds:

- The SQLite database file (`database.sqlite`) when `DB_CONNECTION=sqlite`
- `storage/` (logs, framework cache, uploaded icons, Passport keys, app icons)
- The `installed` marker tracking the image commit

### Snapshotting the volume

Back up the whole volume to a tarball on the host:

```bash
docker run --rm \
  -v 2fa-vault_2fa-vault-data:/data:ro \
  -v "$(pwd):/backup" \
  alpine \
  tar czf /backup/2fa-vault-data-$(date +%Y%m%d).tar.gz -C /data .
```

> Volume names are prefixed with the compose project name (the directory name
> by default). Confirm with `docker volume ls | grep 2fa-vault-data`.

### Restoring the volume

```bash
docker compose -f docker-compose.prod.yml stop app
docker volume rm 2fa-vault_2fa-vault-data  # only if replacing wholesale
docker volume create 2fa-vault_2fa-vault-data
docker run --rm \
  -v 2fa-vault_2fa-vault-data:/data \
  -v "$(pwd):/backup" \
  alpine \
  tar xzf /backup/2fa-vault-data-20260708.tar.gz -C /data
docker compose -f docker-compose.prod.yml start app
```

---

## 3. Offsite replication

Both backup targets (the `/backup` dumps and the `/2fauth` tarballs) live on
the Docker host. For disaster recovery, push them offsite. Common options:

- **rclone** to S3 / Backblaze B2 / Wasabi on a host cron:

  ```bash
  0 4 * * * docker run --rm -v 2fa-vault_mysql-backup:/data:ro \
      -v ~/.config/rclone:/config/rclone rclone/rclone \
      copy /data my-b2:2fa-vault-backups/ --backup-dir archive/$(date +\%F)
  ```

- **Cloudflare R2** via the AWS CLI (`--endpoint-url`).
- **Borg / restic** to an SSH target if you already run host-level backups.

Keep at least one offsite copy outside the host's failure domain.

---

## 4. User-level encrypted backups (in-app)

Distinct from the operator backups above: each user can configure their own
encrypted `.vault` destination (S3 or WebDAV) in **Settings → Auto-Backup**.
Those backups are encrypted with the user's master key on the client before
they ever leave the browser, and the server only relays the encrypted blob.

Driver scheduling is handled by `app/Jobs/AutoBackupJob`, dispatched every
minute by `app/Console/Commands/RunAutoBackupsCommand` (the `backup:auto`
schedule in `app/Console/Kernel.php`). This is purely a user feature and does
not need operator setup.

### WebDAV note (PHP 8.4)

The WebDAV destination uses `league/flysystem-webdav 3.x`, which depends on
`sabre/dav 4.x`. On PHP 8.4, sabre's *server-side* classes emit
`E_DEPRECATED` notices about implicitly nullable parameter types. 2FA-Vault
only uses `\Sabre\DAV\Client` (the client), so the deprecated code paths are
never loaded and the warnings do not surface in practice. The upstream fix
(sabre/dav 5.x) is blocked until `league/flysystem-webdav` loosens its
`sabre/dav ^4.6.0` constraint. Safe to ignore.

---

## 5. Restore testing

A backup you have never restored is a hope, not a backup. Periodically:

1. Spin up a parallel stack on a throwaway host (or `docker compose -p
   restoretest -f docker-compose.prod.yml up -d` with a throwaway `.env`).
2. Restore the latest DB dump and volume tarball.
3. Run `php artisan migrate:status` inside the app container to confirm schema
   integrity, and log in as an admin to spot-check accounts and teams.

Document the restore runbook in your runbook wiki with timestamps of the last
successful restore test.
