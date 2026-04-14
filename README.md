# Caddy Server for Unraid

A native Unraid plugin that runs [Caddy](https://caddyserver.com) as an OS-level reverse proxy with optional [CoreDNS](https://coredns.io) for local DNS resolution. Both services run outside Docker, so they survive array stops and are available immediately at boot.

## Install

In the Unraid web UI, go to **Plugins → Install Plugin** and paste:

```
https://raw.githubusercontent.com/McBrideMusings/unraid-caddy-plugin/main/caddy-server.plg
```

This installs three packages: Caddy, CoreDNS, and the plugin UI.

## Features

- **Reverse proxy** with automatic HTTPS (public domains via ACME, private domains via built-in CA)
- **Interface binding** — restrict sites to specific network interfaces (Tailscale, WireGuard, LAN)
- **CoreDNS** — optional wildcard DNS for private domains (e.g., `*.myserver.local`)
- **SSL certificate trust** — download Caddy's root CA and per-platform trust instructions
- **Network interfaces panel** — detect available interfaces with copy-to-clipboard for `bind` directives
- **Live log viewer** and **Caddyfile editor** in the Unraid UI
- **Boots before the array** — reverse proxy is available even when Docker containers are still starting
- **Self-healing** — a watchdog cron restarts either service within ~1 minute if it dies, with bounded backoff so a permanently broken service won't loop forever
- **Hot-patch without release** — drop a corrected `rc.caddy` or `rc.coredns` into `/boot/config/plugins/caddy-server/override/` and it's applied on next boot

## Caddy Setup

After installing, go to **Settings → Caddy Server** and enable the service. Edit the Caddyfile in the built-in editor.

### Private sites (Tailscale, WireGuard, LAN)

Use `bind` to restrict access to a specific interface and `tls internal` for Caddy's self-signed CA:

```caddyfile
app.myserver.local {
    bind 100.64.0.1
    tls internal
    reverse_proxy localhost:3000
}
```

Trust Caddy's root CA once per device — the SSL Certificate Trust section on the Caddy Server page has a download link and platform-specific instructions. All current and future domains are trusted automatically.

### Public sites (ACME)

For public domains, Caddy obtains Let's Encrypt certificates automatically:

```caddyfile
blog.example.com {
    reverse_proxy localhost:8080
}
```

### Interface binding

The **Network Interfaces** panel (on the Caddy Server page) detects all IPv4 interfaces on the system and labels them by type. Click "copy bind" to copy `bind <ip>` to your clipboard for pasting into a site block.

This enables network isolation — for example, a site bound to a Tailscale IP is only reachable from your tailnet, even if someone reaches the server via another interface.

## CoreDNS Setup

Go to **Settings → CoreDNS** and configure:

| Setting | Description | Example |
|---------|-------------|---------|
| **Zone Name** | Domain to serve | `myserver.local` |
| **IP Address** | What `*.zone` resolves to | `100.64.0.1` |
| **Bind Address** | Interface to listen on (defaults to IP Address) | `100.64.0.1` |

CoreDNS creates a wildcard zone so that `anything.myserver.local` resolves to the configured IP. Point your DNS client (e.g., Tailscale Split DNS) at this address.

The bind address lets you avoid conflicts with other DNS servers. For example, Unraid's libvirt runs dnsmasq on `192.168.122.1:53` — binding CoreDNS to a Tailscale IP keeps them separate.

## Configuration

All persistent config lives on the USB flash drive at `/boot/config/plugins/caddy-server/`:

| File | Purpose |
|------|---------|
| `caddy-server.cfg` | Service enable/disable state, CoreDNS settings |
| `Caddyfile` | Caddy configuration (editable via UI) |

Config is preserved across plugin updates and removals.

## Architecture

```
┌─────────────────────────────────────────┐
│ Unraid Plugin UI                        │
│  caddy-server.page  ←→  status.php      │
│  coredns.page       ←→  coredns-status  │
└────────────┬────────────────┬───────────┘
             │                │
     ┌───────▼──────┐ ┌──────▼───────┐
     │ rc.caddy     │ │ rc.coredns   │
     │ start/stop/  │ │ start/stop/  │
     │ reload/enable│ │ enable/      │
     └───────┬──────┘ │ gen config   │
             │        └──────┬───────┘
     ┌───────▼──────┐ ┌──────▼───────┐
     │ caddy        │ │ coredns      │
     │ /usr/local/  │ │ /usr/local/  │
     │ bin/caddy    │ │ bin/coredns  │
     └──────────────┘ └──────────────┘
```

Both services run as native processes (not in Docker), managed by rc scripts. The `event/driver_loaded` hook starts them at boot before the array and applies any flash-resident overrides from `/boot/config/plugins/caddy-server/override/`. A `caddy-watchdog` cron runs every minute to restart either service if it dies, with bounded backoff (3 consecutive failures, then suppressed until manually cleared or the service recovers).

## Development

### Quick testing

SCP files directly to the server without a full release:

```bash
scp source/usr/local/emhttp/plugins/caddy-server/caddy-server.page yourserver:/usr/local/emhttp/plugins/caddy-server/caddy-server.page
scp source/usr/local/emhttp/plugins/caddy-server/php/status.php yourserver:/usr/local/emhttp/plugins/caddy-server/php/status.php
```

### Local build

```bash
make all          # Download binaries + build all packages
make checksums    # Show MD5s for updating .plg
```

### Release

Tag-triggered via GitHub Actions:

```bash
git tag v2026.03.03
git push origin v2026.03.03
```

The workflow downloads Caddy and CoreDNS binaries, builds three `.txz` packages, creates a GitHub release, and patches the `.plg` file with updated checksums on main.

## File Structure

```
caddy-server.plg                         # Plugin installer (XML)
Makefile                                  # Local dev builds
.github/workflows/release.yml            # CI/CD pipeline
source/
├── etc/rc.d/
│   ├── rc.caddy                         # Caddy service control
│   └── rc.coredns                       # CoreDNS service control
├── install/
│   ├── doinst.sh                        # Post-install setup (wires watchdog cron)
│   └── slack-desc                       # Package metadata
└── usr/local/emhttp/plugins/caddy-server/
    ├── caddy-server.page                # Caddy UI
    ├── coredns.page                     # CoreDNS UI
    ├── default.cfg                      # Default config template
    ├── event/driver_loaded              # Boot hook (applies flash overrides, starts services)
    ├── scripts/caddy-watchdog           # Per-minute supervisor
    └── php/
        ├── status.php                   # Caddy API
        └── coredns-status.php           # CoreDNS API
```
