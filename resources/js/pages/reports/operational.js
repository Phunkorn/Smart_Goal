/*
 * หน้ารายงานภาระงานปฏิบัติการ
 *
 * หน้าทำงานเป็นสองจังหวะ: เลือกคน แล้วค่อยดูของคนนั้น
 * หน้ารายชื่อมีการ์ดรายคนกับกราฟงานของวันนี้ ส่วนหน้าของคนมีกราฟใบเดียวกันและอีกสองตาราง
 *
 * กราฟชุดของทั้งแผนก (ชั่วโมงงาน/หมวดงาน/รายคน) ไม่ถูกใช้บนหน้านี้แล้ว
 * เพราะตอบคนละคำถามกับ "คนนี้ทำงานประจำครบไหม"
 */
import Chart from 'chart.js/auto';
import {initializeChartCards, parseChartData} from './chart-lifecycle.js';
import {buildOperationalChartConfigs} from './operational-chart-config.js';
import {initTablePager} from './table-pager.js';
import {initPeopleModal} from './people-modal.js';

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

    /*
     * ทั้งสองตารางแสดงครั้งละ 10 แถว และใช้ตัวแบ่งหน้าตัวเดียวกับรายงานอื่น
     * ปุ่มถูก render มาจาก Blade แล้ว ที่นี่เพียงผูกเข้ากับตารางของมัน
     * หน้าหนึ่งมีได้ทีละตาราง ตัวที่ไม่มีอยู่จะคืน null เองโดยไม่ทำให้ไฟล์พัง
     */
    /*
     * รายละเอียดของการ์ดพนักงานเปิดเป็น modal ผูกที่ page ทีเดียว
     * การ์ดถูกซ่อน/แสดงใหม่ตลอดจากการแบ่งหน้า จึงต้องเป็น delegation ไม่ใช่ผูกทีละใบ
     */
    initPeopleModal(page);

    initTablePager({
        table: page.querySelector('[data-operational-people-table]'),
        pager: page.querySelector('[data-operational-people-pager]'),
        rowSelector: '[data-operational-people-row]',
        pageLabel: page.querySelector('[data-operational-people-page]'),
        previous: page.querySelector('[data-operational-people-previous]'),
        next: page.querySelector('[data-operational-people-next]'),
    });

    initTablePager({
        table: page.querySelector('[data-operational-days-table]'),
        pager: page.querySelector('[data-operational-days-pager]'),
        rowSelector: '[data-operational-days-row]',
        pageLabel: page.querySelector('[data-operational-days-page]'),
        previous: page.querySelector('[data-operational-days-previous]'),
        next: page.querySelector('[data-operational-days-next]'),
    });

    // รายการงานประจำทีละรายการ ใช้ตัวแบ่งหน้าตัวเดียวกันอีกเช่นกัน
    initTablePager({
        table: page.querySelector('[data-checklist-table]'),
        pager: page.querySelector('[data-checklist-pager]'),
        rowSelector: '[data-checklist-row]',
        pageLabel: page.querySelector('[data-checklist-page]'),
        previous: page.querySelector('[data-checklist-previous]'),
        next: page.querySelector('[data-checklist-next]'),
    });

    /*
     * เหลือกราฟใบเดียว คืองานของวันนี้รายคน ซึ่งเป็นข้อมูลชุดเดียวกับการ์ดด้านล่าง
     * initializeChartCards ข้าม canvas ที่ไม่มีอยู่ให้เองอยู่แล้ว จึงไม่ต้องมีเงื่อนไขซ้อน
     */
    const configs = buildOperationalChartConfigs(
        parseChartData(document.getElementById('report-chart-data'))
    );

    initializeChartCards({
        root: document,
        ChartCtor: Chart,
        configs,
        definitions: [
            {id: 'operationalTodayMemberChart', key: 'todayMembers'},
        ],
    });
}
