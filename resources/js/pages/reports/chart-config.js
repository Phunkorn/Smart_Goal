export const reportChartColors = {
    gray: '#94a3b8', blue: '#2375ed', purple: '#7c3aed', green: '#12a66a', amber: '#f59e0b', red: '#ef4444',
};

export const safeSeries = (values) => Array.isArray(values)
    ? values.map((value) => Number.isFinite(Number(value)) && Number(value) >= 0 ? Number(value) : 0)
    : [];
const safeLabels = (labels) => Array.isArray(labels) ? labels.map(String) : [];
const colorsFromTones = (tones) => Array.isArray(tones) ? tones.map((tone) => reportChartColors[tone] || reportChartColors.gray) : [];
export const reportChartAnimation = Object.freeze({duration: 460, easing: 'easeOutQuart'});
export const lineAnimations = Object.freeze({x: {duration: 500, easing: 'easeOutQuart'}, y: {duration: 420, easing: 'easeOutQuart'}});
const tooltip = {backgroundColor: 'rgba(22,35,61,.94)', padding: 10, titleSpacing: 4, bodySpacing: 4, cornerRadius: 8};
const cartesianPlugins = {legend: {position: 'bottom', labels: {usePointStyle: true, pointStyle: 'circle', boxWidth: 7, padding: 14}}, tooltip};
const cartesianScales = {x: {grid: {display: false}, border: {display: false}}, y: {beginAtZero: true, ticks: {precision: 0}, border: {display: false}, grid: {color: 'rgba(148,163,184,.16)'}}};

export function normalizeReportChartData(data = {}) {
    const trend = data.trend || {}, status = data.status || {}, departments = data.departments || {};
    const completed = data.completed || {}, onTime = data.onTime || {}, priority = data.priority || {}, workload = data.workload || {};
    return {
        trend: {labels: safeLabels(trend.labels), created: safeSeries(trend.created), completed: safeSeries(trend.completed)},
        status: {labels: safeLabels(status.labels), values: safeSeries(status.values), colors: colorsFromTones(status.tones)},
        departments: {labels: safeLabels(departments.labels), total: safeSeries(departments.total), completed: safeSeries(departments.completed), overdue: safeSeries(departments.overdue)},
        completed: {labels: safeLabels(completed.labels), values: safeSeries(completed.values)},
        onTime: {labels: safeLabels(onTime.labels), values: safeSeries(onTime.values), eligible: safeSeries(onTime.eligible)},
        priority: {labels: safeLabels(priority.labels), values: safeSeries(priority.values), colors: colorsFromTones(priority.tones)},
        workload: {labels: safeLabels(workload.labels), doing: safeSeries(workload.doing), review: safeSeries(workload.review), late: safeSeries(workload.late)},
    };
}

export function doughnutConfig(data, {cutout = '68%'} = {}) {
    return {type: 'doughnut', data: {labels: data.labels, datasets: [{data: data.values, backgroundColor: data.colors, borderColor: '#fff', borderWidth: 3, hoverOffset: 5}]}, options: {responsive: true, maintainAspectRatio: false, cutout, animation: {...reportChartAnimation, animateRotate: true, animateScale: true}, layout: {padding: {top: 2, right: 8, bottom: 0, left: 8}}, plugins: {legend: {position: 'bottom', labels: {usePointStyle: true, pointStyle: 'circle', boxWidth: 7, padding: 12}}, tooltip}}};
}

export function buildReportChartConfigs(data = {}) {
    const n = normalizeReportChartData(data);
    return {
        trend: {type: 'line', data: {labels: n.trend.labels, datasets: [{label: 'งานที่สร้าง', data: n.trend.created, borderColor: reportChartColors.blue, backgroundColor: 'rgba(35,117,237,.11)', fill: true, tension: .35, pointRadius: 3, pointHoverRadius: 5}, {label: 'งานที่เสร็จ', data: n.trend.completed, borderColor: reportChartColors.green, backgroundColor: 'rgba(18,166,106,.08)', fill: true, tension: .35, pointRadius: 3, pointHoverRadius: 5}]}, options: {responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false}, animation: reportChartAnimation, animations: lineAnimations, plugins: cartesianPlugins, scales: cartesianScales}},
        status: doughnutConfig(n.status),
        departments: {type: 'bar', data: {labels: n.departments.labels, datasets: [{label: 'งานทั้งหมด', data: n.departments.total, backgroundColor: reportChartColors.blue, borderRadius: 5}, {label: 'เสร็จ', data: n.departments.completed, backgroundColor: reportChartColors.green, borderRadius: 5}, {label: 'ล่าช้า', data: n.departments.overdue, backgroundColor: reportChartColors.red, borderRadius: 5}]}, options: {responsive: true, maintainAspectRatio: false, animation: reportChartAnimation, plugins: cartesianPlugins, scales: cartesianScales}},
        completed: {type: 'bar', data: {labels: n.completed.labels, datasets: [{label: 'งานเสร็จ', data: n.completed.values, backgroundColor: reportChartColors.blue, borderRadius: 6, maxBarThickness: 44}]}, options: {responsive: true, maintainAspectRatio: false, animation: reportChartAnimation, plugins: {legend: {display: false}, tooltip}, scales: cartesianScales}},
        onTime: {reportHasData: n.onTime.eligible.some((value) => value > 0), type: 'bar', data: {labels: n.onTime.labels, datasets: [{label: 'ตรงเวลา', data: n.onTime.values, backgroundColor: reportChartColors.green, borderRadius: 6, maxBarThickness: 32}]}, options: {indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: reportChartAnimation, plugins: {legend: {display: false}, tooltip: {...tooltip, callbacks: {label: (context) => `${context.raw}%`}}}, scales: {x: {beginAtZero: true, max: 100, ticks: {callback: (value) => `${value}%`}, grid: {color: 'rgba(148,163,184,.16)'}}, y: {grid: {display: false}, border: {display: false}}}}},
        priority: doughnutConfig(n.priority),
        workload: {type: 'bar', data: {labels: n.workload.labels, datasets: [{label: 'กำลังทำ', data: n.workload.doing, backgroundColor: reportChartColors.blue}, {label: 'รอตรวจสอบ', data: n.workload.review, backgroundColor: reportChartColors.purple}, {label: 'ล่าช้า', data: n.workload.late, backgroundColor: reportChartColors.red}]}, options: {indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: reportChartAnimation, plugins: cartesianPlugins, scales: {x: {stacked: true, beginAtZero: true, ticks: {precision: 0}, grid: {color: 'rgba(148,163,184,.16)'}}, y: {stacked: true, grid: {display: false}, border: {display: false}}}}},
    };
}
