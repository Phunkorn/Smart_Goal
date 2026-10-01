import test from 'node:test';
import assert from 'node:assert/strict';
import {CONTEXT_GROUPS, contextGroupsFor} from '../../resources/js/pages/workspace/toolbar-context.js';

/*
 * กฎว่าแถบรูปแบบ (แถวที่สองของแถบเครื่องมือ) โผล่กลุ่มไหน
 *
 * ทั้งตารางเป็นตรรกะบริสุทธิ์ จึงทดสอบได้โดยไม่ต้องสร้างหน้าจอ ส่วนการซ่อน/แสดง
 * จริงบน DOM เป็นงานของ toolbar.js และถูกทดสอบผ่านเส้นทางจริงในเทสต์ของแถบเครื่องมือ
 */

const groups = (state) => contextGroupsFor(state);

test('ผู้ที่ดูอย่างเดียวไม่เห็นแถบรูปแบบเลย ไม่ว่าถืออะไรหรือเลือกอะไรไว้', () => {
    assert.deepEqual(groups({tool: 'sticky', selectedTypes: ['sticky'], canEdit: false}), []);
    assert.deepEqual(groups({tool: 'pen', canEdit: false}), []);
});

test('เครื่องมือที่ไม่ได้สร้างชิ้นงานไม่ต้องมีแถบรูปแบบ', () => {
    ['select', 'hand', 'eraser'].forEach((tool) => {
        assert.deepEqual(groups({tool}), [], tool);
    });
});

test('ถือเครื่องมือวาดเส้นหรือรูปทรง เห็นสีและความหนาเส้น', () => {
    ['pen', 'rect', 'ellipse', 'line', 'arrow'].forEach((tool) => {
        assert.deepEqual(groups({tool}), ['stroke', 'width'], tool);
    });
});

test('ถือเครื่องมือกระดาษโน้ต เห็นสีโน้ตและรูปแบบตัวอักษรครบ', () => {
    assert.deepEqual(
        groups({tool: 'sticky'}),
        ['stroke', 'sticky', 'font', 'textstyle', 'align']
    );
});

/* กล่องข้อความไม่มีสีพื้น จึงไม่ต้องมีกลุ่มสีกระดาษโน้ต */
test('ถือเครื่องมือกล่องข้อความ เห็นรูปแบบตัวอักษรแต่ไม่มีสีกระดาษโน้ต', () => {
    assert.deepEqual(groups({tool: 'text'}), ['stroke', 'font', 'textstyle', 'align']);
});

/*
 * สิ่งที่เลือกไว้ชนะเครื่องมือที่ถืออยู่ เพราะการเลือกชิ้นงานคือการบอกว่าจะแก้ชิ้นนั้น
 * ส่วนเครื่องมือเป็นเพียงสิ่งที่จะใช้ครั้งถัดไป
 */
test('ชิ้นที่เลือกไว้ตัดสินแทนเครื่องมือที่ถืออยู่', () => {
    assert.deepEqual(
        groups({tool: 'pen', selectedTypes: ['sticky']}),
        ['stroke', 'sticky', 'font', 'textstyle', 'align']
    );
    assert.deepEqual(groups({tool: 'sticky', selectedTypes: ['rect']}), ['stroke', 'width']);
});

test('เลือกรูปภาพไม่มีรูปแบบให้ปรับ แถบจึงหายไปทั้งแถว', () => {
    assert.deepEqual(groups({tool: 'select', selectedTypes: ['image']}), []);
});

test('เลือกหลายชนิดพร้อมกันได้ผลเป็นการรวมกลุ่มของทุกชนิด', () => {
    assert.deepEqual(
        groups({tool: 'select', selectedTypes: ['rect', 'sticky']}),
        ['stroke', 'sticky', 'width', 'font', 'textstyle', 'align']
    );
    assert.deepEqual(
        groups({tool: 'select', selectedTypes: ['image', 'line']}),
        ['stroke', 'width']
    );
});

/*
 * ตำแหน่งของกลุ่มบนแถบต้องไม่ขยับไปมาตามสิ่งที่เลือก ไม่งั้นผู้ใช้ที่ชินตำแหน่ง
 * จะกดผิดปุ่มทุกครั้งที่เปลี่ยนชิ้นที่เลือก
 */
test('ลำดับของกลุ่มคงที่เสมอไม่ว่าจะส่งชนิดเข้ามาเรียงแบบไหน', () => {
    const forward = groups({tool: 'select', selectedTypes: ['sticky', 'rect']});
    const reversed = groups({tool: 'select', selectedTypes: ['rect', 'sticky']});

    assert.deepEqual(forward, reversed);
    assert.deepEqual(
        forward,
        CONTEXT_GROUPS.filter((group) => forward.includes(group)),
        'ต้องเรียงตาม CONTEXT_GROUPS'
    );
});

test('ชิ้นที่เลือกซ้ำชนิดกันไม่ทำให้กลุ่มซ้ำ', () => {
    assert.deepEqual(groups({tool: 'select', selectedTypes: ['rect', 'rect', 'rect']}), ['stroke', 'width']);
});

test('ชนิดหรือเครื่องมือที่ไม่รู้จักไม่ทำให้พัง', () => {
    assert.deepEqual(groups({tool: 'ไม่มีจริง'}), []);
    assert.deepEqual(groups({tool: 'select', selectedTypes: ['ไม่มีจริง']}), []);
    assert.deepEqual(groups({}), []);
});
