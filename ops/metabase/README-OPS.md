# Swaapin Metabase production operations

Public URL: `https://swaapin.ir/meta/base/`. Nginx strips `/meta/base/` before forwarding to Metabase on `127.0.0.1:3000`. The `/meta/base` path redirects permanently to the trailing-slash URL. The route sends `X-Robots-Tag: noindex, nofollow, noarchive`; `robots.txt` disallows it.

## Architecture

- Metabase OSS is pinned to `metabase/metabase:v0.64.3` and runs with host networking so it can reach MariaDB's unchanged `127.0.0.1:3306` bind. `MB_JETTY_HOST=127.0.0.1` prevents public access to port 3000.
- PostgreSQL 17.11 stores Metabase's application state on the persistent `metabase_pg_data` volume. Its port is bound only to `127.0.0.1:5432`.
- Source database `swapin` is accessed by `metabase_ro`@`127.0.0.1`, with only `SELECT ON swapin.*`. Neither WordPress nor application/root credentials are used.
- Metabase has a 3 GiB container limit and 2 GiB Java heap. PostgreSQL has a 768 MiB limit. Docker logs rotate at 10 MiB × 5 files per service.
- A 2 GiB `/swapfile` is persistent in `/etc/fstab` as a safety buffer.
- Container secrets and the admin password are stored under `/opt/swaapin-metabase/secrets` with mode 600, root ownership. The real `.env` is mode 600. Never put these in Git.

## Operations

```sh
cd /opt/swaapin-metabase
docker compose ps
docker compose logs --tail=100 metabase
docker compose logs --tail=100 postgres
curl -fsS http://127.0.0.1:3000/api/health
systemctl status swaapin-metabase-backup.timer
```

The first admin uses `darazedr@gmail.com`. To hand the password to the owner, have them read `/opt/swaapin-metabase/secrets/metabase_admin_password` in their own root SSH session and change it after first login. Do not paste the password into a ticket, chat, command log, or repository. Public sharing is disabled.

## Backups and upgrades

`swaapin-metabase-backup.timer` runs daily at 03:15 Asia/Tehran. `scripts/backup.sh` creates a custom-format `pg_dump`, verifies it with `pg_restore -l`, retains seven daily and four weekly copies, and writes files root-only under `/opt/swaapin-metabase/backups`. `scripts/restore-test.sh` restores to a temporary database, checks that tables exist, and removes the temporary database. A restore test succeeded before handover. Arrange an encrypted off-host copy separately; local copies do not cover total host loss.

Before every Metabase upgrade: run `scripts/backup.sh` and `scripts/restore-test.sh`, verify the official stable release and release notes, change the pinned tag in Git, deploy the Compose file, check migrations and health, then run browser and Swaapin regression checks. Do not use `latest` or beta/RC tags.

## Analytics definitions and limits

`scripts/build-dashboards.py` defines ten dashboards and 69 aggregate questions against actual Production tables. It executes every new or changed query through Metabase before saving. `dashboard-state.json` on the host records IDs for idempotent re-runs and is not a secret; it is not committed.

The primary MariaDB session and system timezone are UTC. Metabase UI/report timezone is Asia/Tehran. Native SQL calendar-day expressions such as `CURDATE()` and `DATE(created_at)` currently use the database's UTC day. Treat daily cutoffs as UTC until timestamp storage semantics and business-day policy are confirmed, then change all calendar-day queries together. No historical event tracking is inferred.

The Executive operational funnel counts distinct people at each recorded stage and labels the ratio to the previous stage as a **population ratio**, not a causal conversion. Observed timestamp pairs support an approximate time-to-next-stage card. The full Visitor → Signup → Listing → Match → Offer View → Offer → Negotiation → Agreement → Successful Swap funnel requires future `user_events` instrumentation. `user_events` does not exist; no events are backfilled. ISO match shown, viewed, clicked and offer-created events are also absent. The AI/Matching dashboard reports only the recorded match rows, scores, distances and AI reviews.

The Engagement dashboard keeps listing conversations (`user_conversations`, `user_messages`), offer-thread `messages`, support `live_chats`, and trade `secure_room_messages` separate. It does not merge their message counts into a fabricated single conversation measure.

`payments.amount` for successful payments is **gross throughput**, not recognized net revenue. Fee/refund totals use actual `wallet_transactions.type` values; a zero can mean that the corresponding type has no records. Category performance attributes offers and completed trades to the offered listing's category; it counts source rows without duplicating listing views across joins.

## Access and privacy

The default All Users group has no self-service query creation on `swapin`. Metabase omits the `create-queries` key in its permissions API when the value is “No”. The default sample database was removed. Administrators remain able to manage reports. Sensitive fields including `password_hash`, `national_id`, identity image paths, bank details, `google_id`, phone, email and store login are marked `sensitive` and `PII` in metadata. Token/OTP/API-key tables are hidden. Only aggregate dashboards are in the shared Swaapin Analytics collection. Do not add non-admin users with broader database/query permissions.

Metabase OSS does not support the Pro/Enterprise View data and Download results permissions. Metadata hiding is a presentation control, not a database security boundary. Therefore do not grant native SQL access or admin membership to general analytics users. For independent self-service analytics on sensitive tables, create sanitized database views under a controlled migration and grant a separate reporting user access only to those views, or license Metabase's column security features. An IP allowlist can be added after the team's egress IPs are known; it is not enforced now.

## Rollback

Follow [ROLLBACK.md](ROLLBACK.md). The preflight archive is in `/root/swaapin-metabase-preflight/`. It contains verified Nginx and `swapin` backups. `scripts/rollback-dry-run.sh <backup-dir>` syntax-tests both the archived and deployed configs without reloading Nginx; it passed before handover. A live rollback was not performed because it would interrupt the new analytics route. Do not place Nginx backups in `sites-enabled`.
