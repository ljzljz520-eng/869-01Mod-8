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
            'min_sample_rate' => 10,
            'priority' => 0,
            'description' => '未匹配任何规则时的兜底策略'
        ];
    }

    $pageValue = (float)$matchedRule['page_value'];
    $errorThreshold = (float)$matchedRule['error_rate_threshold'];
    $authRatioCfg = (float)$matchedRule['auth_ratio'];
    $baseRate = (float)$matchedRule['base_sample_rate'];
    $minRate = (float)$matchedRule['min_sample_rate'];

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
    $errorBoost = $currentErrorRate >= $errorThreshold ? 1.5 : 1.0;
    $authBoost = $isAuthenticated ? (1 + $authRatioCfg / 200) : 1.0;

    $dynamicRate = $baseRate * $valueWeight * $errorBoost * $authBoost;

    $budgetPressure = min(1.0, $fullSamplesHour / max(1, $budgetPerHour * 0.7));
    if ($budgetPressure > 0.8) {
        $dynamicRate *= (1 - $budgetPressure * 0.6);
    }

    if ($trafficSpike) {
        $dynamicRate = min($dynamicRate, max($emergencyRate, $minRate * 1.5));
    }

    $dynamicRate = max($minRate, min(100, $dynamicRate));

    $sampleLevel = 'skip';
    $roll = mt_rand(1, 10000) / 100;
    if ($roll <= $dynamicRate) {
        $sampleLevel = $dynamicRate >= 70 || $pageValue >= 70 ? 'full' : 'light';
    }

    return [
        'sample_level' => $sampleLevel,
        'applied_rate' => round($dynamicRate, 2),
        'matched_rule_id' => $matchedRule['id'],
        'matched_rule_name' => $matchedRule['rule_name'],
        'page_value' => $pageValue,
        'error_boost_active' => $errorBoost > 1,
        'auth_boost_active' => $authBoost > 1,
        'traffic_spike' => $trafficSpike,
        'budget_pressure' => round($budgetPressure * 100, 2),
        'budget_used_hour' => $fullSamplesHour,
        'light_count_hour' => $lightSamplesHour
    ];
}

function getLostCapabilities($currentRates, $targetRates) {
    $lost = [];

    foreach ($currentRates as $ruleName => $cur) {
        $tgt = $targetRates[$ruleName] ?? $cur;
        if ($tgt >= $cur) continue;
        $drop = $cur - $tgt;
        $capabilities = [];

        if ($tgt < 100 && $cur >= 100) {
            $capabilities[] = '全量追踪';
        }
        if ($tgt < 90) {
            $capabilities[] = '长尾性能分位数 (P95/P99)';
        }
        if ($tgt < 70) {
            $capabilities[] = '细粒度设备指纹对比';
        }
        if ($tgt < 50) {
            $capabilities[] = '单用户行为路径还原';
            $capabilities[] = '地域细分热力图';
        }
        if ($tgt < 30) {
            $capabilities[] = '低流量页面异常检测';
            $capabilities[] = '浏览器版本细分统计';
        }
        if ($tgt < 15) {
            $capabilities[] = '时段波动趋势分析';
            $capabilities[] = '来源渠道归因 (小流量渠道)';
        }
        if ($tgt < 5) {
            $capabilities[] = '个体级别调试能力';
            $capabilities[] = '罕见设备/浏览器覆盖';
        }

        if (!empty($capabilities)) {
            $lost[] = [
                'rule' => $ruleName,
                'current_rate' => $cur,
                'target_rate' => $tgt,
                'drop_percent' => round($drop, 2),
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

            $where = "WHERE 1=1";
            $params = [];

            if ($search) {
                $where .= " AND (ip LIKE :search OR remark LIKE :search OR city LIKE :search)";
                $params[':search'] = "%$search%";
            }
            if ($filterLevel) {
                $where .= " AND sample_level = :level";
                $params[':level'] = $filterLevel;
            }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM visitors $where");
            $countStmt->execute($params);
            $total = $countStmt->fetchColumn();

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
            $today = date('Y-m-d');
            $todayStmt = $pdo->prepare("SELECT COUNT(*) FROM visitors WHERE DATE(created_at) = :today");
            $todayStmt->execute([':today' => $today]);
            $todayCount = $todayStmt->fetchColumn();

            $totalStmt = $pdo->query("SELECT COUNT(*) FROM visitors");
            $totalCount = $totalStmt->fetchColumn();

            echo json_encode([
                'status' => 'success',
                'total' => $totalCount,
                'today' => $todayCount
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
                    'auth_ratio', 'base_sample_rate', 'min_sample_rate', 'priority', 'enabled', 'description'];

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

            $stmt = $pdo->query("SELECT rule_name, base_sample_rate, min_sample_rate, page_value, priority FROM sampling_config WHERE enabled = 1 ORDER BY priority DESC");
            $rules = $stmt->fetchAll();

            $currentRates = [];
            $targetRates = [];
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
                $currentRate = (float)$r['base_sample_rate'];
                $currentRates[$name] = $currentRate;

                $targetRate = $currentRate;
                if (isset($rateOverrides[$name])) {
                    $targetRate = (float)$rateOverrides[$name];
                } else {
                    $weight = ((float)$r['page_value'] * max(1, (int)$r['priority'] / 10)) / max(1, $weightSum);
                    $share = $trafficPerHour * $weight;
                    $allowedShare = $budgetPerHourNew * $weight;
                    $scaled = $currentRate * min(1, $allowedShare / max(1, $share)) * $budgetRatio;
                    $targetRate = max((float)$r['min_sample_rate'], min(100, $scaled));
                }

                $targetRates[$name] = round($targetRate, 2);
                $ruleDetails[] = [
                    'rule_name' => $name,
                    'current_rate' => $currentRate,
                    'target_rate' => $targetRates[$name],
                    'page_value' => (float)$r['page_value'],
                    'priority' => (int)$r['priority'],
                    'min_sample_rate' => (float)$r['min_sample_rate']
                ];
            }

            $lost = getLostCapabilities($currentRates, $targetRates);

            $affectedRules = count($lost);
            $totalCapabilitiesLost = 0;
            foreach ($lost as $l) $totalCapabilitiesLost += count($l['lost_capabilities']);

            $overallImpact = 'low';
            if ($totalCapabilitiesLost >= 8 || $affectedRules >= 4) $overallImpact = 'high';
            elseif ($totalCapabilitiesLost >= 3 || $affectedRules >= 2) $overallImpact = 'medium';

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
