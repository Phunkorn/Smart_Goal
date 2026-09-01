import assert from 'node:assert/strict';
import test from 'node:test';
import {JSDOM} from 'jsdom';
import {initializeSettingsPage, openPasswordModal} from '../../resources/js/pages/settings/index.js';

test('opens the password modal after server validation errors', () => {
    const dom = new JSDOM('<main data-settings-page><div data-password-modal data-open-on-load="true"></div></main>');
    const shown = [];
    const bootstrapApi = {Modal: {getOrCreateInstance: (element) => ({show: () => shown.push(element)})}};
    assert.equal(initializeSettingsPage(dom.window.document, bootstrapApi), true);
    assert.equal(shown.length, 1);
});

test('does not open an unmarked password modal', () => {
    const dom = new JSDOM('<div data-password-modal></div>');
    assert.equal(openPasswordModal(dom.window.document, {}), false);
});
