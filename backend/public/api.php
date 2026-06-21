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

function matchUrlPattern($url, $pattern) {
    if ($pattern === '*') return true;
    $patterns = array_map('trim', explode(',', $pattern));
    $url = strtolower($url);
    foreach ($patterns as $p) {
        $p = strtolower(trim($p));
        if ($p === '') continue;
        $regex = '#^' . str_replace('\*', '.*', preg_quote($p, '#')) . '$#i';
        if (preg_match($regex, $url)) return true;
        if (strpos($url, trim($p, '*')) !== false && $p !== '*') return true;
    }
    return false;
}

function getMetaValue($pdo, $key, $default = null) {
    $stmt = $pdo->prepare("SELECT meta_value FROM sampling_metadata WHERE meta_key = :key");
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();
    return $row ? $row['meta_value'] : $default;
}

function computeSampleDecision($pdo, $pageUrl, $isAuthenticated = false, $currentErrorRate = 0) {
    $stmt = $pdo->query("SELECT * FROM sampling_config WHERE enabled = 1 ORDER BY priority DESC, id ASC");
    $rules = $stmt->fetchAll();
    $matchedRule = null;

    foreach ($rules as $rule) {
        if (matchUrlPattern($pageUrl, $rule['url_pattern'])) {
            $matchedRule = $rule;
            break;
        }
    }

    if (!$matchedRule) {
        $matchedRule = [
            'id' => 0,
            'rule_name' => '默认规则',
            'page_value' => 50,
            'error_rate_threshold' => 5,
            'auth_ratio' => 0,
            'base_sample_rate' => 50,
            'light_sample_rate' => 50,
            'min_sample_rate' => 10,
            'priority' => 0,
            'description' => '未匹配任何规则时的兜底策略'
        ];
    }

    $pageValue = (float)$matchedRule['page_value'];
    $errorThreshold = (float)$matchedRule['error_rate_threshold'];
    $authRatioCfg = (float)$matchedRule['auth_ratio'];
    $baseFullRate = (float)$matchedRule['base_sample_rate'];
    $baseLightRate = (float)($matchedRule['light_sample_rate'] ?? max($baseFullRate * 1.5, 40));
    $minRate = (float)$matchedRule['min_sample_rate'];

    $baseLightRate = max($baseLightRate, $baseFullRate);

    $hourStart = date('Y-m-d H:00:00');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visitors WHERE created_at >= :start");
    $stmt->execute([':start' => $hourStart]);
    $fullSamplesHour = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM lightweight_metrics WHERE created_at >= :start");
    $stmt->execute([':start' => $hourStart]);
    $lightSamplesHour = (int)$stmt->fetchColumn();

    $budgetPerHour = (float)getMetaValue($pdo, 'global_budget_per_hour', 50000);
    $spikeThreshold = (float)getMetaValue($pdo, 'traffic_spike_threshold', 150);
    $emergencyRate = (float)getMetaValue($pdo, 'emergency_sample_rate', 10);

    $totalHour = $fullSamplesHour + $lightSamplesHour + 1;
    $basePerHour = $budgetPerHour * 0.6;
    $trafficSpike = $totalHour > ($basePerHour * ($spikeThreshold / 100));

    $valueWeight = $pageValue / 100;
    $authValueBoost = 1 + ($authRatioCfg / 100) * 0.6;
    $errorBoost = $currentErrorRate >= $errorThreshold ? 1.5 : 1.0;
    $authRequestBoost = $isAuthenticated ? 1.2 : 1.0;

    $fullRate = $baseFullRate * $valueWeight * $authValueBoost * $errorBoost * $authRequestBoost;
    $lightRate = $baseLightRate * (0.4 + 0.6 * $valueWeight) * $authValueBoost * $errorBoost;

    $budgetPressure = min(1.0, $fullSamplesHour / max(1, $budgetPerHour * 0.7));
    if ($budgetPressure > 0.8) {
        $decay = 1 - $budgetPressure * 0.6;
        $fullRate *= $decay;
        $lightRate *= $decay;
    }

    if ($trafficSpike) {
        $spikeCap = max($emergencyRate, $minRate * 1.5);
        $fullRate = min($fullRate, $spikeCap);
        $lightRate = min($lightRate, $spikeCap * 2);
    }

    $fullRate = max($minRate, min(100, $fullRate));
    $lightRate = max($minRate * 2, min(100, $lightRate));
    $lightRate = max($lightRate, $fullRate);

    $sampleLevel = 'skip';
    $rollLight = mt_rand(1, 10000) / 100;
    if ($rollLight <= $lightRate) {
        $rollFull = mt_rand(1, 10000) / 100;
        $fullProb = ($fullRate / max(0.1, $lightRate)) * 100;
        if ($rollFull <= $fullProb) {
            $sampleLevel = 'full';
        } else {
            $sampleLevel = 'light';
        }
    }

    return [
        'sample_level' => $sampleLevel,
        'applied_rate' => round($lightRate, 2),
        'full_rate' => round($fullRate, 2),
        'light_rate' => round($lightRate, 2),
        'matched_rule_id' => $matchedRule['id'],
        'matched_rule_name' => $matchedRule['rule_name'],
        'page_value' => $pageValue,
        'auth_ratio_config' => $authRatioCfg,
        'error_boost_active' => $errorBoost > 1,
        'auth_value_boost_active' => $authValueBoost > 1,
        'auth_request_boost_active' => $authRequestBoost > 1,
        'traffic_spike' => $trafficSpike,
        'budget_pressure' => round($budgetPressure * 100, 2),
        'budget_used_hour' => $fullSamplesHour,
        'light_count_hour' => $lightSamplesHour
    ];
}

