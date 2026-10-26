import assert from 'node:assert/strict';
import {readdirSync, readFileSync, statSync} from 'node:fs';
import test from 'node:test';
import {initTicketForm, readPhotoLimits, selectPhotos} from '../../resources/js/pages/tickets/form.js';
import {click, mountDom} from './helpers/dom.js';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const MB = 1024 * 1024;
const limits = {maxFiles: 3, maxBytes: 10 * MB, accept: ['image/jpeg', 'image/png']};

/** markup ที่ตรงกับ tickets/components/form-fields.blade.php + submit-button.blade.php (เฉพาะ hook ที่ JS ใช้) */
const markup = () => `<!doctype html><html><body>
<form data-ticket-form>
    <select data-ticket-location required>
        <option value="" disabled selected>เลือก</option>
        <option value="5">สาขาบางนา</option>
        <option value="other">อื่น ๆ (ระบุเอง)</option>
    </select>
    <div data-ticket-location-other><input type="text" name="location_name"></div>
    <div class="ticket-photos" data-ticket-photos data-max-files="3" data-max-bytes="${10 * MB}" data-accept="image/jpeg,image/png">
        <input type="file" name="photos[]" multiple data-ticket-photos-input>
        <ul data-ticket-photos-list></ul>
        <p data-ticket-photos-error hidden></p>
    </div>
    <button type="submit" data-ticket-submit>
        <span data-ticket-submit-spinner hidden></span>
        <span data-ticket-submit-label>ส่งแจ้งปัญหา</span>
    </button>
</form>
</body></html>`;

function setup() {
    const dom = mountDom(markup());
    const {document, window} = dom;
    const input = document.querySelector('[data-ticket-photos-input]');

    // jsdom ไม่มี DataTransfer — ให้ input เก็บรายการที่ form.js ตั้งได้ตรง ๆ
    let files = [];
    Object.defineProperty(input, 'files', {configurable: true, get: () => files, set: (value) => { files = value; }});

    let urlCount = 0;
    const revoked = [];
    initTicketForm(document, {
        createFileList: (list) => [...list],
        objectUrls: {createObjectURL: () => `blob:${++urlCount}`, revokeObjectURL: (url) => revoked.push(url)},
    });

    const pick = (...picked) => {
        files = picked;
        input.dispatchEvent(new window.Event('change', {bubbles: true}));
    };
    const image = (name, type = 'image/jpeg') => new window.File(['x'], name, {type});

    return {...dom, input, pick, image, revoked, files: () => files};
}

test('photos taken with the camera join the same list as the gallery (still max 3), and a form reset clears them', () => {
    const dom = mountDom(`<!doctype html><html><body>
<form data-ticket-form>
    <div class="ticket-photos" data-ticket-photos data-max-files="3" data-max-bytes="${10 * MB}" data-accept="image/jpeg,image/png">
        <input type="file" name="photos[]" multiple data-ticket-photos-input>
        <input type="file" accept="image/*" capture="environment" data-ticket-photos-camera>
        <ul data-ticket-photos-list></ul>
        <p data-ticket-photos-error hidden></p>
    </div>
</form></body></html>`);
    try {
        const {document, window} = dom;
        const input = document.querySelector('[data-ticket-photos-input]');
        const camera = document.querySelector('[data-ticket-photos-camera]');
        let files = [];
        Object.defineProperty(input, 'files', {configurable: true, get: () => files, set: (value) => { files = value; }});
        let shot = [];
        Object.defineProperty(camera, 'files', {configurable: true, get: () => shot});
        initTicketForm(document, {createFileList: (list) => [...list], objectUrls: {createObjectURL: () => 'blob:x', revokeObjectURL() {}}});
        const image = (name) => new window.File(['x'], name, {type: 'image/jpeg'});
        const take = (name) => {
            shot = [image(name)];
            camera.dispatchEvent(new window.Event('change', {bubbles: true}));
        };

        files = [image('gallery.jpg')];
        input.dispatchEvent(new window.Event('change', {bubbles: true}));
        take('camera-1.jpg');
        take('camera-2.jpg');
        assert.deepEqual(files.map((file) => file.name), ['gallery.jpg', 'camera-1.jpg', 'camera-2.jpg'], 'รูปจากกล้องอยู่ใน input ที่ส่งไป server');
        assert.equal(camera.getAttribute('name'), null, 'input ของกล้องไม่ถูกส่งซ้ำ');

        take('camera-3.jpg');
        assert.equal(files.length, 3, 'รวมไม่เกิน 3 รูป');
        assert.equal(document.querySelector('[data-ticket-photos-error]').hidden, false);

        document.querySelector('form').dispatchEvent(new window.Event('reset'));
        assert.equal(document.querySelectorAll('[data-ticket-photos-list] li').length, 0, 'reset ล้างรูปที่เลือก');
    } finally {
        dom.cleanup();
    }
});

