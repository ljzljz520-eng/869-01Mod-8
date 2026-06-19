// backend/public/js/collector.js - 访客信息采集器（完整版）

(function () {
    // 解析 User-Agent 获取浏览器信息
    const parseUserAgent = () => {
        const ua = navigator.userAgent;
        let browser = '未知';
        let browserVersion = '';
        let os = '未知';
        let osVersion = '';
        let deviceType = '桌面设备';

        // 检测浏览器
        if (ua.includes('Edg/')) {
            browser = 'Edge';
            browserVersion = ua.match(/Edg\/([\d.]+)/)?.[1] || '';
        } else if (ua.includes('Chrome/') && !ua.includes('Chromium')) {
            browser = 'Chrome';
            browserVersion = ua.match(/Chrome\/([\d.]+)/)?.[1] || '';
        } else if (ua.includes('Firefox/')) {
            browser = 'Firefox';
            browserVersion = ua.match(/Firefox\/([\d.]+)/)?.[1] || '';
        } else if (ua.includes('Safari/') && !ua.includes('Chrome')) {
            browser = 'Safari';
            browserVersion = ua.match(/Version\/([\d.]+)/)?.[1] || '';
        } else if (ua.includes('Opera') || ua.includes('OPR/')) {
            browser = 'Opera';
            browserVersion = ua.match(/(?:Opera|OPR)\/([\d.]+)/)?.[1] || '';
        }

        // 检测操作系统 (注意：Chrome 90+ 会冻结 macOS 版本为 10.15.7)
        if (ua.includes('Windows NT 10')) {
            os = 'Windows';
            osVersion = '10/11';
        } else if (ua.includes('Windows NT 6.3')) {
            os = 'Windows';
            osVersion = '8.1';
        } else if (ua.includes('Windows NT 6.1')) {
            os = 'Windows';
            osVersion = '7';
        } else if (ua.includes('Mac OS X')) {
            os = 'macOS';
            // 从 UA 提取版本（可能是冻结值）
            osVersion = ua.match(/Mac OS X ([\d_]+)/)?.[1]?.replace(/_/g, '.') || '';
        } else if (ua.includes('iPhone')) {
            os = 'iOS';
            osVersion = ua.match(/iPhone OS ([\d_]+)/)?.[1]?.replace(/_/g, '.') || '';
            deviceType = '手机';
        } else if (ua.includes('iPad')) {
            os = 'iPadOS';
            osVersion = ua.match(/CPU OS ([\d_]+)/)?.[1]?.replace(/_/g, '.') || '';
            deviceType = '平板';
        } else if (ua.includes('Android')) {
            os = 'Android';
            osVersion = ua.match(/Android ([\d.]+)/)?.[1] || '';
            deviceType = ua.includes('Mobile') ? '手机' : '平板';
        } else if (ua.includes('Linux')) {
            os = 'Linux';
            osVersion = '';
        }

        return { browser, browserVersion, os, osVersion, deviceType };
    };

    // 获取网络连接信息
    const getConnectionInfo = () => {
        const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (conn) {
            // 物理连接类型（部分浏览器支持）
            let physicalType = conn.type || '';
            // 映射为中文
            const typeMap = {
                'wifi': 'WiFi',
                'cellular': '蜂窝网络',
                'ethernet': '有线网络',
                'bluetooth': '蓝牙',
                'wimax': 'WiMAX',
                'other': '其他',
                'none': '无网络',
                'unknown': '未知'
            };
            physicalType = typeMap[physicalType] || physicalType;

            // 等效连接速度
            const effectiveType = conn.effectiveType || '';
            const speedMap = {
                '4g': '4G (快速)',
                '3g': '3G (中速)',
                '2g': '2G (慢速)',
                'slow-2g': '极慢'
            };
            const speed = speedMap[effectiveType] || effectiveType;

            // 组合显示
            let result = [];
            if (physicalType) result.push(physicalType);
            if (speed) result.push(speed);
            if (conn.downlink) result.push(`${conn.downlink}Mbps`);

            return {
                type: result.length > 0 ? result.join(' / ') : '未知',
                downlink: conn.downlink ? `${conn.downlink} Mbps` : '',
                rtt: conn.rtt ? `${conn.rtt} ms` : ''
            };
        }
        return { type: '浏览器不支持', downlink: '', rtt: '' };
    };

    // 获取 WebGL 渲染器信息（显卡）
    const getWebGLInfo = () => {
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            if (gl) {
                const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                if (debugInfo) {
                    return {
                        vendor: gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL),
                        renderer: gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL)
                    };
                }
            }
        } catch (e) { }
        return { vendor: '', renderer: '' };
    };

    // 尝试使用 User-Agent Client Hints API 获取更准确信息
    const getClientHints = async () => {
        const hints = {
            platform: '',
            platformVersion: '',
            mobile: false,
            model: '',
            architecture: ''
        };

        if (navigator.userAgentData) {
            try {
                // 高熵值需要权限
                const highEntropy = await navigator.userAgentData.getHighEntropyValues([
                    'platform',
                    'platformVersion',
                    'architecture',
                    'model',
                    'mobile',
                    'fullVersionList'
                ]);
                hints.platform = highEntropy.platform || '';
                hints.platformVersion = highEntropy.platformVersion || '';
                hints.mobile = highEntropy.mobile || false;
                hints.model = highEntropy.model || '';
                hints.architecture = highEntropy.architecture || '';
            } catch (e) {
                // 降级使用低熵值
                hints.platform = navigator.userAgentData.platform || '';
                hints.mobile = navigator.userAgentData.mobile || false;
            }
        }
        return hints;
    };

    // 主采集函数
    const collectData = async () => {
        const uaInfo = parseUserAgent();
        const webglInfo = getWebGLInfo();
        const connInfo = getConnectionInfo();
        const clientHints = await getClientHints();

        // 如果 Client Hints 提供了更准确的平台版本，使用它
        let finalOsVersion = uaInfo.osVersion;
        if (clientHints.platformVersion) {
            finalOsVersion = clientHints.platformVersion;
        }

        // 基础数据
        const data = {
            // 浏览器/系统信息
            browser: uaInfo.browser,
            browser_version: uaInfo.browserVersion,
            os: clientHints.platform || uaInfo.os,
            os_version: finalOsVersion,
            device_type: clientHints.mobile ? '手机' : uaInfo.deviceType,

            // 屏幕信息
            screen_width: screen.width,
            screen_height: screen.height,
            window_width: window.innerWidth,
            window_height: window.innerHeight,
            color_depth: screen.colorDepth,
            pixel_ratio: window.devicePixelRatio || 1,

            // 浏览器设置
            language: navigator.language || navigator.userLanguage || '未知',
            platform: navigator.platform || '未知',
            cookie_enabled: navigator.cookieEnabled ? 1 : 0,
            do_not_track: navigator.doNotTrack === '1' ? 1 : 0,

            // 硬件信息
            device_memory: navigator.deviceMemory || 0,
            cpu_cores: navigator.hardwareConcurrency || 0,
            touch_points: navigator.maxTouchPoints || 0,
            gpu_vendor: webglInfo.vendor,
            gpu_renderer: webglInfo.renderer,
            architecture: clientHints.architecture || '',

            // 网络信息
            connection_type: connInfo.type,
            connection_downlink: connInfo.downlink,
            connection_rtt: connInfo.rtt,

            // 时区
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            timezone_offset: new Date().getTimezoneOffset(),

            // 来源页面（完整地址 + 类型提示）
            referrer: (() => {
                const ref = document.referrer;
                if (!ref) {
                    return location.href + ' [直接访问]';
                }
                try {
                    const refUrl = new URL(ref);
                    if (refUrl.hostname === location.hostname) {
                        return ref + ' [站内]';
                    }
                    return ref + ' [外部]';
                } catch (e) {
                    return ref;
                }
            })(),

            // IP 定位信息（默认值）
            country: '',
            city: '',
            isp: ''
        };

        // 尝试获取 IP 定位信息
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 3000);

            const ipRes = await fetch('https://ip-api.com/json/?lang=zh-CN', {
                signal: controller.signal
            });
            clearTimeout(timeoutId);

            if (ipRes.ok) {
                const ipData = await ipRes.json();
                if (ipData.status === 'success') {
                    data.country = ipData.country || '';
                    data.city = ipData.city || '';
                    data.isp = ipData.isp || '';
                }
            }
        } catch (e) {
            console.log('IP 定位获取失败');
        }

        // 提交数据
        try {
            const res = await fetch('/api.php?action=collect', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const json = await res.json();
            console.log('采集完成', json);
            if (json.id) {
                sessionStorage.setItem('visitor_id', json.id);
            }
        } catch (err) {
            console.error('采集失败', err);
        }
    };

    // 页面加载完成后执行
    if (document.readyState === 'complete') {
        collectData();
    } else {
        window.addEventListener('load', collectData);
    }
})();
