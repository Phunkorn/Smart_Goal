/*
 * markup ของหน้ากระดานไอเดียสำหรับเทสต์ — ชุดเดียวที่ทุกไฟล์เทสต์ใช้ร่วมกัน
 *
 * ก่อนหน้านี้แต่ละไฟล์เทสต์เขียน markup ของแถบเครื่องมือขึ้นมาเอง ชุดละไม่เหมือนกัน
 * เทสต์จึงผ่านได้ทั้งที่หน้าจริงพัง เพราะ markup ในเทสต์ไม่ตรงกับที่ Blade ปล่อยออกมา
 * ที่นี่จึงสะท้อนโครงจริงของ board-toolbar.blade.php + board-toolbar-context.blade.php
 * + board-context-menu.blade.php: แถบสองแถวในเชลล์เดียว เมนู และเมนูคลิกขวา
 *
 * ยังเป็น markup ย่อ (ไม่ครบทุกสีทุกขนาด) เพราะเทสต์ต้องการแค่ hook ที่ JavaScript
 * ค้นหา ความครบถ้วนของ Blade เป็นหน้าที่ของ tests/js/workspace-contract.test.js
 * ซึ่งอ่านไฟล์ Blade จริง
 */

export const DESIGN = {
    defaultTool: 'select',
    defaultStroke: '#1f2937',
    defaultStrokeWidth: 4,
    defaultStickyColor: '#fde68a',
    defaultFontSize: 20,
    minFontSize: 8,
    maxFontSize: 96,
    minLetterSpacing: -2,
    maxLetterSpacing: 8,
    defaultLetterSpacing: 0,
    maxTextLength: 2000,
    minScale: 0.1,
    maxScale: 4,
    pointerTools: ['pen', 'eraser'],
    tools: {
        hand: {shortcut: 'H', icon: 'bi-arrows-move'},
        select: {shortcut: 'V', icon: 'bi-cursor'},
        pen: {shortcut: 'P', icon: 'bi-pencil'},
        eraser: {shortcut: 'E', icon: 'bi-eraser'},
        sticky: {shortcut: 'N', icon: 'bi-sticky'},
        text: {shortcut: 'T', icon: 'bi-type'},
        rect: {shortcut: 'R', icon: 'bi-square'},
        ellipse: {shortcut: 'O', icon: 'bi-circle'},
        line: {shortcut: 'L', icon: 'bi-slash-lg'},
        arrow: {shortcut: 'A', icon: 'bi-arrow-up-right'},
        image: {shortcut: null, icon: 'bi-image'},
    },
};

/** รายการหนึ่งในเมนูคลิกขวา ธง edit ตรงกับ WorkspaceDesign::CANVAS_MENU */
const menuItem = ({command, needs, edit}) => `
            <button type="button" class="wsb-menu__item" role="menuitem"
                data-command="${command}" data-needs="${needs}"${edit ? ' data-requires-edit' : ''}>
                <span class="wsb-menu__item-label">${command}</span>
            </button>`;

const CONTEXT_MENU_ITEMS = [
    {command: 'copy', needs: 'selection', edit: false},
    {command: 'duplicate', needs: 'selection', edit: true},
    {command: 'paste', needs: 'clipboard', edit: true},
    {command: 'bring-to-front', needs: 'selection', edit: true},
    {command: 'bring-forward', needs: 'selection', edit: true},
    {command: 'send-backward', needs: 'selection', edit: true},
    {command: 'send-to-back', needs: 'selection', edit: true},
    {command: 'center', needs: 'selection', edit: false},
    {command: 'delete', needs: 'selection', edit: true},
];

