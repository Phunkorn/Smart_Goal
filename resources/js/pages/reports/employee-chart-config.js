import {doughnutConfig, lineAnimations, normalizeReportChartData, reportChartAnimation, reportChartColors, safeSeries} from './chart-config.js';

export function buildEmployeeChartConfigs(data = {}) {
    const common = normalizeReportChartData(data);
    const onTime = data.onTime || {};
    const eligible = Number.isFinite(Number(onTime.eligible)) ? Number(onTime.eligible) : 0;
    const onTimeValues = eligible > 0 ? safeSeries([onTime.onTime, onTime.late]) : [];
    const scales = {x: {grid: {display: false}, border: {display: false}}, y: {beginAtZero: true, ticks: {precision: 0}, border: {display: false}, grid: {color: 'rgba(148,163,184,.16)'}}};
    return {
        trend: {type: 'line', data: {labels: common.trend.labels, datasets: [{label: 'งานที่สร้าง', data: common.trend.created, borderColor: reportChartColors.blue, backgroundColor: 'rgba(35,117,237,.1)', fill: true, tension: .35, pointRadius: 3, pointHoverRadius: 5}, {label: 'งานที่เสร็จ', data: common.trend.completed, borderColor: reportChartColors.green, backgroundColor: 'rgba(18,166,106,.08)', fill: true, tension: .35, pointRadius: 3, pointHoverRadius: 5}]}, options: {responsive: true, maintainAspectRatio: false, animation: reportChartAnimation, animations: lineAnimations, interaction: {mode: 'index', intersect: false}, plugins: {legend: {position: 'bottom', labels: {usePointStyle: true, boxWidth: 7}}}, scales}},
        status: doughnutConfig(common.status),
        completed: {type: 'bar', data: {labels: common.completed.labels, datasets: [{label: 'งานเสร็จ', data: common.completed.values, backgroundColor: reportChartColors.blue, borderRadius: 6, maxBarThickness: 42}]}, options: {responsive: true, maintainAspectRatio: false, animation: reportChartAnimation, plugins: {legend: {display: false}}, scales}},
        onTime: doughnutConfig({labels: ['ตรงเวลา', 'เกินกำหนด'], values: onTimeValues, colors: [reportChartColors.green, reportChartColors.red]}, {cutout: '72%'}),
        priority: doughnutConfig(common.priority),
    };
}
