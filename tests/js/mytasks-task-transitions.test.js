import assert from 'node:assert/strict';
import test from 'node:test';

import {confirmTaskTransition, isModalStatusOptionDisabled, transitionKind} from '../../resources/js/pages/mytasks/task-transitions.js';

test('transition kind is shared across table board and modal callers', () => {
    assert.equal(transitionKind(2, 3, {can_submit_review: true}), 'submit');
    assert.equal(transitionKind(3, 4, {can_review: true}), 'approve');
    assert.equal(transitionKind(3, 2, {can_review: true}), 'return');
    assert.equal(transitionKind(2, 4, {can_self_close: true}), 'self-close');
    assert.equal(transitionKind(4, 2, {can_reopen: true}), 'reopen');
    assert.equal(transitionKind(2, 5, {}), 'standard');
});

test('late modal options follow backend workflow capabilities', () => {
    const delegated = {can_submit_review: true, can_self_close: false, is_final: false};
    assert.equal(isModalStatusOptionDisabled(6, 3, delegated), false);
    assert.equal(isModalStatusOptionDisabled(6, 6, delegated), false);
    assert.equal(isModalStatusOptionDisabled(6, 4, delegated), true);

    const selfOwned = {can_submit_review: false, can_self_close: true, is_final: false};
    assert.equal(isModalStatusOptionDisabled(6, 4, selfOwned), false);
    assert.equal(isModalStatusOptionDisabled(6, 6, selfOwned), false);
    assert.equal(isModalStatusOptionDisabled(6, 3, selfOwned), true);

    const unrelated = {can_submit_review: false, can_self_close: false, is_final: false};
    assert.equal(isModalStatusOptionDisabled(6, 6, unrelated), false);
    assert.equal(isModalStatusOptionDisabled(6, 3, unrelated), true);
    assert.equal(isModalStatusOptionDisabled(6, 4, unrelated), true);
});

test('final tasks remain locked and normal task options remain unchanged', () => {
    assert.equal(isModalStatusOptionDisabled(4, 2, {is_final: true}), true);
    assert.equal(isModalStatusOptionDisabled(2, 5, {is_final: false}), false);
});

test('return confirmation requires and forwards the revision reason', async () => {
    let configuration;
    global.window = {
        Swal: {
            fire: async (value) => {
                configuration = value;
                return {isConfirmed: true, value: '  แก้ไขเอกสารแนบ  '};
            },
        },
    };

    const payload = await confirmTaskTransition(3, 2, {can_review: true});

    assert.equal(configuration.input, 'textarea');
    assert.ok(configuration.inputValidator(''));
    assert.equal(configuration.inputValidator('พร้อมส่ง'), undefined);
    assert.deepEqual(payload, {job_status: 2, reason: 'แก้ไขเอกสารแนบ'});
});

test('reopen is explicit and cancellation prevents a request', async () => {
    global.window = {Swal: {fire: async () => ({isConfirmed: true})}};
    assert.deepEqual(
        await confirmTaskTransition(4, 2, {can_reopen: true}),
        {job_status: 2, action: 'reopen'},
    );

    global.window = {Swal: {fire: async () => ({isConfirmed: false})}};
    assert.equal(await confirmTaskTransition(2, 3, {can_submit_review: true}), null);
});
