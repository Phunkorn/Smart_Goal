import Chart from 'chart.js/auto';
import {initializeChartCards, parseChartData} from './chart-lifecycle.js';
import {buildEmployeeChartConfigs} from './employee-chart-config.js';
import {buildOperationalChartConfigs} from './operational-chart-config.js';
import {initTablePager} from './table-pager.js';
import {initSelectDropdowns} from '../../components/select-dropdown.js';
import {initSubtaskModal} from '../../components/subtask-modal.js';

const page = document.querySelector('.employee-report');
if (page) {
    initSelectDropdowns(page);
    initSubtaskModal(document);

    // Keep long task names compact in the table while exposing the complete name on hover.
    page.querySelectorAll('.employee-report__tasks tbody th a').forEach((link) => {
        link.title = link.textContent.trim();
    });

    const period = page.querySelector('[data-report-period]');
    const customDates = page.querySelector('[data-report-custom-dates]');
    const synchronizeCustomDates = () => { if (customDates) customDates.hidden = period?.value !== 'custom'; };
    period?.addEventListener('change', synchronizeCustomDates);
    synchronizeCustomDates();

    // ใช้ตัวแบ่งหน้าตัวเดียวกับหน้ารายงานของฉัน ไม่สร้างชุดที่สอง
    initTablePager({
        table: page.querySelector('[data-employee-task-table]'),
        pager: page.querySelector('[data-employee-task-pager]'),
        rowSelector: '[data-employee-task-row]',
        pageLabel: page.querySelector('[data-employee-task-page]'),
        previous: page.querySelector('[data-employee-task-previous]'),
        next: page.querySelector('[data-employee-task-next]'),
    });

    const configs = buildEmployeeChartConfigs(parseChartData(document.getElementById('employee-report-chart-data')));
    /*
     * สามใบ — กราฟ "ปิดงานได้เดือนละเท่าไร" ถูกตัดออกเพราะซ้ำกับเส้น "งานที่เสร็จ"
     * ในกราฟแนวโน้ม ส่วน config ของมันยังอยู่ใน chart-config.js เพราะหน้ารายงาน
     * ภาพรวมองค์กรยังใช้อยู่
     */
    initializeChartCards({root: document, ChartCtor: Chart, configs, definitions: [
        {id: 'employeeTrendChart', key: 'trend'}, {id: 'employeeStatusChart', key: 'status'},
        {id: 'employeePriorityChart', key: 'priority'},
    ]});

}
