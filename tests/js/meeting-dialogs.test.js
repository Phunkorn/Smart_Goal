import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    deriveAttendeeState,
    initializeAttendeeDepartmentFilter,
    initializeMeetingAttendeeModals,
    initializeMeetingEditTriggers,
    initializeMeetingModals,
    initializeMeetingProjectLinks,
    resolveMeetingModal,
    showMeetingModal,
    updateAttendeeSelection,
} from '../../resources/js/pages/meetings/index.js';
import {initSelectDropdowns} from '../../resources/js/components/select-dropdown.js';
import {initializePeopleSelector} from '../../resources/js/components/people-selector.js';
import {click, clickCheckbox, mountDom, peopleSelectorMarkup} from './helpers/dom.js';

const source = readFileSync(new URL('../../resources/js/pages/meetings/index.js', import.meta.url), 'utf8');
const bladeSources = [
    'index.blade.php',
    'show.blade.php',
    'components/form-modal.blade.php',
    'components/meeting-card.blade.php',
].map((file) => readFileSync(new URL(`../../resources/views/meetings/${file}`, import.meta.url), 'utf8')).join('\n');
const formSource = readFileSync(new URL('../../resources/views/meetings/components/form-modal.blade.php', import.meta.url), 'utf8');
const cardSource = readFileSync(new URL('../../resources/views/meetings/components/meeting-card.blade.php', import.meta.url), 'utf8');
const listSource = readFileSync(new URL('../../resources/views/meetings/components/meeting-list.blade.php', import.meta.url), 'utf8');
// ตัวเลือกผู้เข้าร่วมถูกยกไปเป็น component กลางที่ใช้ร่วมกับผู้ร่วมงานของงานแล้ว
const peopleSelectorSource = readFileSync(new URL('../../resources/views/components/people-selector.blade.php', import.meta.url), 'utf8');
const formCss = readFileSync(new URL('../../resources/css/pages/meetings/form.css', import.meta.url), 'utf8');

test('meeting feedback and deletion use SweetAlert2', () => {
    assert.match(source, /window\.Swal\.fire/);
    assert.match(source, /ยืนยันการลบการประชุม/);
    assert.match(source, /result\.isConfirmed/);
});

