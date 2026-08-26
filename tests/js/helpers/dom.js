import {JSDOM} from 'jsdom';

/**
 * เตรียม DOM จำลองสำหรับ test ที่ต้องพิสูจน์พฤติกรรมจริง เช่น chips, focus, body lock
 *
 * jsdom ไม่คำนวณ layout จึงใช้ตรวจเรื่องขนาดหรือ getBoundingClientRect ไม่ได้
 * ส่วนนั้นยังต้องตรวจด้วยตาบนเบราว์เซอร์จริง
 */
export function mountDom(html = '<!doctype html><html><body></body></html>', {url = 'http://localhost/'} = {}) {
    // ต้องตั้ง url จริงเสมอ — ค่าเริ่มต้นของ jsdom (about:blank) ทำให้ new URL(path, location.origin)
    // ที่โค้ด production เรียกจริง (เช่น ensureMeetingsForSelectedMonth ของปฏิทิน) throw "Invalid URL"
    // เงียบ ๆ เพราะ about:blank ใช้เป็น base ของ URL แบบ relative ไม่ได้ ทำให้เส้นทางนั้นไม่เคยถูกทดสอบจริง
    const dom = new JSDOM(html, {pretendToBeVisual: true, url});
    const {window} = dom;

    // โค้ด production เรียก requestAnimationFrame ตอนโฟกัส ให้ทำงานทันทีใน test
    window.requestAnimationFrame = (callback) => {
        callback(0);
        return 0;
    };

    const previous = {
        document: globalThis.document,
        window: globalThis.window,
        Element: globalThis.Element,
        HTMLElement: globalThis.HTMLElement,
        CustomEvent: globalThis.CustomEvent,
        requestAnimationFrame: globalThis.requestAnimationFrame,
    };

    globalThis.document = window.document;
    globalThis.window = window;
    globalThis.Element = window.Element;
    globalThis.HTMLElement = window.HTMLElement;
    globalThis.CustomEvent = window.CustomEvent;
    globalThis.requestAnimationFrame = window.requestAnimationFrame;

    return {
        window,
        document: window.document,
        cleanup() {
            Object.assign(globalThis, previous);
            window.close();
        },
    };
}

/** จำลองการคลิกที่ทำให้ change/click event ทำงานจริงตามลำดับของเบราว์เซอร์ */
export function clickCheckbox(checkbox) {
    checkbox.checked = !checkbox.checked;
    checkbox.dispatchEvent(new checkbox.ownerDocument.defaultView.Event('change', {bubbles: true}));
}

export function typeInto(input, value) {
    input.value = value;
    input.dispatchEvent(new input.ownerDocument.defaultView.Event('input', {bubbles: true}));
}

export function click(element) {
    element.dispatchEvent(new element.ownerDocument.defaultView.MouseEvent('click', {bubbles: true, cancelable: true}));
}

export function pressKey(target, key, options = {}) {
    const view = target.ownerDocument?.defaultView || target.defaultView || target;
    target.dispatchEvent(new view.KeyboardEvent('keydown', {key, bubbles: true, cancelable: true, ...options}));
}

/**
 * markup ของ people-selector ที่ตรงกับ resources/views/components/people-selector.blade.php
 * ถ้า Blade เปลี่ยน hook ต้องแก้ที่นี่ด้วย test จึงจะยังสะท้อนของจริง
 */
export function peopleSelectorMarkup({instanceId = 'demo', inputName = 'people[]', people = [], departments = [], selected = [], disabled = [], readOnly = false} = {}) {
    const selectedSet = new Set(selected.map(String));
    const disabledSet = new Set(disabled.map(String));

    const options = people.map((person) => {
        const isSelected = selectedSet.has(String(person.id));
        const isDisabled = readOnly || disabledSet.has(String(person.id));
        const search = `${person.name} ${person.department || ''}`.toLowerCase();

        return `<label class="people-selector__option${isSelected ? ' is-selected' : ''}" data-people-option
            data-person-id="${person.id}" data-department-id="${person.departmentId ?? ''}" data-search="${search}">
            <input type="checkbox" id="${instanceId}-person-${person.id}" name="${inputName}" value="${person.id}"
                data-people-checkbox data-person-name="${person.name}"${isSelected ? ' checked' : ''}${isDisabled ? ' disabled' : ''}>
            <span><strong>${person.name}</strong><small>${person.department || ''}</small></span>
        </label>`;
    }).join('');

    const filters = departments.map((department) =>
        `<button type="button" data-people-department data-department-id="${department.id}" aria-pressed="false">${department.name}</button>`
    ).join('');

    return `<div class="people-selector-field" data-people-selector data-instance="${instanceId}"${readOnly ? ' data-readonly="true"' : ''}>
        <div class="people-selector">
            <div class="people-selector__browser">
                <label><input type="search" data-people-search></label>
                <div class="people-selector__departments">
                    <button type="button" data-people-department data-department-id="" aria-pressed="true" class="is-active">ทั้งหมด</button>
                    ${filters}
                </div>
                <div class="people-selector__options" data-people-options>
                    ${options}
                    <p data-people-empty hidden>ไม่พบรายชื่อ</p>
                </div>
            </div>
            <div class="people-selector__selected">
                <strong data-people-count>เลือกแล้ว ${selected.length} คน</strong>
                <div data-people-chips><p data-people-chips-empty${selected.length ? ' hidden' : ''}>ยังไม่ได้เลือก</p></div>
            </div>
        </div>
    </div>`;
}
