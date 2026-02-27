#!/bin/bash

PLUGIN_NAME="caddy-server"
CONFIG_DIR="/boot/config/plugins/${PLUGIN_NAME}"

# Set permissions
chmod 755 /etc/rc.d/rc.caddy
chmod 755 /usr/local/emhttp/plugins/${PLUGIN_NAME}/event/driver_loaded

# Create persistent config directory
mkdir -p "$CONFIG_DIR"

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
EOF
fi
