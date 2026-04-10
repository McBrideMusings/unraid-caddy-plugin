Deploy changed source files to the Unraid test server and verify the installation.

## Steps

1. Read `$UNRAID_HOST` env var (default: `unraid`) for the target server
2. Use `git diff --name-only HEAD` and `git ls-files --others --exclude-standard` to find changed/new files under `source/`
3. For each changed file in `source/`, SCP it to the corresponding path on the server (e.g. `source/etc/logrotate.d/caddy-plugin` → `/etc/logrotate.d/caddy-plugin`)
4. If no changed files are found under `source/`, deploy ALL files under `source/` instead

## Verification

After deploying, run these checks on the server via SSH:

1. Confirm deployed files exist at their target paths
2. Check if Caddy is running: `pgrep -x caddy`
3. Check plugin log exists or can be created: `touch /var/log/caddy-plugin.log && ls -la /var/log/caddy-plugin.log`
4. Check logrotate config: `cat /etc/logrotate.d/caddy-plugin`
5. Test logrotate config is valid: `logrotate -d /etc/logrotate.d/caddy-plugin 2>&1`

Report results for each check.
