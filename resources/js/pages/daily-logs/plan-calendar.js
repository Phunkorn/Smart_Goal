/*
 * Read-only plan calendar. Dates select a list; they never write WorkLogs or
 * create a shift/roster assignment. Filtering submits the existing GET page.
 *
 * กดวันแล้วได้สองอย่างพร้อมกัน: รายการ "งานในวันที่ …" ใต้ปฏิทินเปลี่ยนเป็นวันนั้น
 * และเปิดกล่องรายละเอียดของวันนั้น ทั้งสองที่ใช้ renderItems() ชุดเดียวกัน
 * สถานะและป้ายทั้งหมดมาจาก server (WorkLogQueryService::calendarFor) ที่นี่แค่วาด
 */
import {modalStack} from '../../components/modal-stack.js';

export function initPlanCalendar({root = document.querySelector('[data-daily-log]'), stack = null} = {}) {
    const panel = root?.querySelector('[data-plan-calendar]');
    if (! panel || panel.dataset.planReady === 'on') return null;
    panel.dataset.planReady = 'on';

    let entries = {};
    try {
        entries = JSON.parse(panel.querySelector('[data-plan-entries]')?.textContent || '{}');
    } catch {
        entries = {};
    }
    const title = panel.querySelector('[data-plan-selected-title]');
    const list = panel.querySelector('[data-plan-selected-items]');
    const doc = panel.ownerDocument;

    const modal = panel.querySelector('[data-plan-day-modal]');
    const modalTitle = modal?.querySelector('[data-plan-day-modal-title]');
    const modalSummary = modal?.querySelector('[data-plan-day-modal-summary]');
    const modalList = modal?.querySelector('[data-plan-day-modal-items]');
    const layers = modal ? (stack || modalStack(doc)) : null;

    const itemsOf = (date) => (Array.isArray(entries[date]) ? entries[date] : []);

    const dayLabel = (date) => new Intl.DateTimeFormat('th-TH-u-ca-buddhist', {
        day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Bangkok',
    }).format(new Date(`${date}T12:00:00+07:00`));

    const personNode = (person) => {
        const owner = doc.createElement('span');
        owner.className = 'daily-plan__item-owner';
        const avatar = doc.createElement('span');
        avatar.className = 'daily-plan__avatar';
        if (person.avatar_url) {
            const image = doc.createElement('img');
            image.src = person.avatar_url;
            image.alt = '';
            image.loading = 'lazy';
            avatar.appendChild(image);
        } else {
            avatar.textContent = person.initial || '?';
        }
        const ownerName = doc.createElement('span');
        ownerName.textContent = person.name || '';
        owner.append(avatar, ownerName);
        return owner;
    };

    /** งานประจำที่ทำร่วมกันเป็นแถวเดียว มีทุกคนที่ทำอยู่ด้วยกัน (รวมกลุ่มที่ server แล้ว) */
    const peopleOf = (item) => (Array.isArray(item.people) ? item.people : []);

    const peopleNode = (item) => {
        const people = doc.createElement('span');
        people.className = 'daily-plan__item-people';
        people.title = peopleOf(item).map((person) => person.name || '').join(', ');
        people.append(...peopleOf(item).map(personNode));
        return people;
    };

    /** ตัววาดรายการชุดเดียวของทั้งรายการใต้ปฏิทินและกล่องรายละเอียด */
    const renderItems = (container, items) => {
        if (! container) return;
        container.replaceChildren();

        if (items.length === 0) {
            const empty = doc.createElement('p');
            empty.className = 'daily-plan__empty';
            empty.textContent = 'ไม่มีงานที่ลงไว้ในวันนี้';
            container.appendChild(empty);
            return;
        }

        items.forEach((item) => {
            const row = doc.createElement('div');
            row.className = 'daily-plan__item';
            const time = doc.createElement('span');
            time.className = 'daily-plan__item-time';
            time.textContent = item.time || '—';
            const body = doc.createElement('span');
            body.className = 'daily-plan__item-body';
            const name = doc.createElement('strong');
            const icon = doc.createElement('i');
            icon.className = `bi ${item.kind === 'field' ? 'bi-geo-alt' : 'bi-arrow-repeat'}`;
            icon.setAttribute('aria-hidden', 'true');
            name.appendChild(icon);
            name.appendChild(doc.createTextNode(item.title || ''));
            const meta = doc.createElement('small');
            meta.textContent = (item.kind === 'field' ? 'งานนอกสถานที่' : 'งานประจำ')
                + (item.category ? ` · ${item.category}` : '')
                + (item.project ? ` · ${item.project}` : '');
            body.append(name, meta);
            // ป้ายและโทนสีมาจาก WorkLogDesign ฝั่ง server — ที่นี่แค่วาด ไม่ตัดสินสถานะเอง
            if (item.status_label) {
                const status = doc.createElement('span');
                const tone = /^[a-z]+$/.test(item.status_tone || '') ? item.status_tone : 'gray';
                status.className = `daily-plan__item-status daily-plan__item-status--${tone}`;
                status.textContent = item.status_label;
                body.appendChild(status);
            }
            row.append(time, body, peopleNode(item));
            container.appendChild(row);
        });
    };

    /** สรุปหัวกล่อง เช่น "4 รายการ · 3 คน · เสร็จแล้ว 2 · พบปัญหา 1" */
    const summaryOf = (items) => {
        const people = new Set(items.flatMap((item) => peopleOf(item).map((person) => person.id))).size;
        const byStatus = new Map();
        items.forEach((item) => {
            if (item.status_label) byStatus.set(item.status_label, (byStatus.get(item.status_label) || 0) + 1);
        });

        return [`${items.length} รายการ`, `${people} คน`, ...[...byStatus].map(([label, count]) => `${label} ${count}`)]
            .join(' · ');
    };

    const showDay = (date) => {
        const items = itemsOf(date);
        panel.querySelectorAll('[data-plan-day]').forEach((button) => {
            button.setAttribute('aria-pressed', button.dataset.planDay === date ? 'true' : 'false');
        });
        if (title) title.textContent = `งานในวันที่ ${dayLabel(date)} (${items.length} รายการ)`;
        renderItems(list, items);
        return items;
    };

    const closeDay = () => {
        if (modal) layers.close(modal);
    };

    const openDay = (date, opener = null) => {
        const items = showDay(date);
        if (! modal) return;

        if (modalTitle) modalTitle.textContent = `งานวันที่ ${dayLabel(date)}`;
        if (modalSummary) modalSummary.textContent = summaryOf(items);
        renderItems(modalList, items);
        layers.open(modal, opener);
    };

    panel.addEventListener('click', (event) => {
        if (modal?.contains(event.target)) {
            // ปุ่มปิด หรือคลิกที่ฉากหลังนอกแผงกล่อง
            if (event.target.closest('[data-plan-day-modal-close]') || event.target === modal) closeDay();
            return;
        }

        const button = event.target.closest('[data-plan-day]');
        if (button && panel.contains(button) && ! button.disabled) openDay(button.dataset.planDay, button);
    });
    // modal-stack ส่งเหตุการณ์นี้เมื่อกด Escape ขณะกล่องนี้อยู่ชั้นบนสุด
    modal?.addEventListener('modalstack:dismiss', closeDay);

    panel.querySelectorAll('[data-plan-filter]').forEach((select) => {
        select.addEventListener('change', () => select.form?.submit());
    });
    return {showDay, openDay, closeDay};
}
