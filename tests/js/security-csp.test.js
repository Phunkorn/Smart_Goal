import assert from 'node:assert/strict';
import {readFileSync, readdirSync} from 'node:fs';
import test from 'node:test';
import {JSDOM} from 'jsdom';
import {initDocumentPrint, printDocument} from '../../resources/js/app.js';

test('Blade templates do not introduce event attributes, inline styles or stylesheets', () => {
    const visit = (directory) => {
        for (const entry of readdirSync(directory, {withFileTypes: true})) {
            const path = new URL(entry.name, directory);
            if (entry.isDirectory()) visit(new URL(entry.name + '/', directory));
            else if (entry.name.endsWith('.blade.php')) {
                const source = readFileSync(path, 'utf8').replace(/\{\{--[\s\S]*?--\}\}/g, '');
                assert.doesNotMatch(source, /\son[a-z]+\s*=|\sstyle\s*=|<style\b/i, path.pathname);
            }
        }
    };
    visit(new URL('../../resources/views/', import.meta.url));
});

test('the CSS-free SweetAlert build opens and closes without injecting a stylesheet', async () => {
    const dom = new JSDOM('<!doctype html><html><head></head><body><button id="opener">Open</button></body></html>', {runScripts: 'outside-only', pretendToBeVisual: true});
    try {
        dom.window.scrollTo = () => {};
        dom.window.HTMLElement.prototype.getClientRects = () => [{width: 100, height: 30}];
        const library = readFileSync(new URL('../../node_modules/sweetalert2/dist/sweetalert2.min.js', import.meta.url), 'utf8');
        assert.match(library, /setAttribute\("style","display:none !important"\)/, 'review the exact Safari hash when upgrading SweetAlert');
        dom.window.eval(library);
        const pending = dom.window.Swal.fire({title: 'CSP test', text: 'Existing dialog', animation: false, returnFocus: false});
        assert.equal(dom.window.Swal.getPopup().querySelector('.swal2-title').textContent, 'CSP test');
        assert.equal(dom.window.document.querySelectorAll('style').length, 0);
        dom.window.Swal.close();
        await pending;
        assert.equal(dom.window.document.querySelector('.swal2-container'), null);
    } finally {
        dom.window.close();
    }
});

test('standalone and iframe print actions reuse the same function without inline handlers', () => {
    const dom = new JSDOM('<button data-document-print>Print</button>');
    try {
        let prints = 0;
        dom.window.focus = () => {};
        dom.window.print = () => { prints++; };
        initDocumentPrint(dom.window.document);
        initDocumentPrint(dom.window.document);
        dom.window.document.querySelector('button').click();
        assert.equal(prints, 1);
        printDocument(dom.window);
        assert.equal(prints, 2);
    } finally {
        dom.window.close();
    }
});
