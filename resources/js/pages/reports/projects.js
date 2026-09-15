import Chart from 'chart.js/auto';
import {initAutoSubmitFilters} from '../../components/auto-submit-filter.js';
import {parseDateValue, thaiDateLabel, useDatePickers} from '../../components/date-picker.js';
import {initEvidenceModal} from '../../components/evidence-modal.js';
import {initParticipantsPopovers} from '../../components/participants-popover.js';
import {initSelectDropdowns} from '../../components/select-dropdown.js';
import {initSubtaskModal} from '../../components/subtask-modal.js';
import {initializeChartCards, parseChartData} from './chart-lifecycle.js';
import {buildProjectChartConfigs} from './project-chart-config.js';
import {initProjectPeriod} from './project-period.js';

/*
 * รายงานโปรเจกต์ประจำเดือน — หน้าหลักและหน้า "ดูรายละเอียดทั้งหมด"
 *
 * ช่องพนักงาน เดือน และการเรียงลำดับส่งฟอร์มทันทีเมื่อเปลี่ยนค่า ตัวกรองในการ์ดตัวกรอง
 * รอปุ่ม "ค้นหา" การกรอง การเรียง และการแบ่งหน้าทำที่ server ทั้งหมด
 *
 * กราฟแท่งจาก project-chart-config.js: แนวโน้มรายเดือนและสถานะ (ทุกมุมมอง)
 * งานที่ปิดได้รายคน (เฉพาะภาพรวมแผนก — รายบุคคลไม่มี canvas ตัว initializer จึงข้ามไปเอง)
 * การ์ดงานล่าช้าที่ต้องติดตามเป็นรายการ HTML ไม่ต้องใช้สคริปต์
 * หน้ารายละเอียดไม่มีกราฟ ตัว initializer จึงข้ามไปเอง
 */
const page = document.querySelector('.project-report');
if (page) {
    initSelectDropdowns(page);
    initAutoSubmitFilters(page);
    // ช่วงวันที่ที่กำหนดเอง: ปฏิทิน พ.ศ. ของระบบ และป้ายวันที่ที่อัปเดตตามค่าที่เลือก
    useDatePickers();
    initProjectPeriod(page, {
        formatDate: (value) => {
            const date = parseDateValue(value);

            return date ? thaiDateLabel(date) : '—';
        },
    });
    initSubtaskModal(document);
    // หน้า "ดูรายละเอียดทั้งหมด": ป้ายงานย่อยและไอคอนไฟล์เปิดกล่องหลักฐานของงานใบนั้น
    initEvidenceModal(document);
    // ปุ่ม "N คน" ในคอลัมน์ผู้เข้าร่วม เปิดรายชื่อใต้ปุ่ม
    initParticipantsPopovers(document);

    const chartData = document.getElementById('project-report-chart-data');
    if (chartData) {
        initializeChartCards({root: document, ChartCtor: Chart, configs: buildProjectChartConfigs(parseChartData(chartData)), definitions: [
            {id: 'projectTrendChart', key: 'trend'},
            {id: 'projectStatusChart', key: 'status'},
            {id: 'projectBreakdownChart', key: 'breakdown'},
        ]});
    }
}
