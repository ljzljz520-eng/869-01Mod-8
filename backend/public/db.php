<?php
// backend/public/db.php

class Database
{
    private static $pdo;

    public static function connect()
    {
        if (self::$pdo === null) {
            try {
                // 确保数据目录存在
                $dbPath = '/var/www/html/data/visitors.sqlite';
                $dir = dirname($dbPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }

                self::$pdo = new PDO("sqlite:" . $dbPath);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                // 初始化表结构
                self::initTable();

            } catch (PDOException $e) {
                die("Database connection failed: " . $e->getMessage());
            }
        }
        return self::$pdo;
    }

    private static function initTable()
    {
        $visitorsSql = "CREATE TABLE IF NOT EXISTS visitors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT,
            country TEXT,
            city TEXT,
            isp TEXT,
            user_agent TEXT,
            
            browser TEXT,
            browser_version TEXT,
            os TEXT,
            os_version TEXT,
            device_type TEXT,
            
            screen_width INTEGER,
            screen_height INTEGER,
            window_width INTEGER,
            window_height INTEGER,
            
            language TEXT,
            timezone TEXT,
            platform TEXT,
            cookie_enabled INTEGER,
            
            touch_points INTEGER,
            device_memory REAL,
            cpu_cores INTEGER,
            connection_type TEXT,
            
            referrer TEXT,
            remark TEXT,
            sample_level TEXT DEFAULT 'full',
            page_value REAL DEFAULT 0,
            error_rate REAL DEFAULT 0,
            auth_ratio REAL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        self::$pdo->exec($visitorsSql);

        $columns = self::$pdo->query("PRAGMA table_info(visitors)")->fetchAll();
        $colNames = array_column($columns, 'name');
        if (!in_array('sample_level', $colNames)) {
            self::$pdo->exec("ALTER TABLE visitors ADD COLUMN sample_level TEXT DEFAULT 'full'");
        }
        if (!in_array('page_value', $colNames)) {
            self::$pdo->exec("ALTER TABLE visitors ADD COLUMN page_value REAL DEFAULT 0");
        }
        if (!in_array('error_rate', $colNames)) {
            self::$pdo->exec("ALTER TABLE visitors ADD COLUMN error_rate REAL DEFAULT 0");
        }
        if (!in_array('auth_ratio', $colNames)) {
            self::$pdo->exec("ALTER TABLE visitors ADD COLUMN auth_ratio REAL DEFAULT 0");
        }

        $configSql = "CREATE TABLE IF NOT EXISTS sampling_config (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            rule_name TEXT NOT NULL,
            url_pattern TEXT NOT NULL,
            page_value REAL DEFAULT 50,
            error_rate_threshold REAL DEFAULT 5,
            auth_ratio REAL DEFAULT 0,
            base_sample_rate REAL DEFAULT 100,
            min_sample_rate REAL DEFAULT 5,
            priority INTEGER DEFAULT 50,
            enabled INTEGER DEFAULT 1,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        self::$pdo->exec($configSql);

        $metaSql = "CREATE TABLE IF NOT EXISTS sampling_metadata (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            meta_key TEXT UNIQUE NOT NULL,
            meta_value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        self::$pdo->exec($metaSql);

        $lightSql = "CREATE TABLE IF NOT EXISTS lightweight_metrics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT,
            user_agent TEXT,
            os TEXT,
            browser TEXT,
            device_type TEXT,
            country TEXT,
            city TEXT,
            referrer TEXT,
            page_url TEXT,
            sample_level TEXT DEFAULT 'light',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        self::$pdo->exec($lightSql);

        $configCount = self::$pdo->query("SELECT COUNT(*) FROM sampling_config")->fetchColumn();
        if ($configCount == 0) {
            self::seedSamplingConfig();
        }

        $metaCount = self::$pdo->query("SELECT COUNT(*) FROM sampling_metadata")->fetchColumn();
        if ($metaCount == 0) {
            self::seedSamplingMetadata();
        }

        $count = self::$pdo->query("SELECT COUNT(*) FROM visitors")->fetchColumn();
        if ($count == 0) {
            self::seedData();
        }
    }

