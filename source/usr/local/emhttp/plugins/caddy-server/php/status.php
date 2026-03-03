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
