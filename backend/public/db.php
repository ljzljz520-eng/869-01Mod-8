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
        $sql = "CREATE TABLE IF NOT EXISTS visitors (
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
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";

        self::$pdo->exec($sql);

        // 检查是否需要插入演示数据
        $count = self::$pdo->query("SELECT COUNT(*) FROM visitors")->fetchColumn();
        if ($count == 0) {
            self::seedData();
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
