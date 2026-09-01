import {reportChartAnimation, reportChartColors, safeSeries as sharedSafeSeries} from './chart-config.js';

export function safeSeries(values = []) {
    return sharedSafeSeries(values);
}

const tooltip = {backgroundColor: 'rgba(15,23,42,.95)', padding: 11, titleSpacing: 4, bodySpacing: 5, cornerRadius: 9, usePointStyle: true, boxPadding: 5};

export function workloadChartConfig(data = {}) {
    return {
        type: 'bar',
        data: {
            labels: Array.isArray(data.labels) ? data.labels : [],
            datasets: [{
                label: 'งานครบกำหนด',
                data: safeSeries(data.values),
                backgroundColor: reportChartColors.blue,
                borderRadius: 4,
                maxBarThickness: 54,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            // ใช้แอนิเมชันชุดเดียวกับหน้ารายงานอื่น chart-lifecycle จะปิดให้เองเมื่อผู้ใช้ตั้ง prefers-reduced-motion
            animation: reportChartAnimation,
            plugins: {legend: {display: false}, tooltip},
            scales: {
                x: {grid: {display: false}, border: {display: false}, ticks: {color: '#64748b'}},
                y: {beginAtZero: true, ticks: {precision: 0, color: '#64748b'}, border: {display: false}, grid: {color: 'rgba(148,163,184,.13)'}},
            },
        },
    };
}

/**
 * ไม่ต้องปั้นสไลซ์หลอกเมื่อไม่มีข้อมูลอีกต่อไป
 * การ์ดกราฟมีสถานะว่างของตัวเองแล้ว ซึ่งบอกผู้ใช้ตรง ๆ ดีกว่าวงกลมสีเทาที่คลิกอะไรไม่ได้
 */
export function priorityChartConfig(data = {}) {
    const values = safeSeries(data.values);

    return {
        type: 'doughnut',
        data: {
            labels: Array.isArray(data.labels) ? data.labels.map(String) : [],
            datasets: [{
                data: values,
                backgroundColor: Array.isArray(data.tones)
                    ? data.tones.map((tone) => reportChartColors[tone] ?? reportChartColors.gray)
                    : [],
                borderColor: '#fff',
                borderWidth: 3,
                hoverOffset: 6,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            animation: {...reportChartAnimation, animateRotate: true, animateScale: true},
            plugins: {
                legend: {display: false},
                tooltip: {
                    ...tooltip,
                    callbacks: {
                        label(context) {
                            const total = (context.dataset.data || []).reduce((sum, value) => sum + (Number(value) || 0), 0);
                            const value = Number(context.raw) || 0;
                            const share = total > 0 ? Math.round((value / total) * 100) : 0;

                            return `${context.label}: ${value} งาน (${share}%)`;
                        },
                    },
                },
            },
        },
    };
}
