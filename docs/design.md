# Speed Probe 详细设计文档

## 1. 数据库设计

系统使用 SQLite 数据库，核心表为 `visitors`。

### 1.1 表结构：visitors

| 字段名 | 类型 | 说明 |
| :--- | :--- | :--- |
| `id` | INTEGER PK | 自增主键 |
| `ip` | TEXT | 访客 IP 地址 |
| `country` | TEXT | 国家 |
| `city` | TEXT | 城市 |
| `isp` | TEXT | 运营商 |
| `user_agent` | TEXT | 原始 User-Agent 字符串 |
| `browser` | TEXT | 浏览器名称 (Chrome, Safari...) |
| `browser_version` | TEXT | 浏览器版本 |
| `os` | TEXT | 操作系统 (macOS, Windows...) |
| `os_version` | TEXT | 系统版本 |
| `device_type` | TEXT | 设备类型 (Desktop, Mobile, Tablet) |
| `screen_width` | INTEGER | 屏幕宽度 |
| `screen_height` | INTEGER | 屏幕高度 |
| `language` | TEXT | 语言偏好 |
| `timezone` | TEXT | 时区 |
| `device_memory` | REAL | 设备内存 (近似值 GB) |
| `connection_type` | TEXT | 网络连接类型 (WiFi/4G...) |
| `referrer` | TEXT | 来源页面 |
| `remark` | TEXT | 管理员备注 |
| `created_at` | DATETIME | 访问时间 (默认当前时间) |

## 2. API 接口设计

所有接口入口均为 `api.php`，通过查询参数 `action` 区分功能。

### 2.1 数据采集 (Collect)

*   **Endpoint**: `POST /api.php?action=collect`
*   **Content-Type**: `application/json`
*   **Request Body**:
    ```json
    {
      "browser": "Chrome",
      "os": "macOS",
      "screen_width": 1920,
      ... // 其他客户端采集字段
    }
    ```
*   **Response**:
    ```json
    { "status": "success", "id": 123 }
    ```

### 2.2 获取列表 (List)

*   **Endpoint**: `GET /api.php?action=list`
*   **Params**:
    *   `page`: 页码 (默认 1)
    *   `limit`: 每页数量 (默认 20)
    *   `search`: 搜索关键词 (可选)
*   **Response**:
    ```json
    {
      "status": "success",
      "data": [ ... ],
      "total": 100,
      "page": 1,
      "pages": 5
    }
    ```

### 2.3 获取统计 (Stats)

*   **Endpoint**: `GET /api.php?action=stats`
*   **Response**:
    ```json
    {
      "status": "success",
      "total": 1000,
      "today": 50
    }
    ```

### 2.4 更新备注 (Remark)

*   **Endpoint**: `POST /api.php?action=remark`
*   **Request Body**:
    ```json
    { "id": 123, "remark": "目标用户A" }
    ```
*   **Response**: `{"status": "success"}`

## 3. 前端设计

### 3.1 测速页 (index.php)
*   **风格**: Cyberpunk / Tech 风格，深色背景，霓虹配色。
*   **交互**:
    *   加载时自动执行 `collector.js`。
    *   点击"开始测速"触发 `speedtest.js` 动画，模拟 Ping -> Jitter -> Download -> Upload 过程。
    *   终端样式日志区域实时滚动显示"测试"日志。

### 3.2 管理后台 (admin.php)
*   **框架**: Vue 3 + Tailwind CSS。
*   **布局**: 
    *   响应式头部（移动端自动折叠）。
    *   数据概览卡片（Total/Today）。
    *   搜索工具栏。
    *   数据表格（支持横向滚动）。
*   **详情弹窗**: 固定头部和底部，内容区域滚动，包含数据准确性提示。
