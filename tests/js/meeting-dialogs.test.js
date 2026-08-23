import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    deriveAttendeeState,
    initializeMeetingModals,
    resolveMeetingModal,
    showMeetingModal,
    updateAttendeeSelection,
} from '../../resources/js/pages/meetings/index.js';

const source = readFileSync(new URL('../../resources/js/pages/meetings/index.js', import.meta.url), 'utf8');
const bladeSources = [
    'index.blade.php',
    'show.blade.php',
    'components/form-modal.blade.php',
    'components/meeting-card.blade.php',
].map((file) => readFileSync(new URL(`../../resources/views/meetings/${file}`, import.meta.url), 'utf8')).join('\n');
const formSource = readFileSync(new URL('../../resources/views/meetings/components/form-modal.blade.php', import.meta.url), 'utf8');
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
    assert.match(formSource, /data-meeting-attendee-checkbox/);
    assert.match(formSource, /name="attendees\[\]"/);
    assert.match(formSource, /data-meeting-department-filter/);
    assert.match(formSource, /data-meeting-selected-attendees/);
    assert.doesNotMatch(formSource, /<select[^>]*name="attendees\[\]"[^>]*multiple/);
    assert.doesNotMatch(source, /selectedOptions|ctrlKey|shiftKey|data-meeting-attendee-select/);
    assert.doesNotMatch(formCss, /(?:^|\n)\s*\.modal(?:\s|\{|\.|#|:)/);
    assert.doesNotMatch(formCss, /\.meeting-form-modal \.modal-(?:dialog|content|body)[^{]*\{[^}]*\bheight\s*:/s);
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
