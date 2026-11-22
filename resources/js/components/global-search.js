/**
 * ช่องค้นหางานและโปรเจกต์บน Topbar (layouts/components/global-search.blade.php)
 *
 * เป็น popover ที่มีเจ้าของสถานะคนเดียวคือไฟล์นี้: เปิด/ปิด โฟกัส Escape คลิกนอกกล่อง และการยิงคำค้น
 * - เปิดด้วยปุ่มบน Topbar หรือ Ctrl + K (⌘ + K บน macOS) ปิดด้วย Escape หรือคลิกนอกกล่อง แล้วคืนโฟกัสให้ปุ่ม
 * - ไม่ล็อกการเลื่อนหน้าและไม่บังทั้งหน้า (ไม่ใช่ modal) การมองเห็นคุมด้วยคลาส is-open ล้วน ๆ
 * - หน่วงการพิมพ์ DEBOUNCE_MS แล้วยกเลิกคำขอก่อนหน้า (AbortController) และทิ้งคำตอบที่มาช้ากว่าคำค้นล่าสุด
 * - ลูกศรขึ้น/ลงเลือกผลลัพธ์ (aria-activedescendant) Enter เปิดรายการที่เลือก
 * - ผลลัพธ์สร้างด้วย DOM API และ textContent เท่านั้น ข้อความจากฐานข้อมูลจึงไม่ถูกตีความเป็น HTML
 *
 * เรียกซ้ำได้โดยไม่ผูก listener ซ้ำ และ destroy() ถอด listener ทั้งหมด
 */
export const DEBOUNCE_MS = 300;

const MESSAGES = {
    idle: 'ค้นจากชื่องาน เนื้อหางาน หรือชื่อโปรเจกต์',
    short: (min) => `พิมพ์อย่างน้อย ${min} ตัวอักษรเพื่อค้นหา`,
    loading: 'กำลังค้นหา...',
    empty: (query) => `ไม่พบงานหรือโปรเจกต์ที่ตรงกับ “${query}”`,
    error: 'ค้นหาไม่สำเร็จ กรุณาลองใหม่อีกครั้ง',
};

/**
 * แบ่งข้อความเป็นส่วนที่ตรง/ไม่ตรงคำค้น (ไม่สนตัวพิมพ์) — ฟังก์ชันล้วน ไม่แตะ DOM
 *
 * @returns {{text: string, match: boolean}[]}
 */
export function highlightParts(text, query) {
    const source = String(text ?? '');
    const needle = String(query ?? '').trim();
    const lowerSource = source.toLocaleLowerCase();
    const lowerNeedle = needle.toLocaleLowerCase();

    // ตัวอักษรบางตัวเปลี่ยนความยาวเมื่อแปลงตัวพิมพ์ ตำแหน่งจะคลาดกัน จึงไม่ไฮไลต์ในกรณีนั้น
    if (! needle || lowerSource.length !== source.length || lowerNeedle.length !== needle.length) {
        return source ? [{text: source, match: false}] : [];
    }

    const parts = [];
    let cursor = 0;
    let found = lowerSource.indexOf(lowerNeedle);
    while (found !== -1) {
        if (found > cursor) parts.push({text: source.slice(cursor, found), match: false});
        parts.push({text: source.slice(found, found + needle.length), match: true});
        cursor = found + needle.length;
        found = lowerSource.indexOf(lowerNeedle, cursor);
    }
    if (cursor < source.length) parts.push({text: source.slice(cursor), match: false});

    return parts;
}

