<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>探针管理后台 · 采样预算控制台</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8'
                        }
                    }
                }
            }
        };
    </script>
</head>

<body class="bg-slate-50 min-h-screen text-gray-800">
    <div id="app" class="pb-10">
        <!-- 头部 -->
        <header class="bg-white shadow-sm border-b border-gray-100 sticky top-0 z-40">
            <div class="container mx-auto px-4 md:px-6 py-4 flex flex-col md:flex-row justify-between items-center space-y-3 md:space-y-0">
                <div class="flex items-center space-x-2">
                    <i class="ri-radar-fill text-brand-600 text-2xl"></i>
                    <h1 class="text-xl font-bold font-sans">探针管理后台</h1>
                </div>
                <div class="text-sm text-gray-500 flex items-center space-x-4">
                    <span><i class="ri-time-line mr-1"></i> {{ currentTime }}</span>
                    <a href="/" target="_blank" class="text-brand-600 hover:text-brand-700 font-medium">
                        <i class="ri-external-link-line mr-1"></i>访问前台
                    </a>
                </div>
            </div>

            <div class="container mx-auto px-4 md:px-6">
                <div class="flex space-x-1 border-b-0">
                    <button @click="activeTab = 'visitors'"
                        :class="['px-5 py-3 text-sm font-medium rounded-t-lg transition flex items-center space-x-2',
                            activeTab === 'visitors' ? 'bg-slate-50 text-brand-700 border-x border-t border-gray-200 -mb-px' :
                            'text-gray-500 hover:text-gray-700 hover:bg-gray-50']">
                    <i class="ri-team-line"></i><span>访客管理</span>
                </button>
                    <button @click="activeTab = 'sampling'"
                        :class="['px-5 py-3 text-sm font-medium rounded-t-lg transition flex items-center space-x-2',
                            activeTab === 'sampling' ? 'bg-slate-50 text-brand-700 border-x border-t border-gray-200 -mb-px' :
                            'text-gray-500 hover:text-gray-700 hover:bg-gray-50']">
                    <i class="ri-pie-chart-2-line"></i><span>采样预算控制台</span>
                    <span v-if="samplingStats && (samplingStats.warnings.budget || samplingStats.warnings.db || samplingStats.warnings.spike)"
                        class="ml-1 inline-flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-red-500 rounded-full animate-pulse">
                        !
                    </span>
                </button>
                </div>
            </div>
        </header>

        <main class="container mx-auto px-4 md:px-6 py-6 md:py-8">
            <!-- 访客管理 TAB -->
            <div v-if="activeTab === 'visitors'">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <div class="text-gray-500 text-sm mb-1 flex items-center"><i class="ri-database-2-line mr-1"></i>总访问量</div>
                        <div class="text-2xl font-bold">{{ stats.total }}</div>
                    </div>
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <div class="text-gray-500 text-sm mb-1 flex items-center"><i class="ri-calendar-todo-line mr-1"></i>今日访问</div>
                        <div class="text-2xl font-bold text-brand-600">{{ stats.today }}</div>
                    </div>
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <div class="text-gray-500 text-sm mb-1 flex items-center"><i class="ri-bubble-chart-line mr-1"></i>全量追踪</div>
                        <div class="text-2xl font-bold text-emerald-600">{{ samplingStats?.level_distribution?.full || 0 }}</div>
                    </div>
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <div class="text-gray-500 text-sm mb-1 flex items-center"><i class="ri-feather-line mr-1"></i>轻量指标</div>
                        <div class="text-2xl font-bold text-amber-600">{{ samplingStats?.current?.all_time?.light || 0 }}</div>
                    </div>
                </div>

                <!-- 工具栏 -->
                <div class="flex flex-col md:flex-row md:space-y-4 md:space-y-0 md:justify-between md:items-center mb-6">
                    <div class="flex flex-col md:flex-row space-y-3 md:space-y-0 md:space-x-3 w-full md:w-auto">
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                                <i class="ri-search-line text-gray-400"></i>
                            </span>
                            <input type="text" v-model="searchQuery" @keyup.enter="fetchData(1)"
                                class="w-full md:w-72 py-2 pl-10 pr-4 text-gray-700 bg-white border rounded-lg
                                    focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200"
                                placeholder="搜索 IP / 城市 / 备注...">
                        </div>
                        <select v-model="filterLevel" @change="fetchData(1)"
                            class="py-2 px-4 border rounded-lg bg-white text-gray-700
                                focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
                            <option value="">全部采样级别</option>
                            <option value="full">全量追踪</option>
                            <option value="light">轻量指标</option>
                        </select>
                    </div>
                    <button @click="fetchData(1)"
                        class="px-4 py-2 bg-brand-600 text-white rounded-lg hover:bg-brand-700 transition shadow-sm">
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
                                    <th class="px-6 py-4 font-semibold">采样级别</th>
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
                                        <span v-if="item.sample_level === 'full' || !item.sample_level"
                                            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">
                                            <i class="ri-shield-check-line mr-1"></i> 全量
                                        </span>
                                        <span v-else
                                            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                            <i class="ri-feather-line mr-1"></i> 轻量
                                        </span>
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
                                            class="text-brand-600 hover:text-brand-800 text-sm">详情</button>
                                        <button @click="editRemark(item)"
                                            class="text-gray-600 hover:text-gray-800 text-sm">备注</button>
                                    </td>
                                </tr>
                                <tr v-if="visitors.length === 0">
                                    <td colspan="7" class="px-6 py-12 text-center text-gray-400">
                                        <i class="ri-inbox-line text-4xl block mb-2 opacity-40"></i>
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
            </div>

            <!-- ============ 采样预算控制台 TAB ============ -->
            <div v-if="activeTab === 'sampling'">

                <!-- 告警条 -->
                <div v-if="samplingStats" class="mb-6 space-y-3">
                    <div v-if="samplingStats.warnings.spike"
                        class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg flex items-start space-x-3">
                        <i class="ri-fire-line text-red-600 text-xl mt-0.5"></i>
                        <div class="flex-1">
                            <div class="font-semibold text-red-800">流量突增告警</div>
                            <div class="text-sm text-red-700 mt-1">
                                当前小时访问量达到基准的 <b>{{ samplingStats.current.spike_ratio }}%</b>，已触发自动降采样。
                                高优先级页面仍保持最低保障采样率。
                            </div>
                        </div>
                    </div>
                    <div v-if="samplingStats.warnings.budget"
                        class="bg-amber-50 border-l-4 border-amber-500 p-4 rounded-r-lg flex items-start space-x-3">
                        <i class="ri-pie-chart-box-line text-amber-600 text-xl mt-0.5"></i>
                        <div class="flex-1">
                            <div class="font-semibold text-amber-800">预算即将耗尽</div>
                            <div class="text-sm text-amber-700 mt-1">
                                本小时全量样本已消耗 <b>{{ samplingStats.current.budget_used_pct }}%</b> 的小时预算。
                            </div>
                        </div>
                    </div>
                    <div v-if="samplingStats.warnings.db"
                        class="bg-orange-50 border-l-4 border-orange-500 p-4 rounded-r-lg flex items-start space-x-3">
                        <i class="ri-database-line text-orange-600 text-xl mt-0.5"></i>
                        <div class="flex-1">
                            <div class="font-semibold text-orange-800">数据库容量告警</div>
                            <div class="text-sm text-orange-700 mt-1">
                                数据库已使用 <b>{{ samplingStats.current.db_size_mb }}MB</b>，
                                达上限的 <b>{{ samplingStats.current.db_used_pct }}%</b>。
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 第一行：预算仪表板 -->
                <div v-if="samplingStats" class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    <!-- 预算消耗 -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-semibold text-gray-800 flex items-center">
                                <i class="ri-time-line text-brand-600 mr-2"></i>本小时预算消耗
                            </h3>
                            <span :class="['text-xs font-medium px-2 py-1 rounded',
                                samplingStats.warnings.budget ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700']">
                                {{ samplingStats.current.budget_used_pct }}%
                            </span>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <div class="flex justify-between text-xs text-gray-500 mb-1">
                                    <span>全量样本 (Full)
                                    </span>
                                    <span class="font-medium">
                                        {{ samplingStats.current.hour.full }} / {{ samplingStats.budget.per_hour }}
                                    </span>
                                </div>
                                <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-emerald-400 to-emerald-600 transition-all"
                                        :style="{ width: Math.min(100, samplingStats.current.budget_used_pct) + '%' }">
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between text-xs text-gray-500 mb-1">
                                    <span>轻量指标 (Light)</span>
                                    <span class="font-medium">{{ samplingStats.current.hour.light }}</span>
                                </div>
                                <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-amber-400 to-amber-600"
                                        :style="{ width: Math.min(100, samplingStats.current.hour.light /
                                            Math.max(1, samplingStats.budget.per_hour * 2) * 100) + '%' }">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 pt-4 border-t border-gray-100 grid grid-cols-3 text-center">
                            <div>
                                <div class="text-lg font-bold text-gray-900">
                                    {{ samplingStats.current.hour.total }}
                                </div>
                                <div class="text-xs text-gray-500">小时总量</div>
                            </div>
                            <div>
                                <div class="text-lg font-bold text-emerald-600">
                                    {{ samplingStats.current.day.total }}
                                </div>
                                <div class="text-xs text-gray-500">今日总量</div>
                            </div>
                            <div>
                                <div class="text-lg font-bold text-brand-600">
                                    {{ samplingStats.current.all_time.total }}
                                </div>
                                <div class="text-xs text-gray-500">累计总量</div>
                            </div>
                        </div>
                    </div>

                    <!-- 数据库容量 -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-semibold text-gray-800 flex items-center">
                                <i class="ri-database-2-line text-orange-600 mr-2"></i>数据库容量
                            </h3>
                            <span :class="['text-xs font-medium px-2 py-1 rounded',
                                samplingStats.warnings.db ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700']">
                                {{ samplingStats.current.db_size_mb }}MB / {{ samplingStats.budget.db_size_limit_mb }}MB
                            </span>
                        </div>
                        <div class="relative pt-2 pb-4">
                            <div class="h-4 bg-gray-100 rounded-full overflow-hidden">
                                <div class="h-full bg-gradient-to-r from-orange-400 via-amber-400 to-rose-500 transition-all"
                                    :style="{ width: Math.min(100, samplingStats.current.db_used_pct) + '%' }">
                                </div>
                            </div>
                            <div class="mt-2 flex justify-between text-xs text-gray-500">
                                <span>0</span>
                                <span>警戒线 {{ samplingStats.budget.warn_threshold_pct }}%</span>
                                <span>上限</span>
                            </div>
                        </div>
                        <div class="mt-2 pt-4 border-t border-gray-100 grid grid-cols-2 gap-3">
                            <div class="bg-slate-50 rounded-lg p-3">
                                <div class="text-xs text-gray-500">全量记录</div>
                                <div class="text-xl font-bold text-gray-800 mt-1">
                                    {{ samplingStats.current.all_time.full }}
                                </div>
                            </div>
                            <div class="bg-slate-50 rounded-lg p-3">
                                <div class="text-xs text-gray-500">轻量记录</div>
                                <div class="text-xl font-bold text-gray-800 mt-1">
                                    {{ samplingStats.current.all_time.light }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 采样分布 -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-semibold text-gray-800 flex items-center">
                                <i class="ri-pulse-line text-rose-600 mr-2"></i>采样级别分布
                            </h3>
                            <span :class="['text-xs font-medium px-2 py-1 rounded',
                                samplingStats.warnings.spike ? 'bg-red-100 text-red-700 animate-pulse' :
                                                        'bg-slate-100 text-slate-700']">
                                {{ samplingStats.warnings.spike ? '突增模式' : '正常模式' }}
                            </span>
                        </div>
                        <div class="space-y-3">
                            <div class="flex items-center">
                                <div class="w-24 text-xs text-gray-600">全量追踪</div>
                                <div class="flex-1 h-5 bg-gray-100 rounded overflow-hidden mx-2 relative">
                                    <div class="h-full bg-emerald-500 transition-all"
                                        :style="{ width: (samplingStats.current.all_time.full /
                                            Math.max(1, samplingStats.current.all_time.total) * 100) + '%' }">
                                    </div>
                                </div>
                                <div class="w-16 text-right text-xs font-medium text-gray-700">
                                    {{ samplingStats.level_distribution?.full || 0 }}
                                </div>
                            </div>
                            <div class="flex items-center">
                                <div class="w-24 text-xs text-gray-600">轻量指标</div>
                                <div class="flex-1 h-5 bg-gray-100 rounded overflow-hidden mx-2">
                                    <div class="h-full bg-amber-500 transition-all"
                                        :style="{ width: (samplingStats.current.all_time.light /
                                            Math.max(1, samplingStats.current.all_time.total) * 100) + '%' }">
                                    </div>
                                </div>
                                <div class="w-16 text-right text-xs font-medium text-gray-700">
                                    {{ samplingStats.current.all_time.light }}
                                </div>
                            </div>
                            <div class="flex items-center">
                                <div class="w-24 text-xs text-gray-600">跳过(估算)
                                </div>
                                <div class="flex-1 h-5 bg-gray-100 rounded overflow-hidden mx-2">
                                    <div class="h-full bg-gray-400 transition-all"></div>
                                </div>
                                <div class="w-16 text-right text-xs font-medium text-gray-400">—</div>
                            </div>
                        </div>
                        <div class="mt-4 pt-4 border-t border-gray-100 space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">突增触发阈值</span>
                                <span class="font-medium">{{ samplingStats.budget.spike_threshold_pct }}%</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">当前/基准比</span>
                                <span :class="['font-medium',
                                    samplingStats.current.spike_ratio > samplingStats.budget.spike_threshold_pct ?
                                                            'text-red-600' : 'text-emerald-600']">
                                    {{ samplingStats.current.spike_ratio }}%
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 全局参数 -->
                <div v-if="samplingMeta" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-8">
                    <div class="flex items-center justify-between mb-5">
                        <div>
                            <h3 class="font-semibold text-gray-800 flex items-center text-lg">
                                <i class="ri-settings-3-line text-brand-600 mr-2"></i>全局采样参数
                            </h3>
                            <p class="text-xs text-gray-500 mt-1">调整预算与阈值会实时影响采样决策引擎的行为
                            </p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                每小时全量样本预算
                                <span class="text-brand-600 ml-1 font-mono">{{ localMeta.global_budget_per_hour }}</span>
                            </label>
                            <input type="range" v-model.number="localMeta.global_budget_per_hour" min="1000"
                                max="200000" step="1000" @change="metaDirty = true"
                                class="w-full accent-brand-600">
                            <div class="flex justify-between text-xs text-gray-400 mt-1">
                                <span>1k</span><span>200k</span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                预算告警阈值
                                <span class="text-brand-600 ml-1 font-mono">{{ localMeta.budget_warn_threshold }}%</span>
                            </label>
                            <input type="range" v-model.number="localMeta.budget_warn_threshold" min="50"
                                max="100" step="5" @change="metaDirty = true"
                                class="w-full accent-brand-600">
                            <div class="flex justify-between text-xs text-gray-400 mt-1">
                                <span>50%</span><span>100%</span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                数据库容量上限
                                <span class="text-brand-600 ml-1 font-mono">{{ localMeta.db_size_limit_mb }} MB</span>
                            </label>
                            <input type="range" v-model.number="localMeta.db_size_limit_mb" min="50" max="5000"
                                step="50" @change="metaDirty = true" class="w-full accent-brand-600">
                            <div class="flex justify-between text-xs text-gray-400 mt-1">
                                <span>50MB</span><span>5GB</span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                流量突增阈值
                                <span class="text-brand-600 ml-1 font-mono">{{ localMeta.traffic_spike_threshold }}%</span>
                            </label>
                            <input type="range" v-model.number="localMeta.traffic_spike_threshold" min="110"
                                max="500" step="10" @change="metaDirty = true"
                                class="w-full accent-brand-600">
                            <p class="text-xs text-gray-400 mt-1">超过基准流量的百分比触发应急降采样
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                突增应急采样率
                                <span class="text-brand-600 ml-1 font-mono">{{ localMeta.emergency_sample_rate }}%</span>
                            </label>
                            <input type="range" v-model.number="localMeta.emergency_sample_rate" min="1"
                                max="50" step="1" @change="metaDirty = true"
                                class="w-full accent-brand-600">
                            <p class="text-xs text-gray-400 mt-1">突增模式下基础采样率上限
                            </p>
                        </div>
                        <div class="flex items-end">
                            <div class="flex space-x-3 w-full">
                                <button @click="saveMeta" :disabled="!metaDirty || savingMeta"
                                    class="flex-1 px-4 py-2 bg-brand-600 text-white rounded-lg hover:bg-brand-700 transition disabled:opacity-50 shadow-sm">
                                    <i class="ri-save-line mr-1"></i>
                                    {{ savingMeta ? '保存中...' : '保存全局参数' }}
                                </button>
                                <button @click="refreshAllSampling"
                                    class="px-4 py-2 border border-gray-200 rounded-lg hover:bg-gray-50 transition">
                                    <i class="ri-refresh-line"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 规则配置表 -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-8">
                    <div
                        class="px-6 py-4 border-b border-gray-100 flex flex-col md:flex-row md:items-center md:justify-between space-y-3 md:space-y-0">
                        <div>
                            <h3 class="font-semibold text-gray-800 flex items-center text-lg">
                                <i class="ri-list-check-2 text-brand-600 mr-2"></i>采样规则配置
                            </h3>
                            <p class="text-xs text-gray-500 mt-1">
                                按优先级匹配 URL，命中后根据页面价值、错误率、授权比例动态调整采样率
                            </p>
                        </div>
                        <button @click="openRuleEditor()"
                            class="px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition shadow-sm whitespace-nowrap">
                            <i class="ri-add-line mr-1"></i>新建规则
                        </button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm border-collapse">
                            <thead>
                                <tr class="bg-slate-50 text-gray-600 text-xs uppercase tracking-wider">
                                    <th class="px-5 py-3 text-left font-semibold">状态</th>
                                    <th class="px-5 py-3 text-left font-semibold">规则名称</th>
                                    <th class="px-5 py-3 text-left font-semibold">URL 匹配模式</th>
                                    <th class="px-5 py-3 text-center font-semibold">页面价值</th>
                                    <th class="px-5 py-3 text-center font-semibold">错误率阈值</th>
                                    <th class="px-5 py-3 text-center font-semibold">授权比例</th>
                                    <th class="px-5 py-3 text-center font-semibold">全量采样率</th>
                                    <th class="px-5 py-3 text-center font-semibold">轻量采样率</th>
                                    <th class="px-5 py-3 text-center font-semibold">最低保障</th>
                                    <th class="px-5 py-3 text-center font-semibold">优先级</th>
                                    <th class="px-5 py-3 text-right font-semibold">操作</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="rule in samplingRules" :key="rule.id" :class="['transition',
                                    rule.enabled ? 'hover:bg-slate-50' : 'bg-gray-50/60 opacity-60']">
                                    <td class="px-5 py-4">
                                        <label class="inline-flex items-center cursor-pointer">
                                            <input type="checkbox" :checked="rule.enabled == 1"
                                                @change="toggleRule(rule)" class="sr-only peer">
                                            <div
                                                class="relative w-10 h-5 bg-gray-200 rounded-full peer peer-checked:bg-emerald-500 transition">
                                                <div
                                                    class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition peer-checked:translate-x-5 shadow-sm">
                                                </div>
                                            </div>
                                        </label>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-medium text-gray-900">{{ rule.rule_name }}</div>
                                        <div v-if="rule.description"
                                            class="text-xs text-gray-500 mt-0.5">
                                            {{ rule.description }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <code
                                            class="text-xs bg-slate-100 text-slate-700 px-2 py-1 rounded font-mono break-all max-w-xs inline-block">
                                            {{ rule.url_pattern }}
                                        </code>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium',
                                            rule.page_value >= 80 ? 'bg-rose-100 text-rose-700' :
                                            rule.page_value >= 50 ? 'bg-amber-100 text-amber-700' :
                                            'bg-slate-100 text-slate-600']">
                                            {{ rule.page_value }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-center text-gray-600 font-mono">
                                        {{ rule.error_rate_threshold }}%
                                    </td>
                                    <td class="px-5 py-4 text-center text-gray-600 font-mono">
                                        {{ rule.auth_ratio }}%
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span :class="['font-bold',
                                            rule.base_sample_rate >= 80 ? 'text-emerald-600' :
                                            rule.base_sample_rate >= 40 ? 'text-amber-600' : 'text-rose-600']">
                                            {{ rule.base_sample_rate }}%
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span :class="['font-medium',
                                            rule.light_sample_rate >= 80 ? 'text-emerald-600' :
                                            rule.light_sample_rate >= 40 ? 'text-sky-600' : 'text-gray-500']">
                                            {{ rule.light_sample_rate }}%
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-center text-gray-600 font-mono">
                                        {{ rule.min_sample_rate }}%
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span
                                            class="inline-block w-8 text-center rounded px-1 py-0.5 text-xs"
                                            :style="{
                                                backgroundColor: `hsl(${220 - rule.priority * 1.8}, 80%, ${90 - rule.priority * 0.4}%)`,
                                                color: '#1e40af'
                                            }">
                                            {{ rule.priority }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-right space-x-1">
                                        <button @click="openRuleEditor(rule)"
                                            class="text-brand-600 hover:text-brand-800 text-xs px-2 py-1 rounded hover:bg-brand-50">编辑</button>
                                        <button @click="deleteRule(rule)"
                                            class="text-rose-600 hover:text-rose-800 text-xs px-2 py-1 rounded hover:bg-rose-50">删除</button>
                                    </td>
                                </tr>
                                <tr v-if="samplingRules.length === 0">
                                    <td colspan="11" class="px-5 py-12 text-center text-gray-400">
                                        <i class="ri-file-list-3-line text-4xl block mb-2 opacity-40"></i>
                                        暂无规则，点击右上角新建
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 能力预估面板 -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div
                        class="flex flex-col md:flex-row md:items-center md:justify-between mb-6 space-y-3 md:space-y-0">
                        <div>
                            <h3 class="font-semibold text-gray-800 flex items-center text-lg">
                                <i class="ri-crystal-ball-line text-purple-600 mr-2"></i>调整影响预估
                            </h3>
                            <p class="text-xs text-gray-500 mt-1">
                                拖动下方滑块实时预览调整采样预算或单规则采样率后，将失去哪些分析能力
                            </p>
                        </div>
                        <button @click="runEstimate"
                            class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition shadow-sm">
                            <i class="ri-play-circle-line mr-1"></i>重新计算预估
                        </button>
                    </div>

                    <!-- 预算滑块 -->
                    <div class="bg-purple-50/60 border border-purple-100 rounded-lg p-5 mb-6">
                        <div class="flex items-center justify-between mb-3">
                            <label class="text-sm font-medium text-purple-900">
                                <i class="ri-funds-line mr-1"></i>预估小时全量预算
                            </label>
                            <div class="flex items-center space-x-2">
                                <span class="text-2xl font-bold text-purple-700 font-mono">
                                    {{ estimateBudget }}
                                </span>
                                <span class="text-sm text-purple-500">条/小时</span>
                            </div>
                        </div>
                        <input type="range" v-model.number="estimateBudget" min="1000" max="200000" step="1000"
                            @input="estimateDirty = true" class="w-full accent-purple-600">
                        <div class="flex justify-between text-xs text-purple-400 mt-2">
                            <span>1,000</span>
                            <span>当前: {{ samplingStats?.budget?.per_hour || 50000 }}</span>
                            <span>200,000</span>
                        </div>
                        <div v-if="estimateResult"
                            class="mt-4 pt-4 border-t border-purple-200">
                            <div class="flex items-center space-x-3">
                                <div :class="[
                                    'flex-shrink-0 w-14 h-14 rounded-full flex items-center justify-center text-white text-lg font-bold',
                                    estimateResult.summary.overall_impact === 'high' ? 'bg-rose-500' :
                                    estimateResult.summary.overall_impact === 'medium' ? 'bg-amber-500' : 'bg-emerald-500'
                                ]">
                                    {{ estimateResult.summary.overall_impact === 'high' ? '高' :
                                        estimateResult.summary.overall_impact === 'medium' ? '中' : '低' }}
                                </div>
                                <div class="flex-1">
                                    <div class="font-semibold text-gray-800">
                                        {{ estimateResult.summary.impact_description }}
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        受影响规则 <b class="text-gray-700">{{ estimateResult.summary.rules_affected }}</b> 个
                                        ·丢失分析维度 <b class="text-gray-700">{{ estimateResult.summary.capabilities_lost_count }}</b> 项
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 各规则滑块 -->
                    <div v-if="estimateResult" class="space-y-4 mb-6">
                        <div v-for="r in estimateResult.rules" :key="r.rule_name"
                            class="border border-gray-200 rounded-lg p-4 transition"
                            :class="[
                                r.target_full_rate < r.current_full_rate ? 'bg-rose-50/40 border-rose-200' : '',
                                r.target_full_rate > r.current_full_rate ? 'bg-emerald-50/40 border-emerald-200' : ''
                            ]">
                            <div
                                class="flex flex-col md:flex-row md:items-center md:justify-between mb-3 space-y-2 md:space-y-0">
                                <div>
                                    <div class="font-medium text-gray-800 flex items-center space-x-2">
                                        <span>{{ r.rule_name }}</span>
                                        <span
                                            class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">页面价值
                                            {{ r.page_value }}
                                        </span>
                                        <span
                                            class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">优先级
                                            {{ r.priority }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-3 text-sm">
                                    <div class="text-center">
                                        <div class="text-gray-500 text-xs">全量</div>
                                        <div class="flex items-center space-x-1">
                                            <span class="text-emerald-700 font-mono font-bold">{{ r.current_full_rate }}%</span>
                                            <i class="ri-arrow-right-s-line text-gray-400"></i>
                                            <span :class="['font-mono font-bold',
                                                r.target_full_rate < r.current_full_rate ? 'text-rose-600' :
                                                r.target_full_rate > r.current_full_rate ? 'text-emerald-600' : 'text-gray-700']">
                                                {{ r.target_full_rate }}%
                                            </span>
                                        </div>
                                    </div>
                                    <div class="w-px h-6 bg-gray-200"></div>
                                    <div class="text-center">
                                        <div class="text-gray-500 text-xs">轻量</div>
                                        <div class="flex items-center space-x-1">
                                            <span class="text-sky-700 font-mono">{{ r.current_light_rate }}%</span>
                                            <i class="ri-arrow-right-s-line text-gray-400"></i>
                                            <span :class="['font-mono',
                                                r.target_light_rate < r.current_light_rate ? 'text-rose-500' :
                                                r.target_light_rate > r.current_light_rate ? 'text-sky-600' : 'text-gray-600']">
                                                {{ r.target_light_rate }}%
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <input type="range" v-model.number="estimateOverrides[r.rule_name]"
                                :min="r.min_sample_rate" :max="100" step="1" @input="estimateDirty = true"
                                class="w-full accent-purple-600">
                            <div class="flex justify-between text-xs text-gray-400 mt-1">
                                <span>最低 {{ r.min_sample_rate }}%</span><span>调整全量采样率</span><span>100%</span>
                            </div>
                        </div>
                    </div>

                    <!-- 丢失能力清单 -->
                    <div v-if="estimateResult && estimateResult.lost_capabilities.length > 0"
                        class="border-2 border-rose-200 rounded-lg p-5 bg-rose-50/40">
                        <div class="flex items-center space-x-2 mb-4">
                            <i class="ri-alert-line text-rose-600 text-xl"></i>
                            <h4 class="font-semibold text-rose-900">预计将丢失以下分析能力</h4>
                        </div>
                        <div class="space-y-3">
                            <div v-for="item in estimateResult.lost_capabilities" :key="item.rule"
                                class="bg-white rounded-lg p-4 border"
                                :class="item.severity === 'high' ? 'border-rose-200' :
                                    item.severity === 'medium' ? 'border-amber-200' : 'border-sky-200'">
                                <div class="flex items-center justify-between mb-3">
                                    <div class="font-medium text-gray-900 flex items-center space-x-2">
                                        <i :class="['mr-1',
                                            item.severity === 'high' ? 'ri-alarm-warning-line text-rose-500' :
                                            item.severity === 'medium' ? 'ri-alert-line text-amber-500' :
                                            'ri-information-line text-sky-500']"></i>
                                        {{ item.rule }}
                                        <span :class="['text-xs px-2 py-0.5 rounded-full font-medium',
                                            item.severity === 'high' ? 'bg-rose-100 text-rose-700' :
                                            item.severity === 'medium' ? 'bg-amber-100 text-amber-700' :
                                            'bg-sky-100 text-sky-700']">
                                            {{ item.severity === 'high' ? '严重' :
                                               item.severity === 'medium' ? '中等' : '轻微' }}
                                        </span>
                                    </div>
                                    <div class="text-sm flex items-center space-x-3">
                                        <div class="text-center">
                                            <div class="text-gray-400 text-xs">全量</div>
                                            <div class="flex items-center">
                                                <span class="text-gray-500 font-mono">{{ item.current_full_rate }}%</span>
                                                <i class="ri-arrow-right-s-line text-gray-300 mx-0.5"></i>
                                                <span class="font-bold text-rose-600 font-mono">{{ item.target_full_rate }}%</span>
                                            </div>
                                        </div>
                                        <div class="text-center">
                                            <div class="text-gray-400 text-xs">轻量</div>
                                            <div class="flex items-center">
                                                <span class="text-gray-500 font-mono text-xs">{{ item.current_light_rate }}%</span>
                                                <i class="ri-arrow-right-s-line text-gray-300 mx-0.5"></i>
                                                <span class="font-mono text-rose-500 text-xs">{{ item.target_light_rate }}%</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span v-for="cap in item.lost_capabilities" :key="cap"
                                        :class="['inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium border',
                                            item.severity === 'high' ? 'bg-rose-100 text-rose-800 border-rose-200' :
                                            item.severity === 'medium' ? 'bg-amber-100 text-amber-800 border-amber-200' :
                                            'bg-sky-100 text-sky-700 border-sky-200']">
                                        <i class="ri-close-circle-line mr-1"></i>{{ cap }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else-if="estimateResult"
                        class="border-2 border-emerald-200 rounded-lg p-5 bg-emerald-50/40 text-center">
                        <i class="ri-shield-check-line text-4xl text-emerald-500 mb-2 block"></i>
                        <div class="font-semibold text-emerald-800">当前配置下无显著能力丢失
                        </div>
                        <div class="text-sm text-emerald-700 mt-1">
                            所有规则的核心分析维度均可完整保留
                        </div>
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
                            <span class="font-mono text-gray-800 break-all">{{ formatValue(currentItem[field.key]) }}</span>
                        </div>
                    </div>
                    <div class="px-6 py-4 bg-amber-50 border-t border-amber-100 text-xs text-amber-700 space-y-1">
                        <div class="font-semibold mb-2"><i class="ri-information-line mr-1"></i>数据准确性说明</div>
                        <div>• <b>系统版本</b>：Chrome 90+ 将 macOS 版本冻结为 10.15.7</div>
                        <div>• <b>设备内存</b>：浏览器返回模糊值（如 4/8GB）</div>
                        <div>• <b>IP 定位</b>：本地/内网 IP 无法定位，需部署到公网</div>
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
                            class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-brand-500 focus:border-brand-500"></textarea>
                    </div>
                </div>
                <div class="px-6 py-4 border-t bg-gray-50 flex justify-end space-x-3">
                    <button @click="showRemarkModal = false"
                        class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded">取消</button>
                    <button @click="saveRemark"
                        class="px-4 py-2 bg-brand-600 text-white rounded hover:bg-brand-700">保存</button>
                </div>
            </div>
        </div>

        <!-- 规则编辑弹窗 -->
        <div v-if="showRuleModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
            @click.self="showRuleModal = false">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col">
                <div class="px-6 py-4 border-b flex justify-between items-center flex-shrink-0">
                    <h3 class="text-lg font-bold">{{ ruleForm.id ? '编辑规则' : '新建采样规则' }}</h3>
                    <button @click="showRuleModal = false" class="text-gray-400 hover:text-gray-600">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-6 space-y-5">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">规则名称 *</label>
                            <input type="text" v-model="ruleForm.rule_name" placeholder="例如：高价值支付页"
                                class="w-full border rounded-lg p-2.5 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">URL 匹配模式 *</label>
                            <input type="text" v-model="ruleForm.url_pattern"
                                placeholder="多个用逗号分隔，支持 * 通配符，如 */checkout*, *user*"
                                class="w-full border rounded-lg p-2.5 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 font-mono text-sm">
                            <p class="text-xs text-gray-500 mt-1">系统按优先级从上到下匹配，命中即停止</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">页面价值 (0-100)</label>
                            <input type="number" v-model.number="ruleForm.page_value" min="0" max="100"
                                class="w-full border rounded-lg p-2.5 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                            <div class="mt-2 h-1.5 bg-gradient-to-r from-gray-300 via-amber-400 to-rose-500 rounded"></div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">优先级 (0-100)</label>
                            <input type="number" v-model.number="ruleForm.priority" min="0" max="100"
                                class="w-full border rounded-lg p-2.5 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                            <p class="text-xs text-gray-500 mt-1">越大越先匹配</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">错误率阈值 (%)</label>
                            <input type="number" v-model.number="ruleForm.error_rate_threshold" min="0" max="100"
                                class="w-full border rounded-lg p-2.5 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                            <p class="text-xs text-gray-500 mt-1">超过则自动提升采样率 1.5x</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">授权比例 (%)</label>
                            <input type="number" v-model.number="ruleForm.auth_ratio" min="0" max="100"
                                class="w-full border rounded-lg p-2.5 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                            <p class="text-xs text-gray-500 mt-1">该类页面授权用户占比，越高页面价值加成越大</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">全量采样率 (%)</label>
                            <input type="number" v-model.number="ruleForm.base_sample_rate" min="0" max="100"
                                class="w-full border rounded-lg p-2.5 font-bold text-brand-700 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                            <p class="text-xs text-gray-500 mt-1">完整 30+ 字段入库的比例</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">轻量采样率 (%)</label>
                            <input type="number" v-model.number="ruleForm.light_sample_rate" min="0" max="100"
                                class="w-full border rounded-lg p-2.5 text-sky-700 focus:ring-2 focus:ring-sky-500 focus:border-sky-500">
                            <p class="text-xs text-gray-500 mt-1">仅 10 个核心字段入库，低优先级页面可设高</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">最低保障采样率 (%)</label>
                            <input type="number" v-model.number="ruleForm.min_sample_rate" min="0" max="100"
                                class="w-full border rounded-lg p-2.5 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                            <p class="text-xs text-gray-500 mt-1">即使流量突增全量也不低于此值</p>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">规则描述</label>
                            <textarea v-model="ruleForm.description" rows="2"
                                class="w-full border rounded-lg p-2.5 focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
                                placeholder="简要描述该规则的用途和匹配范围..."></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label class="inline-flex items-center cursor-pointer">
                                <input type="checkbox" v-model="ruleForm.enabled" :true-value="1" :false-value="0"
                                    class="sr-only peer">
                                <div class="relative w-11 h-6 bg-gray-200 rounded-full peer peer-checked:bg-emerald-500 transition">
                                    <div class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full transition peer-checked:translate-x-5 shadow">
                                    </div>
                                </div>
                                <span class="ml-3 text-sm font-medium text-gray-700">启用此规则</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 border-t bg-gray-50 flex justify-end space-x-3 flex-shrink-0">
                    <button @click="showRuleModal = false"
                        class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded">取消</button>
                    <button @click="saveRule" :disabled="savingRule"
                        class="px-4 py-2 bg-brand-600 text-white rounded hover:bg-brand-700 disabled:opacity-50">
                        {{ savingRule ? '保存中...' : '保存规则' }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const { createApp, ref, reactive, onMounted, watch } = Vue;
        createApp({
            setup() {
                const activeTab = ref('visitors');
                const visitors = ref([]);
                const total = ref(0);
                const page = ref(1);
                const totalPages = ref(1);
                const searchQuery = ref('');
                const filterLevel = ref('');
                const stats = ref({ total: 0, today: 0 });

                const showDetailModal = ref(false);
                const showRemarkModal = ref(false);
                const currentItem = ref({});
                const remarkForm = ref({ id: null, remark: '' });
                const currentTime = ref('');

                const samplingStats = ref(null);
                const samplingMeta = ref(null);
                const samplingRules = ref([]);
                const localMeta = reactive({});
                const metaDirty = ref(false);
                const savingMeta = ref(false);

                const showRuleModal = ref(false);
                const savingRule = ref(false);
                const ruleForm = reactive({
                    id: null, rule_name: '', url_pattern: '', page_value: 50,
                    error_rate_threshold: 5, auth_ratio: 0, base_sample_rate: 50,
                    light_sample_rate: 50, min_sample_rate: 5, priority: 50,
                    enabled: 1, description: ''
                });

                const estimateBudget = ref(50000);
                const estimateOverrides = reactive({});
                const estimateResult = ref(null);
                const estimateDirty = ref(false);
                let estimateTimer = null;

                const detailFields = [
                    { key: 'id', label: 'ID' },
                    { key: 'sample_level', label: '采样级别' },
                    { key: 'page_value', label: '页面价值' },
                    { key: 'error_rate', label: '当时错误率(%)' },
                    { key: 'auth_ratio', label: '授权状态(%)' },
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
                        if (json.status === 'success') stats.value = { total: json.total, today: json.today };
                    } catch (e) { }
                };

                const fetchData = async (p = 1) => {
                    try {
                        const res = await fetch(
                            `/api.php?action=list&page=${p}&search=${searchQuery.value}&sample_level=${filterLevel.value}`
                        );
                        const json = await res.json();
                        if (json.status === 'success') {
                            visitors.value = json.data;
                            total.value = json.total;
                            page.value = json.page;
                            totalPages.value = json.pages;
                        }
                    } catch (e) { }
                    fetchStats();
                };

                const fetchSamplingStats = async () => {
                    try {
                        const res = await fetch('/api.php?action=sampling_stats');
                        const json = await res.json();
                        if (json.status === 'success') {
                            samplingStats.value = json;
                            if (!estimateBudget.value || estimateBudget.value === 50000) {
                                estimateBudget.value = parseInt(json.budget.per_hour) || 50000;
                            }
                        }
                    } catch (e) { }
                };

                const fetchSamplingMeta = async () => {
                    try {
                        const res = await fetch('/api.php?action=sampling_meta');
                        const json = await res.json();
                        if (json.status === 'success') {
                            samplingMeta.value = json.data;
                            Object.keys(json.data).forEach(k => {
                                localMeta[k] = isNaN(json.data[k]) ? json.data[k] : Number(json.data[k]);
                            });
                            metaDirty.value = false;
                        }
                    } catch (e) { }
                };

                const fetchSamplingRules = async () => {
                    try {
                        const res = await fetch('/api.php?action=sampling_config');
                        const json = await res.json();
                        if (json.status === 'success') samplingRules.value = json.data;
                    } catch (e) { }
                };

                const saveMeta = async () => {
                    savingMeta.value = true;
                    try {
                        const payload = {};
                        ['global_budget_per_hour', 'budget_warn_threshold', 'db_size_limit_mb',
                            'traffic_spike_threshold', 'emergency_sample_rate'].forEach(k => {
                            if (localMeta[k] !== undefined) payload[k] = String(localMeta[k]);
                        });
                        const res = await fetch('/api.php?action=sampling_meta', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload)
                        });
                        const json = await res.json();
                        if (json.status === 'success') {
                            metaDirty.value = false;
                            await fetchSamplingStats();
                            await runEstimate();
                        }
                    } finally { savingMeta.value = false; }
                };

                const openRuleEditor = (rule = null) => {
                    if (rule) {
                        Object.keys(ruleForm).forEach(k => {
                            ruleForm[k] = rule[k] !== undefined ? rule[k] :
                                (k === 'enabled' ? 1 : (typeof ruleForm[k] === 'number' ? 0 : ''));
                        });
                        ruleForm.id = rule.id;
                    } else {
                        ruleForm.id = null;
                        ruleForm.rule_name = '';
                        ruleForm.url_pattern = '';
                        ruleForm.page_value = 50;
                        ruleForm.error_rate_threshold = 5;
                        ruleForm.auth_ratio = 0;
                        ruleForm.base_sample_rate = 50;
                        ruleForm.light_sample_rate = 50;
                        ruleForm.min_sample_rate = 5;
                        ruleForm.priority = 50;
                        ruleForm.enabled = 1;
                        ruleForm.description = '';
                    }
                    showRuleModal.value = true;
                };

                const saveRule = async () => {
                    if (!ruleForm.rule_name || !ruleForm.url_pattern) {
                        alert('请填写规则名称和 URL 匹配模式'); return;
                    }
                    savingRule.value = true;
                    try {
                        const payload = {};
                        Object.keys(ruleForm).forEach(k => payload[k] = ruleForm[k]);
                        const res = await fetch('/api.php?action=sampling_config', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload)
                        });
                        const json = await res.json();
                        if (json.status === 'success') {
                            showRuleModal.value = false;
                            await fetchSamplingRules();
                            await fetchSamplingStats();
                            await runEstimate();
                        }
                    } finally { savingRule.value = false; }
                };

                const toggleRule = async (rule) => {
                    const newEnabled = rule.enabled == 1 ? 0 : 1;
                    try {
                        await fetch('/api.php?action=sampling_config', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: rule.id, enabled: newEnabled })
                        });
                        rule.enabled = newEnabled;
                        await runEstimate();
                    } catch (e) { }
                };

                const deleteRule = async (rule) => {
                    if (!confirm(`确定删除规则 "${rule.rule_name}" 吗？`)) return;
                    try {
                        const res = await fetch(`/api.php?action=sampling_config&id=${rule.id}`, { method: 'DELETE' });
                        const json = await res.json();
                        if (json.status === 'success') {
                            await fetchSamplingRules();
                            await runEstimate();
                        }
                    } catch (e) { }
                };

                const runEstimate = async () => {
                    try {
                        const overrides = {};
                        Object.keys(estimateOverrides).forEach(k => {
                            if (estimateOverrides[k] !== null && estimateOverrides[k] !== undefined) {
                                overrides[k] = estimateOverrides[k];
                            }
                        });
                        const res = await fetch('/api.php?action=sampling_estimate', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                budget_per_hour: estimateBudget.value,
                                rate_overrides: overrides
                            })
                        });
                        const json = await res.json();
                        if (json.status === 'success') {
                            estimateResult.value = json;
                            estimateDirty.value = false;
                        }
                    } catch (e) { }
                };

                const refreshAllSampling = () =>
                    Promise.all([fetchSamplingStats(), fetchSamplingMeta(), fetchSamplingRules()]).then(runEstimate);

                watch([estimateBudget], () => {
                    estimateDirty.value = true;
                    clearTimeout(estimateTimer);
                    estimateTimer = setTimeout(runEstimate, 400);
                }, { deep: true });

                watch(estimateOverrides, () => {
                    estimateDirty.value = true;
                    clearTimeout(estimateTimer);
                    estimateTimer = setTimeout(runEstimate, 400);
                }, { deep: true });

                const prevPage = () => { if (page.value > 1) fetchData(page.value - 1); };
                const nextPage = () => { if (page.value < totalPages.value) fetchData(page.value + 1); };
                const openDetail = (item) => { currentItem.value = item; showDetailModal.value = true; };
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
                        }
                    } catch (e) { alert('错误: ' + e.message); }
                };
                const getDeviceIcon = (item) => {
                    const os = (item.os || '').toLowerCase();
                    if (os.includes('mac') || os.includes('windows') || os.includes('linux'))
                        return 'ri-computer-line mr-1';
                    if (os.includes('android') || os.includes('ios') || os.includes('ipad'))
                        return 'ri-smartphone-line mr-1';
                    return 'ri-device-line mr-1';
                };
                const formatValue = (val) => {
                    if (val === null || val === undefined || val === '') return '-';
                    if (val === 0) return '0';
                    return val;
                };

                setInterval(() => {
                    const now = new Date();
                    currentTime.value = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}:${String(now.getSeconds()).padStart(2, '0')}`;
                }, 1000);

                setInterval(() => {
                    if (activeTab.value === 'sampling') fetchSamplingStats();
                }, 15000);

                onMounted(() => {
                    fetchData();
                    fetchStats();
                    refreshAllSampling();
                });

                return {
                    activeTab,
                    visitors, total, page, totalPages, searchQuery, filterLevel, stats,
                    showDetailModal, showRemarkModal, currentItem, remarkForm, currentTime,
                    detailFields, formatValue, fetchData, prevPage, nextPage, openDetail, editRemark, saveRemark, getDeviceIcon,
                    samplingStats, samplingMeta, samplingRules, localMeta, metaDirty, savingMeta,
                    saveMeta, refreshAllSampling,
                    showRuleModal, savingRule, ruleForm, openRuleEditor, saveRule, toggleRule, deleteRule,
                    estimateBudget, estimateOverrides, estimateResult, estimateDirty, runEstimate
                };
            }
        }).mount('#app');
    </script>
</body>
</html>
