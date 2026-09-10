import Chart from 'chart.js/auto';
import {initializeChartCards, parseChartData} from './chart-lifecycle.js';
import {priorityChartConfig, workloadChartConfig} from './my-chart-config.js';
import {initTablePager} from './table-pager.js';
import {initSelectDropdowns} from '../../components/select-dropdown.js';
import {initSubtaskModal} from '../../components/subtask-modal.js';

/**
 * ใช้วงจรชีวิตกราฟชุดเดียวกับหน้ารายงานฝั่ง admin
 *
 * เดิมหน้านี้เรียก new Chart() ตรง ๆ จึงไม่ได้สเกเลตันระหว่างรอ ไม่มีการเฟดเข้า
 * และไม่มีสถานะว่าง/ผิดพลาด ทั้งที่ระบบเหล่านั้นมีอยู่แล้วและใช้ร่วมกันได้
 */
const page = document.querySelector('.personal-report');

if (page) {
    const chartData = parseChartData(document.getElementById('personalReportChartData'));

    initSelectDropdowns(page);
    initSubtaskModal(document);

    initTablePager({
        table: page.querySelector('[data-personal-attention-table]'),
        pager: page.querySelector('[data-personal-attention-pager]'),
        rowSelector: '[data-personal-attention-row]',
        pageLabel: page.querySelector('[data-personal-attention-page]'),
        previous: page.querySelector('[data-personal-attention-previous]'),
        next: page.querySelector('[data-personal-attention-next]'),
    });

    initTablePager({
        table: page.querySelector('[data-personal-contribution-table]'),
        pager: page.querySelector('[data-personal-contribution-pager]'),
        rowSelector: '[data-personal-contribution-row]',
        pageLabel: page.querySelector('[data-personal-contribution-page]'),
        previous: page.querySelector('[data-personal-contribution-previous]'),
        next: page.querySelector('[data-personal-contribution-next]'),
    });

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
