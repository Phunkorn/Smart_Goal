import {doughnutConfig, lineAnimations, normalizeReportChartData, orderStatusSlices, reportChartAnimation, reportChartColors} from './chart-config.js';

export function buildEmployeeChartConfigs(data = {}) {
    const common = normalizeReportChartData(data);
    const scales = {
        x: {grid: {display: false}, border: {display: false}, ticks: {color: '#64748b'}},
        y: {beginAtZero: true, ticks: {precision: 0, color: '#64748b'}, border: {display: false}, grid: {color: 'rgba(148,163,184,.13)'}},
    };
    return {
        trend: {type: 'line', data: {labels: common.trend.labels, datasets: [{label: 'งานที่สร้าง', data: common.trend.created, borderColor: reportChartColors.blue, backgroundColor: 'rgba(29,78,216,.10)', borderWidth: 2, fill: true, tension: .35, pointRadius: 3, pointHoverRadius: 6, pointBackgroundColor: '#fff', pointBorderWidth: 2}, {label: 'งานที่เสร็จ', data: common.trend.completed, borderColor: reportChartColors.green, backgroundColor: 'rgba(5,150,105,.09)', borderWidth: 2, fill: true, tension: .35, pointRadius: 3, pointHoverRadius: 6, pointBackgroundColor: '#fff', pointBorderWidth: 2}]}, options: {responsive: true, maintainAspectRatio: false, animation: reportChartAnimation, animations: lineAnimations, interaction: {mode: 'index', intersect: false}, plugins: {legend: {position: 'bottom', labels: {usePointStyle: true, boxWidth: 7}}}, scales}},
        status: doughnutConfig(orderStatusSlices(common.status)),
        completed: {type: 'bar', data: {labels: common.completed.labels, datasets: [{label: 'งานเสร็จ', data: common.completed.values, backgroundColor: reportChartColors.blue, borderRadius: 4, maxBarThickness: 42}]}, options: {responsive: true, maintainAspectRatio: false, animation: reportChartAnimation, plugins: {legend: {display: false}}, scales}},
        priority: doughnutConfig(common.priority),
    };
}
