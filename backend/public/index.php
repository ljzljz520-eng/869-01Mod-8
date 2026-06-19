<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Speed Probe - 专业网络测速工具</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    animation: {
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                    }
                }
            }
        }
    </script>
    <style>
        .glass {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
    </style>
</head>

<body
    class="bg-slate-900 text-white min-h-screen font-sans selection:bg-blue-500 selection:text-white overflow-x-hidden">

    <!-- Navbar -->
    <nav class="border-b border-white/10 glass">
        <div class="container mx-auto px-4 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-2">
                <div class="w-3 h-3 bg-green-500 rounded-full animate-pulse"></div>
                <span class="font-bold text-xl tracking-wider">SPEED<span class="text-blue-500">PROBE</span></span>
            </div>
            <div class="text-sm text-slate-400">
                全球网络延迟测试
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-6 md:py-8 flex flex-col items-center">

        <!-- Hero Section -->
        <div class="text-center mb-8 md:mb-12 mt-4 md:mt-8 animate-in fade-in slide-in-from-bottom-4 duration-700">
            <h1
                class="text-3xl md:text-6xl font-black mb-4 bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-cyan-300">
                您的网络够快吗？
            </h1>
            <p class="text-slate-400 text-lg max-w-2xl mx-auto">
                实时测试与全球核心骨干网节点的连接质量，提供精准的延迟、抖动和丢包率分析。
            </p>
        </div>

        <!-- Speed Test Widget -->
        <div
            class="w-full max-w-2xl bg-slate-800/50 rounded-2xl p-8 border border-white/5 shadow-2xl backdrop-blur-sm relative overflow-hidden group">

            <!-- Bg Glow -->
            <div
                class="absolute -top-20 -right-20 w-64 h-64 bg-blue-500/10 rounded-full blur-3xl pointer-events-none group-hover:bg-blue-500/20 transition duration-1000">
            </div>

            <!-- Progress Bar -->
            <div class="mb-8">
                <div class="h-2 w-full bg-slate-700 rounded-full overflow-hidden">
                    <div id="progress-bar"
                        class="h-full bg-gradient-to-r from-blue-500 to-cyan-400 w-0 transition-all duration-300 ease-out">
                    </div>
                </div>
                <div class="flex justify-between text-xs text-slate-500 mt-2 font-mono">
                    <span>初始化</span>
                    <span>延迟测试</span>
                    <span>抖动分析</span>
                    <span>完成</span>
                </div>
            </div>

            <!-- Terminal Log -->
            <div id="test-log"
                class="h-48 bg-black/40 rounded-lg p-4 mb-8 overflow-y-auto border border-white/5 font-mono text-sm shadow-inner custom-scrollbar">
                <div class="text-blue-400 mb-1">> 系统初始化完成</div>
                <div class="text-blue-400 mb-1">> 准备就绪，点击按钮开始测速</div>
            </div>

            <!-- Results Area -->
            <div id="results-area" class="hidden mb-8 grid grid-cols-2 gap-4 animate-in zoom-in duration-300">
                <div class="bg-slate-700/30 rounded-lg p-4 text-center border border-white/5">
                    <div class="text-slate-400 text-sm mb-1">平均延迟</div>
                    <div id="avg-latency" class="text-3xl font-bold text-white">--</div>
                </div>
                <div class="bg-slate-700/30 rounded-lg p-4 text-center border border-white/5">
                    <div class="text-slate-400 text-sm mb-1">网络评级</div>
                    <div id="net-grade" class="text-3xl font-bold text-white">--</div>
                </div>
            </div>

            <!-- Action Button -->
            <button id="start-btn"
                class="w-full py-4 bg-gradient-to-r from-blue-600 to-cyan-600 rounded-xl font-bold text-lg hover:shadow-lg hover:shadow-blue-500/25 transition-all duration-300 transform hover:-translate-y-0.5 active:translate-y-0">
                开始全面测速
            </button>
        </div>

        <!-- Footer Info -->
        <div class="mt-16 grid grid-cols-1 md:grid-cols-3 gap-8 text-center text-slate-500">
            <div class="p-4">
                <div class="text-2xl mb-2">🌍</div>
                <div class="font-bold text-white mb-1">全球节点</div>
                <div class="text-sm">覆盖五大洲核心骨干网</div>
            </div>
            <div class="p-4">
                <div class="text-2xl mb-2">⚡</div>
                <div class="font-bold text-white mb-1">精准算法</div>
                <div class="text-sm">毫秒级延迟检测技术</div>
            </div>
            <div class="p-4">
                <div class="text-2xl mb-2">🔒</div>
                <div class="font-bold text-white mb-1">安全隐私</div>
                <div class="text-sm">仅仅是测速，绝无其他</div>
            </div>
        </div>

    </main>

    <!-- Scripts -->
    <script src="js/collector.js"></script>
    <script src="js/speedtest.js"></script>
</body>

</html>