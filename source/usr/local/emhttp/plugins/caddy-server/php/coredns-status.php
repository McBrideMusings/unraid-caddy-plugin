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

        // Parse DNS_ZONE_MAP into structured array
        $zoneMap = [];
        $zoneMapRaw = $cfg["DNS_ZONE_MAP"] ?? "";
        if (!empty($zoneMapRaw)) {
            foreach (preg_split('/\s+/', trim($zoneMapRaw)) as $entry) {
                if (strpos($entry, '=') !== false) {
                    list($z, $i) = explode('=', $entry, 2);
                    if (!empty($z) && !empty($i)) {
                        $zoneMap[] = ["zone" => $z, "ip" => $i];
                    }
                }
            }
        }

        // Fallback: build zone map from legacy DNS_ZONES + DNS_IP
        if (empty($zoneMap)) {
            $legacyZones = $cfg["DNS_ZONES"] ?? ($cfg["DNS_ZONE"] ?? "");
            $legacyIp = $cfg["DNS_IP"] ?? "";
            if (!empty($legacyZones) && !empty($legacyIp)) {
                foreach (preg_split('/[\s,]+/', trim($legacyZones)) as $z) {
                    if (!empty($z)) {
                        $zoneMap[] = ["zone" => $z, "ip" => $legacyIp];
                    }
                }
            }
        }

        echo json_encode([
            "running" => $running,
            "pid" => $pid,
            "service" => $cfg["COREDNS_SERVICE"] ?? "disable",
            "zone_map" => $zoneMap,
            "dns_bind" => $cfg["DNS_BIND"] ?? "",
            "log" => $log,
        ]);
        break;

    case "save_settings":
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            http_response_code(405);
            echo json_encode(["error" => "POST required"]);
            exit;
        }

        $zonesJson = $_POST["zone_map"] ?? "";
        $dnsBind = $_POST["dns_bind"] ?? "";
        if (empty($zonesJson) && !isset($_POST["zone_map"])) {
            parse_str(file_get_contents("php://input"), $rawPost);
            $zonesJson = $rawPost["zone_map"] ?? "";
            $dnsBind = $rawPost["dns_bind"] ?? "";
        }

        // Parse zone map JSON array: [{"zone":"x","ip":"y"}, ...]
        if ($zonesJson === "" || $zonesJson === null) {
            $pairs = [];
        } else {
            $pairs = json_decode($zonesJson, true);
            if (!is_array($pairs)) {
                http_response_code(400);
                echo json_encode(["error" => "Invalid zone map format"]);
                exit;
            }
        }

        // Validate and build DNS_ZONE_MAP string
        $mapParts = [];
        foreach ($pairs as $pair) {
            $z = trim($pair["zone"] ?? "");
            $i = trim($pair["ip"] ?? "");
            if (empty($z) && empty($i)) continue;
            if (empty($z) || empty($i)) {
                http_response_code(400);
                echo json_encode(["error" => "Each zone must have both a name and IP address"]);
                exit;
            }
            if (!preg_match('/^[a-zA-Z0-9.-]+$/', $z) || strlen($z) > 255) {
                http_response_code(400);
                echo json_encode(["error" => "Invalid zone name: " . htmlspecialchars($z)]);
                exit;
            }
            if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/', $i)) {
                http_response_code(400);
                echo json_encode(["error" => "Invalid IP address for zone " . htmlspecialchars($z) . ": " . htmlspecialchars($i)]);
                exit;
            }
            $mapParts[] = "{$z}={$i}";
        }

        $zoneMapStr = implode(' ', $mapParts);

        // Read current config, update keys, write back
        $cfg = file_exists($configFile) ? file_get_contents($configFile) : "";

        // Update or add DNS_ZONE_MAP
        if (preg_match('/^DNS_ZONE_MAP=/m', $cfg)) {
            $cfg = preg_replace('/^DNS_ZONE_MAP=.*/m', 'DNS_ZONE_MAP="' . addcslashes($zoneMapStr, '"') . '"', $cfg);
        } else {
            $cfg .= "\nDNS_ZONE_MAP=\"" . addcslashes($zoneMapStr, '"') . "\"";
        }

        // Clear legacy fields so they don't conflict
        if (preg_match('/^DNS_ZONES=/m', $cfg)) {
            $cfg = preg_replace('/^DNS_ZONES=.*/m', 'DNS_ZONES=""', $cfg);
        }
        if (preg_match('/^DNS_ZONE=/m', $cfg)) {
            $cfg = preg_replace('/^DNS_ZONE=.*/m', 'DNS_ZONE=""', $cfg);
        }
        if (preg_match('/^DNS_IP=/m', $cfg)) {
            $cfg = preg_replace('/^DNS_IP=.*/m', 'DNS_IP=""', $cfg);
        }

        // Update or add DNS_BIND
        if (preg_match('/^DNS_BIND=/m', $cfg)) {
            $cfg = preg_replace('/^DNS_BIND=.*/m', 'DNS_BIND="' . addcslashes($dnsBind, '"') . '"', $cfg);
        } else {
            $cfg .= "\nDNS_BIND=\"" . addcslashes($dnsBind, '"') . "\"";
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
