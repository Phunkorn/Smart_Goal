import Chart from 'chart.js/auto';
import {initializeChartCards, parseChartData} from './chart-lifecycle.js';
import {buildEmployeeChartConfigs} from './employee-chart-config.js';

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
        {id: 'employeeCompletedChart', key: 'completed'}, {id: 'employeeOnTimeChart', key: 'onTime'},
        {id: 'employeePriorityChart', key: 'priority'},
    ]});
}
