/*
 * หน้ารายงานภาระงานปฏิบัติการ
 *
 * ใช้ initializeChartCards และ parseChartData ตัวเดียวกับรายงานอื่น เพราะ markup
 * ของการ์ดกราฟใช้สัญญาเดียวกัน (data-report-chart / data-chart-state) การ์ดจึงได้
 * skeleton, สถานะว่าง และสถานะผิดพลาดเหมือนกันโดยไม่ต้องเขียนซ้ำ
 */
import Chart from 'chart.js/auto';
import {initializeChartCards, parseChartData} from './chart-lifecycle.js';
import {buildOperationalChartConfigs} from './operational-chart-config.js';
import {initTablePager} from './table-pager.js';

const page = document.querySelector('.report-operational');

if (page) {
    // ช่องวันที่แบบกำหนดเองแสดงเฉพาะเมื่อเลือกช่วง "กำหนดเอง"
    // พฤติกรรมเดียวกับหน้ารายงานภาพรวมองค์กร
    const period = page.querySelector('[data-report-period]');
    const customDates = page.querySelector('[data-report-custom-dates]');
    const synchronizeCustomDates = () => {
        if (customDates) customDates.hidden = period?.value !== 'custom';
    };

    period?.addEventListener('change', synchronizeCustomDates);
    synchronizeCustomDates();

    // ตารางรายคนแสดงครั้งละ 10 แถว ปุ่มเปลี่ยนหน้าถูก render มาจาก Blade แล้ว
    initTablePager({
        table: page.querySelector('[data-operational-member-table]'),
        pager: page.querySelector('[data-operational-member-pager]'),
        rowSelector: '[data-operational-member-row]',
        pageLabel: page.querySelector('[data-operational-member-page]'),
        previous: page.querySelector('[data-operational-member-previous]'),
        next: page.querySelector('[data-operational-member-next]'),
    });

    const configs = buildOperationalChartConfigs(
        parseChartData(document.getElementById('report-chart-data'))
    );

    initializeChartCards({
        root: document,
        ChartCtor: Chart,
        configs,
        definitions: [
            {id: 'operationalDailyChart', key: 'daily'},
            {id: 'operationalCategoryChart', key: 'categories'},
            {id: 'operationalMemberChart', key: 'members'},
            {id: 'operationalInterruptChart', key: 'interrupts'},
        ],
    });
}
