import Chart from 'chart.js/auto';
import {initializeChartCards, parseChartData} from './chart-lifecycle.js';
import {priorityChartConfig, workloadChartConfig} from './my-chart-config.js';

/**
 * ใช้วงจรชีวิตกราฟชุดเดียวกับหน้ารายงานฝั่ง admin
 *
 * เดิมหน้านี้เรียก new Chart() ตรง ๆ จึงไม่ได้สเกเลตันระหว่างรอ ไม่มีการเฟดเข้า
 * และไม่มีสถานะว่าง/ผิดพลาด ทั้งที่ระบบเหล่านั้นมีอยู่แล้วและใช้ร่วมกันได้
 */
const page = document.querySelector('.personal-report');

if (page) {
    const chartData = parseChartData(document.getElementById('personalReportChartData'));

    initializeChartCards({
        root: document,
        ChartCtor: Chart,
        configs: {
            workload: workloadChartConfig(chartData.workload),
            priority: priorityChartConfig(chartData.priority),
        },
        definitions: [
            {id: 'personalWorkloadChart', key: 'workload'},
            {id: 'personalPriorityChart', key: 'priority'},
        ],
    });
}
