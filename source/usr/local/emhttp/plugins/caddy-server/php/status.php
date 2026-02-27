<?php

$plugin = "caddy-server";
$configDir = "/boot/config/plugins/{$plugin}";
$configFile = "{$configDir}/{$plugin}.cfg";
$caddyfile = "{$configDir}/Caddyfile";
$logFile = "/var/log/caddy.log";
$pidFile = "/var/run/caddy.pid";

// CSRF validation
$vars = parse_ini_file("/var/local/emhttp/var.ini");
$csrfToken = $vars["csrf_token"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $requestToken = $_POST["csrf_token"] ?? $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "";
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

    default:
        http_response_code(400);
        echo json_encode(["error" => "Unknown action"]);
}
