import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const read = (path) => readFile(new URL('../../' + path, import.meta.url), 'utf8');

/** ความสว่างสัมพัทธ์ตาม WCAG ของสี #rrggbb */
const luminance = (hex) => {
    const channels = [1, 3, 5]
        .map((offset) => parseInt(hex.slice(offset, offset + 2), 16) / 255)
        .map((value) => (value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4));

    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
};

const contrastRatio = (first, second) => {
    const [light, dark] = [luminance(first), luminance(second)].sort((a, b) => b - a);

    return (light + 0.05) / (dark + 0.05);
};

test('an unread notification card separates itself from the page background', async () => {
    const tokens = await read('resources/css/foundations/tokens.css');
    const css = await read('resources/css/pages/notifications.css');

    const pageBackground = tokens.match(/--bg:\s*(#[0-9a-fA-F]{6})/)[1];
    const unread = css
        .slice(css.lastIndexOf('.notification-center__item.is-unread {'))
        .match(/background:\s*(#[0-9a-fA-F]{6})/)[1];

    /*
     * ของเดิมการ์ดที่ยังไม่อ่านใช้ #f7f9ff บนพื้นหน้า #F5F7FC ซึ่งต่างกันราว 1.01 เท่า
     * มองด้วยตาเปล่าแล้วกลืนกันจนแยกไม่ออกว่ารายการไหนยังไม่ได้อ่าน
     * ซึ่งเป็นข้อมูลหลักที่หน้านี้ต้องสื่อ
     */
    const ratio = contrastRatio(pageBackground, unread);
    assert.ok(
        ratio >= 1.05,
        'พื้นการ์ดที่ยังไม่อ่าน (' + unread + ') ต่างจากพื้นหน้า (' + pageBackground + ') เพียง ' + ratio.toFixed(3) + ' เท่า',
    );

    // เส้นสถานะด้านซ้ายต้องหนาพอที่จะอ่านออกแม้พื้นหลังจะอ่อน
    assert.match(css.slice(css.lastIndexOf('.notification-center__item.is-unread {')), /box-shadow:\s*inset\s+4px/);
});

test('today on the calendar reads as blue, not as the purple that means "meeting"', async () => {
    const css = await read('resources/css/components/task-workspace/calendar/base.css');

    const today = css
        .slice(css.lastIndexOf('.mytasks-calendar__day.is-today .mytasks-calendar__day-number {'))
        .match(/background:\s*(#[0-9a-fA-F]{6})/)[1]
        .toLowerCase();
    const meetingTone = css.match(/is-tone-meeting \{\s*--calendar-tone:\s*(#[0-9a-fA-F]{6})/)[1].toLowerCase();

    assert.notEqual(today, meetingTone, 'วันนี้กับโทนของการประชุมต้องไม่ใช้สีเดียวกัน');

    const [red, green, blue] = [1, 3, 5].map((offset) => parseInt(today.slice(offset, offset + 2), 16));
    assert.ok(blue > red && blue > green, 'วงกลมวันนี้ต้องเป็นสีฟ้า ไม่ใช่สีม่วง (ได้ ' + today + ')');
});

test('all-caps labels get one shared tracking value instead of none', async () => {
    const typography = await read('resources/css/foundations/typography.css');
    const layout = await read('resources/css/components/layout.css');

    // ถูก import ก่อนไฟล์ของแต่ละหน้า หน้าที่ต้องการค่าเฉพาะจึงยังทับได้
    assert.match(layout, /@import '\.\.\/foundations\/typography\.css';/);
    assert.ok(
        layout.indexOf('typography.css') < layout.indexOf('./layout/sidebar.css'),
        'typography.css ต้องถูก import ก่อนสไตล์ของแต่ละส่วน',
    );

    const tracking = Number(typography.match(/--caps-tracking:\s*(\d*\.?\d+)em/)[1]);
    assert.ok(tracking >= 0.08, 'ช่องไฟ ' + tracking + 'em ยังแคบเกินกว่าจะอ่านสบายในตัวพิมพ์ใหญ่ของฟอนต์ Prompt');

    // ป้ายที่เคยไม่มี letter-spacing เลยต้องอยู่ในรายการ
    for (const label of [
        'admin-approvals-list__header',
        'employee-reset-modal__eyebrow',
        'notion-kicker',
        'wb-eyebrow',
    ]) {
        assert.ok(typography.includes('.' + label), label + ' ต้องได้ช่องไฟของตัวพิมพ์ใหญ่ด้วย');
    }

    assert.match(typography, /letter-spacing: var\(--caps-tracking\);/);
});
