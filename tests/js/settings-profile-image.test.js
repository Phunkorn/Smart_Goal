import assert from 'node:assert/strict';
import test from 'node:test';
import {readFile} from 'node:fs/promises';
import {JSDOM} from 'jsdom';
import {initializeProfileImagePicker} from '../../resources/js/pages/settings/profile-image.js';

const EMPTY_TEXT = 'เลือกรูปภาพใหม่ หรือลากไฟล์มาวางที่นี่';

function render() {
    const dom = new JSDOM(`
        <form>
            <div class="settings-upload" data-profile-image-picker>
                <input id="settingsProfileImage" type="file" name="profile_image" data-profile-image-input>
                <span data-profile-image-name>${EMPTY_TEXT}</span>
            </div>
        </form>`);
    const document = dom.window.document;
    return {
        dom,
        document,
        picker: document.querySelector('[data-profile-image-picker]'),
        input: document.querySelector('[data-profile-image-input]'),
        label: document.querySelector('[data-profile-image-name]'),
    };
}

function choose(env, files) {
    Object.defineProperty(env.input, 'files', {configurable: true, value: files});
    env.input.dispatchEvent(new env.dom.window.Event('change', {bubbles: true}));
}

test('แสดงชื่อไฟล์ที่เลือกในกล่อง และกลับเป็นข้อความเดิมเมื่อยกเลิกการเลือก', () => {
    const env = render();
    assert.equal(initializeProfileImagePicker(env.document), 1);

    choose(env, [{name: 'avatar.png'}]);
    assert.equal(env.label.textContent, 'avatar.png');
    assert.ok(env.picker.classList.contains('has-file'));

    choose(env, []);
    assert.equal(env.label.textContent, EMPTY_TEXT);
    assert.ok(! env.picker.classList.contains('has-file'));
});

test('ไฮไลต์กล่องระหว่างลากไฟล์ผ่านและเอาออกเมื่อวางหรือลากออก', () => {
    const env = render();
    initializeProfileImagePicker(env.document);
    const fire = (type) => env.input.dispatchEvent(new env.dom.window.Event(type, {bubbles: true}));

    fire('dragenter');
    assert.ok(env.picker.classList.contains('is-dragover'));
    fire('dragleave');
    assert.ok(! env.picker.classList.contains('is-dragover'));
    fire('dragenter');
    fire('drop');
    assert.ok(! env.picker.classList.contains('is-dragover'));
});

test('เรียกซ้ำไม่ผูก listener ซ้ำ', () => {
    const env = render();
    initializeProfileImagePicker(env.document);
    initializeProfileImagePicker(env.document);

    let writes = 0;
    new env.dom.window.MutationObserver((records) => { writes += records.length; })
        .observe(env.label, {childList: true});
    choose(env, [{name: 'avatar.png'}]);

    return new Promise((resolve) => setTimeout(() => {
        assert.equal(writes, 1);
        resolve();
    }, 0));
});

test('Blade หน้าตั้งค่ายังคงชื่อ field, action และ hook เดิมทั้งหมด', async () => {
    const markup = await readFile(new URL('../../resources/views/settings/index.blade.php', import.meta.url), 'utf8');

    for (const needle of [
        'data-settings-page',
        "route('settings.update')",
        'enctype="multipart/form-data"',
        "@method('PATCH')",
        'name="name"',
        'name="phone"',
        'name="profile_image"',
        'accept="image/png,image/jpeg,image/webp"',
        'data-bs-target="#settingsPasswordModal"',
        "@include('settings.components.telegram-card')",
        "@include('settings.components.password-modal')",
        'data-profile-image-picker',
        'data-profile-image-input',
        'data-profile-image-name',
    ]) {
        assert.ok(markup.includes(needle), `Blade ต้องมี ${needle}`);
    }
});
