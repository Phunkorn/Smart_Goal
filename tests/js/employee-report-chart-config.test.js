import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {buildEmployeeChartConfigs} from '../../resources/js/pages/reports/employee-chart-config.js';
import {buildReportChartConfigs} from '../../resources/js/pages/reports/chart-config.js';

test('employee chart configs normalize malformed values and expose required chart types', () => {
    const configs = buildEmployeeChartConfigs({
        trend: {labels: ['Aug'], created: ['2'], completed: ['bad']},
        status: {labels: ['Doing'], values: ['3'], tones: ['blue']},
        completed: {labels: ['Aug'], values: [-2]},
        priority: {labels: ['Routine'], values: [1], tones: ['gray']},
        onTime: {eligible: 2, onTime: 1, late: 1},
    });

    assert.equal(configs.trend.type, 'line');
    assert.equal(configs.status.type, 'doughnut');
    assert.equal(configs.completed.type, 'bar');
    assert.equal(configs.onTime.type, 'doughnut');
    assert.equal(configs.priority.type, 'doughnut');
    assert.equal(configs.trend.options.animation.duration, 460);
    assert.equal(configs.trend.options.animations.x.duration, 500);
    assert.equal(configs.completed.options.animation.duration, 460);
    assert.equal(configs.completed.options.animations, undefined);
    assert.equal(configs.onTime.options.animation.animateRotate, true);
    assert.deepEqual(configs.trend.data.datasets[1].data, [0]);
    assert.deepEqual(configs.completed.data.datasets[0].data, [0]);
    assert.deepEqual(configs.onTime.data.datasets[0].data, [1, 1]);
});

test('bar charts use the Chart.js scale baseline without hard-coded canvas origins', () => {
    const configs = buildReportChartConfigs({
        departments: {labels: ['IT'], total: [2], completed: [1], overdue: [1]},
        completed: {labels: ['Aug'], values: [1]},
        onTime: {labels: ['IT'], values: [50], eligible: [2]},
        workload: {labels: ['IT'], doing: [1], review: [1], late: [0]},
    });

    for (const key of ['departments', 'completed', 'onTime', 'workload']) {
        assert.equal(configs[key].options.animation.duration, 460);
        assert.equal(configs[key].options.animations, undefined);
    }
    assert.equal(configs.onTime.options.indexAxis, 'y');
    assert.equal(configs.workload.options.indexAxis, 'y');

    const source = readFileSync(new URL('../../resources/js/pages/reports/chart-config.js', import.meta.url), 'utf8');
    assert.doesNotMatch(source, /\bfrom\s*:\s*0\b/);
});

test('employee on-time chart stays empty when there is no eligible due date', () => {
    const configs = buildEmployeeChartConfigs({onTime: {eligible: 0, onTime: 0, late: 0}});

    assert.deepEqual(configs.onTime.data.datasets[0].data, []);
});
