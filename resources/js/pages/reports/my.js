import Chart from 'chart.js/auto';
import {initializeChartCards, parseChartData} from './chart-lifecycle.js';
import {workloadChartConfig} from './my-chart-config.js';

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
        // เหลือกราฟเดียว โดนัทความสำคัญถูกถอดออกจากหน้านี้
        // พนักงานไม่ได้ใช้สัดส่วนความสำคัญตัดสินใจอะไร และมันทำให้หน้ายาวขึ้นโดยเปล่าประโยชน์
        configs: {workload: workloadChartConfig(chartData.workload)},
        definitions: [{id: 'personalWorkloadChart', key: 'workload'}],
    });
}
