import Chart from 'chart.js/auto';
import { priorityChartConfig, workloadChartConfig } from './my-chart-config.js';

const dataElement = document.getElementById('personalReportChartData');

if (dataElement) {
    let chartData = {};

    try {
        chartData = JSON.parse(dataElement.textContent || '{}');
    } catch {
        chartData = {};
    }

    const workloadCanvas = document.getElementById('personalWorkloadChart');
    const priorityCanvas = document.getElementById('personalPriorityChart');

    if (workloadCanvas) new Chart(workloadCanvas, workloadChartConfig(chartData.workload));
    if (priorityCanvas) new Chart(priorityCanvas, priorityChartConfig(chartData.priority));
}
