import assert from 'node:assert/strict';
import {existsSync, readFileSync} from 'node:fs';
import test from 'node:test';
import {reportChartColors} from '../../resources/js/pages/reports/chart-config.js';
import {buildTicketChartConfigs, departmentColors, departmentSliceColors, normalizeTicketChartData} from '../../resources/js/pages/reports/ticket-chart-config.js';
import {initTicketReport} from '../../resources/js/pages/reports/tickets.js';
import {click, mountDom} from './helpers/dom.js';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const stripCssComments = (css) => css.replace(/\/\*[\s\S]*?\*\//g, '');

const chartData = {
    trend: {labels: ['1 ต.ค.', '2 ต.ค.', '3 ต.ค.'], values: [2, 0, 5], unit: 'วัน'},
    departments: {labels: ['บัญชี', 'บุคคลภายนอก', 'อื่น ๆ (2 แผนก)'], values: [4, 3, 2], other: true},
};

/** markup เดียวกับ resources/views/reports/tickets.blade.php ส่วนที่สคริปต์แตะ */
function page(data = chartData, {trendState = 'loading', departmentState = 'loading', extraOpen = false} = {}) {
    const card = (id, kind, state) => `
        <article class="ticket-report__card" data-report-chart data-chart-kind="${kind}" data-chart-state="${state}">
            <div class="report-chart-shell">
                <div class="report-chart-skeleton" data-chart-skeleton></div>
                <div class="report-chart-wrap"><canvas id="${id}"></canvas></div>
                <div class="report-chart-state report-chart-state--empty" data-chart-empty></div>
                <div class="report-chart-state report-chart-state--error" data-chart-error></div>
            </div>
        </article>`;

    return `<!doctype html><html><body><div class="ticket-report">
        <form method="GET" action="/reports/tickets" class="ticket-report__filters" data-auto-submit-form>
            <select name="month" data-auto-submit data-period-select>
                <option value="custom" data-auto-submit-skip>กำหนดช่วงวันที่เอง…</option>
                <option value="2026-10" selected>ตุลาคม 2569</option>
                <option value="2026-09">กันยายน 2569</option>
            </select>
            <div class="ticket-report__range" data-period-range hidden>
                <input type="date" name="from" value="2026-10-01" data-period-input="from" disabled>
                <input type="date" name="to" value="2026-10-31" data-period-input="to" disabled>
                <button type="submit">แสดงผล</button>
            </div>
            <select name="status" data-auto-submit><option value="">ทุกสถานะ</option><option value="closed">ปิดงาน</option></select>
            <button type="button" hidden aria-expanded="false" aria-controls="extra" data-ticket-filter-toggle>ตัวกรองเพิ่มเติม</button>
            <div id="extra" data-ticket-filter-panel${extraOpen ? ' data-open' : ''}>
                <select name="location" data-auto-submit><option value="">ทุกสาขา</option><option value="other">อื่น ๆ</option></select>
            </div>
        </form>
        <dl><div data-ticket-fact="total"><dd>7</dd></div></dl>
        ${card('ticketTrendChart', 'bar', trendState)}
        ${card('ticketDepartmentChart', 'doughnut', departmentState)}
        <form method="GET" action="/reports/tickets" data-auto-submit-form>
            <input type="search" name="q" data-auto-submit-debounce="500">
        </form>
        <script type="application/json" id="ticket-report-chart-data">${JSON.stringify(data)}</script>
        <table data-ticket-report-list><tbody><tr data-ticket-row="1"><th>TK-000001</th></tr></tbody></table>
    </div></body></html>`;
}

/** Chart.js จำลอง: เก็บ config และเรียก afterDraw ของ plugin เหมือนวาดเสร็จ */
function fakeChart({fail = false} = {}) {
    const created = [];

    class FakeChart {
        constructor(canvas, config) {
            if (fail) throw new Error('canvas unavailable');
            this.canvas = canvas;
            this.config = config;
            created.push(this);
            config.plugins.forEach((plugin) => plugin.afterDraw?.(this));
        }
    }

    return {FakeChart, created};
}

test('chart data is sanitised: bad numbers become 0 and series never outrun their labels', () => {
    const data = normalizeTicketChartData({
        trend: {labels: ['a', 'b'], values: [3, -1, 9], unit: '<script>'},
        departments: {labels: 'nope', values: [1], other: 'yes'},
    });

    assert.deepEqual(data.trend, {labels: ['a', 'b'], values: [3, 0], unit: 'วัน'});
    assert.deepEqual(data.departments, {labels: [], values: [], other: false});
    assert.deepEqual(normalizeTicketChartData(null).trend.values, []);
});

test('two charts: a vertical daily trend bar and a department donut in the report colours', () => {
    const configs = buildTicketChartConfigs(chartData);

    assert.deepEqual(Object.keys(configs), ['trend', 'departments']);
    assert.equal(configs.trend.type, 'bar');
    assert.deepEqual(configs.trend.data.datasets[0].data, [2, 0, 5]);
    assert.equal(configs.trend.data.datasets[0].backgroundColor, reportChartColors.blue);
    assert.equal(configs.trend.options.scales.y.ticks.precision, 0, 'Ticket เป็นจำนวนเต็ม');
    assert.equal(configs.trend.options.plugins.tooltip.callbacks.label({parsed: {y: 5}}), '5 Ticket');

    assert.equal(configs.departments.type, 'doughnut');
    assert.deepEqual(configs.departments.data.labels, ['บัญชี', 'บุคคลภายนอก', 'อื่น ๆ (2 แผนก)']);
    assert.deepEqual(configs.departments.data.datasets[0].data, [4, 3, 2]);
    assert.equal(configs.departments.options.plugins.legend.display, false, 'legend เป็น HTML จาก server');
    assert.equal(configs.departments.options.scales, undefined);
});

test('donut slices follow the report colour order and the folded "other" slice is grey', () => {
    assert.deepEqual([...departmentSliceColors], [reportChartColors.blue, reportChartColors.green, reportChartColors.purple, reportChartColors.amber, reportChartColors.red]);
    assert.deepEqual(departmentColors({labels: ['a', 'b', 'c'], other: false}), [reportChartColors.blue, reportChartColors.green, reportChartColors.purple]);
    assert.deepEqual(departmentColors({labels: ['a', 'b', 'c'], other: true}), [reportChartColors.blue, reportChartColors.green, reportChartColors.gray]);
    assert.equal(departmentColors({labels: ['a', 'b', 'c', 'd', 'e', 'f'], other: true}).at(-1), reportChartColors.gray);
    assert.deepEqual(buildTicketChartConfigs(chartData).departments.data.datasets[0].backgroundColor, departmentColors(normalizeTicketChartData(chartData).departments));
});

test('the donut tooltip names the department with its count and share of the total', () => {
    const label = buildTicketChartConfigs(chartData).departments.options.plugins.tooltip.callbacks.label;
    const dataset = {data: [4, 3, 2]};

    assert.equal(label({label: 'บัญชี', raw: 4, dataset}), 'บัญชี: 4 Ticket (44.4%)');
    assert.equal(label({label: 'x', raw: 0, dataset: {data: [0]}}), 'x: 0 Ticket (0%)');
});

test('the slice colours in CSS are the same values the chart uses', () => {
    const css = stripCssComments(read('resources/css/pages/reports/tickets.css'));
    const cssColor = (name) => css.match(new RegExp(`--tr-slice-${name}: (#[0-9a-f]{6});`, 'i'))?.[1].toLowerCase();

    departmentSliceColors.forEach((color, index) => assert.equal(cssColor(index), color, `slice ${index}`));
    assert.equal(cssColor('other'), reportChartColors.gray);
});

test('the chart config declares no colours of its own', () => {
    const source = read('resources/js/pages/reports/ticket-chart-config.js').replace(/\/\*[\s\S]*?\*\//g, '');
    const hexes = source.match(/#[0-9a-f]{6}\b/gi) ?? [];

    assert.deepEqual(hexes.map((hex) => hex.toLowerCase()), ['#334155'], 'มีแค่สีตัวอักษรของแกน (เดียวกับรายงานโปรเจกต์)');
});

test('opening the page draws both charts once and reveals them', () => {
    const dom = mountDom(page());
    try {
        const {FakeChart, created} = fakeChart();
        initTicketReport(dom.document, {ChartCtor: FakeChart});
        initTicketReport(dom.document, {ChartCtor: FakeChart});

        assert.equal(created.length, 2, 'เรียกซ้ำไม่สร้างกราฟซ้ำ');
        assert.deepEqual(created.map((chart) => chart.canvas.id), ['ticketTrendChart', 'ticketDepartmentChart']);
        dom.document.querySelectorAll('[data-report-chart]').forEach((card) => assert.equal(card.dataset.chartState, 'ready'));
    } finally {
        dom.cleanup();
    }
});

test('an empty period shows the empty state and never builds a chart', () => {
    const dom = mountDom(page({trend: {labels: ['1 ต.ค.'], values: [0]}, departments: {labels: [], values: []}}, {trendState: 'empty', departmentState: 'empty'}));
    try {
        const {FakeChart, created} = fakeChart();
        initTicketReport(dom.document, {ChartCtor: FakeChart});

        assert.equal(created.length, 0);
        dom.document.querySelectorAll('[data-report-chart]').forEach((card) => assert.equal(card.dataset.chartState, 'empty'));
    } finally {
        dom.cleanup();
    }
});

test('a chart failure shows the error state while server numbers and tables stay', () => {
    const dom = mountDom(page());
    try {
        const {FakeChart} = fakeChart({fail: true});
        assert.doesNotThrow(() => initTicketReport(dom.document, {ChartCtor: FakeChart}));

        dom.document.querySelectorAll('[data-report-chart]').forEach((card) => assert.equal(card.dataset.chartState, 'error'));
        assert.equal(dom.document.querySelector('[data-ticket-fact="total"] dd').textContent, '7');
        assert.ok(dom.document.querySelector('[data-ticket-row="1"]'));
    } finally {
        dom.cleanup();
    }
});

test('broken chart JSON is treated as no data instead of throwing', () => {
    const dom = mountDom(page().replace(JSON.stringify(chartData), '{not json'));
    try {
        const {FakeChart, created} = fakeChart();
        assert.doesNotThrow(() => initTicketReport(dom.document, {ChartCtor: FakeChart}));
        assert.equal(created.length, 0);
    } finally {
        dom.cleanup();
    }
});

test('prefers-reduced-motion turns chart animation off', () => {
    const dom = mountDom(page());
    try {
        dom.window.matchMedia = (query) => ({matches: query === '(prefers-reduced-motion: reduce)'});
        globalThis.window.matchMedia = dom.window.matchMedia;
        const {FakeChart, created} = fakeChart();
        initTicketReport(dom.document, {ChartCtor: FakeChart});

        assert.equal(created.length, 2);
        created.forEach((chart) => assert.equal(chart.config.options.animation, false));
    } finally {
        dom.cleanup();
    }
});

test('filters submit on change, but the custom range waits for its dates', () => {
    const dom = mountDom(page());
    try {
        const form = dom.document.querySelector('form');
        let submitted = 0;
        form.requestSubmit = () => { submitted += 1; };
        initTicketReport(dom.document, {ChartCtor: fakeChart().FakeChart});

        const period = form.querySelector('[data-period-select]');
        const range = form.querySelector('[data-period-range]');
        period.value = 'custom';
        period.dispatchEvent(new dom.window.Event('change', {bubbles: true}));
        assert.equal(submitted, 0, 'กำหนดช่วงเอง = เปิดช่องวันที่ ยังไม่ส่ง');
        assert.equal(range.hidden, false);
        assert.ok([...range.querySelectorAll('input')].every((input) => !input.disabled));

        period.value = '2026-09';
        period.dispatchEvent(new dom.window.Event('change', {bubbles: true}));
        assert.equal(submitted, 1);
        assert.ok([...range.querySelectorAll('input')].every((input) => input.disabled), 'from/to ไม่ติดไปกับการเลือกเดือน');

        const status = form.querySelector('select[name="status"]');
        status.value = 'closed';
        status.dispatchEvent(new dom.window.Event('change', {bubbles: true}));
        assert.equal(submitted, 2);
    } finally {
        dom.cleanup();
    }
});

test('the page is registered with Vite and loads its own entries', () => {
    const vite = read('vite.config.js');
    const view = read('resources/views/reports/tickets.blade.php');

    assert.match(vite, /'resources\/css\/pages\/report-tickets\.css'/);
    assert.match(vite, /'resources\/js\/pages\/reports\/tickets\.js'/);
    assert.match(view, /@vite\('resources\/css\/pages\/report-tickets\.css'\)/);
    assert.match(view, /@vite\('resources\/js\/pages\/reports\/tickets\.js'\)/);
    assert.match(read('resources/css/pages/report-tickets.css'), /@import '\.\/reports\/shared\.css';/);
});

test('ticket report CSS never hides or truncates data and has no gradients or !important', () => {
    const css = stripCssComments(read('resources/css/pages/reports/tickets.css'));

    assert.doesNotMatch(css, /!important/);
    assert.doesNotMatch(css, /gradient\(/);
    assert.doesNotMatch(css, /overflow\s*:\s*hidden/);
    assert.doesNotMatch(css, /text-overflow|line-clamp/);
    assert.doesNotMatch(css, /position\s*:\s*(fixed|sticky)/);
    // ตารางเป็นการ์ดก่อนถึงเดสก์ท็อป และหัวตารางยังอ่านได้ด้วย screen reader
    assert.match(css, /@media \(max-width: 1279\.98px\) \{\s*\.ticket-report__table--cards thead \{[^}]*clip-path: inset\(50%\);/);
    assert.match(css, /\.ticket-report__table--cards td::before \{ content: attr\(data-label\);/);
});

test('ticket report Blade has no inline styles, style blocks or native dialogs', () => {
    const files = ['tickets', 'components/tickets/filters', 'components/tickets/ticket-table', 'components/tickets/pager'];

    for (const file of files) {
        const source = read(`resources/views/reports/${file}.blade.php`);
        assert.doesNotMatch(source, /<style|\sstyle="/, file);
        assert.doesNotMatch(source, /(alert|confirm|prompt)\(/, file);
    }
});

test('the additional-details accordions are gone from Blade and CSS, with no leftover partials', () => {
    const view = read('resources/views/reports/tickets.blade.php');
    const css = stripCssComments(read('resources/css/pages/reports/tickets.css'));

    for (const partial of ['sla', 'breakdown', 'people', 'definitions']) {
        assert.equal(existsSync(new URL(`../../resources/views/reports/components/tickets/${partial}.blade.php`, import.meta.url)), false, partial);
        assert.doesNotMatch(view, new RegExp(`components\.tickets\.${partial}`));
    }
    assert.doesNotMatch(view, /<details|ticket-report__more|ticket-report__disclosure/);
    assert.doesNotMatch(css, /disclosure|__more|__facts|__sla|__watch|__breakdown|__share|__meter|__definitions|is-number|table--people/);
});

test('the extra filters fold away behind their button and open by themselves when one is in use', () => {
    const closed = mountDom(page());
    try {
        initTicketReport(closed.document, {ChartCtor: fakeChart().FakeChart});
        const toggle = closed.document.querySelector('[data-ticket-filter-toggle]');
        const panel = closed.document.querySelector('#extra');

        assert.equal(toggle.hidden, false);
        assert.equal(panel.hidden, true);

        click(toggle);
        assert.equal(panel.hidden, false);
        assert.equal(toggle.getAttribute('aria-expanded'), 'true');

        click(toggle);
        assert.equal(panel.hidden, true);
    } finally {
        closed.cleanup();
    }

    const open = mountDom(page(chartData, {extraOpen: true}));
    try {
        initTicketReport(open.document, {ChartCtor: fakeChart().FakeChart});

        assert.equal(open.document.querySelector('#extra').hidden, false, 'มีตัวกรองรองใช้อยู่ = เปิดไว้');
    } finally {
        open.cleanup();
    }
});

test('typing in the ticket search submits only the search form once typing stops', async () => {
    const dom = mountDom(page());
    try {
        const [filters, search] = dom.document.querySelectorAll('form');
        const submitted = [];
        filters.requestSubmit = () => submitted.push('filters');
        search.requestSubmit = () => submitted.push('search');
        initTicketReport(dom.document, {ChartCtor: fakeChart().FakeChart});
        initTicketReport(dom.document, {ChartCtor: fakeChart().FakeChart});

        const input = search.querySelector('input[name="q"]');
        for (const value of ['ก', 'กอ', 'กอม']) {
            input.value = value;
            input.dispatchEvent(new dom.window.Event('input', {bubbles: true}));
        }
        assert.deepEqual(submitted, [], 'ยังไม่ส่งระหว่างพิมพ์');

        await new Promise((resolve) => setTimeout(resolve, 650));
        assert.deepEqual(submitted, ['search'], 'ส่งครั้งเดียวหลังหยุดพิมพ์ และไม่ผูก listener ซ้ำ');
    } finally {
        dom.cleanup();
    }
});

test('the report page has no KPI cards and keeps the chart and table hooks', () => {
    const view = read('resources/views/reports/tickets.blade.php');
    const table = read('resources/views/reports/components/tickets/ticket-table.blade.php');
    const filters = read('resources/views/reports/components/tickets/filters.blade.php');
    const css = stripCssComments(read('resources/css/pages/reports/tickets.css'));

    for (const source of [view, table, filters, css]) {
        assert.doesNotMatch(source, /report-kpi|ticket-report__kpi|data-ticket-kpi/);
    }
    assert.match(view, /id="ticketTrendChart"/);
    assert.match(view, /id="ticketDepartmentChart"/);
    assert.doesNotMatch(view, /ticketLocationChart/);
    assert.match(table, /data-auto-submit-debounce/);
    assert.match(table, /aria-label="ดูรายละเอียด \{\{ \$ticket->ticket_number \}\}" title="ดูรายละเอียด"><i class="bi bi-eye" aria-hidden="true"><\/i><\/a>/);
    assert.doesNotMatch(table, /type="checkbox"/);
    // ปุ่มพับตัวกรองเพิ่มเติมซ่อนไว้ก่อน — ไม่มี JS ตัวกรองรองยังใช้ได้ครบ
    assert.match(filters.match(/<button[\s\S]*?data-ticket-filter-toggle>/)?.[0] ?? '', /\shidden\s/);
    assert.match(css, /\.ticket-report \[hidden\] \{ display: none; \}/);
});

test('the shared filter toggle lives in one component that the queue page re-exports', () => {
    const queue = read('resources/js/pages/tickets/index.js');

    assert.match(read('resources/js/components/ticket-filter-toggle.js'), /export function initTicketFilterToggle/);
    assert.doesNotMatch(queue, /export function initTicketFilterToggle/);
    assert.match(queue, /export \{initTicketFilterToggle\}/);
    assert.match(read('resources/js/pages/reports/tickets.js'), /components\/ticket-filter-toggle\.js/);
});

test('chart height comes from three server-chosen tiers that also set the shared mobile variable', () => {
    const css = stripCssComments(read('resources/css/pages/reports/tickets.css'));
    const view = read('resources/views/reports/tickets.blade.php');

    for (const [tier, desktop, mobile] of [['sm', 200, 200], ['md', 260, 240], ['lg', 320, 280]]) {
        assert.match(css, new RegExp(`\.ticket-report__charts--${tier} \{ --report-chart-height: ${desktop}px; --report-chart-height-mobile: ${mobile}px; \}`));
    }
    assert.match(view, /ticket-report__charts--\{\{ \['sm', 'md', 'lg'\]\[\$chartTier\] \}\}/);
    assert.doesNotMatch(css, /\.ticket-report \{[^}]*--report-chart-height/, 'ไม่มีความสูงกราฟคงที่ที่ราก ซ้อนกับ tier');
});

test('the ticket search placeholder names the list it searches', () => {
    assert.match(read('resources/views/reports/components/tickets/ticket-table.blade.php'), /placeholder="ค้นหาในรายการ Ticket"/);
});

test('the report root takes the width of the layout main area and never caps or re-centres itself', () => {
    const css = stripCssComments(read('resources/css/pages/reports/tickets.css'));
    const root = css.match(/\.ticket-report \{([^}]*)\}/)?.[1] ?? '';

    assert.match(root, /width: 100%;/);
    assert.doesNotMatch(root, /max-width|margin-inline|margin-left|margin-right|100vw/, 'ความกว้างมาจาก main.main ของ layout');
    assert.doesNotMatch(read('resources/css/pages/report-tickets.css'), /max-width|margin-inline/);
});
