export const reportChartColors = {
    gray: '#64748b',
    blue: '#2563eb',
    purple: '#7c3aed',
    green: '#079455',
    amber: '#d97706',
    red: '#dc2626',
};

const numberList = (values) => Array.isArray(values)
    ? values.map((value) => Number.isFinite(Number(value)) ? Number(value) : 0)
    : [];

export function normalizeReportChartData(data = {}) {
    const trend = data.trend || {};
    const status = data.status || {};

    return {
        trend: {
            labels: Array.isArray(trend.labels) ? trend.labels.map(String) : [],
            created: numberList(trend.created),
            completed: numberList(trend.completed),
        },
        status: {
            labels: Array.isArray(status.labels) ? status.labels.map(String) : [],
            values: numberList(status.values),
            colors: Array.isArray(status.tones)
                ? status.tones.map((tone) => reportChartColors[tone] || reportChartColors.gray)
                : [],
        },
    };
}

export function buildReportChartConfigs(data) {
    const normalized = normalizeReportChartData(data);

    return {
        trend: {
            type: 'line',
            data: {
                labels: normalized.trend.labels,
                datasets: [
                    {label: 'งานที่สร้าง', data: normalized.trend.created, borderColor: reportChartColors.blue, backgroundColor: 'rgba(37, 99, 235, .12)', fill: true, tension: .35},
                    {label: 'งานที่เสร็จ', data: normalized.trend.completed, borderColor: reportChartColors.green, backgroundColor: 'rgba(7, 148, 85, .08)', fill: true, tension: .35},
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {mode: 'index', intersect: false},
                plugins: {legend: {position: 'bottom', labels: {usePointStyle: true, boxWidth: 8}}},
                scales: {y: {beginAtZero: true, ticks: {precision: 0}, grid: {color: 'rgba(148, 163, 184, .16)'}}, x: {grid: {display: false}}},
            },
        },
        status: {
            type: 'doughnut',
            data: {
                labels: normalized.status.labels,
                datasets: [{data: normalized.status.values, backgroundColor: normalized.status.colors, borderWidth: 0, hoverOffset: 4}],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {legend: {display: false}},
            },
        },
    };
}