test('selectPhotos keeps at most the limit and explains every rejected file', () => {
    const file = (name, type = 'image/jpeg', size = 1000) => ({name, type, size});

    const tooMany = selectPhotos([file('a.jpg'), file('b.jpg')], [file('c.jpg'), file('d.jpg')], limits);
    assert.deepEqual(tooMany.files.map((f) => f.name), ['a.jpg', 'b.jpg', 'c.jpg']);
    assert.deepEqual(tooMany.errors, ['แนบรูปได้ไม่เกิน 3 รูป']);

    const wrongType = selectPhotos([], [file('doc.pdf', 'application/pdf')], limits);
    assert.equal(wrongType.files.length, 0);
    assert.match(wrongType.errors[0], /doc\.pdf/);

    const tooBig = selectPhotos([], [file('big.jpg', 'image/jpeg', 11 * MB)], limits);
    assert.equal(tooBig.files.length, 0);
    assert.match(tooBig.errors[0], /10 MB/);
});

test('limits are read from the data attributes the server writes', () => {
    const {document, cleanup} = mountDom(markup());
    try {
        assert.deepEqual(readPhotoLimits(document.querySelector('[data-ticket-photos]')), limits);
    } finally {
        cleanup();
    }
});

test('choosing "other" reveals a required location field and choosing a branch hides it', () => {
    const {document, window, cleanup} = setup();
    try {
        const select = document.querySelector('[data-ticket-location]');
        const other = document.querySelector('[data-ticket-location-other]');
        const otherInput = other.querySelector('input');

        assert.equal(other.hidden, true);
        assert.equal(otherInput.required, false);

        select.value = 'other';
        select.dispatchEvent(new window.Event('change', {bubbles: true}));
        assert.equal(other.hidden, false);
        assert.equal(otherInput.required, true);
        assert.equal(document.activeElement, otherInput);

        select.value = '5';
        select.dispatchEvent(new window.Event('change', {bubbles: true}));
        assert.equal(other.hidden, true);
        assert.equal(otherInput.required, false);
    } finally {
        cleanup();
    }
});

test('picking photos shows previews, adds across picks, and stops at three', () => {
    const {document, pick, image, files, cleanup} = setup();
    try {
        pick(image('a.jpg'), image('b.png', 'image/png'));
        assert.equal(document.querySelectorAll('.ticket-photos__item').length, 2);
        assert.equal(document.querySelector('.ticket-photos__item img').getAttribute('src'), 'blob:1');
        assert.equal(document.querySelector('.ticket-photos__name').textContent, 'a.jpg');

        pick(image('c.jpg'), image('d.jpg'));
        assert.deepEqual(files().map((f) => f.name), ['a.jpg', 'b.png', 'c.jpg']);
        assert.equal(document.querySelectorAll('.ticket-photos__item').length, 3);

        const error = document.querySelector('[data-ticket-photos-error]');
        assert.equal(error.hidden, false);
        assert.match(error.textContent, /ไม่เกิน 3 รูป/);
        assert.equal(document.querySelector('[data-ticket-photos]').classList.contains('is-full'), true);
    } finally {
        cleanup();
    }
});

test('a photo can be removed before sending and the input keeps the same set', () => {
    const {document, pick, image, files, revoked, cleanup} = setup();
    try {
        pick(image('a.jpg'), image('b.jpg'));

        const removeFirst = document.querySelector('[data-ticket-photo-remove="0"]');
        assert.match(removeFirst.getAttribute('aria-label'), /ลบรูปที่ 1/);
        click(removeFirst);

        assert.deepEqual(files().map((f) => f.name), ['b.jpg']);
        assert.equal(document.querySelectorAll('.ticket-photos__item').length, 1);
        assert.ok(revoked.length >= 2, 'old preview URLs are released');
        assert.equal(document.querySelector('[data-ticket-photos-error]').hidden, true);
    } finally {
        cleanup();
    }
});

test('a non-image is refused with a message and never reaches the input', () => {
    const {document, pick, image, files, cleanup} = setup();
    try {
        pick(image('report.pdf', 'application/pdf'));

        assert.deepEqual(files(), []);
        assert.match(document.querySelector('[data-ticket-photos-error]').textContent, /report\.pdf/);
    } finally {
        cleanup();
    }
});

