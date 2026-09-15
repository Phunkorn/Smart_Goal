import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {reportChartColors} from '../../resources/js/pages/reports/chart-config.js';

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
        'resources/js/pages/reports/project-chart-config.js',
        'app/Services/ProjectReportService.php',
    ].map((path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8')).join('\n');

    for (const retired of ['#f59e0b', '#ef4444', '#2375ed', '#12a66a', '#7c3aed', '#94a3b8']) {
        assert.equal(sources.includes(retired), false, `ยังพบสีเดิมที่ตกเกณฑ์: ${retired}`);
    }
});

test('โดนัทงานที่ปิดได้รายคนไม่มีเขียวและไม่ใช้แดงที่สงวนไว้สื่อล่าช้า', () => {
    const service = readFileSync(new URL('../../app/Services/ProjectReportService.php', import.meta.url), 'utf8');
    const colors = [...(service.match(/MEMBER_COLORS = \[([^\]]*)\]/)?.[1] ?? '').matchAll(/#[0-9a-f]{6}/gi)].map((match) => match[0].toLowerCase());

    assert.equal(colors.length, 5);
    for (const forbidden of ['#059669', '#047857', '#16a34a', '#e11d48', '#dc2626']) {
        assert.equal(colors.includes(forbidden), false, `ห้ามใช้สี ${forbidden}`);
    }
});
