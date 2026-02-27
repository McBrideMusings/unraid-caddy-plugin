PLUGIN_NAME  := caddy-server
CADDY_VER    := 2.9.1
PLUGIN_VER   := 2026.02.26
ARCH         := x86_64

CADDY_PKG    := caddy-$(CADDY_VER)-$(ARCH)-1.txz
PLUGIN_PKG   := $(PLUGIN_NAME)-$(PLUGIN_VER)-$(ARCH)-1.txz

BUILD_DIR    := build
CADDY_STAGE  := $(BUILD_DIR)/caddy-staging
PLUGIN_STAGE := $(BUILD_DIR)/plugin-staging
PACKAGES_DIR := packages

CADDY_URL    := https://github.com/caddyserver/caddy/releases/download/v$(CADDY_VER)/caddy_$(CADDY_VER)_linux_amd64.tar.gz

.PHONY: all clean download-caddy package-caddy package-plugin checksums

all: package-caddy package-plugin checksums

download-caddy:
	@echo "Downloading Caddy $(CADDY_VER)..."
	mkdir -p $(BUILD_DIR)
	curl -L -o $(BUILD_DIR)/caddy.tar.gz $(CADDY_URL)
	mkdir -p $(CADDY_STAGE)/usr/local/bin
	tar -xzf $(BUILD_DIR)/caddy.tar.gz -C $(BUILD_DIR) caddy
	mv $(BUILD_DIR)/caddy $(CADDY_STAGE)/usr/local/bin/caddy
	chmod 755 $(CADDY_STAGE)/usr/local/bin/caddy

package-caddy: download-caddy
	@echo "Packaging Caddy binary..."
	mkdir -p $(PACKAGES_DIR)
	cd $(CADDY_STAGE) && makepkg -l y -c n ../$(CADDY_PKG)
	mv $(BUILD_DIR)/$(CADDY_PKG) $(PACKAGES_DIR)/

package-plugin:
	@echo "Packaging plugin..."
	mkdir -p $(PLUGIN_STAGE) $(PACKAGES_DIR)
	cp -a source/* $(PLUGIN_STAGE)/
	cd $(PLUGIN_STAGE) && makepkg -l y -c n ../$(PLUGIN_PKG)
	mv $(BUILD_DIR)/$(PLUGIN_PKG) $(PACKAGES_DIR)/

checksums: $(PACKAGES_DIR)/$(CADDY_PKG) $(PACKAGES_DIR)/$(PLUGIN_PKG)
	@echo "Generating checksums..."
	@echo "Caddy:  $$(md5sum $(PACKAGES_DIR)/$(CADDY_PKG) | cut -d' ' -f1)"
	@echo "Plugin: $$(md5sum $(PACKAGES_DIR)/$(PLUGIN_PKG) | cut -d' ' -f1)"
	@echo ""
	@echo "Update caddy-server.plg with these MD5 values."

clean:
	rm -rf $(BUILD_DIR)
	rm -rf $(PACKAGES_DIR)
