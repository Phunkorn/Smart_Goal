import {boardFilterStateFrom, normalizeTaskScope, parametersForTaskWorkspace} from './pages/mytasks/task-filter-state.js';

(() => {
    const workspace = document.querySelector('[data-workspace]');
    if (!workspace) return;
    const database = workspace.querySelector('.notion-database');
    const groupSelect = workspace.querySelector('[data-group]');
    /**
     * ต้องจำกัดที่ role="tab" เท่านั้น — `data-view` ถูกใช้สองความหมายในหน้านี้:
     * เป็น "ปุ่มสลับมุมมอง" บนแถบ .notion-viewbar (role="tab") และเป็น "สถานะมุมมองปัจจุบัน"
     * บน <section class="notion-database" data-view="..."> ซึ่งครอบ panel ทั้งหมดรวมถึงปฏิทิน
     *
     * เดิมเลือกด้วย '[data-view]' เปล่า ๆ จึงจับ section ตัวครอบมาเป็น "ปุ่ม" ด้วย ผลคือ
     * click listener ของปุ่มสลับมุมมองถูกผูกไว้กับ section ที่ครอบทั้งปฏิทิน คลิกอะไรก็ตาม
     * ข้างใน (chip งาน/ประชุม, ปุ่มเปลี่ยนเดือน, ช่องวันที่) จะ bubble ขึ้นมาโดน listener นี้
     * แล้วยิง selectView() → applyView() → event 'mytasks:viewchange' ทุกครั้ง
     * ทำให้ปฏิทินถูกสั่ง re-render และ Quick View ที่เพิ่งเปิดถูกปิดทิ้งทันที
     * (และ section ยังโดนใส่ class active/aria-selected ทั้งที่ไม่ใช่ tab)
     */
    const tabs = [...workspace.querySelectorAll('[role="tab"][data-view]')];
    const boardToolbar = workspace.querySelector('[data-board-toolbar]');
    const scopeControl = workspace.querySelector('[data-task-scope-control]');
    if (!database) return;
    if (!tabs.length) {
        database.dataset.view = 'table';
        return;
    }

    // มุมมองที่หน้านี้มีปุ่มจริง — หน้าที่ไม่มี panel ของมุมมองนั้นต้องสลับไปหาไม่ได้
    const knownViews = tabs.map((tab) => tab.dataset.view);
    // ปุ่มที่มี data-view-navigate ต้องโหลดหน้าใหม่ เพราะ panel ของมันถูก render จาก server เท่านั้น
    const clientViews = tabs.filter((tab) => !('viewNavigate' in tab.dataset)).map((tab) => tab.dataset.view);
    const fallbackView = clientViews.includes('calendar') ? 'calendar' : (clientViews[0] || 'table');
    /**
     * เขียน History เฉพาะหน้าที่ server อ่าน ?view= จริง กันไม่ให้ URL ของหน้าอื่นเปื้อน
     *
     * หน้าเป็นผู้ประกาศความสามารถนี้เองผ่าน data-view-history ไม่ใช่ให้ JS เดาจาก context
     * เพราะตอนนี้มีสองหน้าที่ resolve ?view= ฝั่ง server แล้ว (งานของฉัน และ Admin Member Workspace)
     */
    const historyEnabled = workspace.dataset.viewHistory === 'true';
    const serverView = knownViews.includes(database.dataset.view) ? database.dataset.view : fallbackView;

    let tableGrouping = groupSelect?.value || 'project';

    /**
     * เปลี่ยนเฉพาะ DOM ห้ามแตะ History เด็ดขาด
     * เพราะถูกเรียกจาก popstate ด้วย ถ้าเขียน History ซ้ำจะเกิด entry ซ้อนหรือวนลูป
     */
    const applyView = (requestedView, announce = true) => {
        const view = knownViews.includes(requestedView) ? requestedView : fallbackView;
        database.dataset.view = view;
        if (boardToolbar) boardToolbar.hidden = view !== 'board';
        tabs.forEach((tab) => {
            const active = tab.dataset.view === view;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', String(active));
        });
        workspace.querySelectorAll('[data-view-panel]').forEach((panel) => {
            panel.setAttribute('aria-hidden', String(panel.dataset.viewPanel !== view));
        });

        if (groupSelect) groupSelect.disabled = false;
        groupSelect?.closest('.notion-group')?.classList.remove('is-locked');
        if (view === 'table' && groupSelect && groupSelect.value !== tableGrouping) {
            groupSelect.value = tableGrouping;
            groupSelect.dispatchEvent(new Event('change', {bubbles: true}));
        }

        document.dispatchEvent(new CustomEvent('mytasks:viewchange', {detail: {view}}));
        if (announce) document.querySelector('[data-toast]')?.dispatchEvent(new CustomEvent('viewchange'));
        return view;
    };

    /** ผู้ใช้กดเปลี่ยนมุมมองเอง จึงต้องสร้าง History entry ให้ย้อนกลับได้ */
    const selectView = (view) => {
        if (!clientViews.includes(view)) return;
        applyView(view);
        if (!historyEnabled) return;

        const url = new URL(window.location.href);
        if (url.searchParams.get('view') === view) return;
        url.searchParams.set('view', view);
        window.history.pushState({mytasksView: view}, '', url);
    };

    tabs.forEach((tab) => {
        if ('viewNavigate' in tab.dataset) return;
        tab.addEventListener('click', () => selectView(tab.dataset.view));
    });

    window.addEventListener('popstate', () => {
        const view = new URL(window.location.href).searchParams.get('view');
        applyView(knownViews.includes(view) ? view : serverView, false);
    });

    /*
     * ตัวกรองขอบเขตงานเป็น popover <details> ไม่ใช่ <select> อีกต่อไป
     * เพราะ native select ใส่หัวกลุ่มและคำอธิบายกำกับแต่ละตัวเลือกไม่ได้
     *
     * ตามกฎ popover ของโปรเจกต์: ปิดเมื่อคลิกนอกกรอบและเมื่อกด Escape
     * แล้วคืนโฟกัสให้ตัวเปิดเสมอ
     */
    const scopeMenu = workspace.querySelector('[data-task-scope-menu]');

    const closeScopeMenu = ({restoreFocus = false} = {}) => {
        if (!scopeMenu?.open) return;
        scopeMenu.removeAttribute('open');
        if (restoreFocus) scopeMenu.querySelector('summary')?.focus();
    };

    scopeControl?.addEventListener('click', (event) => {
        const option = event.target.closest('[data-task-scope-option]');
        if (!option) return;

        event.preventDefault();
        closeScopeMenu();

        const url = new URL(window.location.href);
        const state = boardFilterStateFrom(url.searchParams);
        const parameters = parametersForTaskWorkspace(
            url.searchParams,
            state,
            normalizeTaskScope(option.dataset.taskScopeOption),
        );
        parameters.set('view', database.dataset.view);
        url.search = parameters.toString();
        window.location.assign(url);
    });

    if (scopeMenu) {
        document.addEventListener('click', (event) => {
            if (!scopeMenu.contains(event.target)) closeScopeMenu();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') closeScopeMenu({restoreFocus: true});
        });
    }

    groupSelect?.addEventListener('change', () => {
        if (database.dataset.view !== 'board') tableGrouping = groupSelect.value;
    });

    // server ตัดสินมุมมองมาแล้ว init จึงแค่ประกาศสถานะ ไม่ต้องอ่านค่าที่ไหนมาทับ (จึงไม่กระพริบ)
    applyView(serverView, false);

    // replaceState ไม่สร้าง entry ใหม่ ใช้เพื่อให้ URL ตั้งต้นมี ?view= ไว้เป็นจุดอ้างอิงของ Back/Forward
    if (historyEnabled) {
        const initialUrl = new URL(window.location.href);
        if (initialUrl.searchParams.get('view') !== serverView) {
            initialUrl.searchParams.set('view', serverView);
            window.history.replaceState({mytasksView: serverView}, '', initialUrl);
        }
    }
})();
