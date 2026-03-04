<?php

$plugin = "caddy-server";
$configDir = "/boot/config/plugins/{$plugin}";
$configFile = "{$configDir}/{$plugin}.cfg";
$caddyfile = "{$configDir}/Caddyfile";
$logFile = "/var/log/caddy.log";
$pidFile = "/var/run/caddy.pid";

// CSRF validation — Unraid strips csrf_token from $_POST, so parse raw body
$vars = parse_ini_file("/var/local/emhttp/var.ini");
$csrfToken = $vars["csrf_token"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $requestToken = "";
    if (!empty($_POST["csrf_token"])) {
        $requestToken = $_POST["csrf_token"];
    } else {
        parse_str(file_get_contents("php://input"), $rawPost);
        $requestToken = $rawPost["csrf_token"] ?? "";
    }
    if (empty($requestToken)) {
        $requestToken = $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "";
    }

    if ($requestToken !== $csrfToken) {
        http_response_code(403);
        echo json_encode(["error" => "Invalid CSRF token"]);
        exit;
    }
}

$action = $_GET["action"] ?? "status";

header("Content-Type: application/json");

switch ($action) {
    case "status":
        $running = false;
        $pid = null;
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if ($pid && file_exists("/proc/{$pid}")) {
                $running = true;
            }
        }

        $version = trim(shell_exec("/usr/local/bin/caddy version 2>/dev/null") ?: "unknown");

        $log = "";
        if (file_exists($logFile)) {
            $log = trim(shell_exec("tail -n 50 " . escapeshellarg($logFile)));
        }

        $cfg = parse_ini_file($configFile) ?: [];
        $service = $cfg["SERVICE"] ?? "disable";

        echo json_encode([
            "running" => $running,
            "pid" => $pid,
            "version" => $version,
            "service" => $service,
            "log" => $log,
        ]);
        break;

    case "save_caddyfile":
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            http_response_code(405);
            echo json_encode(["error" => "POST required"]);
            exit;
        }

        $content = $_POST["content"] ?? "";
        if (empty($content)) {
            parse_str(file_get_contents("php://input"), $rawPost);
            $content = $rawPost["content"] ?? "";
        }
        if (file_put_contents($caddyfile, $content) !== false) {
            echo json_encode(["success" => true]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Failed to write Caddyfile"]);
        }
        break;

    case "save_and_reload":
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            http_response_code(405);
            echo json_encode(["error" => "POST required"]);
            exit;
        }

        $content = $_POST["content"] ?? "";
        if (empty($content)) {
            parse_str(file_get_contents("php://input"), $rawPost);
            $content = $rawPost["content"] ?? "";
        }
        if (file_put_contents($caddyfile, $content) === false) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to write Caddyfile"]);
            exit;
        }

        $output = shell_exec("/usr/local/bin/caddy reload --config " . escapeshellarg($caddyfile) . " 2>&1");
        $running = false;
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if ($pid && file_exists("/proc/{$pid}")) {
                $running = true;
            }
        }

        echo json_encode([
            "success" => $running,
            "output" => trim($output),
        ]);
        break;

    case "modules":
        $version = trim(shell_exec("/usr/local/bin/caddy version 2>/dev/null") ?: "unknown");

        $installed = [];
        $rawModules = shell_exec("/usr/local/bin/caddy list-modules --packages 2>/dev/null") ?: "";
        foreach (explode("\n", trim($rawModules)) as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            // Lines with a package path in parentheses are non-standard if not from caddyserver/caddy
            if (preg_match('/^(\S+)\s+\((.+)\)$/', $line, $m)) {
                $pkg = $m[2];
                if (strpos($pkg, "github.com/caddyserver/caddy") !== 0) {
                    $installed[] = ["module" => $m[1], "package" => $pkg];
                }
            }
        }

        $cfg = parse_ini_file($configFile) ?: [];
        $configured = array_filter(explode("|", $cfg["CADDY_MODULES"] ?? ""));

        // Check if a staged binary is waiting to be installed
        $stagedBin = "/tmp/caddy_custom";
        $stagedReady = file_exists($stagedBin) && filesize($stagedBin) > 0;
        $stagedVersion = "";
        $stagedModules = [];
        if ($stagedReady) {
            $stagedVersion = trim(shell_exec(escapeshellarg($stagedBin) . " version 2>/dev/null") ?: "");
            $stagedRaw = shell_exec(escapeshellarg($stagedBin) . " list-modules --packages 2>/dev/null") ?: "";
            foreach (explode("\n", trim($stagedRaw)) as $sline) {
                $sline = trim($sline);
                if (empty($sline)) continue;
                if (preg_match('/^(\S+)\s+(\S+)$/', $sline, $sm)) {
                    if ($sm[2] !== "github.com/caddyserver/caddy" && strpos($sm[2], "github.com/caddyserver/caddy/") !== 0) {
                        $stagedModules[] = ["module" => $sm[1], "package" => $sm[2]];
                    }
                }
            }
        }

        $hasBackup = file_exists("/usr/local/bin/caddy.bak");

        echo json_encode([
            "installed_modules" => $installed,
            "configured_modules" => array_values($configured),
            "caddy_version" => $version,
            "staged_ready" => $stagedReady,
            "staged_version" => $stagedVersion,
            "staged_modules" => $stagedModules,
            "has_backup" => $hasBackup,
        ]);
        break;

    case "rebuild_progress":
        $progressFile = "/tmp/caddy_rebuild_progress";
        if (file_exists($progressFile)) {
            echo file_get_contents($progressFile);
        } else {
            echo json_encode(["step" => "", "progress" => 0]);
        }
        break;

    case "download_caddy":
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            http_response_code(405);
            echo json_encode(["error" => "POST required"]);
            exit;
        }

        $progressFile = "/tmp/caddy_rebuild_progress";
        $writeProgress = function($step, $pct) use ($progressFile) {
            file_put_contents($progressFile, json_encode(["step" => $step, "progress" => $pct]));
        };

        $writeProgress("Validating module paths...", 5);

        $body = $_POST["modules"] ?? "";
        if (empty($body)) {
            parse_str(file_get_contents("php://input"), $rawPost);
            $body = $rawPost["modules"] ?? "";
        }

        $modules = array_filter(array_map("trim", explode("\n", $body)));

        foreach ($modules as $mod) {
            if (!preg_match('#^[a-zA-Z0-9._-]+(/[a-zA-Z0-9._@-]+)+$#', $mod)) {
                @unlink($progressFile);
                http_response_code(400);
                echo json_encode(["error" => "Invalid module path: {$mod}"]);
                exit;
            }
        }

        $writeProgress("Detecting Caddy version...", 10);
        $versionRaw = trim(shell_exec("/usr/local/bin/caddy version 2>/dev/null") ?: "");
        $version = preg_match('/^v?[\d.]+/', $versionRaw, $vm) ? $vm[0] : "";
        if (empty($version)) {
            @unlink($progressFile);
            http_response_code(500);
            echo json_encode(["error" => "Could not determine Caddy version"]);
            exit;
        }

        $url = "https://caddyserver.com/api/download?os=linux&arch=amd64&version={$version}";
        foreach ($modules as $mod) {
            $url .= "&p=" . urlencode($mod);
        }

        $writeProgress("Building and downloading custom binary...", 15);
        $tmpBin = "/tmp/caddy_custom";
        @unlink($tmpBin);
        $curlCmd = "curl -s -L --max-time 120 -w '%{http_code}' -o " . escapeshellarg($tmpBin) . " " . escapeshellarg($url) . " 2>&1";
        $curlOutput = shell_exec($curlCmd);
        $httpCode = intval(substr(trim($curlOutput), -3));

        if ($httpCode !== 200 || !file_exists($tmpBin) || filesize($tmpBin) === 0) {
            $errDetail = "";
            if (file_exists($tmpBin) && filesize($tmpBin) > 0 && filesize($tmpBin) < 4096) {
                $errBody = json_decode(file_get_contents($tmpBin), true);
                $errDetail = $errBody["error"]["message"] ?? "";
            }
            @unlink($tmpBin);
            @unlink($progressFile);
            if (empty($errDetail)) {
                $errDetail = "HTTP {$httpCode} — check that module paths are registered at caddyserver.com/download";
            }
            http_response_code(502);
            echo json_encode(["error" => "Download failed", "detail" => $errDetail]);
            exit;
        }

        $writeProgress("Validating downloaded binary...", 85);
        chmod($tmpBin, 0755);
        $testVersion = trim(shell_exec(escapeshellarg($tmpBin) . " version 2>&1"));
        if (empty($testVersion) || strpos($testVersion, "v") !== 0) {
            @unlink($tmpBin);
            @unlink($progressFile);
            http_response_code(502);
            echo json_encode(["error" => "Downloaded binary failed validation", "detail" => $testVersion]);
            exit;
        }

        // Save module list to config now (so it persists even if they don't install immediately)
        $cfg = parse_ini_file($configFile) ?: [];
        $cfg["CADDY_MODULES"] = implode("|", $modules);
        $cfgLines = [];
        foreach ($cfg as $k => $v) {
            $cfgLines[] = "{$k}=\"{$v}\"";
        }
        file_put_contents($configFile, implode("\n", $cfgLines) . "\n");

        @unlink($progressFile);
        echo json_encode([
            "success" => true,
            "staged_version" => $testVersion,
            "modules" => $modules,
        ]);
        break;

    case "install_caddy":
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            http_response_code(405);
            echo json_encode(["error" => "POST required"]);
            exit;
        }

        $tmpBin = "/tmp/caddy_custom";
        if (!file_exists($tmpBin) || filesize($tmpBin) === 0) {
            http_response_code(404);
            echo json_encode(["error" => "No staged binary found — download one first"]);
            exit;
        }

        // Stop Caddy if running
        $wasRunning = false;
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if ($pid && file_exists("/proc/{$pid}")) {
                $wasRunning = true;
                shell_exec("/etc/rc.d/rc.caddy stop 2>&1");
                sleep(1);
            }
        }

        // Backup and swap
        $caddyBin = "/usr/local/bin/caddy";
        copy($caddyBin, "{$caddyBin}.bak");
        rename($tmpBin, $caddyBin);
        chmod($caddyBin, 0755);

        // Restart if was running
        $startOutput = "";
        if ($wasRunning) {
            $startOutput = trim(shell_exec("/etc/rc.d/rc.caddy start 2>&1"));
        }

        $newVersion = trim(shell_exec("{$caddyBin} version 2>/dev/null") ?: "unknown");

        echo json_encode([
            "success" => true,
            "version" => $newVersion,
            "restarted" => $wasRunning,
            "start_output" => $startOutput,
        ]);
        break;

    case "delete_staged":
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            http_response_code(405);
            echo json_encode(["error" => "POST required"]);
            exit;
        }

        $tmpBin = "/tmp/caddy_custom";
        @unlink($tmpBin);
        echo json_encode(["success" => true]);
        break;

    case "restore_caddy":
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            http_response_code(405);
            echo json_encode(["error" => "POST required"]);
            exit;
        }

        $caddyBin = "/usr/local/bin/caddy";
        $bakFile = "{$caddyBin}.bak";

        if (!file_exists($bakFile)) {
            http_response_code(404);
            echo json_encode(["error" => "No backup binary found"]);
            exit;
        }

        // Check if Caddy is running
        $wasRunning = false;
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if ($pid && file_exists("/proc/{$pid}")) {
                $wasRunning = true;
                shell_exec("/etc/rc.d/rc.caddy stop 2>&1");
                sleep(1);
            }
        }

        // Restore backup
        rename($bakFile, $caddyBin);
        chmod($caddyBin, 0755);

        // Clear CADDY_MODULES in config
        $cfg = parse_ini_file($configFile) ?: [];
        $cfg["CADDY_MODULES"] = "";
        $cfgLines = [];
        foreach ($cfg as $k => $v) {
            $cfgLines[] = "{$k}=\"{$v}\"";
        }
        file_put_contents($configFile, implode("\n", $cfgLines) . "\n");

        // Restart if was running
        $startOutput = "";
        if ($wasRunning) {
            $startOutput = trim(shell_exec("/etc/rc.d/rc.caddy start 2>&1"));
        }

        $newVersion = trim(shell_exec("{$caddyBin} version 2>/dev/null") ?: "unknown");

        echo json_encode([
            "success" => true,
            "version" => $newVersion,
            "restarted" => $wasRunning,
            "start_output" => $startOutput,
        ]);
        break;

    case "interfaces":
        $interfaces = [["interface" => "", "ip" => "0.0.0.0", "label" => "All Interfaces"]];

        $labels = [
            "tailscale0" => "Tailscale",
            "eth0" => "LAN",
            "br0" => "LAN",
            "bond0" => "LAN",
            "docker0" => "Docker",
            "virbr0" => "VM Bridge",
        ];
        $prefixLabels = [
            "wg" => "WireGuard",
            "veth" => "Docker",
        ];

        $output = shell_exec("ip -o -4 addr show 2>/dev/null") ?: "";
        $hasTailscale = false;
        foreach (explode("\n", trim($output)) as $line) {
            if (empty($line)) continue;
            if (!preg_match('/^\d+:\s+(\S+)\s+inet\s+([0-9.]+)/', $line, $m)) continue;
            $iface = $m[1];
            $ip = $m[2];
            if ($iface === "lo") continue;

            $label = "Other";
            if (isset($labels[$iface])) {
                $label = $labels[$iface];
            } else {
                foreach ($prefixLabels as $prefix => $plabel) {
                    if (strpos($iface, $prefix) === 0) {
                        $label = $plabel;
                        break;
                    }
                }
            }
            if ($iface === "tailscale0") $hasTailscale = true;
            $interfaces[] = ["interface" => $iface, "ip" => $ip, "label" => $label];
        }

        // Userspace Tailscale — no tailscale0 interface, try CLI
        if (!$hasTailscale) {
            $tsIp = trim(shell_exec("tailscale ip -4 2>/dev/null") ?: "");
            if (preg_match('/^[0-9.]+$/', $tsIp)) {
                $interfaces[] = ["interface" => "tailscale", "ip" => $tsIp, "label" => "Tailscale"];
            }
        }

        echo json_encode(["interfaces" => $interfaces]);
        break;

    default:
        http_response_code(400);
        echo json_encode(["error" => "Unknown action"]);
}
