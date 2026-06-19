<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>探针管理后台</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
</head>

<body class="bg-gray-100 min-h-screen text-gray-800">
    <div id="app" class="pb-10">
        <!-- 头部 -->
        <header class="bg-white shadow">
            <div
                class="container mx-auto px-4 md:px-6 py-4 flex flex-col md:flex-row justify-between items-center space-y-3 md:space-y-0">
                <div class="flex items-center space-x-2">
                    <i class="ri-radar-fill text-blue-600 text-2xl"></i>
                    <h1 class="text-xl font-bold font-sans">探针管理后台</h1>
                </div>
                <div class="text-sm text-gray-500 flex items-center space-x-4">
                    <span>当前时间: {{ currentTime }}</span>
                    <a href="/" target="_blank" class="text-blue-600 hover:text-blue-800 font-medium">访问前台</a>
                </div>
            </div>
        </header>

        <!-- 主体内容 -->
        <main class="container mx-auto px-4 md:px-6 py-6 md:py-8">

            <!-- 统计卡片 -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <div class="text-gray-500 text-sm mb-1">总访问量</div>
                    <div class="text-2xl font-bold">{{ stats.total }}</div>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <div class="text-gray-500 text-sm mb-1">今日访问</div>
                    <div class="text-2xl font-bold text-blue-600">{{ stats.today }}</div>
                </div>
            </div>

            <!-- 工具栏 -->
            <div class="flex flex-col md:flex-row justify-between items-center mb-6 space-y-4 md:space-y-0">
                <div class="relative w-full md:w-96">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                        <i class="ri-search-line text-gray-400"></i>
                    </span>
                    <input type="text" v-model="searchQuery" @keyup.enter="fetchData(1)"
                        class="w-full py-2 pl-10 pr-4 text-gray-700 bg-white border rounded-lg focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                        placeholder="搜索 IP / 城市 / 备注...">
                </div>
                <button @click="fetchData(1)"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                    <i class="ri-refresh-line mr-1"></i> 刷新
                </button>
            </div>

            <!-- 数据表格 -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-gray-50 text-gray-600 text-sm tracking-wider whitespace-nowrap">
                                <th class="px-6 py-4 font-semibold">ID</th>
                                <th class="px-6 py-4 font-semibold">IP / 位置</th>
                                <th class="px-6 py-4 font-semibold">设备信息</th>
                                <th class="px-6 py-4 font-semibold">备注</th>
                                <th class="px-6 py-4 font-semibold">访问时间</th>
                                <th class="px-6 py-4 font-semibold text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            <tr v-for="item in visitors" :key="item.id"
                                class="hover:bg-gray-50 transition whitespace-nowrap">
                                <td class="px-6 py-4 text-gray-500">#{{ item.id }}</td>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-900">{{ item.ip }}</div>
                                    <div class="text-xs text-gray-500">{{ item.country }} {{ item.city }}</div>
                                    <div class="text-xs text-gray-400">{{ item.isp }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm">
                                        <i :class="getDeviceIcon(item)"></i>
                                        {{ item.os }} / {{ item.browser }}
                                    </div>
                                    <div class="text-xs text-gray-400 mt-1">
                                        {{ item.screen_width }}x{{ item.screen_height }}
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div v-if="item.remark"
                                        class="text-sm text-gray-800 bg-yellow-50 px-2 py-1 rounded inline-block">
                                        {{ item.remark }}
                                    </div>
                                    <div v-else class="text-sm text-gray-300 italic">无</div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    {{ item.created_at }}
                                </td>
                                <td class="px-6 py-4 text-right space-x-2">
                                    <button @click="openDetail(item)"
                                        class="text-blue-600 hover:text-blue-800 text-sm">详情</button>
                                    <button @click="editRemark(item)"
                                        class="text-gray-600 hover:text-gray-800 text-sm">备注</button>
                                </td>
                            </tr>
                            <tr v-if="visitors.length === 0">
                                <td colspan="6" class="px-6 py-12 text-center text-gray-400">
                                    暂无数据
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- 分页 -->
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-between items-center">
                    <div class="text-sm text-gray-500">
                        共 {{ total }} 条记录，第 {{ page }} / {{ totalPages }} 页
                    </div>
                    <div class="flex space-x-2">
                        <button @click="prevPage" :disabled="page <= 1"
                            class="px-3 py-1 bg-white border rounded hover:bg-gray-100 disabled:opacity-50">上一页</button>
                        <button @click="nextPage" :disabled="page >= totalPages"
                            class="px-3 py-1 bg-white border rounded hover:bg-gray-100 disabled:opacity-50">下一页</button>
                    </div>
                </div>
            </div>

        </main>

        <!-- 详情弹窗 -->
        <div v-if="showDetailModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
            @click.self="showDetailModal = false">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col">
                <div class="px-6 py-4 border-b flex justify-between items-center bg-gray-50 flex-shrink-0">
                    <h3 class="text-lg font-bold">访客详情 #{{ currentItem.id }}</h3>
                    <button @click="showDetailModal = false" class="text-gray-400 hover:text-gray-600"><i
                            class="ri-close-line text-xl"></i></button>
                </div>
                <div class="flex-1 overflow-y-auto">
                    <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        <div v-for="field in detailFields" :key="field.key" class="border-b border-gray-100 py-2">
                            <span class="text-gray-500 block mb-1 text-xs">{{ field.label }}</span>
                            <span class="font-mono text-gray-800 break-all">{{ formatValue(currentItem[field.key])
                                }}</span>
                        </div>
                    </div>
                    <div class="px-6 py-4 bg-amber-50 border-t border-amber-100 text-xs text-amber-700 space-y-1">
                        <div class="font-semibold mb-2"><i class="ri-information-line mr-1"></i>数据准确性说明</div>
                        <div>• <b>系统版本</b>：Chrome 90+ 将 macOS 版本冻结为 10.15.7，无法获取真实版本</div>
                        <div>• <b>设备内存</b>：浏览器返回模糊值（如 4/8GB），非精确值</div>
                        <div>• <b>网络类型</b>：仅显示等效网速，无法检测 WiFi/有线/代理</div>
                        <div>• <b>IP 定位</b>：本地/内网 IP 无法定位，需部署到公网服务器</div>
                        <div class="text-amber-600 mt-2">以上为浏览器隐私保护机制限制，非采集错误。</div>
                    </div>
                </div>
                <div class="px-6 py-4 border-t bg-gray-50 text-right flex-shrink-0">
                    <button @click="showDetailModal = false"
                        class="px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">关闭</button>
                </div>
            </div>
        </div>

        <!-- 备注弹窗 -->
        <div v-if="showRemarkModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
            @click.self="showRemarkModal = false">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-md">
                <div class="px-6 py-4 border-b">
                    <h3 class="text-lg font-bold">编辑备注</h3>
                </div>
                <div class="p-6">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">姓名 / 备注信息</label>
                        <textarea v-model="remarkForm.remark" rows="3"
                            class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"></textarea>
                    </div>
                </div>
                <div class="px-6 py-4 border-t bg-gray-50 flex justify-end space-x-3">
                    <button @click="showRemarkModal = false"
                        class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded">取消</button>
                    <button @click="saveRemark"
                        class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">保存</button>
                </div>
            </div>
        </div>

    </div>

    <script>
        const { createApp, ref, onMounted } = Vue;

        createApp({
            setup() {
                const visitors = ref([]);
                const total = ref(0);
                const page = ref(1);
                const totalPages = ref(1);
                const searchQuery = ref('');
                const stats = ref({ total: 0, today: 0 });

                const showDetailModal = ref(false);
                const showRemarkModal = ref(false);
                const currentItem = ref({});
                const remarkForm = ref({ id: null, remark: '' });
                const currentTime = ref('');

                // 详情字段中文映射
                const detailFields = [
                    { key: 'id', label: 'ID' },
                    { key: 'ip', label: 'IP 地址' },
                    { key: 'country', label: '国家' },
                    { key: 'city', label: '城市' },
                    { key: 'isp', label: '运营商' },
                    { key: 'user_agent', label: '用户代理' },
                    { key: 'browser', label: '浏览器' },
                    { key: 'browser_version', label: '浏览器版本' },
                    { key: 'os', label: '操作系统' },
                    { key: 'os_version', label: '系统版本' },
                    { key: 'device_type', label: '设备类型' },
                    { key: 'screen_width', label: '屏幕宽度' },
                    { key: 'screen_height', label: '屏幕高度' },
                    { key: 'window_width', label: '窗口宽度' },
                    { key: 'window_height', label: '窗口高度' },
                    { key: 'language', label: '语言偏好' },
                    { key: 'timezone', label: '时区' },
                    { key: 'platform', label: '平台' },
                    { key: 'cookie_enabled', label: 'Cookie 状态' },
                    { key: 'touch_points', label: '触控点数' },
                    { key: 'device_memory', label: '设备内存 (GB)' },
                    { key: 'cpu_cores', label: 'CPU 核心数' },
                    { key: 'connection_type', label: '网络类型' },
                    { key: 'referrer', label: '来源页面' },
                    { key: 'remark', label: '备注' },
                    { key: 'created_at', label: '访问时间' }
                ];

                const fetchStats = async () => {
                    try {
                        const res = await fetch('/api.php?action=stats');
                        const json = await res.json();
                        if (json.status === 'success') {
                            stats.value = { total: json.total, today: json.today };
                        }
                    } catch (e) {
                        console.error(e);
                    }
                };

                const fetchData = async (p = 1) => {
                    try {
                        const res = await fetch(`/api.php?action=list&page=${p}&search=${searchQuery.value}`);
                        const json = await res.json();
                        if (json.status === 'success') {
                            visitors.value = json.data;
                            total.value = json.total;
                            page.value = json.page;
                            totalPages.value = json.pages;
                        }
                    } catch (e) {
                        console.error(e);
                    }
                    fetchStats();
                };

                const prevPage = () => {
                    if (page.value > 1) fetchData(page.value - 1);
                };

                const nextPage = () => {
                    if (page.value < totalPages.value) fetchData(page.value + 1);
                };

                const openDetail = (item) => {
                    currentItem.value = item;
                    showDetailModal.value = true;
                };

                const editRemark = (item) => {
                    remarkForm.value = { id: item.id, remark: item.remark || '' };
                    showRemarkModal.value = true;
                };

                const saveRemark = async () => {
                    try {
                        const res = await fetch('/api.php?action=remark', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(remarkForm.value)
                        });
                        const json = await res.json();
                        if (json.status === 'success') {
                            showRemarkModal.value = false;
                            fetchData(page.value);
                        } else {
                            alert('保存失败');
                        }
                    } catch (e) {
                        alert('错误: ' + e.message);
                    }
                };

                const getDeviceIcon = (item) => {
                    const os = (item.os || '').toLowerCase();
                    if (os.includes('mac') || os.includes('windows') || os.includes('linux')) return 'ri-computer-line';
                    if (os.includes('android') || os.includes('ios') || os.includes('ipad')) return 'ri-smartphone-line';
                    return 'ri-device-line';
                };

                // 格式化值显示（处理 0、空字符串等情况）
                const formatValue = (val, key) => {
                    if (val === null || val === undefined || val === '') return '-';
                    if (val === 0) return '0';
                    return val;
                };

                // 24小时制时间
                setInterval(() => {
                    const now = new Date();
                    const hours = String(now.getHours()).padStart(2, '0');
                    const minutes = String(now.getMinutes()).padStart(2, '0');
                    const seconds = String(now.getSeconds()).padStart(2, '0');
                    currentTime.value = `${hours}:${minutes}:${seconds}`;
                }, 1000);

                onMounted(() => {
                    fetchData();
                    fetchStats();
                });

                return {
                    visitors, total, page, totalPages, searchQuery, stats,
                    showDetailModal, showRemarkModal, currentItem, remarkForm, currentTime,
                    detailFields, formatValue,
                    fetchData, prevPage, nextPage, openDetail, editRemark, saveRemark, getDeviceIcon
                };
            }
        }).mount('#app');
    </script>
</body>

</html>