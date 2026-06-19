# Speed Probe 开发手册

## 1. 环境准备

开发本系统需要以下环境：

*   **Docker Desktop**: 负责运行容器环境。
*   **Git**: 版本控制。
*   **IDE**: VS Code (推荐) 或其他编辑器。

## 2. 快速启动 (开发模式)

1.  克隆项目或解压源码。
2.  进入项目根目录。
3.  启动容器：
    ```bash
    docker compose up --build
    ```
    *   `--build` 参数确保每次修改 `Dockerfile` 后重新构建。
    *   `-d` 参数后台运行。

4.  访问地址：
    *   前台: `http://localhost:3000`
    *   后台: `http://localhost:3000/admin.php`

## 3. 核心文件说明

### 3.1 采集脚本 (`js/collector.js`)
这是系统的核心。主要逻辑：
1.  **User-Agent 解析**: 使用正则匹配浏览器和 OS（注意处理 Chrome 冻结 macOS 版本问题）。
2.  **Client Hints**: 尝试调用 `navigator.userAgentData` 获取更精确信息。
3.  **网络检测**: 调用 `navigator.connection` 获取网络类型和带宽。
4.  **数据上报**: `fetch` POST 提交到后端。

### 3.2 后端逻辑 (`api.php`)
1.  **数据库连接**: `db.php` 负责 SQLite 连接及自动建表。
2.  **IP 定位**: 优先使用服务端 `ip-api.com` 接口获取地理位置，避免客户端跨域问题。
3.  **数据落地**: 接收 JSON 数据并存储。

## 4. 常见问题排查

### 4.1 数据库无写入权限
如果遇到 SQLite `readonly database` 错误：
确保 `backend/public/data` 目录具有写权限，并且所属组为 `www-data`（容器内用户）。Dockerfile 中已包含：
```dockerfile
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html
```

### 4.2 Nginx 无法解析 PHP
如果 Nginx 报错 `host not found in upstream "php"`, 检查 `nginx/default.conf` 是否配置了 Docker DNS resolver:
```nginx
resolver 127.0.0.11 valid=10s;
```

## 5. 扩展开发

### 添加新采集字段
1.  修改 `js/collector.js`: 在 `data` 对象中添加新字段采集逻辑。
2.  修改 `db.php`: 在 `CREATE TABLE` 语句中添加新列（注意 SQLite ALTER TABLE 限制，生产环境需迁移数据）。
3.  修改 `api.php`: 在 `collect` 动作中接收并 INSERT 新字段。
4.  修改 `admin.php`: 在 `detailFields` 数组中添加显示映射。
