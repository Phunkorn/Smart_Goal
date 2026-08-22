import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildReportChartConfigs,
    normalizeReportChartData,
    reportChartColors,
} from '../../resources/js/pages/reports/chart-config.js';

test('report chart data normalizes numeric values and known status tones', () => {
    const data = normalizeReportChartData({
        trend: {labels: ['ก.ค.', 'ส.ค.'], created: ['2', 4], completed: [1, 'bad']},
        status: {labels: ['กำลังทำ', 'ล่าช้า'], values: ['3', 2], tones: ['blue', 'red']},
    });

    assert.deepEqual(data.trend.created, [2, 4]);
    assert.deepEqual(data.trend.completed, [1, 0]);
    assert.deepEqual(data.status.values, [3, 2]);
    assert.deepEqual(data.status.colors, [reportChartColors.blue, reportChartColors.red]);
});

test('report chart configs use line and doughnut charts with safe empty data', () => {
    const configs = buildReportChartConfigs({});

    assert.equal(configs.trend.type, 'line');
    assert.equal(configs.status.type, 'doughnut');
    assert.deepEqual(configs.trend.data.labels, []);
    assert.deepEqual(configs.status.data.datasets[0].data, []);
    assert.equal(configs.trend.options.scales.y.beginAtZero, true);
});

test('unknown status tones fall back to the shared gray color', () => {
    const configs = buildReportChartConfigs({status: {tones: ['unknown'], values: [1]}});

    assert.deepEqual(configs.status.data.datasets[0].backgroundColor, [reportChartColors.gray]);
});
