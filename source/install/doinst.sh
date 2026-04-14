#!/bin/bash

PLUGIN_NAME="caddy-server"
CONFIG_DIR="/boot/config/plugins/${PLUGIN_NAME}"

# Set permissions
chmod 755 /etc/rc.d/rc.caddy
chmod 755 /etc/rc.d/rc.coredns 2>/dev/null
chmod 755 /usr/local/emhttp/plugins/${PLUGIN_NAME}/event/driver_loaded
chmod 755 /usr/local/emhttp/plugins/${PLUGIN_NAME}/scripts/caddy-watchdog 2>/dev/null

# Create persistent config directory
mkdir -p "$CONFIG_DIR"
mkdir -p "${CONFIG_DIR}/override"

# Create default config if missing
if [ ! -f "${CONFIG_DIR}/${PLUGIN_NAME}.cfg" ]; then
  cp /usr/local/emhttp/plugins/${PLUGIN_NAME}/default.cfg "${CONFIG_DIR}/${PLUGIN_NAME}.cfg"
fi

# Create default Caddyfile if missing
if [ ! -f "${CONFIG_DIR}/Caddyfile" ]; then
  cat > "${CONFIG_DIR}/Caddyfile" <<'EOF'
# Caddy configuration file
# See https://caddyserver.com/docs/caddyfile for syntax
#
# Example reverse proxy:
#
# my.domain.com {
#     reverse_proxy localhost:8080
# }
#
# Restrict a site to a specific network interface:
#
# private.example.com {
#     bind 100.64.0.1
#     tls internal {
#         on_demand
#     }
#     reverse_proxy localhost:3000
# }
EOF
fi

# Install watchdog cron entry (idempotent — safe to re-run on every upgrade)
CRONTAB=/var/spool/cron/crontabs/root
WATCHDOG=/usr/local/emhttp/plugins/${PLUGIN_NAME}/scripts/caddy-watchdog
mkdir -p /var/spool/cron/crontabs
touch "$CRONTAB"
if ! grep -q "caddy-watchdog" "$CRONTAB" 2>/dev/null; then
  echo "* * * * * $WATCHDOG >/dev/null 2>&1" >> "$CRONTAB"
fi
killall -HUP crond 2>/dev/null || true
