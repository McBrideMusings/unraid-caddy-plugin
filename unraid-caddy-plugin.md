# Unraid Caddy Plugin — Project Spec

## Problem

Running a reverse proxy for local services on an Unraid server currently requires either:

1. **A Docker container on Unraid** — breaks when the array stops (need to stop array for maintenance, new drives, etc.), meaning you lose access to Unraid's own web UI via your normal domain right when you need it most.
2. **A separate always-on machine** (like PierceBridge running Caddy) — works, but annoying to maintain a whole machine that does almost nothing.
3. **Pangolin/VPS** — works for public services, but routes all traffic through the VPS. Unacceptable for local media streaming, file downloads, etc. due to bandwidth costs and latency.
4. **Tailscale Serve** — gives HTTPS but only supports port-based routing (no subdomains), doesn't scale well to 40+ services, and no config file to manage.

## Solution

An **Unraid plugin** (not a Docker container) that runs Caddy at the OS level. Plugins run independently of the array, so the reverse proxy stays up even when the array is stopped. This eliminates the need for a separate machine while keeping the reverse proxy always available.

## Architecture

### How Unraid Plugins Work

- A `.plg` file (XML) acts as the installer/updater/uninstaller
- It downloads a `.txz` package (Slackware package format) containing the binary and UI files
- Files are extracted to the RAM disk on every boot; config persists on the USB flash drive
- A `.page` file (PHP + INI headers) gets auto-discovered and added to the Unraid Settings menu
- An `rc.d` script handles start/stop/restart of the daemon
- An event script (`driver_loaded`) auto-starts the service at boot, before the array starts

### File Layout

**Persistent (USB flash — survives reboots):**
```
/boot/config/plugins/caddy-server/
  caddy-server.plg          # Installer (managed by Unraid plugin system)
  caddy-server.cfg           # User settings (service enabled, port, etc.)
  Caddyfile                  # The actual Caddy config
```

**Volatile (RAM disk — rebuilt every boot from .txz):**
```
/usr/local/bin/caddy                                        # Caddy binary
/etc/rc.d/rc.caddy                                          # Service control script
/usr/local/emhttp/plugins/caddy-server/
  caddy-server.page          # Main settings UI
  default.cfg                # Default config values
  event/
    driver_loaded            # Auto-start at boot (before array)
  php/
    status.php               # AJAX endpoint for live status
```

### Service Lifecycle

1. **Boot**: Unraid re-installs the `.txz` package, populating the RAM disk. The `driver_loaded` event fires and starts Caddy if enabled in config.
2. **Array stop/start**: No effect on Caddy — it runs at the OS level.
3. **Settings UI**: User can start/stop/restart Caddy, toggle auto-start, and edit the Caddyfile from the Unraid web UI.
4. **Updates**: Unraid's plugin manager polls the `.plg` URL for version changes and handles upgrades automatically.

### Why Caddy

- Single static Go binary, zero dependencies — ideal for Slackware/Unraid
- Automatic HTTPS with built-in ACME support
- Simple Caddyfile syntax
- Plugin ecosystem via build-time modules (Cloudflare DNS, etc.)

## MVP (v1.0)

### Features

- Install Caddy as a native Unraid plugin
- Settings page under Unraid Settings with:
  - Service enable/disable toggle
  - Start / Stop / Restart buttons
  - Service status indicator
  - Caddyfile editor (textarea with the current Caddyfile contents)
  - Log viewer (tail of Caddy's log output)
- Auto-start at boot (before array starts)
- Caddyfile persists on USB flash drive
- Ships vanilla Caddy binary (no custom modules)

### Intended Use Case (MVP)

Reverse proxy for tailnet-internal services using Tailscale Magic DNS for HTTPS certs. The Caddyfile would look something like:

```
unraid.pierceserver.com {
    reverse_proxy http://127.0.0.1:80
    tls /path/to/tailscale/cert.pem /path/to/tailscale/key.pem
}

filezilla.pierceserver.com {
    reverse_proxy http://127.0.0.1:3002
    tls /path/to/tailscale/cert.pem /path/to/tailscale/key.pem
}
```

Combined with split DNS (pointing `*.pierceserver.com` to the Unraid Tailscale IP when on the tailnet), this gives clean subdomain routing with HTTPS for all local services — no VPS bandwidth, no separate machine.

### Technical Details

- **Caddy binary source**: Official release from `https://github.com/caddyserver/caddy/releases` (linux/amd64 static binary)
- **Package format**: `.txz` (Slackware) hosted on GitHub Releases, downloaded by the `.plg` installer
- **Plugin page registration**: `Menu="Settings"`, auto-discovered by Unraid's Dynamix framework
- **Config persistence**: Caddyfile stored at `/boot/config/plugins/caddy-server/Caddyfile`
- **Logging**: Caddy output goes to `/var/log/caddy.log` (RAM disk, non-persistent — fine for a log viewer)

### Reference Plugins

These existing plugins follow the same daemon-management pattern and are good references:

| Plugin | Repo | Relevance |
|--------|------|-----------|
| WireGuard | [bergware/dynamix](https://github.com/bergware/dynamix) | Official Unraid quality, daemon + settings UI |
| SSH | [docgyver/unraid-v6-plugins](https://github.com/docgyver/unraid-v6-plugins) | Full daemon lifecycle, config persistence |
| ProFTPd | [SlrG/unRAID](https://github.com/SlrG/unRAID) | FTP daemon with rc.d + event handlers |
| SNMP | [kubedzero/unraid-snmp](https://github.com/kubedzero/unraid-snmp) | Build process and .txz packaging docs |

## Roadmap

### v1.1 — Caddyfile Validation

- Validate Caddyfile syntax before applying (`caddy validate`)
- Show validation errors in the UI before restarting
- Config backup before applying changes

### v1.2 — Module Picker (Custom Caddy Builds)

Caddy plugins are compiled into the binary at build time. Caddy provides an official download API that returns a custom binary with user-selected modules:

```
GET https://caddyserver.com/api/download?os=linux&arch=amd64&p=github.com/caddy-dns/cloudflare&p=github.com/caddy-dns/route53
```

The plugin UI would:
1. Present a list of popular Caddy modules (Cloudflare DNS, Route53, etc.)
2. Let the user check which ones they want
3. Download a custom Caddy binary via the API with those modules baked in
4. Replace the running binary and restart

This enables use cases like the Cloudflare DNS challenge for public-facing services without needing Docker.

### v1.3 — GUI-Based Reverse Proxy Management

Instead of (or in addition to) editing the Caddyfile directly, provide a form-based UI for managing reverse proxy entries:
- Add/remove/edit proxy entries with domain, upstream, TLS settings
- Generate the Caddyfile from the UI entries
- Still allow raw Caddyfile editing for advanced users

### Future Ideas

- Automatic Tailscale cert integration (detect Tailscale, pull certs automatically)
- Split DNS helper (configure Pi-hole/CoreDNS rules from the plugin)
- Health check dashboard for upstream services
- Integration with Unraid's Docker tab (auto-discover containers and their ports)
