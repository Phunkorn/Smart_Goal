import Chart from 'chart.js/auto';
import {initializeChartCards, parseChartData} from './chart-lifecycle.js';
import {buildEmployeeChartConfigs} from './employee-chart-config.js';
import {buildOperationalChartConfigs} from './operational-chart-config.js';

const page = document.querySelector('.employee-report');
if (page) {
    const period = page.querySelector('[data-report-period]');
    const customDates = page.querySelector('[data-report-custom-dates]');
    const synchronizeCustomDates = () => { if (customDates) customDates.hidden = period?.value !== 'custom'; };
    period?.addEventListener('change', synchronizeCustomDates);
    synchronizeCustomDates();

    const configs = buildEmployeeChartConfigs(parseChartData(document.getElementById('employee-report-chart-data')));
    initializeChartCards({root: document, ChartCtor: Chart, configs, definitions: [
        {id: 'employeeTrendChart', key: 'trend'}, {id: 'employeeStatusChart', key: 'status'},
        {id: 'employeeCompletedChart', key: 'completed'}, {id: 'employeePriorityChart', key: 'priority'},
    ]});

    /*
     * กราฟภาระงานปฏิบัติการ — ข้อมูลและ config แยกจากกราฟงานโครงการโดยสิ้นเชิง
     * ใช้ island คนละอันและ config builder ของรายงานภาระงานปฏิบัติการ เพื่อให้
     * ตัวเลขสองโดเมนไม่ปนกันแม้แต่ในชั้นการวาด
     */
    const operationalIsland = document.getElementById('employee-operational-chart-data');

    if (operationalIsland) {
        const operationalConfigs = buildOperationalChartConfigs({daily: parseChartData(operationalIsland)});

        initializeChartCards({
            root: document,
            ChartCtor: Chart,
            configs: operationalConfigs,
            definitions: [{id: 'employeeOperationalChart', key: 'daily'}],
        });
    }
}
