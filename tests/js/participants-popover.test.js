import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom, click, pressKey} from './helpers/dom.js';

/**
 * markup ที่ตรงกับคอลัมน์ผู้เข้าร่วมใน reports/projects/index.blade.php
 * ถ้า Blade เปลี่ยน hook ต้องแก้ที่นี่ด้วย test จึงจะยังสะท้อนของจริง
 */
function cell(names) {
    return `
        <div class="project-report__participants" data-participants>
            <button type="button" data-participants-trigger aria-haspopup="true" aria-expanded="false">${names.length} คน</button>
            <div data-participants-panel hidden>
                <p>ผู้เข้าร่วม ${names.length} คน</p>
                <ul>${names.map((name) => `<li><span>${name}</span></li>`).join('')}</ul>
            </div>
        </div>`;
}

async function mountTable(t) {
    const env = mountDom();
    t.after(env.cleanup);

    const {initParticipantsPopovers, panelPosition} = await import('../../resources/js/components/participants-popover.js');
    env.document.body.innerHTML = `<table><tbody>
        <tr><td>${cell(['สมชาย', 'สมหญิง'])}</td></tr>
        <tr><td>${cell(['ธนากร'])}</td></tr>
    </tbody></table><button type="button" id="outside">ข้างนอก</button>`;

    const api = initParticipantsPopovers(env.document);

    return {
        env,
        api,
        initParticipantsPopovers,
        panelPosition,
        document: env.document,
        triggers: [...env.document.querySelectorAll('[data-participants-trigger]')],
        panels: [...env.document.querySelectorAll('[data-participants-panel]')],
    };
}

test('clicking the count opens the name list under it and clicking again closes it', async (t) => {
    const {triggers, panels} = await mountTable(t);

    click(triggers[0]);
    assert.equal(panels[0].hidden, false);
    assert.equal(triggers[0].getAttribute('aria-expanded'), 'true');
    assert.deepEqual([...panels[0].querySelectorAll('li')].map((item) => item.textContent), ['สมชาย', 'สมหญิง']);

    click(triggers[0]);
    assert.equal(panels[0].hidden, true);
    assert.equal(triggers[0].getAttribute('aria-expanded'), 'false');
});

test('only one list is open at a time', async (t) => {
    const {triggers, panels} = await mountTable(t);

    click(triggers[0]);
    click(triggers[1]);

    assert.equal(panels[0].hidden, true);
    assert.equal(panels[1].hidden, false);
});

test('outside click, scroll and escape close it; escape returns focus to the count button', async (t) => {
    const {env, document, triggers, panels} = await mountTable(t);

    click(triggers[0]);
    click(panels[0].querySelector('li'));
    assert.equal(panels[0].hidden, false, 'คลิกในแผงต้องไม่ปิดแผง');

    click(document.getElementById('outside'));
    assert.equal(panels[0].hidden, true);

    click(triggers[0]);
    env.window.dispatchEvent(new env.window.Event('scroll'));
    assert.equal(panels[0].hidden, true);

    triggers[1].focus();
    click(triggers[1]);
    pressKey(document, 'Escape');
    assert.equal(panels[1].hidden, true);
    assert.equal(document.activeElement, triggers[1]);
    // popover ไม่ใช่ modal: ไม่ล็อกการเลื่อนหน้า
    assert.equal(document.body.classList.contains('modal-open'), false);
});

test('initialising twice does not stack listeners', async (t) => {
    const {document, triggers, panels, initParticipantsPopovers} = await mountTable(t);

    assert.equal(initParticipantsPopovers(document), null);
    click(triggers[0]);
    assert.equal(panels[0].hidden, false, 'listener ซ้อนสองชุดจะเปิดแล้วปิดทันที');
});

test('the panel stays inside the viewport and flips above the button near the bottom edge', async (t) => {
    const {panelPosition} = await mountTable(t);
    const viewport = {width: 400, height: 600};
    const size = {width: 240, height: 120};

    assert.deepEqual(panelPosition({left: 20, top: 100, bottom: 130}, size, viewport), {top: 136, left: 20});
    // ชิดขอบขวา: เลื่อนซ้ายไม่ให้ล้นจอ
    assert.equal(panelPosition({left: 350, top: 100, bottom: 130}, size, viewport).left, 152);
    // ใกล้ขอบล่าง: ขึ้นไปเหนือปุ่ม
    assert.equal(panelPosition({left: 20, top: 540, bottom: 570}, size, viewport).top, 414);
});
