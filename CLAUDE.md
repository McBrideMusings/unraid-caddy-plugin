# Unraid Caddy Plugin

## Architecture

Unraid plugin (.plg) that installs Caddy and CoreDNS as managed services on Unraid.

- `caddy-server.plg` — plugin manifest: declares packages, post-install setup, removal script
- `source/` — files installed on the server (mirrored to `/` on Unraid)
  - `usr/local/emhttp/plugins/caddy-server/` — UI pages (.page), PHP backend, default config
  - `usr/local/emhttp/plugins/caddy-server/event/driver_loaded` — boot hook; syncs flash overrides and starts services
  - `usr/local/emhttp/plugins/caddy-server/scripts/caddy-watchdog` — cron-driven supervisor; restarts dead services with bounded backoff
  - `etc/rc.d/rc.caddy`, `rc.coredns` — service start/stop scripts (`status` exits 0 if running, 1 if not)
  - `etc/logrotate.d/caddy-plugin` — logrotate config for plugin log
- `Makefile` — builds .txz packages for release

## Key paths on Unraid

| Path | Purpose |
|------|---------|
| `/boot/config/plugins/caddy-server/` | Persistent config (survives reboot) |
| `/boot/config/plugins/caddy-server/data/` | Caddy `XDG_DATA_HOME` — root CA, intermediate, leaf certs, ACME state. Persists across reboots so trusted devices stay trusted. |
| `/boot/config/plugins/caddy-server/caddy-root-ca.crt` | Flash-side copy of the current root CA (republished each start) for out-of-band download |
| `/boot/config/plugins/caddy-server/override/` | Drop a replacement `rc.caddy` or `rc.coredns` here to hot-patch without a release — applied by `driver_loaded` on boot |
| `/usr/local/emhttp/plugins/caddy-server/` | Plugin UI and backend |
| `/usr/local/emhttp/plugins/caddy-server/scripts/caddy-watchdog` | Watchdog (runs every minute via cron) |
| `/var/run/caddy-server-watchdog/` | Watchdog failure counters (delete `<svc>.fails` to reset backoff) |
| `/usr/local/bin/caddy` | Caddy binary |
| `/var/log/caddy.log` | Caddy runtime log |
| `/var/log/caddy-plugin.log` | Plugin operations log (boot, watchdog, download, install, restore) |
| `/etc/logrotate.d/caddy-plugin` | Logrotate config for plugin log |

## Logging

- **Caddy runtime log:** `/var/log/caddy.log` — managed by Caddy itself
- **Plugin operations log:** `/var/log/caddy-plugin.log` — written by `driver_loaded` (boot), `caddy-watchdog` (supervision), and `status.php` (download/install/restore). Rotated via logrotate (256k, 2 rotations, compressed). Grep for `RESTART`, `RECOVERED`, `FAILED`, `SUPPRESSED`, `OVERRIDE` to investigate.

## Supervision and self-healing

`driver_loaded` runs once post-boot; it syncs `/boot/config/plugins/caddy-server/override/rc.*` onto `/etc/rc.d/` (letting users hot-patch rc scripts without rebuilding the plugin), then starts both services.

`caddy-watchdog` runs every minute via `/var/spool/cron/crontabs/root`. For each enabled service (`SERVICE`, `COREDNS_SERVICE`), it calls `rc.X status`; if the service is down, it calls `rc.X start` up to `MAX_FAILS=3` consecutive times. After that it stops retrying and logs `SUPPRESSED` at most once an hour. Recovery clears the counter. To manually retry a suppressed service, delete `/var/run/caddy-server-watchdog/<name>.fails`.

The cron entry and override dir are installed by `source/install/doinst.sh`. Removing the plugin strips the cron entry (see `caddy-server.plg` `Method="remove"`).

## Development

### Deploy to test server

Use the `/deploy-test` slash command, which reads `$UNRAID_HOST` for the target server.

### Building packages

```sh
make          # builds all .txz packages into build/
make clean    # removes build artifacts
```

## CoreDNS configuration

Config lives in `/boot/config/plugins/caddy-server/caddy-server.cfg`. Per-zone IP mapping uses `DNS_ZONE_MAP`:

```
DNS_ZONE_MAP="piercetower.local=100.114.249.118 piercetower.lan=100.114.249.118 piercemac.lan=100.94.40.126"
DNS_BIND="100.114.249.118"
```

- Each `zone=ip` pair gives that zone its own A record IP
- `DNS_BIND` is the global listen address (defaults to first zone's IP if empty)
- Legacy `DNS_ZONES` + `DNS_IP` format still works as fallback (single IP for all zones)
- Saving from the UI migrates to the new format automatically

`source/usr/local/emhttp/plugins/caddy-server/php/coredns-status.php` handles CoreDNS API actions via `?action=`:
- `status` — service status, parsed zone map, log tail
- `save_settings` — accepts JSON zone/IP pairs, writes `DNS_ZONE_MAP`

## PHP backend

`source/usr/local/emhttp/plugins/caddy-server/php/status.php` handles all API actions via `?action=`:
- `status` — service status, version, recent log
- `save_caddyfile` / `save_and_reload` — Caddyfile management
- `modules` — list installed/configured modules, staged binary info
- `download_caddy` — download custom Caddy build from caddyserver.com
- `install_caddy` — swap staged binary in, graceful restart, health check, rollback on failure
- `restore_caddy` — restore from backup binary
- `interfaces` — list network interfaces for bind config
