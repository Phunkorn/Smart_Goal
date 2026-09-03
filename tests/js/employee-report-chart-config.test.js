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
    });

    // ชุดกราฟต้องเหมือนหน้าภาพรวมทุกประการ ต่างแค่ขอบเขตข้อมูล
    assert.equal(configs.trend.type, 'bar');
    assert.equal(configs.status.type, 'doughnut');
    assert.equal(configs.completed.type, 'bar');
    assert.equal(configs.priority.type, 'bar');
    assert.equal(configs.priority.options.indexAxis, 'y');
    // อัตราตรงเวลาเป็นค่าตัวเดียว จึงแสดงเป็นตัวเลขใหญ่ใน Blade แทนโดนัท
    assert.equal(configs.onTime, undefined);
    // หน้ารายบุคคลไม่มีกราฟงานค้างรายแผนก เพราะขอบเขตเป็นคนเดียว
    assert.equal(configs.workload, undefined);
    assert.equal(configs.trend.options.animation.duration, 460);
    assert.equal(configs.completed.options.animation.duration, 460);
    assert.equal(configs.completed.options.animations, undefined);
    assert.equal(configs.status.options.animation.animateRotate, true);
    assert.deepEqual(configs.trend.data.datasets[1].data, [0]);
    assert.deepEqual(configs.completed.data.datasets[0].data, [0]);
});

test('bar charts use the Chart.js scale baseline without hard-coded canvas origins', () => {
    const configs = buildReportChartConfigs({
        completed: {labels: ['Aug'], values: [1]},
        workload: {labels: ['IT'], doing: [1], review: [1], late: [0]},
    });

    for (const key of ['completed', 'workload']) {
        assert.equal(configs[key].options.animation.duration, 460);
        assert.equal(configs[key].options.animations, undefined);
    }
    assert.equal(configs.workload.options.indexAxis, 'y');
    // การเทียบรายแผนกย้ายไปเป็นตาราง จึงไม่มี config ของกราฟสองอันนี้อีกต่อไป
    assert.equal(configs.departments, undefined);
    assert.equal(configs.onTime, undefined);

    const source = readFileSync(new URL('../../resources/js/pages/reports/chart-config.js', import.meta.url), 'utf8');
    assert.doesNotMatch(source, /\bfrom\s*:\s*0\b/);
});

test('employee status doughnut orders its slices so blue never sits next to purple', () => {
    const configs = buildEmployeeChartConfigs({
        status: {labels: ['กำลังทำ', 'รอตรวจสอบ', 'เสร็จสิ้น'], values: [1, 2, 3], tones: ['blue', 'purple', 'green']},
    });

    // ป้ายต้องย้ายตามค่าและสีเสมอ ไม่ใช่สลับเฉพาะสี
    assert.deepEqual(configs.status.data.labels, ['กำลังทำ', 'เสร็จสิ้น', 'รอตรวจสอบ']);
    assert.deepEqual(configs.status.data.datasets[0].data, [1, 3, 2]);
});
