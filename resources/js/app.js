/** Shared standalone print action; also used by the inspection-report preview. */
export function printDocument(view) {
    view?.focus();
    view?.print();
}

export function initDocumentPrint(doc = globalThis.document) {
    doc?.querySelectorAll('[data-document-print]').forEach((button) => {
        if (button.dataset.printReady === 'on') return;
        button.dataset.printReady = 'on';
        button.addEventListener('click', () => printDocument(doc.defaultView));
    });
}

if (typeof document !== 'undefined') initDocumentPrint(document);
