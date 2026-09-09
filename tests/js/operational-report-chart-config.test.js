import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

import {reportChartColors} from '../../resources/js/pages/reports/chart-config.js';
import {
    buildOperationalChartConfigs,
    normalizeOperationalChartData,
} from '../../resources/js/pages/reports/operational-chart-config.js';

/*
 * กราฟของรายงานภาระงานปฏิบัติการ
 *
 * หน่วยของรายงานนี้เป็น "ชั่วโมง" ต่างจากรายงานโครงการที่นับ "จำนวนงาน"
 * ทูลทิปและแกนจึงต้องเขียนหน่วยของตัวเอง แต่ชุดสีต้องมาจาก chart-config.js
 * ที่เดียว เพราะลำดับสีนั้นผ่านการตรวจคอนทราสต์และตาบอดสีมาแล้ว
 */
const sampleData = {
    daily: {
        labels: ['1 Sep', '2 Sep'],
        routine: [1.5, 2],
        field: [0, 4],
    },
    categories: {
        labels: ['IT Support', 'ซ่อมบำรุง'],
        values: [6, 2],
        tones: ['blue', 'amber'],
    },
    members: {labels: ['สมชาย', 'สมหญิง'], values: [8, 3]},
};

test('normalize คืนโครงสร้างครบทุกชุดข้อมูลแม้ข้อมูลว่าง', () => {
    const normalized = normalizeOperationalChartData({});

    assert.deepEqual(normalized.daily, {labels: [], routine: [], field: []});
    assert.deepEqual(normalized.categories, {labels: [], values: [], colors: []});
    assert.deepEqual(normalized.members, {labels: [], values: []});
});

/*
 * ค่าที่ไม่ใช่ตัวเลขหรือเป็นลบต้องกลายเป็นศูนย์ ไม่ใช่ทำให้กราฟพังทั้งใบ
 */
test('normalize ปัดค่าที่ใช้ไม่ได้เป็นศูนย์', () => {
    const normalized = normalizeOperationalChartData({
        daily: {labels: [1, 'สอง'], routine: ['x', -5, 3]},
        members: {values: [null, undefined, 7]},
    });

    assert.deepEqual(normalized.daily.labels, ['1', 'สอง']);
    assert.deepEqual(normalized.daily.routine, [0, 0, 3]);
    assert.deepEqual(normalized.members.values, [0, 0, 7]);
});

test('สีของกราฟหมวดงานมาจากชุดสีร่วม ไม่ประกาศสีใหม่', () => {
    const normalized = normalizeOperationalChartData(sampleData);

    assert.deepEqual(normalized.categories.colors, [
        reportChartColors.blue,
        reportChartColors.amber,
    ]);
});

test('tone ที่ไม่รู้จักถอยไปใช้สีเทาสำรอง', () => {
    const normalized = normalizeOperationalChartData({
        categories: {labels: ['ไม่ระบุหมวด'], values: [1], tones: ['ไม่มีสีนี้']},
    });

    assert.deepEqual(normalized.categories.colors, [reportChartColors.gray]);
});

test('กราฟรายวันเป็นแท่งซ้อนสองประเภทงาน', () => {
    const configs = buildOperationalChartConfigs(sampleData);

    assert.equal(configs.daily.type, 'bar');
    assert.equal(configs.daily.options.scales.x.stacked, true);
    assert.equal(configs.daily.options.scales.y.stacked, true);
    assert.deepEqual(
        configs.daily.data.datasets.map((dataset) => dataset.label),
        ['งานประจำ', 'งานนอกสถานที่']
    );
});

test('กราฟที่เหลือใช้ชนิดที่ตรงกับ data-chart-kind ใน Blade', () => {
    const configs = buildOperationalChartConfigs(sampleData);

    assert.equal(configs.categories.type, 'doughnut');
    assert.equal(configs.members.type, 'bar');
    assert.equal(configs.members.options.indexAxis, 'y', 'ชั่วโมงรายคนเป็นแท่งแนวนอน');
});

/*
 * ตัวเลขในรายงานนี้เป็นชั่วโมง การใช้ทูลทิปของรายงานโครงการจะได้ข้อความ
 * "8 งาน" ซึ่งผิดความหมาย
 */
test('ทูลทิปและแกนบอกหน่วยเป็นชั่วโมง', () => {
    const configs = buildOperationalChartConfigs(sampleData);

    const dailyLabel = configs.daily.options.plugins.tooltip.callbacks.label({
        raw: 2.5,
        dataset: {label: 'งานประจำ'},
    });
    assert.equal(dailyLabel, 'งานประจำ: 2.5 ชม.');

    const categoryLabel = configs.categories.options.plugins.tooltip.callbacks.label({
        raw: 6,
        label: 'IT Support',
        dataset: {data: [6, 2]},
    });
    assert.equal(categoryLabel, 'IT Support: 6 ชม. (75%)');

    assert.equal(configs.daily.options.scales.y.ticks.callback(4), '4 ชม.');
    assert.equal(configs.members.options.scales.x.ticks.callback(8), '8 ชม.');
});

test('สัดส่วนไม่หารด้วยศูนย์เมื่อยังไม่มีข้อมูล', () => {
    const configs = buildOperationalChartConfigs({});

    const label = configs.categories.options.plugins.tooltip.callbacks.label({
        raw: 0,
        label: 'ไม่ระบุหมวด',
        dataset: {data: []},
    });

    assert.equal(label, 'ไม่ระบุหมวด: 0 ชม. (0%)');
});

/*
 * id ของ canvas ต้องตรงกันสามที่: Blade, ตัวเชื่อมใน operational.js และคีย์ของ
 * config ถ้าหลุดที่ใดที่หนึ่งกราฟจะไม่ถูกวาดโดยไม่มี error ให้เห็น
 */
test('id ของ canvas ตรงกันระหว่าง Blade กับตัวเชื่อมใน entry', () => {
    const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
    const blade = read('resources/views/reports/components/operational-charts.blade.php');
    const entry = read('resources/js/pages/reports/operational.js');
    const configs = buildOperationalChartConfigs(sampleData);

    const pairs = [
        ['operationalDailyChart', 'daily'],
        ['operationalCategoryChart', 'categories'],
        ['operationalMemberChart', 'members'],
    ];

    pairs.forEach(([id, key]) => {
        assert.ok(blade.includes(`'${id}'`), `Blade ต้องมีกราฟ ${id}`);
        assert.ok(entry.includes(`{id: '${id}', key: '${key}'}`), `entry ต้องเชื่อม ${id} กับ ${key}`);
        assert.ok(configs[key], `config ต้องมีคีย์ ${key}`);
    });
});

test('การ์ดกราฟใช้สัญญา markup เดียวกับรายงานอื่น', () => {
    const blade = readFileSync(
        new URL('../../resources/views/reports/components/operational-charts.blade.php', import.meta.url),
        'utf8'
    );

    ['data-report-chart', 'data-chart-kind', 'data-chart-state="loading"',
        'data-chart-skeleton', 'data-chart-empty', 'data-chart-error'].forEach((hook) => {
        assert.ok(blade.includes(hook), `ต้องมี ${hook} เพื่อให้ chart-lifecycle.js ทำงานได้`);
    });
});

test('vite ลงทะเบียน entry ของรายงานภาระงานปฏิบัติการ', () => {
    const viteConfig = readFileSync(new URL('../../vite.config.js', import.meta.url), 'utf8');

    assert.ok(viteConfig.includes("'resources/js/pages/reports/operational.js'"));
    assert.ok(viteConfig.includes("'resources/css/pages/report-operational.css'"));
});