function getLostCapabilities($currentFullRates, $targetFullRates, $currentLightRates = null, $targetLightRates = null) {
    $lost = [];

    foreach ($currentFullRates as $ruleName => $curFull) {
        $tgtFull = $targetFullRates[$ruleName] ?? $curFull;
        $curLight = $currentLightRates[$ruleName] ?? $curFull;
        $tgtLight = $targetLightRates[$ruleName] ?? $tgtFull;

        $capabilities = [];
        $severity = 'low';

        if ($tgtFull < 100 && $curFull >= 100) {
            $capabilities[] = '全量追踪覆盖率';
            $severity = 'medium';
        }
        if ($tgtFull < 90) {
            $capabilities[] = '长尾性能分位数 (P95/P99)';
            if ($severity === 'low') $severity = 'medium';
        }
        if ($tgtFull < 70) {
            $capabilities[] = '细粒度设备指纹对比';
            if ($severity === 'low') $severity = 'medium';
        }
        if ($tgtFull < 50) {
            $capabilities[] = '单用户行为路径还原';
            $capabilities[] = '地域细分热力图';
            $severity = 'medium';
        }
        if ($tgtFull < 30) {
            $capabilities[] = '低流量页面异常检测';
            $capabilities[] = '浏览器版本细分统计';
            $severity = 'high';
        }
        if ($tgtFull < 15) {
            $capabilities[] = '时段波动趋势分析';
            $capabilities[] = '来源渠道归因 (小流量渠道)';
            $severity = 'high';
        }
        if ($tgtFull < 5) {
            $capabilities[] = '个体级别调试能力';
            $capabilities[] = '罕见设备/浏览器覆盖';
            $severity = 'high';
        }

        if ($tgtLight < 90 && $curLight >= 90) {
            $capabilities[] = '轻量指标广泛覆盖';
            if ($severity === 'low') $severity = 'medium';
        }
        if ($tgtLight < 50) {
            $capabilities[] = '整体 PV/UV 趋势可靠性';
            $severity = 'high';
        }
        if ($tgtLight < 20) {
            $capabilities[] = '基本流量来源分布';
            $capabilities[] = '设备大类分布 (桌面/移动)';
            $severity = 'high';
        }
        if ($tgtLight < 5) {
            $capabilities[] = '宏观流量量级估算';
            $severity = 'high';
        }

        $dropFull = $curFull - $tgtFull;
        $dropLight = $curLight - $tgtLight;

        if (!empty($capabilities) && ($dropFull > 0.1 || $dropLight > 0.1)) {
            $lost[] = [
                'rule' => $ruleName,
                'current_full_rate' => round($curFull, 2),
                'target_full_rate' => round($tgtFull, 2),
                'current_light_rate' => round($curLight, 2),
                'target_light_rate' => round($tgtLight, 2),
                'drop_full_percent' => round($dropFull, 2),
                'drop_light_percent' => round($dropLight, 2),
                'severity' => $severity,
                'lost_capabilities' => $capabilities
            ];
        }
    }

    return $lost;
}

