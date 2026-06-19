<?php
// backend/public/api.php
require_once 'db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';
$pdo = Database::connect();

try {
    switch ($action) {
        case 'collect':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid method');
            }
            $input = json_decode(file_get_contents('php://input'), true);

            // 获取真实 IP
            $ip = $_SERVER['REMOTE_ADDR'];
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
            } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $ip = $_SERVER['HTTP_X_REAL_IP'];
            }
            $ip = trim($ip);

            // 服务端 IP 定位（如果客户端没有提供）
            $country = $input['country'] ?? '';
            $city = $input['city'] ?? '';
            $isp = $input['isp'] ?? '';

            if (empty($country) && empty($city)) {
                // 尝试服务端获取 IP 定位
                $geoUrl = "http://ip-api.com/json/{$ip}?lang=zh-CN";
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 3,
                        'ignore_errors' => true
                    ]
                ]);
                $geoJson = @file_get_contents($geoUrl, false, $context);
                if ($geoJson) {
                    $geoData = json_decode($geoJson, true);
                    if ($geoData && $geoData['status'] === 'success') {
                        $country = $geoData['country'] ?? '';
                        $city = $geoData['city'] ?? '';
                        $isp = $geoData['isp'] ?? '';
                    }
                }
            }

            // 补全服务端信息
            $data = [
                ':ip' => $ip,
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':country' => $country,
                ':city' => $city,
                ':isp' => $isp,

                ':browser' => $input['browser'] ?? '未知',
                ':browser_version' => $input['browser_version'] ?? '',
                ':os' => $input['os'] ?? '未知',
                ':os_version' => $input['os_version'] ?? '',
                ':device_type' => $input['device_type'] ?? '桌面设备',

                ':screen_width' => $input['screen_width'] ?? 0,
                ':screen_height' => $input['screen_height'] ?? 0,
                ':window_width' => $input['window_width'] ?? 0,
                ':window_height' => $input['window_height'] ?? 0,

                ':language' => $input['language'] ?? '',
                ':timezone' => $input['timezone'] ?? '',
                ':platform' => $input['platform'] ?? '',
                ':cookie_enabled' => isset($input['cookie_enabled']) ? ($input['cookie_enabled'] ? 1 : 0) : 0,

                ':touch_points' => $input['touch_points'] ?? 0,
                ':device_memory' => $input['device_memory'] ?? 0,
                ':cpu_cores' => $input['cpu_cores'] ?? 0,
                ':connection_type' => $input['connection_type'] ?? '',

                ':referrer' => $input['referrer'] ?? '',
                ':remark' => ''
            ];

            $sql = "INSERT INTO visitors (
                ip, user_agent, country, city, isp,
                browser, browser_version, os, os_version, device_type,
                screen_width, screen_height, window_width, window_height,
                language, timezone, platform, cookie_enabled,
                touch_points, device_memory, cpu_cores, connection_type,
                referrer, remark
            ) VALUES (
                :ip, :user_agent, :country, :city, :isp,
                :browser, :browser_version, :os, :os_version, :device_type,
                :screen_width, :screen_height, :window_width, :window_height,
                :language, :timezone, :platform, :cookie_enabled,
                :touch_points, :device_memory, :cpu_cores, :connection_type,
                :referrer, :remark
            )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($data);

            echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId()]);
            break;

        case 'list':
            $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
            $limit = 20;
            $offset = ($page - 1) * $limit;
            $search = $_GET['search'] ?? '';

            $where = "WHERE 1=1";
            $params = [];

            if ($search) {
                $where .= " AND (ip LIKE :search OR remark LIKE :search OR city LIKE :search)";
                $params[':search'] = "%$search%";
            }

            // count
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM visitors $where");
            $countStmt->execute($params);
            $total = $countStmt->fetchColumn();

            // data
            $stmt = $pdo->prepare("SELECT * FROM visitors $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
            $stmt->execute($params);
            $list = $stmt->fetchAll();

            echo json_encode([
                'status' => 'success',
                'data' => $list,
                'total' => $total,
                'page' => $page,
                'pages' => ceil($total / $limit)
            ]);
            break;

        case 'remark':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid method');
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $id = $input['id'] ?? 0;
            $remark = $input['remark'] ?? '';

            if (!$id)
                throw new Exception('ID required');

            $stmt = $pdo->prepare("UPDATE visitors SET remark = :remark WHERE id = :id");
            $stmt->execute([':remark' => $remark, ':id' => $id]);

            echo json_encode(['status' => 'success']);
            break;

        case 'stats':
            // 今日访问统计
            $today = date('Y-m-d');
            $todayStmt = $pdo->prepare("SELECT COUNT(*) FROM visitors WHERE DATE(created_at) = :today");
            $todayStmt->execute([':today' => $today]);
            $todayCount = $todayStmt->fetchColumn();

            // 总访问量
            $totalStmt = $pdo->query("SELECT COUNT(*) FROM visitors");
            $totalCount = $totalStmt->fetchColumn();

            echo json_encode([
                'status' => 'success',
                'total' => $totalCount,
                'today' => $todayCount
            ]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => '未知操作']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
