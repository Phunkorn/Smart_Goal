import test from 'node:test';
import assert from 'node:assert/strict';
import {chartHasData} from '../../resources/js/pages/reports/chart-lifecycle.js';
import {buildProjectChartConfigs, centerTotalPlugin, normalizeProjectChartData, projectChartColors, statusValueLabels, tint} from '../../resources/js/pages/reports/project-chart-config.js';

/** รูปแบบเดียวกับ ProjectReportService::chartData() */
const sample = {
    trend: {keys: ['2026-01', '2026-02', '2026-03'], labels: ['ม.ค.', 'ก.พ.', 'มี.ค.'], created: [4, 6, 8], completed: [2, 5, 3], selected: 2},
    status: {keys: ['done', 'doing', 'review', 'paused', 'late'], labels: ['เสร็จสิ้น', 'กำลังทำ', 'รอตรวจสอบ', 'พักงาน', 'ล่าช้า'], values: [4, 3, 0, 0, 7], total: 14},
    breakdown: {labels: ['สมชาย', 'สมหญิง', 'อื่น ๆ (2 คน)'], values: [5, 2, 3], colors: ['#1d4ed8', '#ea580c', '#64748b'], total: 10},
};

const allColors = (config) => config.data.datasets.flatMap((set) => [set.backgroundColor].flat());

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

test('the trend is grouped monthly columns: received as context, completed as the main colour, selected month emphasised', () => {
    const {trend} = buildProjectChartConfigs(sample);
    const [received, completed] = trend.data.datasets;

    assert.equal(trend.type, 'bar');
    assert.deepEqual(trend.data.labels, ['ม.ค.', 'ก.พ.', 'มี.ค.']);
    assert.deepEqual([received.label, completed.label], ['งานที่ได้รับ', 'งานที่เสร็จ']);
    assert.deepEqual(completed.backgroundColor, [tint('#1d4ed8', 0.45), tint('#1d4ed8', 0.45), '#1d4ed8']);
    assert.deepEqual(received.backgroundColor, [tint('#d97706', 0.45), tint('#d97706', 0.45), '#d97706']);
    // แกนเดียว ไม่มีแกนที่สอง
    assert.deepEqual(Object.keys(trend.options.scales), ['x', 'y']);
    assert.equal(chartHasData(trend), true);
});

test('status is a horizontal bar per status with count and share written at the bar end', () => {
    const {status} = buildProjectChartConfigs(sample);

    assert.equal(status.type, 'bar');
    assert.equal(status.options.indexAxis, 'y');
    assert.equal(status.options.plugins.legend.display, false);
    assert.deepEqual(status.data.datasets[0].backgroundColor, ['#1d4ed8', '#0891b2', '#a21caf', '#64748b', '#e11d48']);

    const drawn = [];
    const ctx = {save() {}, restore() {}, fillText: (text) => drawn.push(text)};
    const chart = {ctx, data: {datasets: [{data: [4, 3, 0, 0, 7]}]}, getDatasetMeta: () => ({data: [0, 1, 2, 3, 4].map((i) => ({x: 10, y: i}))})};
    statusValueLabels(14).afterDatasetsDraw(chart);
    assert.deepEqual(drawn, ['4 งาน · 29%', '3 งาน · 21%', '0 งาน · 0%', '0 งาน · 0%', '7 งาน · 50%']);
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

test('a custom period shows every month in the range at full strength', () => {
    const {trend} = buildProjectChartConfigs({...sample, trend: {...sample.trend, all_selected: true}});
    const [received, completed] = trend.data.datasets;

    assert.deepEqual(received.backgroundColor, ['#d97706', '#d97706', '#d97706']);
    assert.deepEqual(completed.backgroundColor, ['#1d4ed8', '#1d4ed8', '#1d4ed8']);
});
