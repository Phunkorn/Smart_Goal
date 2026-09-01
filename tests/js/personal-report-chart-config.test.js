import test from 'node:test';
import assert from 'node:assert/strict';
import { priorityChartConfig, safeSeries, workloadChartConfig } from '../../resources/js/pages/reports/my-chart-config.js';

test('safeSeries replaces invalid and negative values with zero', () => {
    assert.deepEqual(safeSeries([2, '3', null, 'bad', -1]), [2, 3, 0, 0, 0]);
});

test('workload config remains finite for empty data', () => {
    const config = workloadChartConfig({ labels: [], values: [undefined, Number.NaN] });
    assert.deepEqual(config.data.datasets[0].data, [0, 0]);
    assert.equal(config.options.scales.y.beginAtZero, true);
});

test('priority config leaves the empty state to the chart card instead of a fake slice', () => {
    const empty = priorityChartConfig({ labels: [], values: [] });

    // ไม่ปั้นสไลซ์หลอกอีกต่อไป การ์ดกราฟมีสถานะว่างของตัวเองที่บอกผู้ใช้ตรง ๆ
    assert.deepEqual(empty.data.datasets[0].data, []);

    const full = priorityChartConfig({ labels: ['1', '2', '3', '4', '5'], values: [1, 2, 3, 4, 5], tones: ['gray', 'blue', 'red', 'amber', 'green'] });
    assert.deepEqual(full.data.datasets[0].data, [1, 2, 3, 4, 5]);
    assert.equal(full.data.datasets[0].backgroundColor.length, 5);
});

test('personal charts share the report animation instead of switching it off', () => {
    // เอฟเฟคโหลดกราฟต้องเหมือนฝั่ง admin ส่วนการปิดแอนิเมชันเป็นหน้าที่ของ chart-lifecycle
    assert.equal(workloadChartConfig({}).options.animation.duration, 460);
    assert.equal(priorityChartConfig({}).options.animation.duration, 460);
});
