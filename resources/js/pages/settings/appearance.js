/** Local previews stay in the browser; uploads use the existing account form flow. */
export function initializeProfileAppearance(root = document) {
    const win = root.defaultView;
    const states = new WeakMap();
    const paintCover = (cover, url) => {
        if (!cover) return;
        cover.querySelectorAll('img').forEach((image) => image.remove());
        if (!url) return;
        const image = root.createElement('img');
        image.alt = '';
        image.dataset.profileCoverImage = '';
        image.src = url;
        cover.append(image);
    };
    const release = (state) => {
        if (state.objectUrl) win.URL.revokeObjectURL(state.objectUrl);
        state.objectUrl = null;
    };
    root.querySelectorAll('[data-account-form="appearance"]').forEach((form) => {
        const input = form.querySelector('[data-appearance-cover]');
        const remove = form.querySelector('[data-appearance-remove]');
        const select = form.querySelector('[data-appearance-frame]');
        const cover = form.querySelector('[data-profile-cover]');
        const portrait = form.querySelector('[data-profile-frame]');
        if (!input || !remove || !select || !cover || !portrait) return;
        const state = {input, remove, select, cover, portrait, objectUrl: null};
        states.set(form, state);
        portrait.dataset.profileFrame = select.value;

        select.addEventListener('change', () => { portrait.dataset.profileFrame = select.value; });
        input.addEventListener('change', () => {
            release(state);
            input.setCustomValidity('');
            const file = input.files?.[0];
            if (file) {
                if (file.size > Number(input.dataset.maxBytes)) {
                    input.setCustomValidity('ไฟล์ภาพมีขนาดเกินที่กำหนด');
                } else if (!input.accept.split(',').includes(file.type)) {
                    input.setCustomValidity('กรุณาเลือกไฟล์ภาพชนิดที่รองรับ');
                } else {
                    remove.checked = false;
                    state.objectUrl = win.URL.createObjectURL(file);
                }
            }
            paintCover(cover, state.objectUrl ?? (remove.checked ? null : cover.dataset.coverUrl));
        });
        remove.addEventListener('change', () => {
            if (remove.checked) {
                input.value = '';
                input.dispatchEvent(new win.Event('change'));
            } else {
                paintCover(cover, cover.dataset.coverUrl);
            }
        });
    });

    return {
        reset(form) {
            const state = states.get(form);
            if (!state) return;
            release(state);
            state.input.setCustomValidity('');
            state.portrait.dataset.profileFrame = state.select.value;
            paintCover(state.cover, state.cover.dataset.coverUrl);
            state.input.dispatchEvent(new win.Event('change'));
        },
        commit(form, appearance) {
            const state = states.get(form);
            if (!state) return;
            root.querySelectorAll('[data-profile-user]').forEach((region) => {
                if (region.dataset.profileUser !== form.dataset.profileUser) return;
                region.querySelectorAll('[data-profile-cover]').forEach((cover) => {
                    cover.dataset.coverUrl = appearance.cover_url ?? '';
                    paintCover(cover, appearance.cover_url);
                });
                region.querySelectorAll('[data-profile-frame]').forEach((portrait) => {
                    portrait.dataset.profileFrame = appearance.avatar_frame;
                });
            });
            [...state.select.options].forEach((option) => {
                option.defaultSelected = option.value === appearance.avatar_frame;
            });
            state.select.value = appearance.avatar_frame;
            state.remove.checked = false;
            state.remove.defaultChecked = false;
            state.input.value = '';
            release(state);
            state.input.dispatchEvent(new win.Event('change'));
        },
    };
}