export const boardMarkup = ({
    capabilities = {canEdit: true, canManageSettings: true, canDelete: true},
    elements = [],
    routes = {},
    design = DESIGN,
    board = {},
} = {}) => `<!doctype html>
<html><body>
<div class="ws-board" data-workspace-board data-board-id="7">
    <script type="application/json" id="workspace-design">${JSON.stringify(design)}</script>
    <script type="application/json" id="workspace-board">${JSON.stringify({
        id: 7, title: 'กระดานทดสอบ', visibility: 'organization', departmentId: 1,
        capabilities, version: 1, ...board,
    })}</script>
    <script type="application/json" id="workspace-document">${JSON.stringify({schema: 1, elements})}</script>
    <script type="application/json" id="workspace-routes">${JSON.stringify(routes)}</script>

    <header class="ws-board__header">
        <div class="ws-board__status">
            <span class="wsb-save wsb-save--teal" data-workspace-save-state data-state="saved" role="status" hidden>
                <i class="bi bi-cloud-check"></i>
                <span data-workspace-save-label>บันทึกแล้ว</span>
            </span>

            <div class="wsb-menu-anchor" data-menu>
                <button type="button" data-menu-toggle aria-haspopup="true" aria-expanded="false"
                    aria-controls="wsbBoardMenu" aria-label="จัดการกระดาน"></button>
                <div class="wsb-menu" id="wsbBoardMenu" data-menu-panel hidden role="menu">
                    <button type="button" class="wsb-menu__item" role="menuitem" data-workspace-refresh></button>
                    <button type="button" class="wsb-menu__item" role="menuitem" data-workspace-board-settings></button>
                    <button type="button" class="wsb-menu__item" role="menuitem" data-workspace-board-delete></button>
                </div>
            </div>
        </div>
    </header>

    <div class="wsb-toolbar-shell" data-workspace-toolbar-shell>
        <div class="wsb-toolbar wsb-toolbar--primary" data-workspace-toolbar id="wsbToolbar" role="toolbar">
            <div class="wsb-toolbar__group">
                <button type="button" class="wsb-tool" data-tool="select" aria-pressed="false"></button>
                <button type="button" class="wsb-tool" data-tool="hand" aria-pressed="false"></button>
                <button type="button" class="wsb-tool" data-tool="pen" data-requires-edit aria-pressed="false"></button>
                <button type="button" class="wsb-tool" data-tool="eraser" data-requires-edit aria-pressed="false"></button>
                <button type="button" class="wsb-tool" data-tool="sticky" data-requires-edit aria-pressed="false"></button>
                <button type="button" class="wsb-tool" data-tool="text" data-requires-edit aria-pressed="false"></button>

                <div class="wsb-menu-anchor" data-menu>
                    <button type="button" class="wsb-menu__trigger" data-menu-toggle data-requires-edit
                        data-menu-tools="rect ellipse line arrow" aria-haspopup="true" aria-expanded="false"
                        aria-controls="wsbMenuShapes" aria-label="รูปทรง">
                        <i class="bi bi-intersect wsb-menu__icon" data-menu-icon data-default-icon="bi bi-intersect"></i>
                        <i class="bi bi-chevron-down wsb-menu__caret"></i>
                    </button>
                    <div class="wsb-menu wsb-menu--row" id="wsbMenuShapes" data-menu-panel hidden role="menu">
                        <button type="button" class="wsb-tool" role="menuitem" data-tool="rect"
                            data-tool-icon="bi bi-square" data-requires-edit aria-pressed="false"></button>
                        <button type="button" class="wsb-tool" role="menuitem" data-tool="ellipse"
                            data-tool-icon="bi bi-circle" data-requires-edit aria-pressed="false"></button>
                        <button type="button" class="wsb-tool" role="menuitem" data-tool="line"
                            data-tool-icon="bi bi-slash-lg" data-requires-edit aria-pressed="false"></button>
                        <button type="button" class="wsb-tool" role="menuitem" data-tool="arrow"
                            data-tool-icon="bi bi-arrow-up-right" data-requires-edit aria-pressed="false"></button>
                    </div>
                </div>

                <button type="button" class="wsb-tool" data-command="attach-image" data-requires-edit></button>
            </div>

            <div class="wsb-toolbar__group">
                <button type="button" class="wsb-tool" data-command="undo" data-requires-edit disabled></button>
                <button type="button" class="wsb-tool" data-command="redo" data-requires-edit disabled></button>
                <button type="button" class="wsb-tool" data-command="delete" data-requires-edit disabled></button>
            </div>

            <div class="wsb-toolbar__group">
                <button type="button" class="wsb-tool" data-command="zoom-out"></button>
                <button type="button" class="wsb-zoom" data-command="zoom-reset"><span data-zoom-label>100%</span></button>
                <button type="button" class="wsb-tool" data-command="zoom-in"></button>
                <button type="button" class="wsb-tool" data-command="fit"></button>
                <button type="button" class="wsb-tool" data-command="fullscreen" data-workspace-fullscreen
                    aria-pressed="false"><i class="bi bi-arrows-fullscreen"></i></button>
            </div>
        </div>

        <div class="wsb-toolbar wsb-toolbar--context" data-workspace-context-toolbar id="wsbContextToolbar"
            role="toolbar" hidden>
            <div class="wsb-toolbar__group" data-context-group="stroke">
                <button type="button" class="wsb-swatch" data-color="#dc2626" data-requires-edit aria-pressed="false"></button>
                <div class="wsb-picker" data-picker>
                    <button type="button" class="wsb-picker__toggle" data-picker-toggle data-requires-edit
                        aria-haspopup="true" aria-expanded="false" aria-controls="wsbStrokePalette">
                        <span class="wsb-swatch__dot" data-picker-current="stroke"></span>
                    </button>
                    <div class="wsb-popover" id="wsbStrokePalette" data-picker-panel hidden>
                        <button type="button" class="wsb-swatch" data-color="#2563eb" data-requires-edit aria-pressed="false"></button>
                        <label class="wsb-custom-color">
                            <input type="color" data-custom-color data-requires-edit value="#1f2937">
                        </label>
                    </div>
                </div>
            </div>

            <div class="wsb-toolbar__group" data-context-group="sticky">
                <button type="button" class="wsb-swatch wsb-swatch--sticky" data-sticky-color="#bfdbfe"
                    data-requires-edit aria-pressed="false"></button>
                <div class="wsb-picker" data-picker>
                    <button type="button" class="wsb-picker__toggle" data-picker-toggle data-requires-edit
                        aria-haspopup="true" aria-expanded="false" aria-controls="wsbStickyPalette">
                        <span class="wsb-swatch__dot" data-picker-current="sticky"></span>
                    </button>
                    <div class="wsb-popover" id="wsbStickyPalette" data-picker-panel hidden>
                        <button type="button" class="wsb-swatch wsb-swatch--sticky" data-sticky-color="#fecaca"
                            data-requires-edit aria-pressed="false"></button>
                    </div>
                </div>
            </div>

            <div class="wsb-toolbar__group" data-context-group="width">
                <div class="wsb-picker" data-picker>
                    <button type="button" class="wsb-picker__toggle" data-picker-toggle data-requires-edit
                        aria-haspopup="true" aria-expanded="false" aria-controls="wsbStrokeWidths">
                        <span class="wsb-width__bar" data-picker-current="width"></span>
                    </button>
                    <div class="wsb-popover wsb-popover--row" id="wsbStrokeWidths" data-picker-panel hidden>
                        <button type="button" class="wsb-width" data-stroke-width="8" data-requires-edit aria-pressed="false"></button>
                    </div>
                </div>
            </div>

            <div class="wsb-toolbar__group" data-context-group="font">
                <button type="button" class="wsb-tool" data-font-step="-2" data-requires-edit></button>
                <label class="wsb-fontfield">
                    <input type="number" class="wsb-fontfield__input" data-font-size-input data-requires-edit
                        value="${design.defaultFontSize}" min="${design.minFontSize}" max="${design.maxFontSize}" step="1">
                </label>
                <button type="button" class="wsb-tool" data-font-step="2" data-requires-edit></button>
            </div>

            <div class="wsb-toolbar__group" data-context-group="textstyle">
                <button type="button" class="wsb-tool" data-bold-toggle data-requires-edit aria-pressed="false"></button>
                <button type="button" class="wsb-tool" data-italic-toggle data-requires-edit aria-pressed="false"></button>
                <button type="button" class="wsb-tool" data-letter-spacing-step="-1" data-requires-edit></button>
                <label class="wsb-fontfield">
                    <input type="number" class="wsb-fontfield__input" data-letter-spacing-input data-requires-edit
                        value="${design.defaultLetterSpacing}" min="${design.minLetterSpacing}"
                        max="${design.maxLetterSpacing}" step="1">
                </label>
                <button type="button" class="wsb-tool" data-letter-spacing-step="1" data-requires-edit></button>
            </div>

            <div class="wsb-toolbar__group" data-context-group="align">
                <button type="button" class="wsb-tool" data-align="left" data-requires-edit aria-pressed="true"></button>
                <button type="button" class="wsb-tool" data-align="center" data-requires-edit aria-pressed="false"></button>
                <button type="button" class="wsb-tool" data-align="right" data-requires-edit aria-pressed="false"></button>
            </div>
        </div>

        <div class="wsb-toolbar-handle-row">
            <button type="button" class="wsb-toolbar-handle" data-workspace-toolbar-toggle
                aria-expanded="true" aria-controls="wsbToolbar wsbContextToolbar"
                data-label-expanded="ซ่อนแถบเครื่องมือ" data-label-collapsed="แสดงแถบเครื่องมือ"
                aria-label="ซ่อนแถบเครื่องมือ"><i class="bi bi-chevron-up"></i></button>
        </div>
    </div>

    <div class="ws-modal" data-workspace-modal hidden>
        <div data-workspace-modal-dismiss></div>
        <form data-workspace-form novalidate>
            <h2 data-workspace-modal-title></h2>
            <input type="text" name="title" data-workspace-title>
            <label><input type="radio" name="visibility" value="organization" checked></label>
            <label><input type="radio" name="visibility" value="department"></label>
            <p data-workspace-error hidden></p>
            <button type="submit" data-workspace-submit></button>
        </form>
    </div>

    <div class="wsb-stage" data-workspace-stage role="group">
        <div class="wsb-layers" data-workspace-layers></div>
        <svg class="wsb-canvas wsb-canvas--tools" data-workspace-canvas aria-hidden="true">
            <g data-workspace-preview></g>
            <g data-workspace-selection></g>
        </svg>
        <div class="wsb-tool-cursor" data-workspace-tool-cursor hidden aria-hidden="true">
            <i class="bi" data-workspace-tool-cursor-icon></i>
        </div>
        <input type="file" data-workspace-image-input multiple hidden>
    </div>

    <div class="wsb-menu" data-workspace-context-menu data-menu data-menu-panel hidden role="menu">
${CONTEXT_MENU_ITEMS.map(menuItem).join('')}
    </div>
</body></html>`;