export function initGlobalSearch(doc = document, {
    win = doc.defaultView,
    fetchImpl = win?.fetch?.bind(win),
    navigate = (url) => win.location.assign(url),
    debounceMs = DEBOUNCE_MS,
} = {}) {
    const root = doc.querySelector('[data-global-search]');
    if (! root || root.dataset.globalSearchReady === 'true') return null;
    root.dataset.globalSearchReady = 'true';

    const trigger = root.querySelector('[data-global-search-trigger]');
    const panel = root.querySelector('[data-global-search-panel]');
    const input = root.querySelector('[data-global-search-input]');
    const results = root.querySelector('[data-global-search-results]');
    const message = root.querySelector('[data-global-search-message]');
    const status = root.querySelector('[data-global-search-status]');
    const clear = root.querySelector('[data-global-search-clear]');
    const endpoint = root.dataset.searchUrl;
    const minLength = Number(root.dataset.searchMinLength) || 2;

    const listeners = new win.AbortController();
    const {signal} = listeners;
    let timer = null;
    let inFlight = null;
    let sequence = 0;
    let activeIndex = -1;
    let lastQuery = null;

    const isOpen = () => root.classList.contains('is-open');
    const options = () => [...results.querySelectorAll('[role="option"]')];

    const setMessage = (text) => {
        message.textContent = text;
        message.hidden = text === '';
    };

    const setLoading = (loading) => {
        root.classList.toggle('is-loading', loading);
        results.setAttribute('aria-busy', String(loading));
    };

    const select = (index) => {
        const all = options();
        activeIndex = all.length === 0 ? -1 : (index + all.length) % all.length;
        all.forEach((option, position) => {
            const selected = position === activeIndex;
            option.setAttribute('aria-selected', String(selected));
            option.classList.toggle('is-selected', selected);
        });
        const active = all[activeIndex];
        if (active) {
            input.setAttribute('aria-activedescendant', active.id);
            active.scrollIntoView?.({block: 'nearest'});
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    };

    const cancelPending = () => {
        win.clearTimeout(timer);
        timer = null;
        inFlight?.abort();
        inFlight = null;
        setLoading(false);
    };

    const resetResults = () => {
        results.replaceChildren();
        input.setAttribute('aria-expanded', 'false');
        select(-1);
    };

    const appendText = (element, text, query) => {
        highlightParts(text, query).forEach((part) => {
            if (part.match) {
                const mark = doc.createElement('mark');
                mark.textContent = part.text;
                element.append(mark);
            } else {
                element.append(doc.createTextNode(part.text));
            }
        });
    };

    const option = ({id, url, icon, title, meta, status: badge, query, modifier}) => {
        const link = doc.createElement('a');
        link.className = `global-search__option${modifier ? ` global-search__option--${modifier}` : ''}`;
        link.id = id;
        link.href = url;
        link.setAttribute('role', 'option');
        link.setAttribute('aria-selected', 'false');
        link.tabIndex = -1;

        const glyph = doc.createElement('span');
        glyph.className = 'global-search__option-icon';
        glyph.setAttribute('aria-hidden', 'true');
        const iconElement = doc.createElement('i');
        iconElement.className = `bi ${icon}`;
        glyph.append(iconElement);

        const text = doc.createElement('span');
        text.className = 'global-search__option-text';
        const name = doc.createElement('span');
        name.className = 'global-search__option-title';
        appendText(name, title, query);
        text.append(name);

        if (meta || badge) {
            const detail = doc.createElement('span');
            detail.className = 'global-search__option-meta';
            if (meta) appendText(detail, meta, query);
            if (badge) {
                const chip = doc.createElement('span');
                chip.className = `global-search__status global-search__status--${badge.tone}`;
                chip.textContent = badge.label;
                detail.append(chip);
            }
            text.append(detail);
        }

        link.append(glyph, text);
        return link;
    };

    const group = (key, label, children) => {
        const section = doc.createElement('div');
        section.className = 'global-search__group';
        section.setAttribute('role', 'group');
        const heading = doc.createElement('div');
        heading.className = 'global-search__group-label';
        heading.id = `global-search-group-${key}`;
        heading.setAttribute('role', 'presentation');
        heading.textContent = label;
        section.setAttribute('aria-labelledby', heading.id);
        section.append(heading, ...children);
        return section;
    };

    const render = (data, query) => {
        resetResults();
        const tasks = Array.isArray(data?.tasks) ? data.tasks : [];
        const projects = Array.isArray(data?.projects) ? data.projects : [];

        if (tasks.length === 0 && projects.length === 0) {
            setMessage(MESSAGES.empty(query));
            status.textContent = MESSAGES.empty(query);
            return;
        }

        const sections = [];
        if (tasks.length > 0) {
            sections.push(group('tasks', 'งาน', tasks.map((task) => option({
                id: `global-search-task-${task.id}`,
                url: task.url,
                icon: 'bi-check2-square',
                title: task.title,
                meta: task.project ? `โปรเจกต์: ${task.project}` : 'งานที่ไม่อยู่ในโปรเจกต์',
                status: task.status,
                query,
            }))));
        }
        if (projects.length > 0) {
            sections.push(group('projects', 'โปรเจกต์', projects.map((project) => option({
                id: `global-search-project-${project.id}`,
                url: project.url,
                icon: 'bi-folder2',
                title: project.title,
                meta: project.owner ? `โปรเจกต์ · ${project.owner}` : 'โปรเจกต์',
                query,
            }))));
        }
        if (data.all_results_url && (data.more_tasks || data.more_projects)) {
            sections.push(option({
                id: 'global-search-all',
                url: data.all_results_url,
                icon: 'bi-arrow-right-circle',
                title: `ดูผลทั้งหมดของ “${query}”`,
                modifier: 'all',
            }));
        }

        results.append(...sections);
        input.setAttribute('aria-expanded', 'true');
        setMessage('');
        status.textContent = `พบ ${tasks.length} งาน และ ${projects.length} โปรเจกต์`;
        select(0);
    };

    const run = async (query) => {
        inFlight?.abort();
        const controller = new win.AbortController();
        inFlight = controller;
        const ticket = ++sequence;
        setLoading(true);

        try {
            const url = new URL(endpoint, win.location.href);
            url.searchParams.set('q', query);
            const response = await fetchImpl(url.toString(), {
                headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin',
                signal: controller.signal,
            });
            if (! response.ok) throw new Error(`search failed: ${response.status}`);
            const data = await response.json();
            // คำตอบของคำค้นเก่าที่มาถึงทีหลังต้องไม่ทับผลของคำค้นล่าสุด
            if (ticket !== sequence) return;
            lastQuery = query;
            render(data, query);
        } catch (error) {
            if (error?.name === 'AbortError' || ticket !== sequence) return;
            resetResults();
            lastQuery = null;
            setMessage(MESSAGES.error);
            status.textContent = MESSAGES.error;
        } finally {
            if (ticket === sequence) {
                setLoading(false);
                inFlight = null;
            }
        }
    };

    const schedule = () => {
        const query = input.value.trim();
        clear.hidden = input.value === '';
        win.clearTimeout(timer);

        if (query.length < minLength) {
            cancelPending();
            resetResults();
            lastQuery = null;
            setMessage(query.length === 0 ? MESSAGES.idle : MESSAGES.short(minLength));
            return;
        }
        if (query === lastQuery) return;

        setMessage(MESSAGES.loading);
        timer = win.setTimeout(() => {
            timer = null;
            run(query);
        }, debounceMs);
    };

    const open = () => {
        if (! isOpen()) {
            // คีย์ลัดเปิดได้ขณะที่ดร็อปดาวน์อื่นของ Topbar เปิดอยู่ ปิดมันก่อนเพื่อไม่ให้ซ้อนกัน
            doc.querySelectorAll('[data-bs-toggle="dropdown"][aria-expanded="true"]').forEach((toggle) => {
                win.bootstrap?.Dropdown?.getOrCreateInstance(toggle)?.hide();
            });
            root.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
        }
        input.focus();
        input.select();
        // คำค้นที่ถูกยกเลิกตอนปิดกล่อง (พิมพ์แล้วปิดก่อนครบเวลาหน่วง) ค้นต่อเมื่อเปิดกลับมา
        if (input.value.trim() !== (lastQuery ?? '')) schedule();
    };

    const close = ({restoreFocus = true} = {}) => {
        if (! isOpen()) return;
        // ข้อความที่พิมพ์และผลลัพธ์ยังอยู่ เปิดอีกครั้งจึงเห็นผลเดิม ส่วนคำค้นที่ยังไม่ได้คำตอบถูกยกเลิก
        cancelPending();
        root.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');
        if (restoreFocus) trigger.focus();
    };

    trigger.addEventListener('click', () => (isOpen() ? close() : open()), {signal});

    input.addEventListener('input', schedule, {signal});

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (options().length === 0) return;
            event.preventDefault();
            select(activeIndex + (event.key === 'ArrowDown' ? 1 : -1));
        } else if (event.key === 'Enter') {
            const active = options()[activeIndex];
            if (! active) return;
            event.preventDefault();
            navigate(active.href);
        }
    }, {signal});

    clear.addEventListener('click', () => {
        input.value = '';
        schedule();
        input.focus();
    }, {signal});

    results.addEventListener('mousemove', (event) => {
        const hovered = event.target.closest('[role="option"]');
        const index = options().indexOf(hovered);
        if (index !== -1 && index !== activeIndex) select(index);
    }, {signal});

    root.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || ! isOpen()) return;
        event.preventDefault();
        event.stopPropagation();
        close();
    }, {signal});

    doc.addEventListener('keydown', (event) => {
        if (! (event.ctrlKey || event.metaKey) || event.altKey || event.key?.toLowerCase() !== 'k') return;
        // modal ของหน้าเป็นเจ้าของโฟกัสอยู่ คีย์ลัดไม่ควรเปิดกล่องค้นหาไว้ข้างหลัง modal
        // แถบเมนูด้านข้างบนจอเล็กก็เป็น modal เช่นกัน (Topbar เป็น inert ระหว่างนั้น)
        if (doc.body.classList.contains('modal-open') || doc.body.classList.contains('mobile-sidebar-open')) return;
        event.preventDefault();
        open();
    }, {signal});

    doc.addEventListener('click', (event) => {
        if (isOpen() && ! root.contains(event.target)) close({restoreFocus: false});
    }, {signal});

    // ดร็อปดาวน์อื่นของ Topbar เปิดขึ้น (เมนู กระดิ่ง บัญชี) — กล่องค้นหาต้องหลีกทาง ไม่ซ้อนกัน
    doc.addEventListener('show.bs.dropdown', () => close({restoreFocus: false}), {signal});

    setMessage(MESSAGES.idle);

    return {
        open,
        close,
        isOpen,
        destroy() {
            cancelPending();
            listeners.abort();
            delete root.dataset.globalSearchReady;
        },
    };
}

if (typeof document !== 'undefined') initGlobalSearch(document);
