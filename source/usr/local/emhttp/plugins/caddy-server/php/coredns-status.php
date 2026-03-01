<?php

$plugin = "caddy-server";
$configDir = "/boot/config/plugins/{$plugin}";
$configFile = "{$configDir}/{$plugin}.cfg";
$logFile = "/var/log/coredns.log";
$pidFile = "/var/run/coredns.pid";

// CSRF validation
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

        $log = "";
        if (file_exists($logFile)) {
            $log = trim(shell_exec("tail -n 50 " . escapeshellarg($logFile)));
        }

        $cfg = parse_ini_file($configFile) ?: [];

        echo json_encode([
            "running" => $running,
            "pid" => $pid,
            "service" => $cfg["COREDNS_SERVICE"] ?? "disable",
            "dns_zone" => $cfg["DNS_ZONE"] ?? "piercetower.local",
            "dns_ip" => $cfg["DNS_IP"] ?? "",
            "log" => $log,
        ]);
        break;

    case "save_settings":
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            http_response_code(405);
            echo json_encode(["error" => "POST required"]);
            exit;
        }

        $dnsZone = $_POST["dns_zone"] ?? "";
        $dnsIp = $_POST["dns_ip"] ?? "";
        if (empty($dnsZone)) {
            parse_str(file_get_contents("php://input"), $rawPost);
            $dnsZone = $rawPost["dns_zone"] ?? "";
            $dnsIp = $rawPost["dns_ip"] ?? "";
        }

        if (empty($dnsZone)) {
            http_response_code(400);
            echo json_encode(["error" => "DNS zone is required"]);
            exit;
        }

        // Read current config, update DNS keys, write back
        $cfg = file_exists($configFile) ? file_get_contents($configFile) : "";

        // Update or add DNS_ZONE
        if (preg_match('/^DNS_ZONE=/', $cfg, $m, 0)) {
            $cfg = preg_replace('/^DNS_ZONE=.*/m', 'DNS_ZONE="' . addcslashes($dnsZone, '"') . '"', $cfg);
        } else {
            $cfg .= "\nDNS_ZONE=\"" . addcslashes($dnsZone, '"') . "\"";
        }

        // Update or add DNS_IP
        if (preg_match('/^DNS_IP=/', $cfg, $m, 0)) {
            $cfg = preg_replace('/^DNS_IP=.*/m', 'DNS_IP="' . addcslashes($dnsIp, '"') . '"', $cfg);
        } else {
            $cfg .= "\nDNS_IP=\"" . addcslashes($dnsIp, '"') . "\"";
        }

        if (file_put_contents($configFile, $cfg) !== false) {
            echo json_encode(["success" => true]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Failed to write config"]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(["error" => "Unknown action"]);
}
