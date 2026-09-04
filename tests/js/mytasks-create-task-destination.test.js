import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const read = (path) => readFile(new URL('../../' + path, import.meta.url), 'utf8');

/*
 * หลังสร้างงานสำเร็จ ผู้ใช้ต้องถูกพาไปมุมมองบอร์ด ซึ่งจัดกลุ่มงานตามโปรเจกต์
 * งานที่เพิ่งสร้างจึงปรากฏใต้โปรเจกต์ที่เพิ่งเลือกไปให้เห็นทันที
 *
 * ของเดิมใช้ location.reload() ซึ่งค้างอยู่ที่มุมมองเดิม ถ้าผู้ใช้อยู่หน้าปฏิทิน
 * หรือหน้าประชุมตอนกดสร้าง หน้าจะโหลดใหม่โดยไม่มีอะไรเปลี่ยนให้เห็นเลย
 */

test('creating a task navigates to the board view and keeps the current filters', async () => {
    const script = await read('resources/js/pages/mytasks/user-task-create.js');

    assert.doesNotMatch(script, /window\.location\.reload\(\)/, 'ต้องไม่โหลดหน้าเดิมซ้ำหลังสร้างงาน');
    assert.match(script, /new URL\(window\.location\.href\)/, 'ต้องต่อยอดจาก URL ปัจจุบัน ไม่ใช่ประกอบใหม่');
    assert.match(script, /searchParams\.set\('view', 'board'\)/);
    assert.match(script, /window\.location\.assign\(destination\)/);

    // ต้องพาไปหลังจากตรวจแล้วว่าคำขอสำเร็จเท่านั้น
    const successBranch = script.slice(script.indexOf('if (!response.ok)'));
    assert.ok(
        successBranch.indexOf('throw new Error') < successBranch.indexOf("searchParams.set('view', 'board')"),
        'ต้องเช็คว่าสร้างสำเร็จก่อนจึงพาไปหน้าบอร์ด',
    );
});
