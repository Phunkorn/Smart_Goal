export const installCompletedGroupToggle = (root = document) => {
    if (!root?.addEventListener) return () => {};

    const onClick = (event) => {
        const summary = event.target?.closest?.('[data-project-board] [data-completed-group] > summary');
        if (!summary || !root.contains(summary)) return;

        const group = summary.parentElement;
        if (!group?.matches?.('[data-completed-group]')) return;

        // Own this toggle explicitly. The board has delegated click handling for
        // several <details> menus, so relying on the browser's deferred native
        // toggle makes this non-menu disclosure vulnerable to later handlers.
        event.preventDefault();
        group.toggleAttribute('open', !group.hasAttribute('open'));
    };

    root.addEventListener('click', onClick);
    return () => root.removeEventListener('click', onClick);
};

if (typeof document !== 'undefined') installCompletedGroupToggle(document);
