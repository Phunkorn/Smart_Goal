/** Local previews stay in the browser; uploads use the existing account form flow. */
import {initializeProfileFrames} from '../../components/profile-frames.js';

export function initializeProfileAppearance(root = document) {
    const win = root.defaultView;
    const states = new WeakMap();
    const {paint: paintFrame, observe, release: releaseFrames} = initializeProfileFrames(root);
    let frames = new Map();
    const savedFrames = new Map();
    const applyFrame = (region, slug) => {
        const option = frames.get(slug) ?? savedFrames.get(slug);
        const source = option?.querySelector('[data-profile-frame]');
        region.querySelectorAll('[data-profile-frame]').forEach((portrait) => {
            if (source || portrait.dataset.profileFrame !== slug) paintFrame(portrait, source, slug);
        });
        const label = region.querySelector('[data-appearance-frame-name]');
        if (label && option) label.textContent = option.dataset.frameName;
    };
    const paintCover = (cover, url) => {
        if (!cover) return;
        const previous = cover.querySelector('img');
        if (previous?.getAttribute('src') === url) return;
        cover.querySelectorAll('img').forEach((image) => image.remove());
        if (!url) return;
        const image = root.createElement('img');
        image.alt = '';
        image.dataset.profileCoverImage = '';
        if (cover.closest('[data-member-card]')) {
            image.loading = cover.hasAttribute('data-priority-cover') ? 'eager' : 'lazy';
            if (image.loading === 'eager') image.fetchPriority = 'high';
        }
        image.src = url;
        cover.append(image);
    };
    const release = (state) => {
        if (state.objectUrl) win.URL.revokeObjectURL(state.objectUrl);
        state.objectUrl = null;
    };
    const bindForm = (form) => {
        const input = form.querySelector('[data-appearance-cover]');
        const remove = form.querySelector('[data-appearance-remove]');
        const frameInput = form.querySelector('[data-appearance-frame]');
        const cover = form.querySelector('[data-profile-cover]');
        if (!input || !remove || !frameInput || !cover) return;
        const state = {input, remove, frameInput, cover, objectUrl: null};
        states.set(form, state);
        if (frameInput.dataset.savedFrameName) {
            const snapshot = root.createElement('span');
            const source = root.createElement('span');
            snapshot.dataset.frameName = frameInput.dataset.savedFrameName;
            source.dataset.profileFrame = frameInput.dataset.savedFrame;
            source.dataset.profileFrameAsset = frameInput.dataset.savedFrameAsset;
            source.dataset.profileFramePoster = frameInput.dataset.savedFramePoster;
            snapshot.append(source);
            savedFrames.set(frameInput.dataset.savedFrame, snapshot);
        }

        frameInput.addEventListener('change', () => applyFrame(form, frameInput.value));
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
    };
    root.querySelectorAll('[data-account-form="appearance"]').forEach(bindForm);

    return {
        async mount(modal, settings) {
            const gallery = modal.hasAttribute('data-frame-gallery')
                ? await import('./frame-gallery.js')
                : null;
            settings.append(modal);
            observe(modal);
            if (gallery) {
                frames = gallery.initializeFrameGallery(root, paintFrame);
                modal.addEventListener('hidden.bs.modal', () => releaseFrames(modal));
            } else {
                bindForm(modal.querySelector('[data-account-form="appearance"]'));
            }
        },
        reset(form) {
            const state = states.get(form);
            if (!state) return;
            release(state);
            state.input.setCustomValidity('');
            state.frameInput.value = state.frameInput.dataset.savedFrame;
            applyFrame(form, state.frameInput.value);
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
                applyFrame(region, appearance.avatar_frame);
            });
            state.frameInput.value = appearance.avatar_frame;
            state.frameInput.dataset.savedFrame = appearance.avatar_frame;
            state.remove.checked = false;
            state.remove.defaultChecked = false;
            state.input.value = '';
            release(state);
            state.input.dispatchEvent(new win.Event('change'));
        },
    };
}
