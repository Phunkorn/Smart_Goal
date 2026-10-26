import Chart from 'chart.js/auto';
import {initAutoSubmitFilters} from '../../components/auto-submit-filter.js';
import {parseDateValue, thaiDateLabel, useDatePickers} from '../../components/date-picker.js';
import {initPeriodRange} from '../../components/period-range.js';
import {initTicketFilterToggle} from '../../components/ticket-filter-toggle.js';
import {initializeChartCards, parseChartData} from './chart-lifecycle.js';
import {buildTicketChartConfigs} from './ticket-chart-config.js';

/*
 * รายงาน Smart Ticket
 *
 * ตัวกรองส่งฟอร์ม GET ทันทีเมื่อเปลี่ยนค่า ยกเว้น "กำหนดช่วงวันที่เอง" ที่รอปุ่ม "แสดงผล"
 * ช่องค้นหาในรายการ Ticket ส่งฟอร์มของตัวเองหลังหยุดพิมพ์ ปุ่ม "ตัวกรองเพิ่มเติม" พับ/เปิดแผงสาขาและเจ้าหน้าที่
 * กราฟ 2 ใบผ่าน chart-lifecycle.js ตัวเดียวกับรายงานอื่น (สร้างครั้งเดียวต่อ canvas,
 * สถานะ loading/empty/error, ปิดแอนิเมชันเมื่อ prefers-reduced-motion)
 * ตัวเลขทั้งหมดอยู่ใน HTML จาก server อยู่แล้ว สคริปต์นี้ล้มก็ยังอ่านรายงานได้ครบ
 */
export function initTicketReport(doc = document, {ChartCtor = Chart} = {}) {
    const page = doc.querySelector('.ticket-report');
    if (!page) return [];

    initAutoSubmitFilters(page);
    initTicketFilterToggle(page);
    initPeriodRange(page, {
        formatDate: (value) => {
            const date = parseDateValue(value);

            return date ? thaiDateLabel(date) : '—';
        },
    });

    return initializeChartCards({
        root: doc,
        ChartCtor,
        configs: buildTicketChartConfigs(parseChartData(doc.getElementById('ticket-report-chart-data'))),
        definitions: [
            {id: 'ticketTrendChart', key: 'trend'},
            {id: 'ticketDepartmentChart', key: 'departments'},
        ],
    });
}

if (typeof document !== 'undefined' && document.querySelector('.ticket-report')) {
    useDatePickers();
    initTicketReport(document);
}