    private static function seedSamplingConfig()
    {
        $stmt = self::$pdo->prepare("INSERT INTO sampling_config (
            rule_name, url_pattern, page_value, error_rate_threshold, auth_ratio,
            base_sample_rate, min_sample_rate, priority, enabled, description
        ) VALUES (
            :rule_name, :url_pattern, :page_value, :error_rate_threshold, :auth_ratio,
            :base_sample_rate, :min_sample_rate, :priority, :enabled, :description
        )");

        $configs = [
            [
                ':rule_name' => '高价值支付页',
                ':url_pattern' => '*checkout*,*payment*,*pay*,*order/confirm*',
                ':page_value' => 95,
                ':error_rate_threshold' => 2,
                ':auth_ratio' => 80,
                ':base_sample_rate' => 100,
                ':min_sample_rate' => 80,
                ':priority' => 100,
                ':enabled' => 1,
                ':description' => '支付相关页面，强制保留高采样率'
            ],
            [
                ':rule_name' => '用户中心',
                ':url_pattern' => '*user*,*account*,*profile*,*dashboard*,*member*',
                ':page_value' => 80,
                ':error_rate_threshold' => 3,
                ':auth_ratio' => 95,
                ':base_sample_rate' => 80,
                ':min_sample_rate' => 50,
                ':priority' => 90,
                ':enabled' => 1,
                ':description' => '登录用户专属页面，授权比例高'
            ],
            [
                ':rule_name' => '商品详情',
                ':url_pattern' => '*product*,*item*,*detail*,*goods*',
                ':page_value' => 70,
                ':error_rate_threshold' => 4,
                ':auth_ratio' => 30,
                ':base_sample_rate' => 60,
                ':min_sample_rate' => 30,
                ':priority' => 70,
                ':enabled' => 1,
                ':description' => '关键转化漏斗页面'
            ],
            [
                ':rule_name' => '首页 / 列表页',
                ':url_pattern' => '*index*,*list*,*category*,*search*,*home*',
                ':page_value' => 40,
                ':error_rate_threshold' => 6,
                ':auth_ratio' => 10,
                ':base_sample_rate' => 30,
                ':min_sample_rate' => 10,
                ':priority' => 40,
                ':enabled' => 1,
                ':description' => '高流量页面，默认低采样率'
            ],
            [
                ':rule_name' => '静态资源 / API',
                ':url_pattern' => '*.js,*.css,*.png,*.jpg,*.jpeg,*.gif,*.svg,*.ico,*.woff,*.woff2,*.map,/api/*',
                ':page_value' => 5,
                ':error_rate_threshold' => 10,
                ':auth_ratio' => 0,
                ':base_sample_rate' => 5,
                ':min_sample_rate' => 1,
                ':priority' => 10,
                ':enabled' => 1,
                ':description' => '静态资源和API，仅保留极轻量指标'
            ],
            [
                ':rule_name' => '默认兜底规则',
                ':url_pattern' => '*',
                ':page_value' => 30,
                ':error_rate_threshold' => 5,
                ':auth_ratio' => 5,
                ':base_sample_rate' => 20,
                ':min_sample_rate' => 5,
                ':priority' => 1,
                ':enabled' => 1,
                ':description' => '未匹配到任何具体规则时使用此规则'
            ]
        ];

        foreach ($configs as $config) {
            $stmt->execute($config);
        }
    }

    private static function seedSamplingMetadata()
    {
        $stmt = self::$pdo->prepare("INSERT INTO sampling_metadata (meta_key, meta_value) VALUES (:meta_key, :meta_value)");
        $metas = [
            ['global_budget_per_hour', '50000'],
            ['budget_warn_threshold', '80'],
            ['db_size_limit_mb', '500'],
            ['traffic_spike_threshold', '150'],
            ['emergency_sample_rate', '10']
        ];
        foreach ($metas as $m) {
            $stmt->execute([':meta_key' => $m[0], ':meta_value' => $m[1]]);
        }
    }

    private static function seedData()
    {
        $stmt = self::$pdo->prepare("INSERT INTO visitors (
            ip, country, city, isp, user_agent, browser, os, screen_width, screen_height, remark, created_at
        ) VALUES (
            :ip, :country, :city, :isp, :user_agent, :browser, :os, :screen_width, :screen_height, :remark, :created_at
        )");

        $demos = [
            [
                ':ip' => '192.168.1.101',
                ':country' => 'China',
                ':city' => 'Shanghai',
                ':isp' => 'China Telecom',
                ':user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)...',
                ':browser' => 'Chrome',
                ':os' => 'Mac OS X',
                ':screen_width' => 1920,
                ':screen_height' => 1080,
                ':remark' => '测试数据 A',
                ':created_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))
            ],
            [
                ':ip' => '10.0.0.5',
                ':country' => 'China',
                ':city' => 'Beijing',
                ':isp' => 'China Unicom',
                ':user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X)...',
                ':browser' => 'Safari',
                ':os' => 'iOS',
                ':screen_width' => 390,
                ':screen_height' => 844,
                ':remark' => '测试数据 B - 手机端',
                ':created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))
            ]
        ];

        foreach ($demos as $demo) {
            $stmt->execute($demo);
        }
    }
}
