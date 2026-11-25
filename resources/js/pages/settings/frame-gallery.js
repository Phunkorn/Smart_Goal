export function initializeFrameGallery(root, paintFrame) {
    const modal = root.querySelector('[data-frame-gallery]');
    if (!modal) return new Map();

    const options = [...modal.querySelectorAll('[data-frame-option]')];
    const frames = new Map(options.map((option) => [option.dataset.frameSlug, option]));
    const tabs = [...modal.querySelectorAll('[data-frame-category]')];
    const search = modal.querySelector('[data-frame-search]');
    const results = modal.querySelector('[role="tabpanel"]');
    const hero = modal.querySelector('.sg-frame-gallery__hero [data-profile-frame]');
    const name = modal.querySelector('[data-frame-gallery-name]');
    const kind = modal.querySelector('[data-frame-gallery-kind]');
    const empty = modal.querySelector('[data-frame-empty]');
    const input = root.querySelector('[data-appearance-frame]');
    let category = 'all';
    let selected;

    const filter = () => {
        const query = search.value.trim().toLowerCase();
        let count = 0;
        options.forEach((option) => {
            const {frameName, frameSlug, frameKind} = option.dataset;
            const matchesCategory = category === 'all' || category === frameKind;
            const matchesQuery = frameName.toLowerCase().includes(query) || frameSlug.includes(query);
            option.hidden = !matchesCategory || !matchesQuery || option.hasAttribute('data-frame-inactive');
            if (!option.hidden) count++;
        });
        empty.hidden = count > 0;
    };
    const select = (option) => {
        selected?.setAttribute('aria-pressed', 'false');
        selected = option;
        selected.setAttribute('aria-pressed', 'true');
        name.textContent = option.dataset.frameName;
        kind.textContent = option.dataset.frameKindLabel;
        paintFrame(hero, option.querySelector('[data-profile-frame]'));
    };
    const activate = (tab) => {
        category = tab.dataset.frameCategory;
        tabs.forEach((item) => {
            item.setAttribute('aria-selected', String(item === tab));
            item.tabIndex = item === tab ? 0 : -1;
        });
        results.setAttribute('aria-labelledby', tab.id);
        filter();
    };
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab));
        tab.addEventListener('keydown', (event) => {
            const next = {ArrowLeft: (index + tabs.length - 1) % tabs.length,
                ArrowRight: (index + 1) % tabs.length, Home: 0, End: tabs.length - 1}[event.key];
            if (next === undefined) return;
            event.preventDefault();
            activate(tabs[next]);
            tabs[next].focus();
        });
    });
    options.forEach((option) => option.addEventListener('click', () => select(option)));
    search.addEventListener('input', filter);
    modal.addEventListener('show.bs.modal', () => {
        search.value = '';
        activate(tabs[0]);
        select(frames.get(input.value) ?? frames.get('none'));
        modal.querySelector('.modal-body').scrollTop = 0;
    });
    modal.querySelector('[data-frame-confirm]').addEventListener('click', () => {
        input.value = selected.dataset.frameSlug;
        input.dispatchEvent(new root.defaultView.Event('change'));
    });

    return frames;
}