test('the submit button shows a busy state and a second submit is blocked', () => {
    const {document, window, cleanup} = setup();
    try {
        const form = document.querySelector('[data-ticket-form]');
        const button = document.querySelector('[data-ticket-submit]');

        const first = new window.Event('submit', {bubbles: true, cancelable: true});
        form.dispatchEvent(first);
        assert.equal(first.defaultPrevented, false);
        assert.equal(button.disabled, true);
        assert.equal(button.getAttribute('aria-busy'), 'true');
        assert.equal(document.querySelector('[data-ticket-submit-spinner]').hidden, false);
        assert.equal(document.querySelector('[data-ticket-submit-label]').textContent, 'กำลังส่ง…');

        const second = new window.Event('submit', {bubbles: true, cancelable: true});
        form.dispatchEvent(second);
        assert.equal(second.defaultPrevented, true);
    } finally {
        cleanup();
    }
});

test('init is idempotent so listeners are never bound twice', () => {
    const {document, cleanup} = setup();
    try {
        assert.equal(initTicketForm(document), false);
        assert.equal(document.querySelector('[data-ticket-form]').dataset.ticketFormReady, 'true');
    } finally {
        cleanup();
    }
});

/* ---------- สัญญาของไฟล์ต้นทาง ---------- */

/** ทุก Blade ของ Smart Ticket (หน้าภายใน หน้าสาธารณะ components) และหน้าตั้งค่าของ admin */
function bladesUnder(dir) {
    const base = new URL(`../../${dir}/`, import.meta.url);

    return readdirSync(base).flatMap((name) => {
        const path = `${dir}/${name}`;
        if (statSync(new URL(name, base)).isDirectory()) return bladesUnder(path);

        return name.endsWith('.blade.php') ? [path] : [];
    });
}

const ticketBlades = [...bladesUnder('resources/views/tickets'), 'resources/views/admin/tickets/index.blade.php'];

