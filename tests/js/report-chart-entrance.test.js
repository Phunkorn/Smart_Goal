import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {reportChartAnimation} from '../../resources/js/pages/reports/chart-config.js';
import {buildProjectChartConfigs} from '../../resources/js/pages/reports/project-chart-config.js';
import {buildOperationalChartConfigs} from '../../resources/js/pages/reports/operational-chart-config.js';

/**
 * การวาดกราฟตอนเข้าหน้า Dashboard ทั้งสองรายงาน — ใช้ Animation API ของ Chart.js ตัวเดิม
 *
 * easeOutQuint ของ Chart.js คือเส้นโค้งเดียวกับ cubic-bezier(0.22, 1, 0.36, 1) ที่การ์ดและตารางใช้
 * (shared.css: --report-ease) ทั้งหน้าจึงชะลอเข้าที่ด้วยจังหวะเดียวกัน
 */
const projectData = {
    trend: {labels: ['ม.ค.', 'ก.พ.'], created: [2, 3], completed: [1, 2], selected: 1},
    status: {keys: ['done', 'late'], labels: ['เสร็จสิ้น', 'ล่าช้า'], values: [3, 1], colors: ['#1d4ed8', '#e11d48'], total: 4},
    breakdown: {labels: ['สมชาย'], values: [3], colors: ['#1d4ed8'], total: 3},
};

test('every chart on both dashboards draws in 500–800ms with the page easing', () => {
    assert.equal(reportChartAnimation.easing, 'easeOutQuint');
    assert.ok(reportChartAnimation.duration >= 500 && reportChartAnimation.duration <= 800, `duration ${reportChartAnimation.duration}`);
    assert.ok(Object.isFrozen(reportChartAnimation));

    const charts = {...buildProjectChartConfigs(projectData), ...Object.fromEntries(Object.entries(buildOperationalChartConfigs({})).map(([key, config]) => [`operational.${key}`, config]))};

    for (const [key, config] of Object.entries(charts)) {
        assert.equal(config.options.animation.duration, reportChartAnimation.duration, key);
        assert.equal(config.options.animation.easing, 'easeOutQuint', key);
    }
    // โดนัทหมุนกวาดเข้า ไม่ขยายจากกลางวง
    for (const key of ['status', 'breakdown', 'operational.workTypes']) {
        assert.equal(charts[key].options.animation.animateRotate, true, key);
        assert.equal(charts[key].options.animation.animateScale, false, key);
    }
});

test('the trend line rises from its baseline in place instead of fanning out from the centre', () => {
    const {trend} = buildProjectChartConfigs(projectData);

    // Chart.js เริ่มทุกจุดที่กึ่งกลางแกน x ในโหมด reset — ตรึงแกน x ไว้ ให้เคลื่อนเฉพาะแนวตั้ง
    assert.deepEqual(trend.options.animations, {x: {duration: 0}});
});

test('the css easing and the chart easing are the same curve', () => {
    const shared = readFileSync(new URL('../../resources/css/pages/reports/shared.css', import.meta.url), 'utf8');
    const chartConfig = readFileSync(new URL('../../resources/js/pages/reports/chart-config.js', import.meta.url), 'utf8');

    assert.match(shared, /--report-ease:cubic-bezier\(0\.22, 1, 0\.36, 1\)/);
    // easeOutQuint(t) = 1 - (1 - t)^5 — ตรวจว่าเส้นโค้ง CSS ผ่านจุดเดียวกันโดยประมาณ (ค่าคลาดเคลื่อน < 0.03)
    const bezier = (p1x, p1y, p2x, p2y) => (x) => {
        let lo = 0;
        let hi = 1;
        const at = (t, a, b) => 3 * a * t * (1 - t) ** 2 + 3 * b * t * t * (1 - t) + t ** 3;
        for (let i = 0; i < 60; i += 1) {
            const mid = (lo + hi) / 2;
            if (at(mid, p1x, p2x) < x) lo = mid; else hi = mid;
        }
        return at((lo + hi) / 2, p1y, p2y);
    };
    const css = bezier(0.22, 1, 0.36, 1);
    for (const x of [0.1, 0.25, 0.5, 0.75, 0.9]) {
        assert.ok(Math.abs(css(x) - (1 - (1 - x) ** 5)) < 0.03, `x=${x}`);
    }
    assert.match(chartConfig, /cubic-bezier\(0\.22, 1, 0\.36, 1\)/);
});
