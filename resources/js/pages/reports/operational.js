/*
 * รายงานปฏิบัติงานประจำเดือน — ภาพรวมทีม Overview รายบุคคล และหน้า detail ทั้งสาม
 *
 * ทุกหน้าใช้ entry เดียวกัน: ดร็อปดาวน์ของตัวกรองเดือน/พนักงาน และเมนู CSV
 * กราฟมีเฉพาะหน้า Overview และภาพรวมทีม — initializeChartCards ข้าม canvas ที่ไม่มีอยู่ให้เอง
 */
import Chart from 'chart.js/auto';
import {initSelectDropdowns} from '../../components/select-dropdown.js';
import {initializeChartCards, parseChartData} from './chart-lifecycle.js';
import {buildOperationalChartConfigs} from './operational-chart-config.js';
import {initCsvMenu, initReportFilter} from './operational-controls.js';
import {initTeamWorkModal} from './operational-team-work.js';

const page = document.querySelector('.report-operational');

if (page) {
    // ดร็อปดาวน์เสริมบน <select> เดิมและยิง change เมื่อเลือก ตัวกรองจึงส่งฟอร์มได้ตามปกติ
    initSelectDropdowns(page);
    initReportFilter(page);
    initCsvMenu(page);
    // กล่อง "ดูงาน" มีเฉพาะภาพรวมทีมของเดือนปัจจุบัน หน้าอื่นคืน null เอง
    initTeamWorkModal(page);

    const chartData = document.getElementById('report-chart-data');

    if (chartData) {
        initializeChartCards({
            root: document,
            ChartCtor: Chart,
            configs: buildOperationalChartConfigs(parseChartData(chartData)),
            definitions: [
                {id: 'operationalDailyHoursChart', key: 'daily'},
                {id: 'operationalWorkTypeChart', key: 'workTypes'},
            ],
        });
    }
}
