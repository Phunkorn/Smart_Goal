import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync, readFileSync, readdirSync, statSync} from 'node:fs';
import {join} from 'node:path';

/*
 * สัญญาของ Topbar แนวนอนที่มาแทนแถบข้าง — ตรวจจากไฟล์ต้นฉบับจริง
 *
 * jsdom ไม่คำนวณ layout ผลการวางตำแหน่งจริงต้องดูบนเบราว์เซอร์ที่ 320–1920px
 * ไฟล์นี้กันไม่ให้แถบข้างหรือโค้ด inline กลับมา และกันไม่ให้ hook ที่สคริปต์ใช้หลุดจาก Blade
 */
const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const code = (path) => read(path).replace(/\/\*[\s\S]*?\*\//g, '').replace(/\{\{--[\s\S]*?--\}\}/g, '');
const block = (css, selector) => {
    const start = css.indexOf(`${selector} {`);
    assert.notEqual(start, -1, `ไม่พบ ${selector}`);
    return css.slice(start, css.indexOf('}', start));
};

const files = (directory, extensions) => readdirSync(new URL(`../../${directory}`, import.meta.url)).flatMap((name) => {
    const relative = join(directory, name).replaceAll('\\', '/');
    if (statSync(new URL(`../../${relative}`, import.meta.url)).isDirectory()) return files(relative, extensions);
    return extensions.some((extension) => name.endsWith(extension)) ? [relative] : [];
});

const navigationViews = [
    'resources/views/layouts/components/top-navigation.blade.php',
    'resources/views/layouts/components/top-navigation-badge.blade.php',
    'resources/views/layouts/components/global-search.blade.php',
    'resources/views/layouts/components/user-dropdown.blade.php',
];

test('the sidebar is gone from the code base, not hidden', () => {
    for (const removed of [
        'resources/js/components/sidebar.js',
        'resources/css/components/layout/sidebar.css',
        'resources/views/layouts/partials/report-nav.blade.php',
        'resources/views/layouts/partials/ticket-nav.blade.php',
        'resources/views/layouts/partials/share-nav-item.blade.php',
    ]) {
        assert.equal(existsSync(new URL(`../../${removed}`, import.meta.url)), false, `ยังพบ ${removed}`);
    }

    for (const path of [...files('resources/css', ['.css']), ...files('resources/js', ['.js']), ...files('resources/views/layouts', ['.blade.php'])]) {
        const source = code(path);
        for (const legacy of ['--sidebar-w', '--sidebar-collapsed-w', 'sidebar-collapsed', 'sidebar-open', 'data-sidebar', 'appSidebar', 'mobile-menu-btn', 'smartgoal.sidebar', 'nav-section-label']) {
            assert.ok(! source.includes(legacy), `${path} ยังอ้าง ${legacy}`);
        }
    }

    const layout = read('resources/views/layouts/app.blade.php');
    assert.doesNotMatch(layout, /<aside/);
    assert.doesNotMatch(layout, /sidebar/i);
    assert.doesNotMatch(code('resources/css/components/layout.css'), /sidebar/);
    assert.doesNotMatch(read('vite.config.js'), /sidebar/);
});

test('the layout loads the navigation through Vite and has no inline script or style', () => {
    const layout = read('resources/views/layouts/app.blade.php');
    const vite = read('vite.config.js');

    for (const entry of ['resources/js/components/top-navigation.js', 'resources/js/components/global-search.js']) {
        assert.ok(layout.includes(`@vite('${entry}')`), `layout ต้องโหลด ${entry}`);
        assert.ok(vite.includes(`'${entry}'`), `vite.config.js ต้องลงทะเบียน ${entry}`);
    }
    assert.ok(layout.includes("@include('layouts.components.top-navigation')"));
    assert.ok(layout.includes("@include('layouts.components.global-search')"));

    // สคริปต์ที่เหลือใน layout ต้องเป็นไฟล์ภายนอก (<script src>) เท่านั้น
    for (const [tag] of layout.matchAll(/<script\b[^>]*>/g)) {
        assert.match(tag, /\ssrc=/, `พบสคริปต์ inline: ${tag}`);
    }
    assert.doesNotMatch(layout, /<style\b/);

    for (const path of navigationViews) {
        const view = code(path);
        assert.doesNotMatch(view, /<style\b|<script\b/, `${path} มี <style>/<script>`);
        assert.doesNotMatch(view, /\sstyle=/, `${path} มี inline style`);
        assert.doesNotMatch(view, /\son[a-z]+=/i, `${path} มี HTML event handler`);
    }
});

test('the scripts style through classes and ARIA, not by writing inline styles', () => {
    for (const path of ['resources/js/components/top-navigation.js', 'resources/js/components/global-search.js']) {
        const source = code(path);
        assert.doesNotMatch(source, /\.style\.|setAttribute\(\s*'style'|cssText/, `${path} แก้ style ตรง ๆ`);
        assert.doesNotMatch(source, /innerHTML|insertAdjacentHTML|outerHTML/, `${path} ประกอบ HTML จากข้อความ`);
        assert.doesNotMatch(source, /\b(alert|confirm|prompt)\(/, `${path} ใช้กล่องโต้ตอบของเบราว์เซอร์`);
    }
});

test('every hook the scripts look up exists in the Blade the layout renders', () => {
    const nav = read('resources/views/layouts/components/top-navigation.blade.php');
    const search = read('resources/views/layouts/components/global-search.blade.php');
    const navScript = read('resources/js/components/top-navigation.js');
    const searchScript = read('resources/js/components/global-search.js');

    for (const hook of ['data-top-nav', 'data-top-nav-list', 'data-top-nav-entry']) {
        assert.ok(navScript.includes(`[${hook}]`), `top-navigation.js ไม่ใช้ ${hook}`);
        assert.ok(nav.includes(hook), `Blade ไม่มี ${hook}`);
    }
    for (const hook of ['data-global-search', 'data-global-search-trigger', 'data-global-search-panel', 'data-global-search-input',
        'data-global-search-results', 'data-global-search-message', 'data-global-search-status', 'data-global-search-clear',
        'data-global-search-shortcut']) {
        assert.ok(searchScript.includes(`[${hook}]`), `global-search.js ไม่ใช้ ${hook}`);
        assert.ok(search.includes(hook), `Blade ไม่มี ${hook}`);
    }
    assert.ok(search.includes("data-search-url=\"{{ route('search') }}\""));

    // ทุกกลุ่มเมนูเปิดแบบ static ด้วยคลาสสไลด์ชุดเดียวกับกระดิ่งและบัญชี
    assert.match(nav, /data-bs-toggle="dropdown" data-bs-display="static"/);
    assert.match(nav, /class="dropdown-menu topbar-slide-menu nav-dropdown/);
    assert.match(read('resources/views/layouts/components/user-dropdown.blade.php'), /data-bs-toggle="dropdown" data-bs-display="static"[\s\S]*topbar-slide-menu topbar-account__menu/);

    // ARIA ของเมนูและกล่องค้นหา
    assert.match(nav, /<nav class="top-nav" aria-label="เมนูหลัก"/);
    assert.match(nav, /aria-current="\{\{ \$item\['current'\] \}\}"/);
    assert.match(search, /role="combobox"/);
    assert.match(search, /role="listbox"/);
    assert.match(search, /aria-modal="false"/);
});

test('one dropdown animation serves the whole topbar, with the agreed timing and reduced motion', () => {
    const shared = code('resources/css/components/layout/shared-ui.css');
    const closed = block(shared, '.dropdown-menu.topbar-slide-menu');
    const opened = block(shared, '.dropdown-menu.topbar-slide-menu.show');

    assert.match(closed, /display:\s*block/);
    assert.match(closed, /visibility:\s*hidden/);
    assert.match(closed, /translateY\(-8px\)/);
    assert.match(closed, /cubic-bezier\(\.2, \.8, \.2, 1\)/);
    assert.match(closed, /\.18s|\.2s/);
    assert.match(opened, /opacity:\s*1/);

    for (const path of ['resources/css/components/layout/navigation.css', 'resources/css/components/layout/global-search.css', 'resources/css/components/layout/topbar.css', 'resources/css/components/layout/shell.css']) {
        assert.match(read(path), /prefers-reduced-motion:\s*reduce/, `${path} ต้องรองรับ reduced motion`);
    }

    const navigation = code('resources/css/components/layout/navigation.css');
    assert.match(navigation, /\.top-nav__toggle\[aria-expanded="true"\] \.top-nav__chevron \{\s*transform:\s*rotate\(180deg\)/);
    // เมนูที่ชิดซ้ายมีตำแหน่งของตัวเอง ไม่พึ่ง data-bs-popper ที่ Bootstrap ถอดตอนปิด
    assert.match(navigation, /\.top-nav \.dropdown-menu\.topbar-slide-menu\.nav-dropdown:not\(\.dropdown-menu-end\) \{[^}]*left:\s*0/);
    assert.doesNotMatch(navigation, /data-bs-popper/);

    const search = code('resources/css/components/layout/global-search.css');
    assert.match(block(search, '.global-search__panel'), /visibility:\s*hidden/);
    assert.match(block(search, '.global-search.is-open .global-search__panel'), /visibility:\s*visible/);
});

test('the topbar is transparent; only its pieces carry a surface', () => {
    const topbar = block(code('resources/css/components/layout/topbar.css'), '.topbar');

    assert.doesNotMatch(topbar, /background|border-bottom|box-shadow|position:\s*sticky|backdrop-filter/);
    assert.match(topbar, /position:\s*relative/);
    assert.match(block(code('resources/css/components/layout/navigation.css'), '.top-nav__list'), /background:\s*var\(--surface\)/);
    assert.match(block(code('resources/css/components/layout/global-search.css'), '.global-search__trigger'), /background:\s*var\(--surface\)/);
});

test('the content uses the full width and pages change with a progressive view transition', () => {
    const shell = code('resources/css/components/layout/shell.css');

    assert.doesNotMatch(block(shell, '.main'), /margin-left/);
    assert.match(shell, /@view-transition \{\s*navigation:\s*auto;/);
    assert.match(shell, /prefers-reduced-motion: reduce\) \{\s*@view-transition \{\s*navigation:\s*none;/);
    // ไม่ทำแอนิเมชันกับ .main เอง (transform บนบรรพบุรุษจะยึดตำแหน่ง modal ผิด และจะเล่นซ้อนกับ view transition)
    assert.doesNotMatch(block(shell, '.main'), /animation|transform|opacity/);
    assert.match(code('resources/css/components/layout/topbar.css'), /view-transition-name:\s*app-topbar/);
    assert.match(code('resources/css/components/layout/navigation.css'), /view-transition-name:\s*top-nav-active/);
});

test('the layout reflows to two rows on tablets and a scrollable row on phones without a drawer', () => {
    const topbar = code('resources/css/components/layout/topbar.css');
    const navigation = code('resources/css/components/layout/navigation.css');

    assert.match(topbar, /@media \(max-width: 1279\.98px\) \{\s*\.topbar \{[\s\S]*?"brand tools"\s*"nav nav"/);
    assert.match(navigation, /@media \(max-width: 767\.98px\) \{\s*\.top-nav__list \{[^}]*overflow-x:\s*auto/);
    assert.match(navigation, /\.top-nav__item\.dropdown \{ position: static; \}/);
    for (const source of [topbar, navigation]) {
        assert.doesNotMatch(source, /translateX\(-|off-canvas|offcanvas|drawer/i);
    }
});