/** CSS ไม่รวมคอมเมนต์ — คอมเมนต์อธิบายกติกา (เช่น "ไม่มี overflow:hidden") ต้องไม่ทำให้ตรวจผิด */
const readCss = (path) => read(path).replace(/\/\*[\s\S]*?\*\//g, '');

const ticketCss = [
    'resources/css/pages/tickets.css',
    'resources/css/pages/tickets-public.css',
    ...readdirSync(new URL('../../resources/css/pages/tickets/', import.meta.url)).map((name) => `resources/css/pages/tickets/${name}`),
];

test('every hook form.js needs exists in the Blade partials', () => {
    // สาขาอยู่ใน location-field (ใช้ทั้ง form-fields แบบหน้าเดียวและขั้นที่ 2 ของ modal แจ้งปัญหา)
    const fields = read('resources/views/tickets/components/location-field.blade.php');
    const picker = read('resources/views/tickets/components/photo-picker.blade.php');
    const button = read('resources/views/tickets/components/submit-button.blade.php');

    for (const hook of ['data-ticket-location', 'data-ticket-location-other']) {
        assert.ok(fields.includes(hook), hook);
    }
    for (const hook of ['data-ticket-photos', 'data-ticket-photos-input', 'data-ticket-photos-list', 'data-ticket-photos-error', 'data-max-files', 'data-max-bytes', 'data-accept']) {
        assert.ok(picker.includes(hook), hook);
    }
    for (const hook of ['data-ticket-submit', 'data-ticket-submit-label', 'data-ticket-submit-spinner']) {
        assert.ok(button.includes(hook), hook);
    }
});

test('ticket pages have no embedded styles or inline scripts', () => {
    assert.ok(ticketBlades.length >= 20, 'all ticket views are covered');

    for (const path of ticketBlades) {
        const source = read(path);
        assert.doesNotMatch(source, /<style/i, path);
        assert.doesNotMatch(source, /\sstyle="/i, path);
        assert.doesNotMatch(source, /<script(?![^>]*type="application\/json")[^>]*>/i, path);
    }
});

/*
 * ข้อยกเว้นเดียว: คิวงานโหมดตาราง (≥ 1280px) ตัดหัวข้อปัญหา ผู้แจ้ง และสาขาด้วย … ตามที่ออกแบบ
 * ข้อความเต็มยังอยู่ใน title ของแต่ละช่อง การ์ด (< 1280px) modal และหน้ารายละเอียด
 */
const queueTruncation = /\.ticket-queue__summary,\s*\.ticket-queue__name,\s*\.ticket-queue__location\s*\{\s*display: block;\s*overflow: hidden;\s*text-overflow: ellipsis;\s*\}/;

test('the queue table truncates only its text cells, only in table mode, and keeps the full text in title', () => {
    const css = readCss('resources/css/pages/tickets/list.css');
    const tableMode = css.slice(css.indexOf('@media (min-width: 1280px)'), css.indexOf('@media (min-width: 1440px)'));
    assert.match(tableMode, queueTruncation);
    assert.equal(css.match(new RegExp(queueTruncation.source, 'g')).length, 1);

    const queue = read('resources/views/tickets/components/queue.blade.php');
    assert.match(queue, /class="ticket-queue__summary" title="\{\{ \$ticket->summary \}\}"/);
    assert.match(queue, /class="ticket-queue__name" title="\{\{ \$ticket->requester_name \}\}"/);
    assert.match(queue, /class="ticket-queue__location" title="\{\{ \$ticket->location_name \}\}"/);
});

test('ticket CSS never hides or clips content to fix layout and has no sticky overlays', () => {
    for (const path of ticketCss) {
        const css = readCss(path).replace(queueTruncation, '');
        assert.doesNotMatch(css, /!important/, path);
        assert.doesNotMatch(css, /overflow(-x|-y)?\s*:\s*hidden/, path);
        assert.doesNotMatch(css, /position\s*:\s*(sticky|fixed)/, path);
        assert.doesNotMatch(css, /line-clamp/, path);
        assert.doesNotMatch(css, /linear-gradient|radial-gradient/, path);
    }
});

test('auto-sized grids never demand more width than a 320px screen has', () => {
    // repeat(auto-fill|auto-fit, minmax(300px, 1fr)) ล้นจอ 320px เพราะพื้นที่เนื้อหาเหลือ ~296px
    // ต้องเขียนเป็น minmax(min(100%, 300px), 1fr) เสมอ
    for (const path of ticketCss) {
        assert.doesNotMatch(readCss(path), /repeat\(\s*auto-(fill|fit)\s*,\s*minmax\(\s*\d/, path);
    }
});

test('every ticket entry used by Blade is registered in vite.config.js', () => {
    const config = read('vite.config.js');
    const used = new Set();

    for (const path of ticketBlades) {
        for (const block of read(path).matchAll(/@vite\(([\s\S]*?)\)/g)) {
            for (const entry of block[1].matchAll(/'(resources\/[^']+)'/g)) used.add(entry[1]);
        }
    }

    assert.deepEqual([...used].sort(), [
        'resources/css/pages/tickets-public.css',
        'resources/css/pages/tickets.css',
        'resources/js/pages/tickets/detail.js',
        'resources/js/pages/tickets/form.js',
        'resources/js/pages/tickets/index.js',
        'resources/js/pages/tickets/public.js',
    ]);
    for (const entry of used) assert.ok(config.includes(`'${entry}'`), entry);
});

test('the internal report form says "กำลังส่งคำขอ..." while sending, the public /support form keeps its own text', () => {
    const markup = (busyLabel) => `<!doctype html><html><body>
<form data-ticket-form><button type="submit" data-ticket-submit ${busyLabel ? `data-busy-label="${busyLabel}"` : ''}>
<span data-ticket-submit-spinner hidden></span><span data-ticket-submit-label>ส่งแจ้งปัญหา</span></button></form></body></html>`;

    for (const [busyLabel, expected] of [['กำลังส่งคำขอ...', 'กำลังส่งคำขอ...'], [null, 'กำลังส่ง…']]) {
        const dom = mountDom(markup(busyLabel));
        try {
            initTicketForm(dom.document);
            dom.document.querySelector('form').dispatchEvent(new dom.window.Event('submit', {bubbles: true, cancelable: true}));
            assert.equal(dom.document.querySelector('[data-ticket-submit-label]').textContent, expected);
        } finally {
            dom.cleanup();
        }
    }

    // ฟอร์มแจ้งปัญหาภายใน (modal + หน้าเดียว) ตั้งข้อความเอง · ฟอร์มสาธารณะใช้ค่าเริ่มต้นเดิม
    for (const path of ['resources/views/tickets/components/submit-modal.blade.php', 'resources/views/tickets/create.blade.php']) {
        assert.match(read(path), /submit-button', \['busyLabel' => 'กำลังส่งคำขอ\.\.\.'\]/, path);
    }
    for (const path of ['resources/views/tickets/public/create.blade.php']) {
        assert.doesNotMatch(read(path), /busyLabel/, path);
    }
});
