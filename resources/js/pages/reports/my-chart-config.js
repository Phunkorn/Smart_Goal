import { reportChartColors } from './chart-config.js';

export function safeSeries(values = []) {
    return values.map((value) => {
        const number = Number(value);
        return Number.isFinite(number) && number >= 0 ? number : 0;
    });
}

export function workloadChartConfig(data = {}) {
    return {
        type: 'bar',
        data: {
            labels: Array.isArray(data.labels) ? data.labels : [],
            datasets: [{
                label: 'งานครบกำหนด',
                data: safeSeries(data.values),
                backgroundColor: reportChartColors.blue,
                borderRadius: 7,
                maxBarThickness: 54,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, border: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0 }, border: { display: false }, grid: { color: '#EEF2FA' } },
            },
        },
    };
}

export function priorityChartConfig(data = {}) {
    const values = safeSeries(data.values);
    const hasData = values.some((value) => value > 0);

    return {
        type: 'doughnut',
        data: {
            labels: hasData && Array.isArray(data.labels) ? data.labels : ['ยังไม่มีข้อมูล'],
            datasets: [{
                data: hasData ? values : [1],
                backgroundColor: hasData
                    ? (Array.isArray(data.tones) ? data.tones.map((tone) => reportChartColors[tone] ?? reportChartColors.gray) : [])
                    : ['#EEF2FA'],
                borderColor: '#FFFFFF',
                borderWidth: 3,
                hoverOffset: 2,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            cutout: '68%',
            plugins: { legend: { display: false }, tooltip: { enabled: hasData } },
        },
    };
}
