import test from 'node:test';
import assert from 'node:assert/strict';

import {
    ALL_KINDS,
    matchesFilter,
    normalizeKind,
    visibleCount,
} from '../../resources/js/pages/daily-logs/filters.js';

/*
 * สถานะตัวกรองประเภทงานในไทม์ไลน์
 *
 * ตัวกรองนี้ทำงานฝั่ง client บนแถวที่ render แล้ว จึงต้องไม่ซ่อนแถวผิดตัว
 * เพราะผู้ใช้จะเข้าใจว่าบันทึกหายไป
 */
test('normalizeKind ปัดค่าที่ไม่รู้จักกลับเป็นทั้งหมด', () => {
    assert.equal(normalizeKind('routine', ['routine', 'field']), 'routine');
    assert.equal(normalizeKind('field', ['routine', 'field']), 'field');
    assert.equal(normalizeKind('ไม่มีจริง', ['routine', 'field']), ALL_KINDS);
    assert.equal(normalizeKind('', ['routine']), ALL_KINDS);
    assert.equal(normalizeKind(null, ['routine']), ALL_KINDS);
});

test('ตัวกรองทั้งหมดแสดงทุกแถว', () => {
    assert.equal(matchesFilter(ALL_KINDS, 'routine'), true);
    assert.equal(matchesFilter(ALL_KINDS, 'field'), true);
});

test('ตัวกรองประเภทเดียวแสดงเฉพาะแถวประเภทนั้น', () => {
    assert.equal(matchesFilter('routine', 'routine'), true);
    assert.equal(matchesFilter('routine', 'field'), false);
});

test('visibleCount บอกจำนวนแถวที่จะเหลืออยู่หลังกรอง', () => {
    const rows = ['routine', 'routine', 'field'];

    assert.equal(visibleCount(ALL_KINDS, rows), 3);
    assert.equal(visibleCount('routine', rows), 2);
    assert.equal(visibleCount('field', rows), 1);
    assert.equal(visibleCount('routine', []), 0);
});
