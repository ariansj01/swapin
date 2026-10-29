# Metabase deployment rollback

This procedure preserves the Swaapin PHP application and its MariaDB data. Do not restore the production `swapin` database unless a separate data-corruption incident requires it.

1. Set `BACKUP_DIR` to the verified pre-change backup directory under `/root/swaapin-metabase-preflight/`.
2. Stop Metabase and PostgreSQL with `docker compose -f /opt/swaapin-metabase/docker-compose.yml stop metabase postgres`. Do not remove the PostgreSQL volume.
3. Restore the saved Nginx site config from `$BACKUP_DIR/nginx-active.conf` to `/etc/nginx/sites-available/swaapin.ir`. Keep backups outside `sites-enabled`.
4. Run `nginx -t`. Reload Nginx only if the test succeeds. If the test fails, immediately copy the backup again and investigate without reloading.
5. Confirm `https://swaapin.ir/`, `/auth/login`, `/listings/`, `/blog/`, and `/sitemap.xml` behave as before. Run all four repository SEO and production guard scripts.
6. Revert the Metabase Git commit in the authoritative repository once the live service is restored. The pre-change Git SHA is in `$BACKUP_DIR/git-head.txt`.
7. Optionally remove the dedicated `metabase_ro` account after verifying no other service uses it. No business data or MariaDB bind configuration needs to change for this deployment.

Swap and Docker can remain installed after a route rollback. They are isolated from Swaapin's application routing and MariaDB contents.
