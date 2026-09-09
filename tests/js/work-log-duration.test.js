import test from 'node:test';
import assert from 'node:assert/strict';

import {
    durationLabel,
    minutesBetween,
    parseClock,
} from '../../resources/js/pages/daily-logs/duration.js';

/*
 * ตรรกะเวลาล้วน ๆ ของหน้าบันทึกงานประจำวัน
 *
 * durationLabel() ต้องให้ผลตรงกับ App\Support\WorkLogDesign::durationLabel()
 * ฝั่ง PHP เสมอ เพราะแถวเดียวกันอาจถูก render จากทั้งสองฝั่ง (ตอนโหลดหน้า
 * ใช้ Blade ส่วนตอนแก้ไขสำเร็จใช้ค่าที่ JavaScript วางลงไป)
 */
test('durationLabel ให้ข้อความไทยเหมือนฝั่ง PHP', () => {
    assert.equal(durationLabel(null), 'ไม่ระบุเวลา');
    assert.equal(durationLabel(undefined), 'ไม่ระบุเวลา');
    assert.equal(durationLabel(0), '0 น.');
    assert.equal(durationLabel(59), '59 น.');
    assert.equal(durationLabel(60), '1 ชม.');
    assert.equal(durationLabel(61), '1 ชม. 1 น.');
    assert.equal(durationLabel(165), '2 ชม. 45 น.');
    assert.equal(durationLabel(1440), '24 ชม.');
});

test('durationLabel ไม่พังเมื่อได้ค่าที่ไม่ใช่ตัวเลข', () => {
    assert.equal(durationLabel('ไม่ใช่ตัวเลข'), 'ไม่ระบุเวลา');
    assert.equal(durationLabel(-30), '0 น.');
});

test('parseClock รับเฉพาะรูปแบบ ชช:นน ที่ถูกต้อง', () => {
    assert.deepEqual(parseClock('09:30'), {hours: 9, minutes: 30});
    assert.deepEqual(parseClock('23:59'), {hours: 23, minutes: 59});
    assert.equal(parseClock('24:00'), null);
    assert.equal(parseClock('9:30'), null);
    assert.equal(parseClock(''), null);
    assert.equal(parseClock(null), null);
});

test('minutesBetween คำนวณช่วงเวลาปกติ', () => {
    assert.equal(minutesBetween('10:45', '13:30'), 165);
    assert.equal(minutesBetween('08:30', '09:10'), 40);
});

/*
 * งานเวรและงานนอกสถานที่ข้ามเที่ยงคืนได้จริง ผลลัพธ์ต้องเป็นบวกเสมอ
 * ไม่ใช่ค่าติดลบที่จะไปทำให้ยอดรวมของวันผิด
 */
test('minutesBetween นับต่อไปวันถัดไปเมื่อข้ามเที่ยงคืน', () => {
    assert.equal(minutesBetween('22:30', '01:15'), 165);
    assert.equal(minutesBetween('23:50', '00:10'), 20);
});

test('minutesBetween คืน null เมื่อเวลาไม่ครบหรือผิดรูปแบบ', () => {
    assert.equal(minutesBetween('09:00', ''), null);
    assert.equal(minutesBetween('', '10:00'), null);
    assert.equal(minutesBetween('9am', '10am'), null);
});
