export function initializeProfileFrames(root) {
    const win = root.defaultView;
    const reducedMotion = win.matchMedia?.('(prefers-reduced-motion: reduce)');
    const visible = new Set();
    let urls = typeof import.meta.env === 'undefined' ? {} : null;
    let loading;
    const assetUrl = (path) => urls?.[`../../images/profile-frames/${path}`];

    const render = (portrait) => {
        const overlay = portrait.querySelector('[data-profile-frame-overlay]');
        if (!overlay) return;
        const {profileFrameAsset, profileFramePoster} = portrait.dataset;
        if (profileFrameAsset && visible.has(portrait) && !urls) {
            loading ??= import('./profile-frame-assets.js').then(({default: assets}) => {
                urls = assets;
                visible.forEach(render);
            }).catch(() => { loading = null; });
            return;
        }
        const poster = assetUrl(profileFramePoster);
        const asset = assetUrl(profileFrameAsset);
        const preferred = reducedMotion?.matches ? poster : asset;
        const displayed = overlay.getAttribute('src');
        const url = visible.has(portrait) || (preferred === poster && displayed === poster) ? preferred : null;
        if (url) {
            if (overlay.getAttribute('src') !== url) {
                overlay.hidden = true;
                overlay.src = url;
            }
        } else {
            overlay.hidden = true;
            overlay.removeAttribute('src');
        }
    };
    const observer = win.IntersectionObserver && new win.IntersectionObserver((entries) => {
        entries.forEach(({target, isIntersecting}) => {
            if (isIntersecting) visible.add(target);
            else visible.delete(target);
            target.toggleAttribute('data-frame-visible', isIntersecting);
            render(target);
        });
    });
    const observe = (container) => container.querySelectorAll('.sg-profile-portrait[data-profile-frame]').forEach((portrait) => {
        const overlay = portrait.querySelector('[data-profile-frame-overlay]');
        overlay?.addEventListener('load', () => { overlay.hidden = !overlay.hasAttribute('src'); });
        overlay?.addEventListener('error', () => {
            const poster = assetUrl(portrait.dataset.profileFramePoster);
            if (poster && overlay.getAttribute('src') !== poster && visible.has(portrait)) overlay.src = poster;
            else overlay.hidden = true;
        });
        if (observer) observer.observe(portrait);
        else {
            visible.add(portrait);
            portrait.setAttribute('data-frame-visible', '');
            render(portrait);
        }
    });
    observe(root);
    reducedMotion?.addEventListener?.('change', () => visible.forEach(render));

    const paint = (portrait, source, slug = source?.dataset.profileFrame ?? 'none') => {
        if (!portrait) return;
        portrait.dataset.profileFrame = slug;
        portrait.dataset.profileFrameAsset = source?.dataset.profileFrameAsset ?? '';
        portrait.dataset.profileFramePoster = source?.dataset.profileFramePoster ?? '';
        render(portrait);
    };

    const release = (container) => {
        container.querySelectorAll('.sg-profile-portrait[data-profile-frame]').forEach((portrait) => {
            visible.delete(portrait);
            portrait.removeAttribute('data-frame-visible');
            const overlay = portrait.querySelector('[data-profile-frame-overlay]');
            if (overlay) {
                overlay.hidden = true;
                overlay.removeAttribute('src');
            }
        });
    };

    return {paint, observe, release};
}