test('meeting JavaScript contains no native browser dialogs', () => {
    const forbidden = [
        /window\.alert\s*\(/,
        /window\.confirm\s*\(/,
        /window\.prompt\s*\(/,
        /(^|[^.\w])alert\s*\(/m,
        /(^|[^.\w])confirm\s*\(/m,
        /(^|[^.\w])prompt\s*\(/m,
    ];

    forbidden.forEach((pattern) => {
        assert.doesNotMatch(source, pattern);
        assert.doesNotMatch(bladeSources, pattern);
    });
});

function modalElement() {
    return {classList: {contains: (className) => ['modal', 'meeting-form-modal'].includes(className)}};
}

function modalRoot(elements = {}, triggers = []) {
    return {
        getElementById: (id) => elements[id] ?? null,
        querySelectorAll: (selector) => selector === '[data-meeting-modal-trigger]' ? triggers : [],
    };
}

function modalTrigger(modalId) {
    const listeners = [];

    return {
        dataset: {meetingModalTrigger: modalId},
        addEventListener: (eventName, listener) => {
            if (eventName === 'click') listeners.push(listener);
        },
        listeners,
    };
}

test('meeting modal resolver rejects missing and non-modal targets', () => {
    const root = modalRoot({notAModal: {classList: {contains: () => false}}});

    assert.equal(resolveMeetingModal(root, 'missingModal'), null);
    assert.equal(resolveMeetingModal(root, 'notAModal'), null);
    assert.equal(showMeetingModal(null, {}), false);
});

test('create and edit triggers resolve existing modal elements', () => {
    const createModal = modalElement();
    const editModal = modalElement();
    const root = modalRoot({createMeetingModal: createModal, editMeetingModal: editModal});

    assert.equal(resolveMeetingModal(root, 'createMeetingModal'), createModal);
    assert.equal(resolveMeetingModal(root, 'editMeetingModal'), editModal);
});

test('viewer without writable modal does not initialize or throw', () => {
    let instanceCount = 0;
    const bootstrapApi = {Modal: {getOrCreateInstance: () => {
        instanceCount += 1;
        return {show: () => {}};
    }}};

    assert.doesNotThrow(() => initializeMeetingModals(modalRoot(), bootstrapApi));
    assert.equal(instanceCount, 0);
});

test('meeting trigger initializes once and only creates an instance when clicked', () => {
    const modal = modalElement();
    const trigger = modalTrigger('createMeetingModal');
    const root = modalRoot({createMeetingModal: modal}, [trigger]);
    let instanceCount = 0;
    let shownWith = null;
    const bootstrapApi = {Modal: {getOrCreateInstance: (element) => {
        assert.equal(element, modal);
        instanceCount += 1;
        return {show: (relatedTarget) => { shownWith = relatedTarget; }};
    }}};

    initializeMeetingModals(root, bootstrapApi);
    initializeMeetingModals(root, bootstrapApi);

    assert.equal(trigger.listeners.length, 1);
    assert.equal(instanceCount, 0);
    trigger.listeners[0]();
    assert.equal(instanceCount, 1);
    assert.equal(shownWith, trigger);
});

test('meeting modal keeps shared compact markup and viewport scroll fallback', () => {
    assert.match(formSource, /modal-dialog modal-lg modal-dialog-centered/);
    assert.doesNotMatch(formSource, /modal-dialog-scrollable/);
    assert.match(formSource, /rows="2"/);
    // หน้าประชุมต้องเรียก component กลาง ไม่ใช่ประกาศ markup ของตัวเอง
    assert.match(formSource, /@include\('components\.people-selector'/);
    assert.match(formSource, /'inputName' => 'attendees\[\]'/);
    // ผู้เข้าร่วมต้องเห็น avatar ตอนค้นหา โดยไม่ต้องเปิดฟีเจอร์อื่นของ team-manager
    assert.match(formSource, /'showAvatar' => true/);
    // ตัวเลือกผู้เข้าร่วมย้ายไปเป็น modal แยก เปิดจากปุ่มในฟอร์มหลัก ไม่ใช่รายการเต็มฝังอยู่ในฟอร์มอีกต่อไป
    assert.match(formSource, /data-meeting-attendees-field="\{\{ \$modalId \}\}Attendees"/);
    assert.match(formSource, /meeting-attendees-modal/);
    // ห้ามเปิดด้วย data-bs-toggle ธรรมดา เพราะ modal สองใบของ Bootstrap ซ้อนกันพร้อมกันไม่ได้ (ดู initializeMeetingAttendeeModals)
    assert.doesNotMatch(formSource, /data-bs-toggle="modal"/);
    /*
     * modal ผู้เข้าร่วมต้องเป็นพี่น้องของ modal หลักจริง ๆ ไม่ใช่ลูกที่อยู่ข้างใน (แม้จะอยู่นอก <form> ก็ตาม)
     * เพราะตอน initializeMeetingAttendeeModals สั่งซ่อน modal หลักด้วย display:none ลูกของมันจะถูกซ่อนไป
     * ด้วยเสมอไม่ว่าตัวเองจะมีคลาส show หรือไม่ — เคยเกิดจริง (เปิดแล้วเจอจอมืดไม่มีอะไรเลย) ตอน nest
     * modal นี้ไว้ข้างใน <form> ของ modal หลัก จึงต้องตรวจตำแหน่งใน source ไว้กันกลับไปผิดอีก
     */
    assert.match(formSource, /^<div class="modal fade meeting-form-modal meeting-attendees-modal" id="\{\{ \$modalId \}\}Attendees"/m);
    const formRegion = formSource.match(/<form[\s\S]*?<\/form>/);
    assert.ok(formRegion, 'ต้องเจอ <form> ในไฟล์');
    assert.doesNotMatch(formRegion[0], /meeting-attendees-modal/, 'modal ผู้เข้าร่วมต้องไม่ซ้อนอยู่ใน <form> ของ modal หลัก');
    // checkbox อยู่นอก <form> แล้ว จึงต้องผูกกลับด้วย attribute form="..." แทน
    assert.match(formSource, /id="\{\{ \$modalId \}\}Form"/);
    assert.match(formSource, /'formId' => \$modalId\.'Form'/);
    assert.match(peopleSelectorSource, /@if\(\$formId\) form="\{\{ \$formId \}\}" @endif/);
    assert.match(peopleSelectorSource, /data-people-checkbox/);
    assert.match(peopleSelectorSource, /data-people-department/);
    assert.match(peopleSelectorSource, /data-people-chips/);
    assert.doesNotMatch(peopleSelectorSource, /<select[^>]*multiple/);
    assert.doesNotMatch(formSource, /<select[^>]*name="attendees\[\]"[^>]*multiple/);
    assert.doesNotMatch(source, /selectedOptions|ctrlKey|shiftKey|data-meeting-attendee-select/);
    assert.doesNotMatch(formCss, /(?:^|\n)\s*\.modal(?:\s|\{|\.|#|:)/);
    assert.doesNotMatch(formCss, /\.meeting-form-modal \.modal-(?:dialog|content|body)[^{]*\{[^}]*\bheight\s*:/s);
});

/*
 * jsdom ไม่มี bootstrap.Modal จริง จึงต้องจำลองพฤติกรรมเท่าที่โค้ดของเราพึ่งพา:
 * toggle class "show" และยิง "hidden.bs.modal" แบบ synchronous ตอน hide()
 */
function fakeBootstrapModal() {
    const instances = new Map();

    return {
        Modal: {
            getOrCreateInstance: (element) => {
                if (!instances.has(element)) {
                    const view = element.ownerDocument.defaultView;
                    instances.set(element, {
                        show: () => {
                            element.classList.add('show');
                            element.removeAttribute('hidden');
                            element.dispatchEvent(new view.Event('shown.bs.modal', {bubbles: true}));
                        },
                        hide: () => {
                            element.classList.remove('show');
                            element.setAttribute('hidden', '');
                            element.dispatchEvent(new view.Event('hidden.bs.modal', {bubbles: true}));
                        },
                    });
                }
                return instances.get(element);
            },
            getInstance: (element) => (element ? instances.get(element) : undefined),
        },
    };
}

function mountAttendeeHandoff(env, people = [{id: 1, name: 'สมชาย ใจดี', department: 'ไอที', departmentId: 10}]) {
    env.document.body.innerHTML = `
        <div class="modal meeting-form-modal show" id="createMeetingModal">
            <div class="meeting-form-modal__attendees" data-meeting-attendees-field="createMeetingModalAttendees">
                <span class="meeting-form-modal__attendees-count">ยังไม่ได้เลือกผู้เข้าร่วม</span>
                <button type="button" class="meeting-form-modal__attendees-trigger">เพิ่มผู้ร่วมประชุม</button>
            </div>
        </div>
        <div class="modal meeting-form-modal meeting-attendees-modal" id="createMeetingModalAttendees" hidden>
            ${peopleSelectorMarkup({instanceId: 'createMeetingModal', inputName: 'attendees[]', people})}
        </div>
    `;
    initializePeopleSelector(env.document.querySelector('[data-people-selector]'));

    return {
        ownerModal: env.document.getElementById('createMeetingModal'),
        attendeesModal: env.document.getElementById('createMeetingModalAttendees'),
        trigger: env.document.querySelector('.meeting-form-modal__attendees-trigger'),
        countElement: env.document.querySelector('.meeting-form-modal__attendees-count'),
        peopleSelectorRoot: env.document.querySelector('[data-people-selector]'),
    };
}

test('เปิดตัวเลือกผู้เข้าร่วมแล้วสลับกับฟอร์มหลัก ไม่ใช่ซ้อนทับกัน', (t) => {
    const env = mountDom();
    t.after(env.cleanup);

    const {ownerModal, attendeesModal, trigger, countElement, peopleSelectorRoot} = mountAttendeeHandoff(env);
    const bootstrapApi = fakeBootstrapModal();
    initializeMeetingAttendeeModals(env.document, bootstrapApi);

    click(trigger);
    assert.equal(ownerModal.classList.contains('show'), false, 'ฟอร์มหลักต้องถูกซ่อนก่อนเปิดตัวเลือกผู้เข้าร่วม ไม่ใช่ซ้อนกันทั้งสองใบ');
    assert.equal(attendeesModal.classList.contains('show'), true);

    clickCheckbox(peopleSelectorRoot.querySelector('[data-people-checkbox][value="1"]'));
    assert.equal(countElement.textContent, 'เลือกแล้ว 1 คน', 'ตัวนับในฟอร์มหลักต้องอัปเดตตามจริงแม้ยังไม่ได้สลับกลับมา');

    bootstrapApi.Modal.getOrCreateInstance(attendeesModal).hide();
    assert.equal(attendeesModal.classList.contains('show'), false);
    assert.equal(ownerModal.classList.contains('show'), true, 'ปิดตัวเลือกผู้เข้าร่วมไม่ว่าด้วยวิธีไหนต้องเปิดฟอร์มหลักกลับมาเสมอ');
});

test('initializeMeetingAttendeeModals ไม่ผูก listener ซ้ำเมื่อเรียกซ้ำ', (t) => {
    const env = mountDom();
    t.after(env.cleanup);

    const {trigger} = mountAttendeeHandoff(env, []);
    let instanceRequests = 0;
    const bootstrapApi = {Modal: {getOrCreateInstance: (element) => {
        instanceRequests += 1;
        return {
            show: () => {},
            hide: () => element.dispatchEvent(new env.window.Event('hidden.bs.modal', {bubbles: true})),
        };
    }}};

    initializeMeetingAttendeeModals(env.document, bootstrapApi);
    initializeMeetingAttendeeModals(env.document, bootstrapApi);

    click(trigger);
    // คลิกครั้งเดียวต้องขอ instance แค่ 2 ครั้ง (ซ่อนใบนอก 1 + เปิดใบใน 1) ถ้า listener ถูกผูกซ้อน ตัวเลขนี้จะเพิ่มเป็นเท่าตัว
    assert.equal(instanceRequests, 2);
});

/* ---------- ปุ่มแก้ไขบนการ์ด: โหลดด้วย AJAX แล้วเปิดเป็น modal ไม่ navigate ทั้งหน้า ---------- */

function meetingCardEditMarkup() {
    return `
        <button type="button" class="meetings-page__icon-button" data-meeting-edit-trigger
            data-meeting-edit-url="/meetings/7/edit-form" aria-label="แก้ไข ทดสอบ">
            <i class="bi bi-pencil" aria-hidden="true"></i>
        </button>
        <div data-meeting-edit-modal-slot></div>
    `;
}

function editFormFragmentMarkup() {
    return `
        <div class="modal fade meeting-form-modal" id="editMeetingModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header"><h2 class="modal-title" id="editMeetingModalTitle">แก้ไขการประชุม</h2></div>
                    <form data-meeting-form>
                        <div class="modal-body"><input id="editMeetingModalTitleInput" name="title" value="ทดสอบ"></div>
                    </form>
                </div>
            </div>
        </div>
    `;
}

test('ปุ่มแก้ไขบนการ์ดโหลดฟอร์มด้วย AJAX มาเติมลง slot แล้วเปิดเป็น modal โดยไม่ navigate ทั้งหน้า', async (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = meetingCardEditMarkup();

    const calls = [];
    globalThis.fetch = async (url) => {
        calls.push(url);
        return {ok: true, text: async () => editFormFragmentMarkup()};
    };
    t.after(() => { delete globalThis.fetch; });

    const bootstrapApi = fakeBootstrapModal();
    initializeMeetingEditTriggers(env.document, bootstrapApi);

    const trigger = env.document.querySelector('[data-meeting-edit-trigger]');
    click(trigger);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.deepEqual(calls, ['/meetings/7/edit-form'], 'ต้อง fetch URL ที่การ์ดฝังไว้ ไม่ใช่ navigate ทั้งหน้า');
    const modal = env.document.getElementById('editMeetingModal');
    assert.ok(modal, 'ต้องมี editMeetingModal ถูกเติมเข้า slot หลังโหลดเสร็จ');
    assert.equal(modal.classList.contains('show'), true, 'ต้องเปิด modal ทันทีหลังโหลดฟอร์มสำเร็จ');
    assert.equal(trigger.disabled, false, 'ปุ่มต้องกลับมากดได้หลังทำงานเสร็จ');
});

test('โหลดฟอร์มแก้ไขไม่สำเร็จต้องแจ้งเตือนด้วย Swal ไม่ใช่ปล่อยเงียบ และปุ่มต้องกลับมากดได้', async (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = meetingCardEditMarkup();

    globalThis.fetch = async () => ({ok: false, status: 500, text: async () => ''});
    t.after(() => { delete globalThis.fetch; });

    const swalCalls = [];
    env.window.Swal = {fire: (options) => { swalCalls.push(options); return Promise.resolve({isConfirmed: true}); }};

    initializeMeetingEditTriggers(env.document, fakeBootstrapModal());

    const trigger = env.document.querySelector('[data-meeting-edit-trigger]');
    click(trigger);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(swalCalls.length, 1);
    assert.equal(swalCalls[0].icon, 'error');
    assert.equal(env.document.getElementById('editMeetingModal'), null, 'ไม่สำเร็จต้องไม่มี modal ถูกเติมเข้ามา');
    assert.equal(trigger.disabled, false, 'ปุ่มต้องกลับมากดได้อีกหลังพัง ไม่ใช่ค้าง disabled');
});

test('การ์ดรายการประชุมไม่ใช้ <a href> นำทางไปหน้าแก้ไขเต็มหน้าอีกต่อไป', () => {
    assert.doesNotMatch(cardSource, /href="\{\{ route\('meetings\.show', \$meetingShowParams \+ \['edit' => 1\]\) \}\}"/);
    assert.match(cardSource, /data-meeting-edit-trigger/);
    assert.match(cardSource, /data-meeting-edit-url="\{\{ route\('meetings\.edit-form', \$meetingShowParams\) \}\}"/);
});

test('ตัวกรองรายการประชุมส่งผลทันทีไม่ต้องกดปุ่ม "แสดงผล"', () => {
    assert.match(listSource, /data-auto-submit-form/);
    assert.match(listSource, /name="search"[^>]*data-auto-submit-debounce/);
    assert.match(listSource, /data-period-select/);
    assert.match(listSource, /name="period" data-auto-submit data-period-select/);
    assert.match(listSource, /name="employee" data-auto-submit/);
    // ช่วงเวลาและพนักงานต้องใช้ดร็อปดาวน์แบบกำหนดสไตล์ได้ ไม่ใช่ <select> เปล่า ๆ
    assert.match(listSource, /<div data-sg-select>\s*<select id="meetingsPeriod"/);
    assert.match(listSource, /<div data-sg-select>\s*<select id="meetingsEmployee"/);
    // "กำหนดช่วงวันที่เอง" ต้องไม่ส่งทันทีที่เลือก (ยังไม่มีวันที่ให้กรอง) และต้องมีช่องวันที่คู่กัน
    assert.match(listSource, /\\App\\Services\\MeetingQueryService::CUSTOM_PERIOD\) data-auto-submit-skip/);
    assert.match(listSource, /data-period-range/);
    assert.match(listSource, /data-period-input="from"/);
    assert.match(listSource, /data-period-input="to"/);
    // ปุ่ม "แสดงผล" แบบกดเองต้องไม่เหลืออยู่นอก <noscript> อีกต่อไป (ตัดทั้งคอมเมนต์ Blade และ noscript ออกก่อนเทียบ)
    const renderedOnly = listSource
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/<noscript>[\s\S]*?<\/noscript>/, '');
    assert.doesNotMatch(renderedOnly, /แสดงผล/);
    assert.match(listSource, /<noscript><button[^>]*type="submit"[^>]*>[\s\S]*?แสดงผล/);
});

test('attendee state supports several checked users and chip removal', () => {
    const options = [
        {id: 1, departmentId: 10, search: 'first it', checked: true},
        {id: 2, departmentId: 10, search: 'พันกร it', checked: true},
        {id: 3, departmentId: 20, search: 'benz marketing', checked: false},
    ];

    assert.deepEqual(deriveAttendeeState(options).selectedIds, ['1', '2']);
    const withThird = updateAttendeeSelection(['1', '2'], 3, true);
    assert.deepEqual(withThird, ['1', '2', '3']);
    assert.deepEqual(updateAttendeeSelection(withThird, 2, false), ['1', '3']);
});

test('department changes preserve selection while search combines with department', () => {
    const options = [
        {id: 1, departmentId: 10, search: 'first technology', checked: true},
        {id: 2, departmentId: 10, search: 'พันกร technology', checked: false},
        {id: 3, departmentId: 20, search: 'benz marketing', checked: true},
    ];
    const marketingState = deriveAttendeeState(options, '', 20);
    const searchedTechnology = deriveAttendeeState(options, 'พัน', 10);

    assert.deepEqual(marketingState.visibleIds, ['3']);
    assert.deepEqual(marketingState.selectedIds, ['1', '3']);
    assert.deepEqual(searchedTechnology.visibleIds, ['2']);
    assert.deepEqual(searchedTechnology.selectedIds, ['1', '3']);
});

/**
 * markup ของส่วน "โปรเจกต์ที่เกี่ยวข้อง" ที่ตรงกับ form-modal.blade.php
 * ถ้า Blade เปลี่ยน hook ต้องแก้ที่นี่ด้วย test จึงจะยังสะท้อนของจริง
 */
function projectLinkMarkup() {
    return `
        <div class="modal meeting-form-modal" id="createMeetingModal">
            <form data-meeting-form>
                <div class="col-12" data-meeting-project-link>
                    <div class="meeting-form-modal__project-grid">
                        <div data-sg-select>
                            <label for="createMeetingModalProject">โปรเจกต์</label>
                            <select id="createMeetingModalProject" name="work_order_list_id" data-meeting-project-select>
                                <option value="">ไม่ระบุ</option>
                                <option value="7" data-tasks='[{"id":11,"name":"ออกแบบหน้าแรก"},{"id":12,"name":"ทดสอบระบบ"}]'>ปรับปรุงเว็บไซต์</option>
                                <option value="9" data-tasks="[]">โปรเจกต์ที่ยังไม่มีงาน</option>
                            </select>
                        </div>
                        <div data-sg-select>
                            <label for="createMeetingModalTask">งาน</label>
                            <select id="createMeetingModalTask" name="work_order_id" data-meeting-task-select>
                                <option value="">ไม่ระบุ</option>
                            </select>
                        </div>
                    </div>
                </div>
            </form>
        </div>`;
}

function mountProjectLink(t) {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = projectLinkMarkup();
    initSelectDropdowns(env.document);
    initializeMeetingProjectLinks(env.document);

    const [projectRoot, taskRoot] = [...env.document.querySelectorAll('.sg-select')];

    return {
        env,
        projectRoot,
        taskRoot,
        projectSelect: env.document.querySelector('[data-meeting-project-select]'),
        taskSelect: env.document.querySelector('[data-meeting-task-select]'),
        chooseProject: (position) => {
            click(projectRoot.querySelector('.sg-select__trigger'));
            click([...projectRoot.querySelectorAll('.sg-select__option')][position]);
        },
    };
}

/*
 * เส้นทางจริงตั้งแต่กดเลือกโปรเจกต์จนตัวเลือกงานเปลี่ยนตาม ไม่ใช่แค่เรียกฟังก์ชันภายใน
 * <select> ยังเป็นแหล่งความจริงเดียวของค่าที่ฟอร์มส่ง
 */
test('choosing a project rebuilds the task options of the shared dropdown', (t) => {
    const {projectRoot, taskRoot, projectSelect, taskSelect, chooseProject} = mountProjectLink(t);

    assert.equal(projectRoot.querySelector('.sg-select__value').textContent, 'ไม่ระบุ');

    chooseProject(1);

    assert.equal(projectSelect.value, '7');
    assert.deepEqual(
        [...taskSelect.options].map((option) => option.textContent),
        ['ไม่ระบุ', 'ออกแบบหน้าแรก', 'ทดสอบระบบ']
    );
    // งานต้องไม่ถูกเลือกให้เอง การผูกงานเป็นทางเลือกแยกจากการผูกโปรเจกต์
    assert.equal(taskSelect.value, '');
    assert.equal(taskRoot.querySelector('.sg-select__value').textContent, 'ไม่ระบุ');

    // แผงของงานอ่าน <option> สดตอนเปิด จึงเห็นงานของโปรเจกต์ที่เพิ่งเลือก
    click(taskRoot.querySelector('.sg-select__trigger'));
    click([...taskRoot.querySelectorAll('.sg-select__option')][2]);

    assert.equal(taskSelect.value, '12');
});

test('switching to a project without tasks clears the previously linked task', (t) => {
    const {taskRoot, taskSelect, chooseProject} = mountProjectLink(t);

    chooseProject(1);
    click(taskRoot.querySelector('.sg-select__trigger'));
    click([...taskRoot.querySelectorAll('.sg-select__option')][1]);
    assert.equal(taskSelect.value, '11');

    chooseProject(2);

    assert.deepEqual([...taskSelect.options].map((option) => option.textContent), ['ไม่ระบุ']);
    assert.equal(taskSelect.value, '');
    assert.equal(taskRoot.querySelector('.sg-select__value').textContent, 'ไม่ระบุ');
});

test('the project link uses the shared dropdown instead of a second implementation', () => {
    const meetingsCss = readFileSync(new URL('../../resources/css/pages/meetings.css', import.meta.url), 'utf8');

    assert.match(formSource, /data-meeting-project-link/);
    assert.match(formSource, /data-meeting-project-select/);
    assert.match(formSource, /data-meeting-task-select/);
    // รายการงานของแต่ละโปรเจกต์มาจาก server ฝังใน data-tasks ไม่ต้องยิง endpoint เพิ่ม
    assert.match(formSource, /data-tasks='@json\(\$project\['tasks'\]\)'/);
    assert.equal((formSource.match(/data-sg-select/g) || []).length, 2);
    assert.match(source, /import \{initSelectDropdowns\} from '\.\.\/\.\.\/components\/select-dropdown\.js'/);
    assert.match(meetingsCss, /@import '\.\.\/components\/select-dropdown\.css'/);
    // ห้ามมีดร็อปดาวน์ชุดที่สองของหน้าประชุมเอง
    assert.doesNotMatch(source, /select-trigger|select-menu|select-option/);
    assert.doesNotMatch(formCss, /meeting-form-modal__select/);
});

/* ---------- กรองมุมมองผู้เข้าร่วมในหน้ารายละเอียดตามแผนก ---------- */

function attendeeDetailMarkup() {
    return `
        <section class="meetings-page__detail-people">
            <div class="meetings-page__attendee-filter" data-attendee-department-filter>
                <button type="button" class="is-active" data-attendee-department="" aria-pressed="true">ทั้งหมด</button>
                <button type="button" data-attendee-department="IT" aria-pressed="false">IT</button>
                <button type="button" data-attendee-department="Account" aria-pressed="false">Account</button>
            </div>
            <div class="meetings-page__attendees" data-attendee-list>
                <span class="meetings-page__attendee" data-attendee-department="IT">สมชาย</span>
                <span class="meetings-page__attendee" data-attendee-department="Account">สมหญิง</span>
                <span class="meetings-page__attendee" data-attendee-department="IT">ปิติ</span>
            </div>
        </section>`;
}

test('คลิกชื่อแผนกกรองให้เห็นเฉพาะผู้เข้าร่วมแผนกนั้น และกด "ทั้งหมด" คืนให้เห็นทุกคน', (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = attendeeDetailMarkup();

    initializeAttendeeDepartmentFilter(env.document);

    const rows = [...env.document.querySelectorAll('[data-attendee-department]')].filter((el) => el.tagName === 'SPAN');
    const visibleNames = () => rows.filter((row) => !row.hidden).map((row) => row.textContent);

    assert.deepEqual(visibleNames(), ['สมชาย', 'สมหญิง', 'ปิติ'], 'เปิดหน้ามาต้องเห็นทั้งหมดเสมอ');

    click(env.document.querySelector('[data-attendee-department="IT"]'));
    assert.deepEqual(visibleNames(), ['สมชาย', 'ปิติ']);
    assert.equal(env.document.querySelector('[data-attendee-department="IT"]').classList.contains('is-active'), true);
    assert.equal(env.document.querySelector('[data-attendee-department=""]').classList.contains('is-active'), false);
    assert.equal(env.document.querySelector('[data-attendee-department=""]').getAttribute('aria-pressed'), 'false');

    click(env.document.querySelector('[data-attendee-department=""]'));
    assert.deepEqual(visibleNames(), ['สมชาย', 'สมหญิง', 'ปิติ'], 'กด "ทั้งหมด" ต้องคืนให้เห็นทุกคนอีกครั้ง');
});

test('initializeAttendeeDepartmentFilter ไม่ผูก listener ซ้ำเมื่อเรียกซ้ำ', (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = attendeeDetailMarkup();

    initializeAttendeeDepartmentFilter(env.document);
    initializeAttendeeDepartmentFilter(env.document);

    click(env.document.querySelector('[data-attendee-department="Account"]'));
    const rows = [...env.document.querySelectorAll('span[data-attendee-department]')];
    assert.deepEqual(rows.filter((row) => !row.hidden).map((row) => row.textContent), ['สมหญิง']);
});

/*
 * การตอบรับเข้าร่วมประชุม — ทั้งสาม modal ใบใหม่ต้องใช้กลไกเปิด/ปิดชุดเดิมของหน้าประชุม
 * ไม่ใช่สร้างตัวจัดการ overlay ใบที่สองขึ้นมาขนานกัน
 */
const declineModalSource = readFileSync(new URL('../../resources/views/invitations/components/decline-modal.blade.php', import.meta.url), 'utf8');
const responseModalSource = readFileSync(new URL('../../resources/views/invitations/components/attendee-status-modal.blade.php', import.meta.url), 'utf8');
const responseActionsSource = readFileSync(new URL('../../resources/views/invitations/components/response-actions.blade.php', import.meta.url), 'utf8');
const responseCss = readFileSync(new URL('../../resources/css/pages/meetings/attendee-response.css', import.meta.url), 'utf8');

test('ปุ่มตอบรับ/ไม่ว่าง และปุ่มสถานะผู้เข้าร่วม ใช้ปุ่มกับตัวเปิด modal ชุดเดิมของหน้าประชุม', () => {
    const showSource = readFileSync(new URL('../../resources/views/meetings/show.blade.php', import.meta.url), 'utf8');

    assert.match(responseActionsSource, /data-meeting-modal-trigger="declineMeetingInvitationModal"/);
    assert.match(showSource, /data-meeting-modal-trigger="meetingAttendeeResponsesModal"/);
    // ไม่มีคลาสปุ่มใหม่ ทุกปุ่มยังเป็น .meetings-page__button ตัวเดิม
    assert.match(responseActionsSource, /class="meetings-page__button meetings-page__button--primary"/);
    assert.doesNotMatch(responseCss, /\.meeting-rsvp__button/);
});

test('modal การตอบรับเข้าร่วมประชุมถูกเปิดได้ด้วย resolveMeetingModal ตัวเดียวกับฟอร์มประชุม', () => {
    [declineModalSource, responseModalSource].forEach((modalSource) => {
        assert.match(modalSource, /class="modal fade meeting-form-modal/);
        assert.match(modalSource, /modal-dialog-centered/);
        assert.match(modalSource, /data-bs-dismiss="modal"/);
    });
});

test('เหตุผลที่ไม่สะดวกเข้าร่วมเป็นข้อมูลบังคับทั้งในฟอร์มและมีช่องแสดงข้อผิดพลาดของตัวเอง', () => {
    assert.match(declineModalSource, /name="decline_reason"/);
    assert.match(declineModalSource, /required/);
    assert.match(declineModalSource, /@error\('decline_reason'\)/);
    // ใบเดียวใช้ทั้งประชุมและนัดหมาย ปลายทางจึงถูกส่งเข้ามา ไม่ hardcode route ของ domain ใด
    assert.match(declineModalSource, /\$invitationDeclineUrl/);
    assert.doesNotMatch(declineModalSource, /route\('meetings\./);
    assert.doesNotMatch(declineModalSource, /route\('appointments\./);
});

test('modal สถานะผู้เข้าร่วมไม่ตัดสินสิทธิ์เองและไม่ประกาศคำของสถานะซ้ำ', () => {
    // สิทธิ์มาจาก policy ฝั่งเซิร์ฟเวอร์ ไม่ใช่เงื่อนไข role ใน Blade
    assert.doesNotMatch(responseModalSource, /role\s*===?\s*'admin'/);
    assert.doesNotMatch(responseModalSource, /->role/);
    // คำกับไอคอนของทุกสถานะมาจาก MeetingAttendeeResponseDesign ที่เดียว
    assert.match(responseModalSource, /\$responseDesign::/);
    assert.doesNotMatch(responseModalSource, /MeetingAttendeeResponseDesign/);
    assert.doesNotMatch(responseModalSource, /'รอตอบรับ'/);
    /*
     * ป้ายสถานะของผู้เปิดหน้าย้ายไปอยู่บนการ์ดคำเชิญแล้ว ส่วน attendee-response-actions
     * เหลือหน้าที่เดียวคือปุ่มสองปุ่ม จึงไม่ต้องรู้จักคำของสถานะอีก
     */
    /*
     * แบนเนอร์ใช้ร่วมสอง domain แล้ว คลาสป้ายสถานะจึงถูกส่งเข้ามาเป็น $invitationDesign
     * ไม่ใช่อ้างถึงคลาสของการประชุมตรง ๆ และไม่ประกาศคำของสถานะเอง
     */
    assert.match(invitationCardSource, /\$invitationDesign::/);
    assert.doesNotMatch(invitationCardSource, /MeetingAttendeeResponseDesign/);
    assert.doesNotMatch(invitationCardSource, /'รอตอบรับ'/);
    assert.doesNotMatch(responseActionsSource, /ResponseDesign/);
});

test('สไตล์การตอบรับเข้าร่วมประชุมใช้โทเคนเดิม ไม่มี gradient และไม่มี dependency ใหม่', () => {
    assert.doesNotMatch(responseCss, /gradient/i);
    assert.doesNotMatch(responseCss, /@tailwind|tailwind/i);
    assert.match(responseCss, /var\(--border\)/);
    assert.match(responseCss, /var\(--green-dim\)/);
    assert.match(responseCss, /var\(--red-dim\)/);
    // ต้องถูกผูกเข้ากับ entry เดิมของหน้าประชุม ไม่ใช่ entry Vite ใบใหม่
    const meetingsCss = readFileSync(new URL('../../resources/css/pages/meetings.css', import.meta.url), 'utf8');
    assert.match(meetingsCss, /@import '\.\/meetings\/attendee-response\.css';/);
});

test('modal การตอบรับเข้าร่วมประชุมไม่ใช้ native dialog และยังพึ่ง Swal ของระบบ', () => {
    [declineModalSource, responseModalSource, responseActionsSource].forEach((modalSource) => {
        assert.doesNotMatch(modalSource, /(^|[^.\w])alert\s*\(/m);
        assert.doesNotMatch(modalSource, /(^|[^.\w])confirm\s*\(/m);
        assert.doesNotMatch(modalSource, /(^|[^.\w])prompt\s*\(/m);
    });
});

test('กดปุ่ม "ไม่ว่างประชุม" เปิด modal เหตุผลจริง และกดซ้ำไม่ผูก listener เพิ่ม', (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = `
        <div class="meetings-page">
            <button type="button" data-meeting-modal-trigger="declineMeetingInvitationModal">ไม่ว่างประชุม</button>
            <div class="modal fade meeting-form-modal" id="declineMeetingInvitationModal">
                <form><textarea name="decline_reason" required></textarea></form>
            </div>
        </div>`;

    const bootstrapApi = fakeBootstrapModal();
    initializeMeetingModals(env.document, bootstrapApi);
    initializeMeetingModals(env.document, bootstrapApi);

    const declineModal = env.document.getElementById('declineMeetingInvitationModal');
    assert.equal(declineModal.classList.contains('show'), false);

    click(env.document.querySelector('[data-meeting-modal-trigger]'));
    assert.equal(declineModal.classList.contains('show'), true, 'ปุ่มไม่ว่างประชุมต้องเปิด modal เหตุผลได้จริง');

    bootstrapApi.Modal.getOrCreateInstance(declineModal).hide();
    click(env.document.querySelector('[data-meeting-modal-trigger]'));
    assert.equal(declineModal.classList.contains('show'), true, 'เปิดซ้ำได้ตามปกติโดยไม่ค้างสถานะ');
});

/*
 * การ์ดคำเชิญเข้าร่วมประชุมและแถบปุ่มติดขอบล่างของมือถือ — ต้องใช้ปุ่มคู่เดียวกับของเดิม
 * ไม่ใช่ประกาศ markup ปุ่มชุดที่สองขึ้นมาขนานกัน
 */
const invitationCardSource = readFileSync(new URL('../../resources/views/invitations/components/response-card.blade.php', import.meta.url), 'utf8');
const mobileActionsSource = readFileSync(new URL('../../resources/views/invitations/components/mobile-actions.blade.php', import.meta.url), 'utf8');
const meetingShowSource = readFileSync(new URL('../../resources/views/meetings/show.blade.php', import.meta.url), 'utf8');

test('ปุ่มตอบรับคำเชิญถูกประกาศที่เดียว การ์ดกับแถบมือถือ include ตัวเดียวกัน', () => {
    [invitationCardSource, mobileActionsSource].forEach((source) => {
        assert.match(source, /@include\('invitations\.components\.response-actions'/);
        // ห้ามประกาศฟอร์ม/ปุ่มตอบรับซ้ำในสองไฟล์ (ชื่อตัวแปรในคอมเมนต์อธิบายสัญญาไม่นับ)
        assert.doesNotMatch(source, /<form[^>]*\$invitationAcceptUrl/);
        assert.doesNotMatch(source, /type="submit"/);
        assert.doesNotMatch(source, /data-meeting-modal-trigger="declineMeetingInvitationModal"/);
    });

    assert.match(responseActionsSource, /\$invitationAcceptUrl/);
    assert.doesNotMatch(responseActionsSource, /route\(/, 'ปุ่มคู่นี้ต้องไม่ผูกกับ route ของ domain ใด');
    assert.match(responseActionsSource, /data-meeting-modal-trigger="declineMeetingInvitationModal"/);
});

test('ปุ่ม RSVP ไม่อยู่ในแถบปุ่มมุมขวาบนอีกต่อไป และการ์ดอยู่เหนือแผงรายละเอียด', () => {
    const actionBar = meetingShowSource.match(/<div class="meetings-page__actions">[\s\S]*?<\/header>/);
    assert.ok(actionBar, 'ต้องเจอแถบปุ่มมุมขวาบนใน show.blade.php');
    assert.doesNotMatch(actionBar[0], /attendee-response-actions/);
    assert.doesNotMatch(actionBar[0], /meetings\.attendance\.accept/);
    // แถบนี้ยังเป็นของผู้สร้างประชุมตามเดิม
    assert.match(actionBar[0], /meetingAttendeeResponsesModal/);
    assert.match(actionBar[0], /editMeetingModal/);

    assert.ok(
        meetingShowSource.indexOf('meeting-invitation-response-card') < meetingShowSource.indexOf('meetings-page__detail-main'),
        'การ์ดคำเชิญต้องถูก include ก่อนแผงรายละเอียดการประชุม'
    );
});

test('แถบปุ่มติดขอบล่างถูกซ่อน/แสดงด้วย CSS media query ไม่ใช่ JavaScript', () => {
    assert.match(responseCss, /\.meeting-rsvp-mobile-actions \{ display: none; \}/);
    assert.match(responseCss, /@media \(max-width: 767\.98px\)[\s\S]*\.meeting-rsvp-mobile-actions \{[\s\S]*position: fixed/);
    // ไม่มีโค้ด JS ใดแตะแถบนี้เลย การซ่อนบนเดสก์ท็อปจึงไม่ขึ้นกับ JavaScript
    assert.doesNotMatch(source, /meeting-rsvp-mobile-actions/);
    assert.doesNotMatch(source, /meeting-invitation/);
});

test('แถบปุ่มมือถือมี touch target ใหญ่พอและไม่ทับเนื้อหาท้ายหน้า', () => {
    assert.match(responseCss, /\.meeting-rsvp-mobile-actions \.meetings-page__button \{[\s\S]*min-height: 48px/);
    assert.match(responseCss, /safe-area-inset-bottom/);
    assert.match(responseCss, /\.meetings-page--awaiting-invitation-response \{ padding-bottom: \d+px; \}/);
    assert.match(meetingShowSource, /meetings-page--awaiting-invitation-response/);
});

test('การ์ดคำเชิญสื่อสถานะด้วยไอคอนและข้อความ ไม่ใช่สีเพียงอย่างเดียว', () => {
    assert.match(invitationCardSource, /statusIcon\(/);
    assert.match(invitationCardSource, /statusLabel\(/);
    // ทุกไอคอนเป็นตัวเสริม ข้อความต้องอ่านได้เองโดยไม่ต้องพึ่งไอคอน
    assert.match(invitationCardSource, /aria-labelledby="meetingInvitationResponseHeading"/);
    assert.doesNotMatch(invitationCardSource, /<i[^>]*class="bi[^"]*"(?![^>]*aria-hidden)/);
});

test('สไตล์การ์ดคำเชิญใช้โทเคนเดิม ไม่มี gradient และไม่มีไฟล์ CSS ใบใหม่', () => {
    assert.match(responseCss, /\.meeting-rsvp-card \{/);
    assert.match(responseCss, /\.meeting-rsvp-card__header/);
    assert.match(responseCss, /\.meeting-rsvp-card__actions/);
    assert.doesNotMatch(responseCss, /gradient/i);
    assert.doesNotMatch(responseCss, /!important/);
    assert.match(responseCss, /var\(--accent-dim\)/);
    assert.match(responseCss, /var\(--radius\)/);
});

test('modal เหตุผลยังเป็นใบเดิมและข้อความผิดพลาดผูกกับ textarea เพื่อ screen reader', () => {
    assert.match(declineModalSource, /id="declineMeetingInvitationModal"/);
    assert.match(declineModalSource, /กรุณาระบุเหตุผลที่ไม่สามารถเข้าร่วม\{\{ \$invitationSubjectNoun \}\}ได้/);
    assert.match(declineModalSource, /ยืนยันไม่เข้าร่วม/);
    assert.match(declineModalSource, /aria-describedby="declineMeetingInvitationReasonError"/);
    assert.match(declineModalSource, /id="declineMeetingInvitationReasonError"/);
    assert.match(declineModalSource, /for="declineMeetingInvitationReason"/);
});

/*
 * คำเชิญใช้ชุด Blade เดียวกันทั้งการประชุมและนัดหมาย — ไฟล์อยู่ที่
 * resources/views/invitations/components/ และรับปลายทาง/ถ้อยคำผ่านตัวแปร
 */
const confirmModalSource = readFileSync(new URL('../../resources/views/invitations/components/confirm-modal.blade.php', import.meta.url), 'utf8');
const appointmentShowSource = readFileSync(new URL('../../resources/views/appointments/show.blade.php', import.meta.url), 'utf8');
const appointmentFormBlade = readFileSync(new URL('../../resources/views/appointments/components/form-modal.blade.php', import.meta.url), 'utf8');

test('partial คำเชิญทุกใบเป็นกลาง ไม่ผูกกับ domain ใด domain หนึ่ง', () => {
    [invitationCardSource, mobileActionsSource, declineModalSource, responseModalSource, confirmModalSource, responseActionsSource]
        .forEach((source) => {
            assert.doesNotMatch(source, /route\('meetings\./, 'ห้าม hardcode route ของการประชุม');
            assert.doesNotMatch(source, /route\('appointments\./, 'ห้าม hardcode route ของนัดหมาย');
            assert.doesNotMatch(source, /MeetingAttendee|AppointmentAttendee/, 'ห้ามอ้างค่าคงที่ของโมเดลใน Blade');
        });
});

test('ทั้งสองหน้ารายละเอียดส่งสัญญาเดียวกันเข้า partial คำเชิญ', () => {
    const required = [
        'invitationSubject',
        'invitationDesign',
        'invitationNoun',
        'invitationSubjectNoun',
        'invitationOrganiser',
        'invitationTitle',
        'invitationSchedule',
        'invitationAcceptUrl',
        'invitationDeclineUrl',
    ];

    required.forEach((key) => {
        assert.match(meetingShowSource, new RegExp(`'${key}' =>`), `meetings/show ต้องส่ง ${key}`);
        assert.match(appointmentShowSource, new RegExp(`'${key}' =>`), `appointments/show ต้องส่ง ${key}`);
    });
});

test('กล่องยืนยันเด้งได้ด้วยกลไกเดิม ไม่มีระบบ auto-open ชุดใหม่', () => {
    // ต้องมีคลาสที่ resolveMeetingModal() รู้จัก มิฉะนั้น showFeedback() จะเปิดไม่ได้
    assert.match(confirmModalSource, /class="modal fade meeting-form-modal/);
    assert.match(confirmModalSource, /id="confirmInvitationModal"/);
    // ใช้ปุ่มคู่เดิม ไม่ประกาศปุ่มใหม่
    assert.match(confirmModalSource, /@include\('invitations\.components\.response-actions'\)/);
    assert.doesNotMatch(confirmModalSource, /type="submit"/);
    // ไม่มีโค้ด JS ที่เปิด modal เองตอนโหลดหน้า นอกจาก showFeedback() เดิม
    assert.doesNotMatch(source, /confirmInvitationModal/);
});

test('ธง respond สั่งเปิดกล่องยืนยันเฉพาะเมื่อผู้ใช้ยังตอบได้', () => {
    [meetingShowSource, appointmentShowSource].forEach((showSource) => {
        assert.match(showSource, /request\(\)->boolean\('respond'\)/);
        assert.match(showSource, /'confirmInvitationModal'/);
        // เงื่อนไขต้องมีการตรวจสิทธิ์ควบคู่เสมอ ไม่ใช่เปิดให้ใครก็ได้ที่ต่อ ?respond=1
        assert.match(showSource, /canRespondTo(Meeting|Appointment)/);
    });
});

test('แบนเนอร์ที่ยังรอตอบได้น้ำหนักต่างจากที่ตอบแล้ว', () => {
    assert.match(invitationCardSource, /meeting-rsvp-card--awaiting/);
    assert.match(invitationCardSource, /meeting-rsvp-card__schedule/);
    assert.match(responseCss, /\.meeting-rsvp-card--awaiting \{[\s\S]*border-left-width: 6px/);
    assert.match(responseCss, /\.meeting-rsvp-card--awaiting \.meeting-rsvp-card__actions \.meetings-page__button \{[^}]*min-height: 48px/s);
});

test('ฟอร์มนัดหมายใช้ตัวเลือกบุคคลกลางตัวเดียวกับฟอร์มประชุม', () => {
    assert.match(appointmentFormBlade, /@include\('components\.people-selector'/);
    assert.match(appointmentFormBlade, /'inputName' => 'attendees\[\]'/);
    assert.match(appointmentFormBlade, /data-meeting-attendees-field/);
    // ผูกกลับฟอร์มหลักด้วย form="..." เพราะตัวเลือกอยู่นอก <form>
    assert.match(appointmentFormBlade, /'formId' => \$appointmentModalId\.'Form'/);
    assert.match(appointmentFormBlade, /id="\{\{ \$appointmentModalId \}\}Form"/);
});

test('ลิงก์แผนที่เปิดแท็บใหม่อย่างปลอดภัยในทั้งสองหน้า', () => {
    [meetingShowSource, appointmentShowSource].forEach((showSource) => {
        assert.match(showSource, /meetings-page__map-link/);
        assert.match(showSource, /target="_blank"[\s\S]{0,40}rel="noopener"/);
    });

    [formSource, appointmentFormBlade].forEach((formBlade) => {
        assert.match(formBlade, /name="map_url"/);
        assert.match(formBlade, /type="url"/);
    });
});
