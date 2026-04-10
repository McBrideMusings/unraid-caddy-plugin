# Unraid Caddy Plugin

## Architecture

Unraid plugin (.plg) that installs Caddy and CoreDNS as managed services on Unraid.

- `caddy-server.plg` — plugin manifest: declares packages, post-install setup, removal script
- `source/` — files installed on the server (mirrored to `/` on Unraid)
  - `usr/local/emhttp/plugins/caddy-server/` — UI pages (.page), PHP backend, default config
  - `etc/rc.d/rc.caddy`, `rc.coredns` — service start/stop scripts
  - `etc/logrotate.d/caddy-plugin` — logrotate config for plugin log
- `Makefile` — builds .txz packages for release

## Key paths on Unraid

| Path | Purpose |
|------|---------|
| `/boot/config/plugins/caddy-server/` | Persistent config (survives reboot) |
| `/usr/local/emhttp/plugins/caddy-server/` | Plugin UI and backend |
| `/usr/local/bin/caddy` | Caddy binary |
| `/var/log/caddy.log` | Caddy runtime log |
| `/var/log/caddy-plugin.log` | Plugin operations log (download, install, restore) |
| `/etc/logrotate.d/caddy-plugin` | Logrotate config for plugin log |

## Logging

- **Caddy runtime log:** `/var/log/caddy.log` — managed by Caddy itself
- **Plugin operations log:** `/var/log/caddy-plugin.log` — written by status.php for download/install/restore actions. Rotated via logrotate (256k, 2 rotations, compressed).

## Development

### Deploy to test server

Use the `/deploy-test` slash command, which reads `$UNRAID_HOST` for the target server.

### Building packages

```sh
make          # builds all .txz packages into build/
make clean    # removes build artifacts
```

## PHP backend

`source/usr/local/emhttp/plugins/caddy-server/php/status.php` handles all API actions via `?action=`:
- `status` — service status, version, recent log
- `save_caddyfile` / `save_and_reload` — Caddyfile management
- `modules` — list installed/configured modules, staged binary info
- `download_caddy` — download custom Caddy build from caddyserver.com
- `install_caddy` — swap staged binary in, graceful restart, health check, rollback on failure
- `restore_caddy` — restore from backup binary
- `interfaces` — list network interfaces for bind config
