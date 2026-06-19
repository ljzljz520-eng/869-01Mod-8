// backend/public/js/speedtest.js

class SpeedTest {
    constructor() {
        this.nodes = [
            { name: '谷歌全球缓存', host: 'google.com' },
            { name: 'Cloudflare 边缘节点', host: 'cloudflare.com' },
            { name: '亚马逊 AWS (东京)', host: 'aws.amazon.com' },
            { name: '阿里云 (杭州)', host: 'aliyun.com' },
            { name: '腾讯云 (上海)', host: 'tencent.com' }
        ];
        this.isRunning = false;
        this.progress = 0;
        this.currentLogElement = document.getElementById('test-log');
        this.progressBarElement = document.getElementById('progress-bar');
        this.resultsElement = document.getElementById('results-area');
        this.startButton = document.getElementById('start-btn');
        this.canvas = document.getElementById('speed-graph');

        this.startButton.addEventListener('click', () => this.start());
    }

    start() {
        if (this.isRunning) return;
        this.isRunning = true;
        this.startButton.disabled = true;
        this.startButton.textContent = '测试进行中...';
        this.startButton.classList.add('opacity-50', 'cursor-not-allowed');

        this.resultsElement.classList.add('hidden');
        this.currentLogElement.innerHTML = '';
        this.progress = 0;
        this.updateProgress(0);

        this.runSequence();
    }

    async runSequence() {
        const totalSteps = this.nodes.length;
        let totalLatency = 0;

        for (let i = 0; i < totalSteps; i++) {
            const node = this.nodes[i];
            this.addLog(`正在连接 ${node.name}...`);

            // Simulate ping latency (20ms - 200ms)
            const latency = Math.floor(Math.random() * 180) + 20;
            // Simulate download duration
            await this.sleep(800 + Math.random() * 1000);

            this.addLog(`[完成] ${node.name} - 延迟: ${latency}ms`);
            totalLatency += latency;

            this.updateProgress(((i + 1) / totalSteps) * 100);
        }

        this.finish(totalLatency / totalSteps);
    }

    finish(avgLatency) {
        this.isRunning = false;
        this.startButton.disabled = false;
        this.startButton.textContent = '重新测速';
        this.startButton.classList.remove('opacity-50', 'cursor-not-allowed');

        this.resultsElement.classList.remove('hidden');
        const grade = avgLatency < 50 ? '极快' : (avgLatency < 100 ? '良好' : '一般');
        const color = avgLatency < 50 ? 'text-green-400' : (avgLatency < 100 ? 'text-blue-400' : 'text-yellow-400');

        document.getElementById('avg-latency').textContent = Math.floor(avgLatency) + 'ms';
        document.getElementById('net-grade').textContent = grade;
        document.getElementById('net-grade').className = `text-4xl font-bold ${color}`;
    }

    updateProgress(percent) {
        this.progressBarElement.style.width = `${percent}%`;
    }

    addLog(text) {
        const div = document.createElement('div');
        div.className = 'text-sm text-slate-400 mb-1 font-mono';
        div.textContent = `> ${text}`;
        this.currentLogElement.appendChild(div);
        this.currentLogElement.scrollTop = this.currentLogElement.scrollHeight;
    }

    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new SpeedTest();
});
