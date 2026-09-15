import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync} from 'node:fs';
import {readFile} from 'node:fs/promises';
import {initAutoSubmitFilters} from '../../resources/js/components/auto-submit-filter.js';
import {initSelectDropdowns} from '../../resources/js/components/select-dropdown.js';
import {initProjectPeriod} from '../../resources/js/pages/reports/project-period.js';
import {click, mountDom} from './helpers/dom.js';

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');
const exists = (path) => existsSync(new URL(`../../${path}`, import.meta.url));

/*
 * รายงานโปรเจกต์ประจำเดือน — หน้าเดียวแทนหน้าภาพรวมและหน้ารายบุคคลชุดเดิม
 */
test('the report and its details page share one header, filter card, sort control and script', async () => {
    const [index, details, header, filters, sort, js, vite] = await Promise.all([
        read('resources/views/reports/projects/index.blade.php'),
        read('resources/views/reports/projects/details.blade.php'),
        read('resources/views/reports/components/projects/header.blade.php'),
        read('resources/views/reports/components/projects/filters.blade.php'),
        read('resources/views/reports/components/projects/sort.blade.php'),
        read('resources/js/pages/reports/projects.js'),
        read('vite.config.js'),
    ]);

    for (const view of [index, details]) {
        assert.match(view, /@vite\('resources\/css\/pages\/report-projects\.css'\)/);
        assert.match(view, /@vite\('resources\/js\/pages\/reports\/projects\.js'\)/);
        for (const partial of ['header', 'filters', 'sort']) {
            assert.match(view, new RegExp(`@include\\('reports\\.components\\.projects\\.${partial}'`));
        }
    }
    assert.match(vite, /'resources\/css\/pages\/report-projects\.css'/);
    assert.match(vite, /'resources\/js\/pages\/reports\/projects\.js'/);

    // ช่องพนักงาน เดือน และการเรียงส่งฟอร์มทันที ตัวกรองรอปุ่มค้นหา
    for (const name of ['owner', 'month']) {
        assert.match(header, new RegExp(`name="${name}" data-auto-submit`));
    }
    assert.match(sort, /name="sort" data-auto-submit/);
    assert.doesNotMatch(filters, /data-auto-submit/);
    // บทบาท: ทั้งหมด / ที่รับผิดชอบ / ที่ร่วมทำ รอปุ่มค้นหาเหมือนตัวกรองอื่น
    for (const name of ['project', 'status', 'scope', 'role']) {
        assert.match(filters, new RegExp(`name="${name}">`));
    }
    assert.match(header, /route\('reports\.projects\.csv', \$query\)/);

    // ตารางหน้าหลักเหลือปุ่มเดียวไปหน้ารายละเอียด ไม่มีรูปผู้เข้าร่วมคนอื่น
    assert.match(index, /route\('reports\.projects\.details', \$query\)/);
    assert.doesNotMatch(index, /project-report__avatars|project-report__button--small/);
    // ทั้งสองหน้าเป็นตารางเดียวกันรวมหลักฐาน: แถวย่อเป็นป้าย/ไอคอน รายละเอียดเต็มอยู่ในกล่องใบเดียวของหน้า
    const [cellsSource, headSource, templates] = await Promise.all([
        read('resources/views/reports/components/projects/task-cells.blade.php'),
        read('resources/views/reports/components/projects/task-head.blade.php'),
        read('resources/views/reports/components/projects/evidence-templates.blade.php'),
    ]);
    assert.match(cellsSource, /@include\('reports\.components\.projects\.evidence-count'/);
    assert.match(templates, /<template data-evidence-template=/);
    assert.match(templates, /@include\('reports\.components\.projects\.file-list'/);
    for (const [view, rows] of [[index, 'previewRows'], [details, 'pageRows']]) {
        assert.match(view, new RegExp(`@include\\('reports\\.components\\.projects\\.evidence-templates', \\['rows' => \\$${rows}\\]\\)`));
        assert.match(view, /@include\('reports\.components\.projects\.evidence-modal'\)/);
        assert.doesNotMatch(view, /<template data-evidence-template=|<th scope="col">/);
    }
    // สถานะเป็นคอลัมน์สุดท้าย ต่อจาก "เสร็จ"
    assert.match(headSource, /<th scope="col">เสร็จ<\/th>\s*<th scope="col">สถานะ<\/th>\s*$/);
    assert.match(js, /initEvidenceModal\(document\)/);
    // ไฟล์ในคอมเมนต์ไม่ใช่หลักฐานของรายงานนี้
    assert.doesNotMatch(await read('app/Services/ProjectReportService.php'), /comment-attachments|viewComments/);

    assert.match(js, /import \{initAutoSubmitFilters\} from '\.\.\/\.\.\/components\/auto-submit-filter\.js'/);
    assert.match(js, /initSelectDropdowns\(page\)/);
    assert.match(js, /initAutoSubmitFilters\(page\)/);
    assert.match(js, /initSubtaskModal\(document\)/);
    // กราฟสองใบ: แนวโน้มรายเดือนทั้งปี และสัดส่วนสถานะ ใช้ config ของรายงานโปรเจกต์
    assert.match(js, /buildProjectChartConfigs/);
    assert.match(js, /id: 'projectTrendChart', key: 'trend'/);
    assert.match(js, /id: 'projectStatusChart', key: 'status'/);
    assert.match(js, /id: 'projectBreakdownChart', key: 'breakdown'/);
    assert.doesNotMatch(index, /projectStatusByProjectChart|project-report__status-legend|data-chart-kind="line"/);
    // กราฟงานที่ปิดได้รายคน (ภาพรวมแผนก) เป็นโดนัท พร้อมตารางชื่อ/จำนวน/% ข้างวง
    assert.match(index, /project-report__chart-card--breakdown" data-report-chart data-chart-kind="doughnut"/);
    assert.match(index, /class="project-report__donut-legend"/);
    assert.match(index, /id="projectTrendChart"/);
    // ภาพรวมแผนกได้อีกสองการ์ด: งานล่าช้าที่ต้องติดตาม (รายการไม่มีแถบ progress) และงานที่ปิดได้รายคน
    assert.match(index, /@if\(\$isTeamView\)\s*<article class="project-report__card project-report__late-card"/);
    assert.match(index, /class="project-report__late-list"/);
    assert.doesNotMatch(index, /late-meter/);
    // ปุ่มดูรายละเอียดทั้งหมดมีทุกมุมมอง (ไม่ถูกครอบด้วยเงื่อนไขภาพรวมแผนก)
    assert.match(index, /<a href="\{\{ route\('reports\.projects\.details', \$query\) \}\}"/);
    assert.doesNotMatch(index, /@unless\(\$isTeamView\)\s*<a href="\{\{ route\('reports\.projects\.details'/);
    // ชื่องานเป็นข้อความธรรมดา ไม่มีลิงก์ไปหน้างานทั้งหน้าหลักและหน้ารายละเอียด
    for (const view of [index, details]) {
        assert.doesNotMatch(view, /\$(row|item)\['url'\]/);
    }
    // ตารางหน้าหลักและหน้ารายละเอียดใช้หัวคอลัมน์และเซลล์ชุดเดียวกัน กดดูทั้งหมดแล้วจึงเจอตารางเดิม
    const [taskHead, taskCells] = await Promise.all([
        read('resources/views/reports/components/projects/task-head.blade.php'),
        read('resources/views/reports/components/projects/task-cells.blade.php'),
    ]);
    for (const view of [index, details]) {
        assert.match(view, /@include\('reports\.components\.projects\.task-head'\)/);
        assert.match(view, /@include\('reports\.components\.projects\.task-cells', \['row' => \$row\]\)/);
    }
    assert.match(taskHead, /<th scope="col">สถานะ<\/th>/);
    // ผู้เข้าร่วมกดดูรายชื่อได้
    assert.match(taskCells, /data-participants-trigger/);
    assert.match(js, /initParticipantsPopovers\(document\)/);
});

test('the replaced report screens leave no page, stylesheet, script or entry behind', async () => {
    for (const path of [
        'resources/views/reports/employees/index.blade.php',
        'resources/views/reports/employee.blade.php',
        'resources/views/reports/projects/employee.blade.php',
        'resources/views/reports/projects/overview.blade.php',
        'resources/views/reports/components/project-owner-select.blade.php',
        'resources/views/reports/components/organization-body.blade.php',
        'resources/css/components/project-owner-filter.css',
        'resources/css/pages/report-employee.css',
        'resources/css/pages/report-employees.css',
        'resources/css/pages/reports/employee.css',
        'resources/css/pages/reports/employee-selection.css',
        'resources/js/pages/reports/employee.js',
        'resources/js/pages/reports/employee-chart-config.js',
        // รายงานภาพรวมองค์กรถูกยกเลิก ภาพรวมแผนกอยู่ในรายงานโปรเจกต์แล้ว
        'app/Services/AdminReportService.php',
        'resources/views/reports/organization.blade.php',
        'resources/views/reports/components/charts.blade.php',
        'resources/views/reports/components/department-table.blade.php',
        'resources/views/reports/components/attention-list.blade.php',
        'resources/views/reports/components/filters.blade.php',
        'resources/js/pages/reports/index.js',
        'resources/css/pages/report-organization.css',
        'resources/css/pages/reports/organization.css',
    ]) {
        assert.equal(exists(path), false, `${path} ต้องถูกลบเมื่อหน้าใหม่มาแทน`);
    }

    const [vite, typography, operationalControls, operationalEntry, chartConfig] = await Promise.all([
        read('vite.config.js'),
        read('resources/css/foundations/typography.css'),
        read('resources/js/pages/reports/operational-controls.js'),
        read('resources/css/pages/report-operational.css'),
        read('resources/js/pages/reports/chart-config.js'),
    ]);
    assert.doesNotMatch(vite, /report-employees?\.css|reports\/employee\.js|report-organization\.css|reports\/index\.js/);
    assert.doesNotMatch(typography, /employee-picker|employee-report|report-page__eyebrow/);
    assert.doesNotMatch(operationalEntry, /organization\.css/);
    // กราฟขององค์กรไม่เหลือค้างในชุดค่าร่วมของกราฟ
    assert.doesNotMatch(chartConfig, /buildReportChartConfigs|orderStatusSlices|stackedTotalLabels/);
    // รายงานปฏิบัติงานใช้ตัวส่งฟอร์มอัตโนมัติตัวเดียวกัน ไม่มีโค้ดชุดที่สอง
    assert.match(operationalControls, /initAutoSubmitFilters\(root, '\[data-operational-filter\]'\)/);
});

test('choosing an employee from the dropdown submits the header form exactly once', (t) => {
    const env = mountDom();
    t.after(env.cleanup);

    env.document.body.innerHTML = `
        <div class="project-report">
            <form data-auto-submit-form action="/reports/projects" method="GET">
                <div data-sg-select>
                    <label for="projectReportOwner">พนักงาน</label>
                    <select id="projectReportOwner" name="owner" data-auto-submit>
                        <option value="" selected>ภาพรวมแผนก IT</option>
                        <option value="7">ธนากร</option>
                    </select>
                </div>
            </form>
        </div>`;

    const root = env.document.querySelector('.project-report');
    const form = root.querySelector('form');
    let submissions = 0;
    form.requestSubmit = () => { submissions += 1; };

    initSelectDropdowns(root);
    initAutoSubmitFilters(root);
    initAutoSubmitFilters(root);

    click(root.querySelector('.sg-select__trigger'));
    click([...root.querySelectorAll('.sg-select__option')][1]);

    assert.equal(root.querySelector('select').value, '7');
    assert.equal(submissions, 1);
});

/*
 * ช่วงเวลา: เลือก "กำหนดช่วงวันที่เอง" แล้วต้องยังไม่ส่ง รอกรอกช่วงก่อน
 * และเมื่อกลับไปเลือกเดือน ช่องวันที่ต้องไม่ติดไปกับฟอร์ม ไม่อย่างนั้น server จะเลือกช่วงเดิม
 */
test('the period dropdown opens a date range for a custom period and never submits a stale range', (t) => {
    const env = mountDom();
    t.after(env.cleanup);

    env.document.body.innerHTML = `
        <div class="project-report">
            <form data-auto-submit-form action="/reports/projects" method="GET">
                <div data-sg-select>
                    <label for="projectReportMonth">ช่วงเวลา</label>
                    <select id="projectReportMonth" name="month" data-auto-submit data-period-select>
                        <option value="2026-09" selected>กันยายน 2569</option>
                        <option value="2026-08">สิงหาคม 2569</option>
                        <option value="custom" data-auto-submit-skip>กำหนดช่วงวันที่เอง…</option>
                    </select>
                </div>
                <div data-period-range hidden>
                    <input type="date" name="from" value="2026-09-01" data-period-input="from" disabled>
                    <input type="date" name="to" value="2026-09-30" data-period-input="to" disabled>
                </div>
            </form>
        </div>`;

    const root = env.document.querySelector('.project-report');
    const form = root.querySelector('form');
    const range = root.querySelector('[data-period-range]');
    const inputs = [...root.querySelectorAll('[data-period-input]')];
    let submissions = 0;
    form.requestSubmit = () => {
        submissions += 1;
        form.dispatchEvent(new Event('submit', {cancelable: true}));
    };

    initSelectDropdowns(root);
    initAutoSubmitFilters(root);
    initProjectPeriod(root);
    initProjectPeriod(root);

    const choose = (index) => {
        click(root.querySelector('.sg-select__trigger'));
        click([...root.querySelectorAll('.sg-select__option')][index]);
    };

    choose(2);
    assert.equal(submissions, 0, 'เลือกกำหนดช่วงเองแล้วต้องยังไม่ส่ง');
    assert.equal(range.hidden, false);
    assert.deepEqual(inputs.map((input) => input.disabled), [false, false]);

    choose(1);
    assert.equal(submissions, 1, 'เลือกเดือนแล้วส่งครั้งเดียว');
    assert.equal(range.hidden, true);
    assert.deepEqual(inputs.map((input) => input.disabled), [true, true]);
});
