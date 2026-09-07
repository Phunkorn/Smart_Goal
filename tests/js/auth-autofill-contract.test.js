import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

/*
 * ช่องที่เบราว์เซอร์กรอกให้อัตโนมัติต้องหน้าตาเหมือนช่องที่พิมพ์เอง
 *
 * .control input ตั้ง background เป็น transparent แล้วให้กรอบ .control เป็นคนวาด
 * พื้นขาวมนโค้ง แต่ Chrome/Edge วาดพื้นหลัง autofill ที่ตัว <input> เองด้วย
 * user-agent style ผลคือมีแถบสีเหลี่ยมโผล่คร่อมอยู่ในกรอบมน ทั้งช่องชื่อผู้ใช้
 * และช่องรหัสผ่านของหน้าเข้าสู่ระบบ
 *
 * กฎนี้อยู่ใน form-base.css ที่ทั้งหน้าเข้าสู่ระบบ หน้าตั้งรหัสผ่าน และหน้าต้อนรับ
 * ใช้ร่วมกัน จึงต้องแก้ที่เดียวแล้วได้ครบทุกหน้า
 */
test('autofilled auth inputs do not paint their own background', async () => {
    const css = await read('resources/css/components/auth/form-base.css');

    const webkit = css.match(/\.control input:-webkit-autofill[\s\S]*?\{([^}]*)\}/)?.[1] ?? '';

    assert.ok(webkit, 'ต้องมีกฎสำหรับช่องที่ถูกกรอกอัตโนมัติ');
    assert.match(webkit, /-webkit-background-clip:\s*text/, 'ต้องตัดพื้นหลังให้เหลือเฉพาะรูปตัวอักษร');
    assert.match(webkit, /-webkit-text-fill-color:\s*var\(--ink\)/, 'การ clip ทำให้สีข้อความหาย ต้องกำหนดกลับ');
    assert.match(webkit, /caret-color:/, 'เคอร์เซอร์ต้องยังมองเห็น');

    // ต้องครอบทุกสถานะ ไม่ใช่เฉพาะตอนยังไม่ได้โฟกัส
    for (const state of [':hover', ':focus', ':active']) {
        assert.match(css, new RegExp(`\\.control input:-webkit-autofill${state}`), `ขาดสถานะ ${state}`);
    }
});

/*
 * selector มาตรฐาน :autofill ต้องอยู่คนละบล็อกกับ :-webkit-autofill
 *
 * เบราว์เซอร์ที่ไม่รู้จัก selector ตัวใดตัวหนึ่งจะทิ้งทั้งบล็อกที่มี selector นั้น
 * ถ้าเขียนรวมกันในชุดเดียว Firefox จะทิ้งกฎของ WebKit ไปด้วยและกลับมาเพี้ยนเหมือนเดิม
 */
test('the standard and webkit autofill selectors are declared separately', async () => {
    const css = await read('resources/css/components/auth/form-base.css');

    const standard = css.match(/\.control input:autofill \{([^}]*)\}/)?.[1] ?? '';

    assert.ok(standard, 'ต้องมีกฎของ selector มาตรฐานแยกออกมา');
    assert.match(standard, /background-image:\s*none/);
    assert.doesNotMatch(standard, /-webkit-autofill/);

    const combined = /:autofill\s*,\s*[^{]*:-webkit-autofill|:-webkit-autofill\s*,\s*[^{]*[^-]:autofill/;
    assert.doesNotMatch(css, combined, 'ห้ามรวม selector สองตระกูลไว้ในชุดเดียวกัน');
});
