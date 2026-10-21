import test from 'node:test';
import assert from 'node:assert/strict';
import {chartHasData} from '../../resources/js/pages/reports/chart-lifecycle.js';
import {readFileSync} from 'node:fs';
import {buildProjectChartConfigs, centerTotalPlugin, normalizeProjectChartData, projectChartColors, shareOf} from '../../resources/js/pages/reports/project-chart-config.js';

/** รูปแบบเดียวกับ ProjectReportService::chartData() */
const sample = {
    trend: {keys: ['2026-01', '2026-02', '2026-03'], labels: ['ม.ค.', 'ก.พ.', 'มี.ค.'], created: [4, 6, 8], completed: [2, 5, 3], selected: 2},
    status: {keys: ['done', 'doing', 'review', 'paused', 'late'], labels: ['เสร็จสิ้น', 'กำลังทำ', 'รอตรวจสอบ', 'พักงาน', 'ล่าช้า'], values: [4, 3, 0, 0, 7], colors: ['#1d4ed8', '#0891b2', '#a21caf', '#64748b', '#e11d48'], total: 14},
    breakdown: {labels: ['สมชาย', 'สมหญิง', 'อื่น ๆ (2 คน)'], values: [5, 2, 3], colors: ['#1d4ed8', '#ea580c', '#64748b'], total: 10},
};

const allColors = (config) => config.data.datasets.flatMap((set) => [set.backgroundColor, set.borderColor, set.pointBackgroundColor].flat().filter(Boolean));

test('the palette has no green and keeps red for late work only', () => {
    const greens = ['#059669', '#047857', '#079455', '#16a34a', '#22c55e', '#10b981'];
    const {trend, status, breakdown} = buildProjectChartConfigs(sample);

    for (const color of [...Object.values(projectChartColors), ...allColors(trend), ...allColors(status), ...allColors(breakdown)]) {
        assert.equal(greens.includes(String(color).toLowerCase()), false, `ห้ามใช้สีเขียว: ${color}`);
    }
    assert.deepEqual(
        Object.entries(projectChartColors).filter(([, color]) => color === projectChartColors.late).map(([key]) => key),
        ['late']
    );
});

test('the trend is a line per series: received as amber, completed as blue, the selected month as a large solid point', () => {
    const {trend} = buildProjectChartConfigs(sample);
    const [received, completed] = trend.data.datasets;

    assert.equal(trend.type, 'line');
    assert.deepEqual(trend.data.labels, ['ม.ค.', 'ก.พ.', 'มี.ค.']);
    assert.deepEqual([received.label, completed.label], ['งานที่ได้รับ', 'งานที่เสร็จสิ้น']);
    assert.deepEqual([received.borderColor, completed.borderColor], ['#d97706', '#1d4ed8']);
    assert.deepEqual([received.data, completed.data], [[4, 6, 8], [2, 5, 3]]);
    assert.equal(received.fill, false);
    // เดือนอื่นเป็นจุดกลวงเล็ก เดือนที่เลือกเป็นจุดทึบใหญ่
    assert.deepEqual(completed.pointBackgroundColor, ['#fff', '#fff', '#1d4ed8']);
    assert.deepEqual(completed.pointRadius, [3.5, 3.5, 5]);
    // tooltip อ่านทั้งสองเส้นของเดือนเดียวกันพร้อมกัน
    assert.deepEqual(trend.options.interaction, {mode: 'index', intersect: false});
    assert.equal(trend.options.plugins.tooltip.callbacks.label({dataset: {label: 'งานที่เสร็จสิ้น'}, raw: 5}), 'งานที่เสร็จสิ้น: 5 งาน');
    // แกนเดียว ไม่มีแกนที่สอง
    assert.deepEqual(Object.keys(trend.options.scales), ['x', 'y']);
    assert.equal(chartHasData(trend), true);
    // โค้งไม่พุ่งเกินค่าจริง และป้ายเป็นจุดทึบสีของเส้น ไม่ใช่วงกลวงจากจุดเดือนแรก
    assert.equal(completed.cubicInterpolationMode, 'monotone');
    const legendItems = trend.options.plugins.legend.labels.generateLabels({data: trend.data, isDatasetVisible: () => true});
    assert.deepEqual(legendItems.map((item) => [item.text, item.fillStyle]), [['งานที่ได้รับ', '#d97706'], ['งานที่เสร็จสิ้น', '#1d4ed8']]);
});

