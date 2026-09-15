import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

import {reportChartColors} from '../../resources/js/pages/reports/chart-config.js';
import {
    buildOperationalChartConfigs,
    normalizeOperationalChartData,
    operationalChartColors,
} from '../../resources/js/pages/reports/operational-chart-config.js';

/*
 * กราฟของรายงานปฏิบัติงานประจำเดือน
 *
 * หน่วยของรายงานนี้เป็น "ชั่วโมง" ทูลทิปและแกนจึงเขียนหน่วยของตัวเอง
 * และโทนสีเป็นน้ำเงิน ไม่ใช้สีเขียวซึ่งสงวนไว้ให้ความหมายของสถานะ
 */
const sampleData = {
    daily: {
        labels: ['1', '2'],
        titles: ['1 ก.ย. 2569', '2 ก.ย. 2569'],
        routine: [1.5, 2],
        field: [0, 4],
    },
    workTypes: {labels: ['งานประจำ', 'งานนอกสถานที่'], values: [98, 30]},
};

test('normalize คืนโครงสร้างครบทุกชุดข้อมูลแม้ข้อมูลว่าง', () => {
    const normalized = normalizeOperationalChartData({});

    assert.deepEqual(normalized.daily, {labels: [], titles: [], routine: [], field: []});
    assert.deepEqual(normalized.workTypes, {labels: [], values: []});
});

test('normalize ปัดค่าที่ใช้ไม่ได้เป็นศูนย์', () => {
    const normalized = normalizeOperationalChartData({
        daily: {labels: [1, 'สอง'], routine: ['x', -5, 3]},
        workTypes: {values: [null, undefined, 7]},
    });

    assert.deepEqual(normalized.daily.labels, ['1', 'สอง']);
    assert.deepEqual(normalized.daily.routine, [0, 0, 3]);
    assert.deepEqual(normalized.workTypes.values, [0, 0, 7]);
});

test('กราฟรายวันเป็นแท่งซ้อนสองประเภทงานในโทนน้ำเงิน', () => {
    const configs = buildOperationalChartConfigs(sampleData);

    assert.equal(configs.daily.type, 'bar');
    assert.equal(configs.daily.options.scales.x.stacked, true);
    assert.equal(configs.daily.options.scales.y.stacked, true);
    assert.deepEqual(configs.daily.data.datasets.map((dataset) => dataset.label), ['งานประจำ', 'นอกสถานที่']);
    assert.deepEqual(
        configs.daily.data.datasets.map((dataset) => dataset.backgroundColor),
        [operationalChartColors.routine, reportChartColors.blue]
    );
    assert.ok(!Object.values(operationalChartColors).includes(reportChartColors.green), 'กราฟหน้านี้ต้องไม่ใช้สีเขียว');
});

test('สัดส่วนประเภทงานเป็นโดนัทที่ไม่มี legend ซ้ำกับรายการใต้กราฟ', () => {
    const configs = buildOperationalChartConfigs(sampleData);

    assert.equal(configs.workTypes.type, 'doughnut');
    assert.equal(configs.workTypes.options.plugins.legend.display, false);
});

test('ทูลทิปและแกนบอกหน่วยเป็นชั่วโมง พร้อมวันที่เต็มของแท่งนั้น', () => {
    const configs = buildOperationalChartConfigs(sampleData);
    const tooltip = configs.daily.options.plugins.tooltip.callbacks;

    assert.equal(tooltip.label({raw: 2.5, dataset: {label: 'งานประจำ'}}), 'งานประจำ: 2.5 ชม.');
    assert.equal(tooltip.title([{dataIndex: 1, label: '2'}]), '2 ก.ย. 2569');
    assert.equal(configs.daily.options.scales.y.ticks.callback(4), '4 ชม.');

    const share = configs.workTypes.options.plugins.tooltip.callbacks.label({
        raw: 98,
        label: 'งานประจำ',
        dataset: {data: [98, 30]},
    });
    assert.equal(share, 'งานประจำ: 98 ชม. (77%)');
});

test('สัดส่วนไม่หารด้วยศูนย์เมื่อยังไม่มีข้อมูล', () => {
    const configs = buildOperationalChartConfigs({});
    const label = configs.workTypes.options.plugins.tooltip.callbacks.label({raw: 0, label: 'งานประจำ', dataset: {data: []}});

    assert.equal(label, 'งานประจำ: 0 ชม. (0%)');
});

/*
 * id ของ canvas ต้องตรงกันสามที่: Blade, ตัวเชื่อมใน operational.js และคีย์ของ config
 * ถ้าหลุดที่ใดที่หนึ่งกราฟจะไม่ถูกวาดโดยไม่มี error ให้เห็น
 */
test('canvas ของแถวกราฟผูกกับ config และใช้สัญญา markup เดียวกับรายงานอื่น', () => {
    // แถวกราฟเป็น component ที่ภาพรวมทีมและ Overview รายบุคคลใช้ร่วมกัน
    const blade = readFileSync(new URL('../../resources/views/reports/components/operational/charts.blade.php', import.meta.url), 'utf8');
    const entry = readFileSync(new URL('../../resources/js/pages/reports/operational.js', import.meta.url), 'utf8');

    for (const [id, key] of [['operationalDailyHoursChart', 'daily'], ['operationalWorkTypeChart', 'workTypes']]) {
        assert.ok(blade.includes(`id="${id}"`), `Blade ต้องมี canvas ${id}`);
        assert.ok(entry.includes(`{id: '${id}', key: '${key}'}`), `operational.js ต้องผูก ${id} กับ ${key}`);
    }

    ['data-report-chart', 'data-chart-kind', 'data-chart-state="loading"',
        'data-chart-skeleton', 'data-chart-empty', 'data-chart-error'].forEach((hook) => {
        assert.ok(blade.includes(hook), `ต้องมี ${hook} เพื่อให้ chart-lifecycle.js ทำงานได้`);
    });
});

test('vite ลงทะเบียน entry ของรายงานปฏิบัติงาน', () => {
    const viteConfig = readFileSync(new URL('../../vite.config.js', import.meta.url), 'utf8');

    assert.ok(viteConfig.includes("'resources/js/pages/reports/operational.js'"));
    assert.ok(viteConfig.includes("'resources/css/pages/report-operational.css'"));
});
