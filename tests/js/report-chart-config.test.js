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

/*
 * แนวโน้มเป็นแท่งคู่ ไม่ใช่เส้น เพราะเส้นลากผ่านเดือนที่ไม่มีข้อมูล
 * แล้วสื่อว่า "โตพรวด" ทั้งที่ความจริงคือเพิ่งเริ่มใช้ระบบ
 *
 * และมีโดนัทได้ใบเดียวคือสถานะ ส่วนความสำคัญเป็นแท่งแนวนอน
 * เพื่อไม่ให้กราฟสองใบที่ตอบคนละคำถามหน้าตาเหมือนกันจนสายตาพยายามเทียบกันเอง
 */
test('report chart configs use bars for trend and keep exactly one doughnut', () => {
    const configs = buildReportChartConfigs({});

    assert.equal(configs.trend.type, 'bar');
    assert.equal(configs.status.type, 'doughnut');
    assert.equal(configs.priority.type, 'bar');
    assert.equal(configs.priority.options.indexAxis, 'y');
    assert.equal(configs.completed.type, 'bar');

    const doughnuts = Object.values(configs).filter((config) => config.type === 'doughnut');
    assert.equal(doughnuts.length, 1, 'หน้าเดียวต้องมีโดนัทใบเดียว');

    assert.deepEqual(configs.trend.data.labels, []);
    assert.deepEqual(configs.status.data.datasets[0].data, []);
    assert.equal(configs.trend.options.scales.y.beginAtZero, true);
});

/*
 * แท่งซ้อนบอกสัดส่วนได้ แต่ตอบไม่ได้ว่ารวมแล้วกี่งานโดยไม่ต้องกวาดตาไปที่แกน
 * ยอดรวมท้ายแท่งจึงเป็นส่วนหนึ่งของกราฟ ไม่ใช่ของตกแต่ง
 */
test('the stacked workload chart writes a total at the end of every bar', () => {
    const configs = buildReportChartConfigs({
        workload: {labels: ['IT'], doing: [2], review: [2], late: [1]},
    });

    const plugin = (configs.workload.plugins || []).find((entry) => entry.id === 'stackedTotalLabels');
    assert.ok(plugin, 'ต้องมีปลั๊กอินเขียนยอดรวม');
    assert.equal(configs.workload.options.layout.padding.right, 56, 'ต้องเผื่อที่ให้ยอดรวมไม่ถูกตัดขอบ');

    const painted = [];
    const ctx = {
        save() {}, restore() {},
        fillText(text) { painted.push(text); },
        set fillStyle(value) {}, set font(value) {}, set textBaseline(value) {}, set textAlign(value) {},
    };
    plugin.afterDatasetsDraw({
        ctx,
        scales: {x: {}},
        data: configs.workload.data,
        isDatasetVisible: () => true,
        getDatasetMeta: () => ({data: [{x: 100, y: 20}]}),
    });

    assert.deepEqual(painted, ['5 งาน']);
});

test('unknown status tones fall back to the shared gray color', () => {
    const configs = buildReportChartConfigs({status: {labels: ['ไม่รองรับ'], tones: ['unknown'], values: [1]}});

    assert.deepEqual(configs.status.data.datasets[0].backgroundColor, [reportChartColors.gray]);
});