try {
    switch ($action) {
        case 'collect':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid method');
            }
            $input = json_decode(file_get_contents('php://input'), true);

            $pageUrl = $input['page_url'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
            $isAuth = !empty($input['authenticated']);
            $curErrRate = (float)($input['current_error_rate'] ?? 0);

            $decision = computeSampleDecision($pdo, $pageUrl, $isAuth, $curErrRate);
            $level = $decision['sample_level'];

            $ip = $_SERVER['REMOTE_ADDR'];
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
            } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $ip = $_SERVER['HTTP_X_REAL_IP'];
            }
            $ip = trim($ip);

            if ($level === 'skip') {
                echo json_encode([
                    'status' => 'success',
                    'sampled' => false,
                    'sample_level' => 'skip',
                    'decision' => $decision,
                    'message' => '采样决策：本次不入库，仅返回决策信息'
                ]);
                break;
            }

            $country = $input['country'] ?? '';
            $city = $input['city'] ?? '';
            $isp = $input['isp'] ?? '';

            if (empty($country) && empty($city)) {
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

            if ($level === 'light') {
                $stmt = $pdo->prepare("INSERT INTO lightweight_metrics (
                    ip, user_agent, os, browser, device_type, country, city, referrer, page_url, sample_level
                ) VALUES (
                    :ip, :user_agent, :os, :browser, :device_type, :country, :city, :referrer, :page_url, :sample_level
                )");
                $stmt->execute([
                    ':ip' => $ip,
                    ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    ':os' => $input['os'] ?? '未知',
                    ':browser' => $input['browser'] ?? '未知',
                    ':device_type' => $input['device_type'] ?? '桌面设备',
                    ':country' => $country,
                    ':city' => $city,
                    ':referrer' => $input['referrer'] ?? '',
                    ':page_url' => $pageUrl,
                    ':sample_level' => 'light'
                ]);
                echo json_encode([
                    'status' => 'success',
                    'sampled' => true,
                    'sample_level' => 'light',
                    'id' => $pdo->lastInsertId(),
                    'decision' => $decision,
                    'stored_fields' => ['ip', 'os', 'browser', 'device_type', 'country', 'city', 'referrer', 'page_url']
                ]);
                break;
            }

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
                ':remark' => '',
                ':sample_level' => 'full',
                ':page_value' => $decision['page_value'],
                ':error_rate' => $curErrRate,
                ':auth_ratio' => $isAuth ? 100 : 0
            ];

            $sql = "INSERT INTO visitors (
                ip, user_agent, country, city, isp,
                browser, browser_version, os, os_version, device_type,
                screen_width, screen_height, window_width, window_height,
                language, timezone, platform, cookie_enabled,
                touch_points, device_memory, cpu_cores, connection_type,
                referrer, remark, sample_level, page_value, error_rate, auth_ratio
            ) VALUES (
                :ip, :user_agent, :country, :city, :isp,
                :browser, :browser_version, :os, :os_version, :device_type,
                :screen_width, :screen_height, :window_width, :window_height,
                :language, :timezone, :platform, :cookie_enabled,
                :touch_points, :device_memory, :cpu_cores, :connection_type,
                :referrer, :remark, :sample_level, :page_value, :error_rate, :auth_ratio
            )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($data);

            echo json_encode([
                'status' => 'success',
                'sampled' => true,
                'sample_level' => 'full',
                'id' => $pdo->lastInsertId(),
                'decision' => $decision
            ]);
            break;

        case 'sample_decision':
            $pageUrl = $_GET['page_url'] ?? '';
            $isAuth = !empty($_GET['auth']);
            $curErrRate = (float)($_GET['error_rate'] ?? 0);
            $decision = computeSampleDecision($pdo, $pageUrl, $isAuth, $curErrRate);
            echo json_encode(['status' => 'success', 'decision' => $decision]);
            break;

        case 'list':
            $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
            $limit = 20;
            $offset = ($page - 1) * $limit;
            $search = $_GET['search'] ?? '';
            $filterLevel = $_GET['sample_level'] ?? '';

            $whereFull = "WHERE 1=1";
            $whereLight = "WHERE 1=1";
            $params = [];

            if ($search) {
                $whereFull .= " AND (ip LIKE :search OR remark LIKE :search OR city LIKE :search)";
                $whereLight .= " AND (ip LIKE :search OR city LIKE :search OR page_url LIKE :search)";
                $params[':search'] = "%$search%";
            }
            if ($filterLevel) {
                if ($filterLevel === 'full') {
                    $whereFull .= " AND sample_level = 'full'";
                    $whereLight .= " AND 1=0";
                } elseif ($filterLevel === 'light') {
                    $whereFull .= " AND 1=0";
                    $whereLight .= " AND sample_level = 'light'";
                }
            }

            $countSql = "SELECT SUM(cnt) as total FROM (
                SELECT COUNT(*) as cnt FROM visitors $whereFull
                UNION ALL
                SELECT COUNT(*) as cnt FROM lightweight_metrics $whereLight
            )";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $listSql = "SELECT * FROM (
                SELECT id, 'full' as source_table, ip, user_agent, country, city, isp,
                    browser, browser_version, os, os_version, device_type,
                    screen_width, screen_height, window_width, window_height,
                    language, timezone, platform, cookie_enabled,
                    touch_points, device_memory, cpu_cores, connection_type,
                    referrer, remark, sample_level, page_value, error_rate, auth_ratio,
                    created_at, '' as page_url
                FROM visitors $whereFull
                UNION ALL
                SELECT id, 'light' as source_table, ip, user_agent, country, city, '' as isp,
                    browser, '' as browser_version, os, '' as os_version, device_type,
                    0 as screen_width, 0 as screen_height, 0 as window_width, 0 as window_height,
                    '' as language, '' as timezone, '' as platform, 0 as cookie_enabled,
                    0 as touch_points, 0 as device_memory, 0 as cpu_cores, '' as connection_type,
                    referrer, '' as remark, sample_level, 0 as page_value, 0 as error_rate, 0 as auth_ratio,
                    created_at, page_url
                FROM lightweight_metrics $whereLight
            ) ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
            $stmt = $pdo->prepare($listSql);
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
            $today = date('Y-m-d');
            $fullTodayStmt = $pdo->prepare("SELECT COUNT(*) FROM visitors WHERE DATE(created_at) = :today");
            $fullTodayStmt->execute([':today' => $today]);
            $fullToday = (int)$fullTodayStmt->fetchColumn();

            $lightTodayStmt = $pdo->prepare("SELECT COUNT(*) FROM lightweight_metrics WHERE DATE(created_at) = :today");
            $lightTodayStmt->execute([':today' => $today]);
            $lightToday = (int)$lightTodayStmt->fetchColumn();

            $fullTotal = (int)$pdo->query("SELECT COUNT(*) FROM visitors")->fetchColumn();
            $lightTotal = (int)$pdo->query("SELECT COUNT(*) FROM lightweight_metrics")->fetchColumn();

            echo json_encode([
                'status' => 'success',
                'total' => $fullTotal + $lightTotal,
                'today' => $fullToday + $lightToday,
                'full_total' => $fullTotal,
                'light_total' => $lightTotal,
                'full_today' => $fullToday,
                'light_today' => $lightToday
            ]);
            break;

        case 'sampling_config':
            $method = $_SERVER['REQUEST_METHOD'];
            if ($method === 'GET') {
                $stmt = $pdo->query("SELECT * FROM sampling_config ORDER BY priority DESC, id ASC");
                $rows = $stmt->fetchAll();
                echo json_encode(['status' => 'success', 'data' => $rows]);
                break;
            }
            if ($method === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                $id = $input['id'] ?? 0;
                $fields = ['rule_name', 'url_pattern', 'page_value', 'error_rate_threshold',
                    'auth_ratio', 'base_sample_rate', 'light_sample_rate', 'min_sample_rate',
                    'priority', 'enabled', 'description'];

                if ($id) {
                    $sets = [];
                    $params = [':id' => $id];
                    foreach ($fields as $f) {
                        if (array_key_exists($f, $input)) {
                            $sets[] = "$f = :$f";
                            $params[":$f"] = $input[$f];
                        }
                    }
                    $sets[] = "updated_at = CURRENT_TIMESTAMP";
                    $sql = "UPDATE sampling_config SET " . implode(', ', $sets) . " WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    echo json_encode(['status' => 'success', 'id' => $id, 'op' => 'update']);
                } else {
                    $placeholders = [];
                    $params = [];
                    foreach ($fields as $f) {
                        $placeholders[] = ":$f";
                        $params[":$f"] = $input[$f] ?? null;
                    }
                    $sql = "INSERT INTO sampling_config (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId(), 'op' => 'insert']);
                }
                break;
            }
            if ($method === 'DELETE') {
                $id = $_GET['id'] ?? 0;
                if (!$id) throw new Exception('ID required');
                $stmt = $pdo->prepare("DELETE FROM sampling_config WHERE id = :id");
                $stmt->execute([':id' => $id]);
                echo json_encode(['status' => 'success', 'deleted' => $id]);
                break;
            }
            throw new Exception('Invalid method');

        case 'sampling_meta':
            $method = $_SERVER['REQUEST_METHOD'];
            if ($method === 'GET') {
                $rows = $pdo->query("SELECT meta_key, meta_value FROM sampling_metadata")->fetchAll();
                $out = [];
                foreach ($rows as $r) $out[$r['meta_key']] = $r['meta_value'];
                echo json_encode(['status' => 'success', 'data' => $out]);
                break;
            }
            if ($method === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                foreach ($input as $k => $v) {
                    $stmt = $pdo->prepare("INSERT INTO sampling_metadata (meta_key, meta_value, updated_at)
                        VALUES (:k, :v, CURRENT_TIMESTAMP)
                        ON CONFLICT(meta_key) DO UPDATE SET meta_value = excluded.meta_value, updated_at = CURRENT_TIMESTAMP");
                    $stmt->execute([':k' => $k, ':v' => $v]);
                }
                echo json_encode(['status' => 'success', 'updated' => count($input)]);
                break;
            }
            throw new Exception('Invalid method');

        case 'sampling_stats':
            $hourStart = date('Y-m-d H:00:00');
            $dayStart = date('Y-m-d 00:00:00');

            $fullHour = $pdo->prepare("SELECT COUNT(*) FROM visitors WHERE created_at >= :s");
            $fullHour->execute([':s' => $hourStart]);
            $fullHourCount = (int)$fullHour->fetchColumn();

            $lightHour = $pdo->prepare("SELECT COUNT(*) FROM lightweight_metrics WHERE created_at >= :s");
            $lightHour->execute([':s' => $hourStart]);
            $lightHourCount = (int)$lightHour->fetchColumn();

            $skipEstimateHour = max(0, (int)(($fullHourCount + $lightHourCount) / max(0.1, ($fullHourCount + $lightHourCount) / 50000) * 0.1));

            $fullDay = $pdo->prepare("SELECT COUNT(*) FROM visitors WHERE created_at >= :s");
            $fullDay->execute([':s' => $dayStart]);
            $fullDayCount = (int)$fullDay->fetchColumn();

            $lightDay = $pdo->prepare("SELECT COUNT(*) FROM lightweight_metrics WHERE created_at >= :s");
            $lightDay->execute([':s' => $dayStart]);
            $lightDayCount = (int)$lightDay->fetchColumn();

            $totalFull = (int)$pdo->query("SELECT COUNT(*) FROM visitors")->fetchColumn();
            $totalLight = (int)$pdo->query("SELECT COUNT(*) FROM lightweight_metrics")->fetchColumn();

            $budgetPerHour = (float)getMetaValue($pdo, 'global_budget_per_hour', 50000);
            $warnThreshold = (float)getMetaValue($pdo, 'budget_warn_threshold', 80);
            $dbSizeLimit = (float)getMetaValue($pdo, 'db_size_limit_mb', 500);
            $spikeThreshold = (float)getMetaValue($pdo, 'traffic_spike_threshold', 150);

            $dbPath = '/var/www/html/data/visitors.sqlite';
            $dbSizeMb = file_exists($dbPath) ? round(filesize($dbPath) / 1024 / 1024, 2) : 0;

            $budgetUsedPct = round(($fullHourCount / max(1, $budgetPerHour)) * 100, 2);
            $dbUsedPct = round(($dbSizeMb / max(1, $dbSizeLimit)) * 100, 2);

            $basePerHour = $budgetPerHour * 0.6;
            $totalHour = $fullHourCount + $lightHourCount;
            $isSpike = $totalHour > ($basePerHour * ($spikeThreshold / 100));

            $levelDist = $pdo->query("SELECT sample_level, COUNT(*) as cnt FROM visitors GROUP BY sample_level")->fetchAll();
            $levelDistMap = [];
            foreach ($levelDist as $d) $levelDistMap[$d['sample_level']] = (int)$d['cnt'];

            $stmt = $pdo->query("SELECT rule_name, base_sample_rate, min_sample_rate, priority FROM sampling_config WHERE enabled = 1 ORDER BY priority DESC");
            $rules = $stmt->fetchAll();

            $currentRates = [];
            foreach ($rules as $r) {
                $currentRates[$r['rule_name']] = (float)$r['base_sample_rate'];
            }

            $metaStmt = $pdo->query("SELECT meta_key, meta_value FROM sampling_metadata");
            $metaMap = [];
            foreach ($metaStmt->fetchAll() as $m) {
                $metaMap[$m['meta_key']] = $m['meta_value'];
            }

            echo json_encode([
                'status' => 'success',
                'budget' => [
                    'per_hour' => $budgetPerHour,
                    'warn_threshold_pct' => $warnThreshold,
                    'db_size_limit_mb' => $dbSizeLimit,
                    'spike_threshold_pct' => $spikeThreshold
                ],
                'current' => [
                    'hour' => [
                        'full' => $fullHourCount,
                        'light' => $lightHourCount,
                        'estimated_skip' => $skipEstimateHour,
                        'total' => $totalHour
                    ],
                    'day' => [
                        'full' => $fullDayCount,
                        'light' => $lightDayCount,
                        'total' => $fullDayCount + $lightDayCount
                    ],
                    'all_time' => [
                        'full' => $totalFull,
                        'light' => $totalLight,
                        'total' => $totalFull + $totalLight
                    ],
                    'db_size_mb' => $dbSizeMb,
                    'budget_used_pct' => $budgetUsedPct,
                    'db_used_pct' => $dbUsedPct,
                    'is_spike' => $isSpike,
                    'spike_ratio' => $basePerHour > 0 ? round($totalHour / $basePerHour * 100, 2) : 0
                ],
                'level_distribution' => $levelDistMap,
                'rules' => $rules,
                'current_rates' => $currentRates,
                'metadata' => $metaMap,
                'warnings' => [
                    'budget' => $budgetUsedPct >= $warnThreshold,
                    'db' => $dbUsedPct >= $warnThreshold,
                    'spike' => $isSpike
                ]
            ]);
            break;

        case 'sampling_estimate':
            $input = $_SERVER['REQUEST_METHOD'] === 'POST'
                ? json_decode(file_get_contents('php://input'), true)
                : $_GET;

            $budgetPerHourNew = (float)($input['budget_per_hour'] ?? getMetaValue($pdo, 'global_budget_per_hour', 50000));
            $rateOverrides = $input['rate_overrides'] ?? [];

            $stmt = $pdo->query("SELECT rule_name, base_sample_rate, light_sample_rate, min_sample_rate, page_value, priority FROM sampling_config WHERE enabled = 1 ORDER BY priority DESC");
            $rules = $stmt->fetchAll();

            $currentFullRates = [];
            $currentLightRates = [];
            $targetFullRates = [];
            $targetLightRates = [];
            $ruleDetails = [];

            $originalBudget = (float)getMetaValue($pdo, 'global_budget_per_hour', 50000);
            $budgetRatio = $budgetPerHourNew / max(1, $originalBudget);

            $weightSum = 0;
            foreach ($rules as $r) {
                $weightSum += (float)$r['page_value'] * max(1, (int)$r['priority'] / 10);
            }

            $remainingBudget = $budgetPerHourNew;
            $hourStart = date('Y-m-d H:00:00');
            $totalThisHour = (int)$pdo->query("SELECT COUNT(*) FROM visitors WHERE created_at >= '$hourStart'")->fetchColumn()
                + (int)$pdo->query("SELECT COUNT(*) FROM lightweight_metrics WHERE created_at >= '$hourStart'")->fetchColumn();
            $trafficPerHour = max($totalThisHour * 4, $budgetPerHourNew * 0.8);

            foreach ($rules as $r) {
                $name = $r['rule_name'];
                $curFull = (float)$r['base_sample_rate'];
                $curLight = (float)($r['light_sample_rate'] ?? max($curFull * 1.5, 40));
                $curLight = max($curLight, $curFull);
                $currentFullRates[$name] = $curFull;
                $currentLightRates[$name] = $curLight;

                $tgtFull = $curFull;
                $tgtLight = $curLight;

                if (isset($rateOverrides[$name])) {
                    $tgtFull = (float)$rateOverrides[$name];
                    $lightToFullRatio = $curLight / max(0.1, $curFull);
                    $tgtLight = $tgtFull * $lightToFullRatio;
                    $tgtLight = max($tgtLight, $tgtFull * 1.5);
                    $tgtLight = min(100, $tgtLight);
                } else {
                    $weight = ((float)$r['page_value'] * max(1, (int)$r['priority'] / 10)) / max(1, $weightSum);
                    $share = $trafficPerHour * $weight;
                    $allowedShare = $budgetPerHourNew * $weight;
                    $scaled = $curFull * min(1, $allowedShare / max(1, $share)) * $budgetRatio;
                    $tgtFull = max((float)$r['min_sample_rate'], min(100, $scaled));

                    $lightToFullRatio = $curLight / max(0.1, $curFull);
                    $tgtLight = $tgtFull * $lightToFullRatio;
                    $tgtLight = max($tgtLight, $tgtFull * 1.5);
                    $tgtLight = min(100, $tgtLight);
                    $tgtLight = max($tgtLight, (float)$r['min_sample_rate']);
                }

                $targetFullRates[$name] = round($tgtFull, 2);
                $targetLightRates[$name] = round($tgtLight, 2);

                $ruleDetails[] = [
                    'rule_name' => $name,
                    'current_full_rate' => $curFull,
                    'target_full_rate' => round($tgtFull, 2),
                    'current_light_rate' => round($curLight, 2),
                    'target_light_rate' => round($tgtLight, 2),
                    'page_value' => (float)$r['page_value'],
                    'priority' => (int)$r['priority'],
                    'min_sample_rate' => (float)$r['min_sample_rate']
                ];
            }

            $lost = getLostCapabilities($currentFullRates, $targetFullRates, $currentLightRates, $targetLightRates);

            $affectedRules = count($lost);
            $totalCapabilitiesLost = 0;
            $maxSeverity = 'low';
            foreach ($lost as $l) {
                $totalCapabilitiesLost += count($l['lost_capabilities']);
                if ($l['severity'] === 'high') $maxSeverity = 'high';
                elseif ($l['severity'] === 'medium' && $maxSeverity === 'low') $maxSeverity = 'medium';
            }

            $overallImpact = $maxSeverity;

            echo json_encode([
                'status' => 'success',
                'budget' => [
                    'original' => $originalBudget,
                    'proposed' => $budgetPerHourNew,
                    'ratio' => round($budgetRatio * 100, 2)
                ],
                'rules' => $ruleDetails,
                'lost_capabilities' => $lost,
                'summary' => [
                    'rules_affected' => $affectedRules,
                    'capabilities_lost_count' => $totalCapabilitiesLost,
                    'overall_impact' => $overallImpact,
                    'impact_description' => $overallImpact === 'high'
                        ? '严重：多个高价值规则的分析能力将明显下降，建议谨慎调整或分批执行'
                        : ($overallImpact === 'medium'
                            ? '中等：部分分析维度将受影响，但核心指标仍可保留'
                            : '轻微：仅边缘维度受影响，核心追踪能力完整保留')
                ]
            ]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => '未知操作']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
