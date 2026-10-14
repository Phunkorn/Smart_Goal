import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';

/*
 * คอลัมน์ "ผู้รับผิดชอบ" และ "ผู้เข้าร่วม" ของการ์ดสรุปใต้ปฏิทินต้องใช้รูปโปรไฟล์จริง
 *
 * ของเดิมอ่าน team.assignee / team.collaborators ซึ่ง [data-team-data] เลิกส่งไปแล้ว
 * (เหลือ leader / participants ตาม App\Support\ProjectRoles) ทุกแถวจึงตกไปที่ตัวย่อ
 * ของคำว่า "ไม่ระบุ" และคำว่า "ไม่มี" ทั้งที่ผู้ใช้อัปโหลดรูปไว้แล้ว
 * fixture ที่นี่จึงใช้รูปร่าง payload เดียวกับที่ workspace-interactions.blade.php ฝังไว้จริง
 */

const isoDate = (date) => [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
].join('-');

const months = Array.from({length: 12}, (_, index) => `<option value="${index}">${index + 1}</option>`).join('');

const person = (id, name, extra = {}) => ({id, name, email: `${id}@example.com`, department: 'IT', avatar_url: `/media/profile/${id}`, ...extra});

const teamData = {
    // ผู้สร้างมอบหมายให้คนอื่น: ผู้รับผิดชอบอยู่ใน participants พร้อม is_assignee
    1: {
        leader: person(10, 'หัวหน้า'),
        participants: [
            person(11, 'ผู้รับผิดชอบ', {status: 'accepted', is_assignee: true}),
            person(12, 'ผู้ร่วมมีรูป', {status: 'accepted', is_assignee: false}),
            person(13, 'ผู้ร่วมไม่มีรูป', {status: 'pending', is_assignee: false, avatar_url: null}),
        ],
    },
    // ผู้สร้างทำงานเอง: ผู้รับผิดชอบคือหัวหน้าโปรเจกต์ จึงไม่อยู่ใน participants
    2: {leader: person(20, 'ทำเอง'), participants: []},
};

const ownerData = {
    1: {name: 'ผู้รับผิดชอบ', avatar_url: '/media/profile/11', initial: 'ผ'},
    2: {name: 'ทำเอง', avatar_url: '/media/profile/20', initial: 'ท'},
    3: {name: 'มานะ', avatar_url: null, initial: 'ม'},
};

let fixtureCount = 0;

async function bootCalendar(t) {
    const env = mountDom();
    t.after(env.cleanup);

    const today = isoDate(new Date());
    const row = (id) => `<div data-row data-id="${id}" data-topic="งาน ${id}" data-project="โปรเจกต์"
        data-status="2" data-priority="2" data-start="${today}" data-due="${today}"></div>`;

    env.document.body.innerHTML = `
        <div data-workspace>
            <div data-workspace-task-source hidden>${row(1)}${row(2)}${row(3)}</div>
            <section data-calendar>
                <h2 data-calendar-title></h2>
                <p data-calendar-display-note></p>
                <input type="search" data-calendar-search>
                <select data-calendar-month>${months}</select>
                <select data-calendar-year></select>
                <div data-calendar-grid></div>
                <div data-calendar-agenda>
                    <strong data-calendar-today-count></strong>
                    <div data-calendar-today-list></div>
                    <p data-calendar-today-empty></p>
                    <h3 data-calendar-month-agenda-title></h3>
                    <strong data-calendar-month-count></strong>
                    <div data-calendar-month-list></div>
                    <p data-calendar-month-empty></p>
                </div>
            </section>
            <script type="application/json" data-team-data>${JSON.stringify(teamData)}</script>
            <script type="application/json" data-owner-data>${JSON.stringify(ownerData)}</script>
            <div data-calendar-detail hidden></div>
            <div data-calendar-day-modal hidden>
                <h2 data-calendar-day-title></h2>
                <button type="button" data-calendar-day-close></button>
                <section data-calendar-day-tasks hidden><b data-calendar-day-task-count></b><div data-calendar-day-task-list></div></section>
                <small data-calendar-day-count></small>
            </div>
            <div data-calendar-agenda-modal hidden><div data-calendar-agenda-modal-list></div></div>
        </div>`;

    globalThis.fetch = () => Promise.resolve({ok: true, json: async () => ({}), text: async () => ''});
    t.after(() => { delete globalThis.fetch; });

    fixtureCount += 1;
    await import(`../../resources/js/pages/mytasks/calendar.js?avatars=${fixtureCount}`);

    const agendaTask = (id) => env.document.querySelector(`[data-calendar-today-list] [data-calendar-task="task-${id}"]`);

    return {
        ...env,
        owner: (id) => agendaTask(id).querySelector('.calendar-avatar.is-owner'),
        collaborators: (id) => [...agendaTask(id).querySelectorAll('.calendar-people__stack .calendar-avatar')],
        stack: (id) => agendaTask(id).querySelector('.calendar-people__stack'),
    };
}

test('คอลัมน์ผู้รับผิดชอบใช้รูปโปรไฟล์ของผู้รับผิดชอบงาน', async (t) => {
    const ui = await bootCalendar(t);

    assert.equal(ui.owner(1).querySelector('img')?.getAttribute('src'), '/media/profile/11');
    assert.equal(ui.owner(1).title, 'ผู้รับผิดชอบ');
    assert.equal(ui.owner(2).querySelector('img')?.getAttribute('src'), '/media/profile/20', 'ผู้สร้างที่ทำงานเองต้องได้รูปของตัวเอง');
});

test('ผู้รับผิดชอบที่ยังไม่มีรูปแสดงตัวย่อชื่อของตัวเอง', async (t) => {
    const ui = await bootCalendar(t);

    assert.equal(ui.owner(3).querySelector('img'), null);
    assert.equal(ui.owner(3).textContent, 'ม');
    assert.equal(ui.owner(3).title, 'มานะ');
});

test('คอลัมน์ผู้เข้าร่วมใช้รูปโปรไฟล์และไม่นับผู้รับผิดชอบซ้ำ', async (t) => {
    const ui = await bootCalendar(t);
    const [withImage, withoutImage, ...rest] = ui.collaborators(1);

    assert.equal(rest.length, 0, 'ผู้รับผิดชอบมีคอลัมน์ของตัวเองแล้ว ต้องไม่ซ้ำในผู้เข้าร่วม');
    assert.equal(withImage.querySelector('img')?.getAttribute('src'), '/media/profile/12');
    assert.equal(withoutImage.querySelector('img'), null);
    assert.equal(withoutImage.textContent, 'ผ');
    assert.ok(withoutImage.classList.contains('is-pending'));
});

test('งานที่ไม่มีผู้เข้าร่วมยังบอกว่าไม่มี', async (t) => {
    const ui = await bootCalendar(t);

    assert.equal(ui.collaborators(2).length, 0);
    assert.equal(ui.stack(2).textContent, 'ไม่มี');
});
