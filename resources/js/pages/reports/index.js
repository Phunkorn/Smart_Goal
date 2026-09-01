import Chart from 'chart.js/auto';
import {buildReportChartConfigs} from './chart-config.js';
import {initializeChartCards, parseChartData} from './chart-lifecycle.js';

const page = document.querySelector('.report-page');
if (page) {
    const period = page.querySelector('[data-report-period]');
    const customDates = page.querySelector('[data-report-custom-dates]');
    const synchronizeCustomDates = () => { if (customDates) customDates.hidden = period?.value !== 'custom'; };
    period?.addEventListener('change', synchronizeCustomDates);
    synchronizeCustomDates();

    const configs = buildReportChartConfigs(parseChartData(document.getElementById('report-chart-data')));
    // การเปรียบเทียบรายแผนกย้ายไปเป็นตาราง จึงเหลือกราฟที่ตอบคำถามต่างกันจริงสี่อัน
    initializeChartCards({root: document, ChartCtor: Chart, configs, definitions: [
        {id: 'reportTrendChart', key: 'trend'}, {id: 'reportStatusChart', key: 'status'},
        {id: 'reportCompletedChart', key: 'completed'}, {id: 'reportPriorityChart', key: 'priority'},
        {id: 'reportWorkloadChart', key: 'workload'},
    ]});
}