test('status is a doughnut coloured by the server palette with the total in the centre and count plus share in the tooltip', () => {
    const {status} = buildProjectChartConfigs(sample);

    assert.equal(status.type, 'doughnut');
    assert.deepEqual(status.data.labels, ['เสร็จสิ้น', 'กำลังทำ', 'รอตรวจสอบ', 'พักงาน', 'ล่าช้า']);
    assert.deepEqual(status.data.datasets[0].data, [4, 3, 0, 0, 7]);
    assert.deepEqual(status.data.datasets[0].backgroundColor, ['#1d4ed8', '#0891b2', '#a21caf', '#64748b', '#e11d48']);
    // ป้าย จำนวน และ % อยู่ในตาราง HTML ข้างวง
    assert.equal(status.options.plugins.legend.display, false);
    assert.equal(status.plugins[0].id, 'projectCenterTotal');
    assert.equal(status.options.plugins.tooltip.callbacks.label({label: 'ล่าช้า', raw: 7}), 'ล่าช้า: 7 งาน (50%)');
    assert.equal(shareOf(4, 14), 29);
    assert.equal(shareOf(3, 0), 0);

    const drawn = [];
    const ctx = {save() {}, restore() {}, fillText: (text) => drawn.push(text)};
    status.plugins[0].afterDraw({ctx, chartArea: {left: 0, right: 200, top: 0, bottom: 200}});
    assert.deepEqual(drawn, ['14', 'งานทั้งหมด']);

    // สีที่ไม่ใช่ hex จาก server ตกกลับไปเป็นสีของสถานะนั้น
    const unsafe = normalizeProjectChartData({status: {keys: ['late', 'done'], values: [1, 1], colors: ['red;x', null]}});
    assert.deepEqual(unsafe.status.colors, [projectChartColors.late, projectChartColors.done]);
});

test('the server status colours are the same values as the page palette', () => {
    const service = readFileSync(new URL('../../app/Services/ProjectReportService.php', import.meta.url), 'utf8');
    const block = service.match(/STATUS_COLORS = \[([^\]]*)\]/)?.[1] ?? '';
    const server = Object.fromEntries([...block.matchAll(/'(\w+)' => '(#[0-9a-f]{6})'/gi)].map(([, key, color]) => [key, color.toLowerCase()]));

    assert.deepEqual(server, {
        done: projectChartColors.done,
        doing: projectChartColors.doing,
        review: projectChartColors.review,
        paused: projectChartColors.paused,
        late: projectChartColors.late,
    });
});

test('work closed per member is a doughnut coloured by the server palette with the total in the centre', () => {
    const {breakdown} = buildProjectChartConfigs(sample);

    assert.equal(breakdown.type, 'doughnut');
    assert.deepEqual(breakdown.data.labels, ['สมชาย', 'สมหญิง', 'อื่น ๆ (2 คน)']);
    assert.deepEqual(breakdown.data.datasets[0].data, [5, 2, 3]);
    assert.deepEqual(breakdown.data.datasets[0].backgroundColor, ['#1d4ed8', '#ea580c', '#64748b']);
    assert.equal(breakdown.data.datasets[0].borderColor, '#fff');
    // ชื่อ จำนวน และ % อยู่ในตาราง HTML ข้างวง
    assert.equal(breakdown.options.plugins.legend.display, false);
    assert.equal(breakdown.plugins[0].id, 'projectCenterTotal');
    assert.equal(breakdown.options.plugins.tooltip.callbacks.label({label: 'สมชาย', raw: 5}), 'สมชาย: 5 งาน (50%)');

    const drawn = [];
    const ctx = {save() {}, restore() {}, fillText: (text) => drawn.push(text)};
    centerTotalPlugin(10, 'งานที่ปิดได้').afterDraw({ctx, chartArea: {left: 0, right: 200, top: 0, bottom: 200}});
    assert.deepEqual(drawn, ['10', 'งานที่ปิดได้']);

    // สีที่ไม่ใช่ hex จาก server ตกเป็นเทา
    const unsafe = normalizeProjectChartData({breakdown: {labels: ['A'], values: [1], colors: ['red;background:url(x)']}});
    assert.deepEqual(unsafe.breakdown.colors, [projectChartColors.paused]);
});

test('broken or empty data normalises to safe empty charts', () => {
    const broken = buildProjectChartConfigs({trend: {labels: ['ม.ค.'], created: [-3], completed: ['a'], selected: 99}, status: {values: [null], total: 'NaN'}, breakdown: {labels: 'x', values: [3]}});

    assert.deepEqual(normalizeProjectChartData({}).trend, {labels: [], created: [], completed: [], selected: 0, allSelected: false});
    // รายบุคคลส่ง breakdown เป็น null
    assert.deepEqual(normalizeProjectChartData({breakdown: null}).breakdown, {labels: [], values: [], colors: [], total: 0});
    assert.equal(normalizeProjectChartData({status: {total: 'NaN'}}).status.total, 0);
    for (const config of Object.values(broken)) {
        assert.equal(chartHasData(config), false);
    }
});

test('a custom period shows every month in the range as a solid point', () => {
    const {trend} = buildProjectChartConfigs({...sample, trend: {...sample.trend, all_selected: true}});
    const [received, completed] = trend.data.datasets;

    assert.deepEqual(received.pointBackgroundColor, ['#d97706', '#d97706', '#d97706']);
    assert.deepEqual(completed.pointBackgroundColor, ['#1d4ed8', '#1d4ed8', '#1d4ed8']);
    assert.deepEqual(completed.pointRadius, [5, 5, 5]);
});
