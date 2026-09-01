import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {
    buildReportChartConfigs,
    orderStatusSlices,
    reportChartColors,
    statusSliceOrder,
} from '../../resources/js/pages/reports/chart-config.js';

/**
 * สัญญาเรื่องสีของกราฟรายงาน
 *
 * ค่าเหล่านี้ผ่านการตรวจครบทุกเกณฑ์การมองเห็นแล้ว (ช่วงความสว่าง ความอิ่มสี
 * การแยกแยะสำหรับตาบอดสี การแยกแยะสำหรับสายตาปกติ และคอนทราสต์ต่อพื้น)
 * ชุดเดิมไม่ผ่าน เพราะ amber #f59e0b มีคอนทราสต์เพียง 2.09 และคู่ amber↔green
 * ต่างกันเพียง ΔE 6.7 สำหรับตาบอดสีชนิด tritan
 *
 * เทสต์นี้มีไว้กันการแก้กลับไปเป็นค่าที่อ่านยากโดยไม่รู้ตัว
 */
const validatedPalette = {
    gray: '#64748b',
    blue: '#1d4ed8',
    green: '#059669',
    purple: '#a21caf',
    amber: '#d97706',
    red: '#e11d48',
};

test('ชุดสีกราฟตรงกับค่าที่ผ่านการตรวจการมองเห็นแล้ว', () => {
    assert.deepEqual(reportChartColors, validatedPalette);
});

test('ไม่มีสีชุดเดิมที่ตกเกณฑ์หลงเหลืออยู่ในโค้ดกราฟ', () => {
    const sources = [
        'resources/js/pages/reports/chart-config.js',
        'resources/js/pages/reports/employee-chart-config.js',
        'resources/js/pages/reports/my-chart-config.js',
    ].map((path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8')).join('\n');

    for (const retired of ['#f59e0b', '#ef4444', '#2375ed', '#12a66a', '#7c3aed', '#94a3b8']) {
        assert.equal(sources.includes(retired), false, `ยังพบสีเดิมที่ตกเกณฑ์: ${retired}`);
    }
});

test('ลำดับสไลซ์ของโดนัทสถานะไม่วาง blue ติดกับ purple', () => {
    // สองสีนี้ต่างกันเพียง ΔE 5.4 สำหรับตาบอดสี จึงต้องมีสีอื่นคั่นเสมอ
    const blue = statusSliceOrder.indexOf('blue');
    const purple = statusSliceOrder.indexOf('purple');

    assert.ok(blue >= 0 && purple >= 0);
    assert.ok(Math.abs(blue - purple) > 1, 'blue กับ purple ต้องไม่อยู่ติดกันในลำดับสไลซ์');
});

test('การเรียงสไลซ์พาป้าย ค่า และสีไปด้วยกันเสมอ', () => {
    const ordered = orderStatusSlices({
        labels: ['กำลังทำ', 'รอตรวจสอบ', 'เสร็จสิ้น', 'พักงาน', 'ล่าช้า'],
        values: [1, 2, 3, 4, 5],
        colors: ['blue', 'purple', 'green', 'amber', 'red'],
        tones: ['blue', 'purple', 'green', 'amber', 'red'],
    });

    assert.deepEqual(ordered.labels, ['กำลังทำ', 'เสร็จสิ้น', 'รอตรวจสอบ', 'พักงาน', 'ล่าช้า']);
    assert.deepEqual(ordered.values, [1, 3, 2, 4, 5]);
    assert.deepEqual(ordered.colors, ['blue', 'green', 'purple', 'amber', 'red']);
});

test('สถานะที่ไม่รู้จักไปต่อท้ายโดยไม่ดันสีของสถานะหลักให้เลื่อน', () => {
    const ordered = orderStatusSlices({
        labels: ['ไม่รองรับ', 'กำลังทำ'],
        values: [9, 1],
        colors: ['gray', 'blue'],
        tones: ['gray', 'blue'],
    });

    assert.deepEqual(ordered.labels, ['กำลังทำ', 'ไม่รองรับ']);
    assert.deepEqual(ordered.values, [1, 9]);
});

test('กราฟชั้นซ้อนมีเส้นคั่นสีพื้นเป็นตัวช่วยแยกแยะนอกเหนือจากสี', () => {
    const configs = buildReportChartConfigs({
        workload: {labels: ['IT'], doing: [1], review: [2], late: [3]},
    });

    for (const dataset of configs.workload.data.datasets) {
        assert.equal(dataset.borderColor, '#fff');
        assert.equal(dataset.borderWidth, 2);
    }

    // legend ต้องมีเสมอเมื่อมีชุดข้อมูลตั้งแต่สองชุดขึ้นไป เพื่อไม่ให้สื่อความหมายด้วยสีอย่างเดียว
    assert.notEqual(configs.workload.options.plugins.legend, undefined);
    assert.notEqual(configs.workload.options.plugins.legend.display, false);
});

test('โดนัทมีเส้นคั่นระหว่างสไลซ์และทูลทิปบอกสัดส่วนเป็นเปอร์เซ็นต์', () => {
    const configs = buildReportChartConfigs({
        status: {labels: ['กำลังทำ', 'ล่าช้า'], values: [3, 1], tones: ['blue', 'red']},
    });

    assert.equal(configs.status.data.datasets[0].borderColor, '#fff');
    assert.equal(configs.status.data.datasets[0].borderWidth, 3);

    const label = configs.status.options.plugins.tooltip.callbacks.label({
        label: 'กำลังทำ',
        raw: 3,
        dataset: {data: [3, 1]},
    });

    assert.equal(label, 'กำลังทำ: 3 งาน (75%)');
});
