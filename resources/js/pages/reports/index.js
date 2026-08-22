import Chart from 'chart.js/auto';
import {buildReportChartConfigs} from './chart-config.js';

const page = document.querySelector('.report-page');

if (page) {
    const period = page.querySelector('[data-report-period]');
    const customDates = page.querySelector('[data-report-custom-dates]');
    const synchronizeCustomDates = () => {
        if (customDates) customDates.hidden = period?.value !== 'custom';
    };

    period?.addEventListener('change', synchronizeCustomDates);
    synchronizeCustomDates();

    const dataElement = document.getElementById('report-chart-data');
    if (dataElement) {
        try {
            const configs = buildReportChartConfigs(JSON.parse(dataElement.textContent || '{}'));
            const trendCanvas = document.getElementById('reportTrendChart');
            const statusCanvas = document.getElementById('reportStatusChart');

            if (trendCanvas) new Chart(trendCanvas, configs.trend);
            if (statusCanvas) new Chart(statusCanvas, configs.status);
        } catch (error) {
            console.error('Unable to render report charts.', error);
        }
    }
}