/**
 * โหนดของชิ้นงานที่ถูกวาดจริง เรียงจากชั้นล่างสุดขึ้นบนสุด
 *
 * ต้องกวาดข้ามทุกชั้น ไม่ใช่ถามชั้นใดชั้นหนึ่ง เพราะจำนวนชั้นไม่คงที่ (ดู
 * layers.js) ลำดับที่ได้คือลำดับใน DOM ซึ่งเท่ากับลำดับการซ้อนทับบนหน้าจอพอดี
 * เทสต์จึงเทียบลำดับชั้นได้ตรง ๆ จากอาร์เรย์นี้
 */
export const renderedNodes = (doc, selector = '[data-el-id]') =>
    Array.from(doc.querySelectorAll(`[data-workspace-layers] ${selector}`));

/** เฉพาะกล่องข้อความ (กระดาษโน้ตและกล่องข้อความ) ซึ่งอยู่ในชั้น HTML */
export const overlayNodes = (doc) => renderedNodes(doc, '.wsb-overlay > [data-el-id]');

/** เหตุการณ์คลิกขวาอย่างที่เบราว์เซอร์ยิง (jsdom ไม่มีตัวช่วยให้) */
export const rightClick = (target, {x = 0, y = 0} = {}) => target.dispatchEvent(
    new target.ownerDocument.defaultView.MouseEvent('contextmenu', {
        bubbles: true,
        cancelable: true,
        clientX: x,
        clientY: y,
        button: 2,
    })
);
