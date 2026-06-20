// backend/public/js/collector.js - 访客信息采集器（支持采样决策分级上报）

(function () {
    const parseUserAgent = () => {
        const ua = navigator.userAgent;
        let browser = '未知';
        let browserVersion = '';
        let os = '未知';
        let osVersion = '';
        let deviceType = '桌面设备';

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

    const getConnectionInfo = () => {
        const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (conn) {
            let physicalType = conn.type || '';
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

            const effectiveType = conn.effectiveType || '';
            const speedMap = {
                '4g': '4G (快速)',
                '3g': '3G (中速)',
                '2g': '2G (慢速)',
                'slow-2g': '极慢'
            };
            const speed = speedMap[effectiveType] || effectiveType;

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
                hints.platform = navigator.userAgentData.platform || '';
                hints.mobile = navigator.userAgentData.mobile || false;
            }
        }
        return hints;
    };

    const isAuthenticated = () => {
        try {
            const hasAuth = document.cookie && document.cookie.match(/(auth|token|session|login|user_id)/i);
            if (hasAuth) return true;
            if (localStorage) {
                for (let i = 0; i < localStorage.length; i++) {
                    const k = localStorage.key(i) || '';
                    if (/^(auth|token|jwt|user_)/i.test(k)) return true;
                }
            }
        } catch (e) { }
        return false;
    };

    const estimateErrorRate = () => {
        try {
            const errs = (window.__probeErrors = window.__probeErrors || []);
            if (errs.length === 0) return 0;
            const recent = errs.filter(e => Date.now() - (e.t || 0) < 60000);
            return Math.min(100, recent.length * 2);
        } catch (e) {
            return 0;
        }
    };

    if (!window.__probeErrorBound) {
        window.addEventListener('error', (e) => {
            window.__probeErrors = window.__probeErrors || [];
            window.__probeErrors.push({ m: e.message, t: Date.now() });
            if (window.__probeErrors.length > 50) window.__probeErrors.shift();
        });
        window.__probeErrorBound = true;
    }

    const buildLightData = (uaInfo, hints) => ({
        page_url: location.href,
        authenticated: isAuthenticated(),
        current_error_rate: estimateErrorRate(),
        os: hints.platform || uaInfo.os,
        browser: uaInfo.browser,
        device_type: hints.mobile ? '手机' : uaInfo.deviceType,
        referrer: document.referrer || location.href + ' [直接访问]',
        country: '',
        city: ''
    });

    const buildFullData = (uaInfo, webglInfo, connInfo, hints, geoData) => {
        let finalOsVersion = uaInfo.osVersion;
        if (hints.platformVersion) {
            finalOsVersion = hints.platformVersion;
        }
        return {
            page_url: location.href,
            authenticated: isAuthenticated(),
            current_error_rate: estimateErrorRate(),

            browser: uaInfo.browser,
            browser_version: uaInfo.browserVersion,
            os: hints.platform || uaInfo.os,
            os_version: finalOsVersion,
            device_type: hints.mobile ? '手机' : uaInfo.deviceType,

            screen_width: screen.width,
            screen_height: screen.height,
            window_width: window.innerWidth,
            window_height: window.innerHeight,
            color_depth: screen.colorDepth,
            pixel_ratio: window.devicePixelRatio || 1,

            language: navigator.language || navigator.userLanguage || '未知',
            platform: navigator.platform || '未知',
            cookie_enabled: navigator.cookieEnabled ? 1 : 0,
            do_not_track: navigator.doNotTrack === '1' ? 1 : 0,

            device_memory: navigator.deviceMemory || 0,
            cpu_cores: navigator.hardwareConcurrency || 0,
            touch_points: navigator.maxTouchPoints || 0,
            gpu_vendor: webglInfo.vendor,
            gpu_renderer: webglInfo.renderer,
            architecture: hints.architecture || '',

            connection_type: connInfo.type,
            connection_downlink: connInfo.downlink,
            connection_rtt: connInfo.rtt,

            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            timezone_offset: new Date().getTimezoneOffset(),

            referrer: (() => {
                const ref = document.referrer;
                if (!ref) return location.href + ' [直接访问]';
                try {
                    const refUrl = new URL(ref);
                    if (refUrl.hostname === location.hostname) return ref + ' [站内]';
                    return ref + ' [外部]';
                } catch (e) {
                    return ref;
                }
            })(),

            country: geoData.country || '',
            city: geoData.city || '',
            isp: geoData.isp || ''
        };
    };

    const fetchGeo = async () => {
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
                    return { country: ipData.country || '', city: ipData.city || '', isp: ipData.isp || '' };
                }
            }
        } catch (e) { }
        return { country: '', city: '', isp: '' };
    };

    const requestSampleDecision = async () => {
        try {
            const pageUrl = encodeURIComponent(location.href);
            const auth = isAuthenticated() ? '1' : '0';
            const err = estimateErrorRate();
            const res = await fetch(
                `/api.php?action=sample_decision&page_url=${pageUrl}&auth=${auth}&error_rate=${err}`
            );
            if (res.ok) {
                const json = await res.json();
                if (json.status === 'success') return json.decision;
            }
        } catch (e) { }
        return { sample_level: 'full' };
    };

    const submitData = async (payload) => {
        try {
            const res = await fetch('/api.php?action=collect', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const json = await res.json();
            console.log('[Probe] 采集结果', json);
            if (json && json.id) {
                sessionStorage.setItem('visitor_id', json.id);
            }
            return json;
        } catch (err) {
            console.error('[Probe] 采集失败', err);
            return null;
        }
    };

    const collectData = async () => {
        if (sessionStorage.getItem('probe_collected') === '1') {
            return;
        }

        const uaInfo = parseUserAgent();
        const hints = await getClientHints();

        const decision = await requestSampleDecision();
        const level = decision.sample_level || 'full';
        console.log('[Probe] 采样决策:', level, decision);

        if (level === 'skip') {
            sessionStorage.setItem('probe_collected', '1');
            console.log('[Probe] 本次采样跳过，不发送数据');
            return;
        }

        let payload;
        if (level === 'light') {
            payload = buildLightData(uaInfo, hints);
        } else {
            const webglInfo = getWebGLInfo();
            const connInfo = getConnectionInfo();
            const geoData = await fetchGeo();
            payload = buildFullData(uaInfo, webglInfo, connInfo, hints, geoData);
        }

        await submitData(payload);
        sessionStorage.setItem('probe_collected', '1');
    };

    if (document.readyState === 'complete') {
        collectData();
    } else {
        window.addEventListener('load', collectData);
    }
})();
