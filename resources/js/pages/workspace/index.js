/*
 * ตัวประกอบของหน้าวาด - จุดเดียวที่ถือสถานะจริง
 *
 * โมดูลอื่นทั้งหมดเป็นฟังก์ชันของอินพุต ที่นี่คือที่เดียวที่มีตัวแปรที่เปลี่ยนค่าได้
 * และเป็นที่เดียวที่ต่อสายระหว่างเหตุการณ์จากผู้ใช้ ตรรกะบริสุทธิ์ และ DOM
 *
 *      pointer.js / toolbar.js / keyboard.js   (รับเหตุการณ์)
 *                 |
 *              index.js          (สถานะ + apply)
 *          /      |      \
 *   tools/*   history.js   camera.js   clipboard.js   selection-frame.js   (ตรรกะบริสุทธิ์)
 *                 |
 *   renderer.js / selection-ui.js         (วาดลง DOM)
 *
 * เฟสนี้ยังใช้ปุ่มบันทึกแบบกดเอง การบันทึกอัตโนมัติและการกันเขียนทับฝั่งหน้าจอ
 * จะเข้ามาในเฟสถัดไปผ่าน save-state.js
 */

import {
    centerOnBounds,
    clampScale,
    createCamera,
    fitToBounds,
    panBy,
    screenToWorld,
    zoomAt,
} from './camera.js';
import {HIT_TOLERANCE_PX, pickTopmost, unionBounds} from './geometry.js';
import {fitInitialSize, initAttachments} from './attachments.js';
import {initAutosave} from './autosave.js';
import {confirmDeleteBoard, createBoardModal} from './board-settings.js';
import {PASTE_OFFSET, copyElements, duplicateElements, pasteFromClipboard} from './clipboard.js';
import {initClipboardEvents} from './clipboard-events.js';
import {createClient} from './client.js';
import * as history from './history.js';
import {initContextMenu} from './context-menu.js';
import {initKeyboardShortcuts, toolShortcutsFrom} from './keyboard.js';
import {layerRuns, syncLayers} from './layers.js';
import {initMenus} from './menu.js';
import {initPointer} from './pointer.js';
import {
    alignOfSelection,
    applyAlignToSelection,
    applyOverlayCamera,
    initOverlayEditing,
    isOverlayType,
    renderOverlay,
} from './overlay-text.js';
import {applyCamera, renderElements, renderPreview} from './renderer.js';
import * as scene from './scene.js';
import {frameOf} from './selection-frame.js';
import {renderSelection} from './selection-ui.js';
import {disableEditControls, initToolbar, initToolbarCollapse} from './toolbar.js';
import {initToolCursor} from './tool-cursor.js';
import {isReadOnlyTool, isViewportTool, toolFor} from './tools/index.js';

/** อ่าน JSON island คืนค่าปริยายเมื่อไม่มีหรืออ่านไม่ออก */
const readIsland = (doc, id, fallback = {}) => {
    const node = doc.getElementById(id);

    if (! node) {
        return fallback;
    }

    try {
        return JSON.parse(node.textContent) ?? fallback;
    } catch {
        return fallback;
    }
};

export const initBoardEditor = ({
    root,
    doc = root?.ownerDocument || globalThis.document,
    idFactory = scene.sequentialIdFactory(),
    // ฉีดเข้ามาได้เพื่อให้เทสต์ตรวจได้ว่าไปที่ไหน โดยไม่ต้องแตะ location จริง
    navigateTo = (url) => { doc.defaultView.location.href = url; },
    reloadPage = () => { doc.defaultView.location.reload(); },
} = {}) => {
    if (! root || root.dataset.workspaceBoardReady === 'on') {
        return null;
    }

    root.dataset.workspaceBoardReady = 'on';

    const design = readIsland(doc, 'workspace-design');
    const boardData = readIsland(doc, 'workspace-board');
    const initialDocument = readIsland(doc, 'workspace-document', {schema: 1, elements: []});
    const routes = readIsland(doc, 'workspace-routes');
    const initialVersion = Number(boardData.version ?? 1);

    const stage = root.querySelector('[data-workspace-stage]');
    // ชั้นเนื้อหาถูกสร้างตามลำดับของเอกสาร ไม่ได้เขียนตายตัวไว้ใน Blade (ดู layers.js)
    const layersRoot = root.querySelector('[data-workspace-layers]');
    const previewLayer = root.querySelector('[data-workspace-preview]');
    const selectionLayer = root.querySelector('[data-workspace-selection]');
    const toolbarRoot = root.querySelector('[data-workspace-toolbar]');
    const contextToolbarRoot = root.querySelector('[data-workspace-context-toolbar]');
    const contextMenuRoot = root.querySelector('[data-workspace-context-menu]');

    if (! stage || ! layersRoot) {
        return null;
    }

    /*
     * ไอคอนเครื่องมือที่ลอยตามเมาส์ บอกว่ากำลังถือดินสอหรือยางลบอยู่
     *
     * ชื่อไอคอนกับรายชื่อเครื่องมือมาจาก WorkspaceDesign ผ่าน JSON island
     * ไม่ได้เขียนซ้ำไว้ในไฟล์ .js (ดู tool-cursor.js ว่าทำไมไม่ใช้ cursor ของ CSS)
     */
    const toolCursor = initToolCursor(stage, root.querySelector('[data-workspace-tool-cursor]'), {
        tools: design.pointerTools || [],
        icons: Object.fromEntries(
            Object.entries(design.tools || {}).map(([name, tool]) => [name, tool.icon])
        ),
    });

    // มือจับพับ/กางแถบเครื่องมือใช้ได้กับทุกคนรวมผู้ดูอย่างเดียว จึงผูกแยก
    // จาก initToolbar ซึ่งด้านล่างต้องรอ capabilities ก่อน
    initToolbarCollapse(toolbarRoot);

    const capabilities = boardData.capabilities || {};
    const limits = {
        minScale: design.minScale ?? 0.1,
        maxScale: design.maxScale ?? 4,
    };

    const state = {
        scene: scene.deserialize(initialDocument),
        camera: createCamera(),
        selection: [],
        // ผู้ที่ดูอย่างเดียวเริ่มด้วยเครื่องมือเลื่อนกระดาน เพราะเครื่องมือที่วาดได้
        // ถูกปิดไว้อยู่แล้ว การเริ่มด้วยเครื่องมือที่กดแล้วไม่เกิดอะไรทำให้สับสน
        tool: capabilities.canEdit ? (design.defaultTool || 'select') : 'hand',
        style: {
            stroke: design.defaultStroke || '#1f2937',
            strokeWidth: design.defaultStrokeWidth || 4,
            stickyColor: design.defaultStickyColor || '#fde68a',
            fontSize: design.defaultFontSize || 20,
            bold: false,
            italic: false,
            letterSpacing: design.defaultLetterSpacing ?? 0,
            align: 'left',
        },
        preview: null,
        draft: null,
        gestureStart: null,
        // กล่องข้อความที่กำลังแก้อยู่ (มีได้ทีละกล่อง) ดู overlay-text.js ว่าทำไม
        editingId: null,
        // ชิ้นงานที่คัดลอกไว้ (ดู clipboard.js) ไม่อยู่ในประวัติ undo เพราะไม่ใช่เนื้อหา
        clipboard: null,
    };

    let past = history.createHistory(state.scene);

    /*
     * การจัดบรรทัดของย่อหน้าที่เคอร์เซอร์อยู่ตอนนี้ ระหว่างเปิดแก้ไขกล่องข้อความ
     *
     * แยกออกจาก state.style.align โดยตั้งใจ เพราะ style.align มีอีกหน้าที่คือ
     * "ค่าตั้งต้นของกล่องใหม่ที่จะสร้าง" ถ้าใช้ตัวเดียวกัน แค่ขยับเคอร์เซอร์ไป
     * อ่านบรรทัดที่จัดกึ่งกลางไว้ก็จะเปลี่ยนค่าตั้งต้นของกล่องถัดไปโดยไม่ตั้งใจ
     * ตัวนี้จึงมีไว้ให้แถบเครื่องมือไฮไลต์ปุ่มให้ตรงกับบรรทัดจริงเท่านั้น และถูก
     * ล้างกลับเป็น null ทุกครั้งที่เลิกแก้ไข (ดู apply() ที่ patch.editingId)
     */
    let editingAlign = null;

    /*
     * เมนู (บนแถบเครื่องมือ บนหัวเรื่อง และเมนูคลิกขวา) ถูกสร้างหลัง toolbar
     * เพราะทั้งสองฝ่ายต้องปิดอีกฝ่ายก่อนเปิดตัวเอง การต่อสายไขว้อยู่ที่นี่จุดเดียว
     */
    let menus = null;

    /*
     * ตะขอสามตัวที่ถูกเติมค่าหลังจากตั้งการบันทึกอัตโนมัติเสร็จ
     *
     * ประกาศเป็นฟังก์ชันเปล่าไว้ก่อน เพราะ apply() และตัวจัดการ pointer ถูก
     * ประกาศก่อนหน้านั้น การอ้างชื่อ autosave ตรง ๆ จะติด temporal dead zone
     */
    let notifyDirty = () => {};
    let beginGesture = () => {};
    let endGesture = () => {};

    const viewport = () => ({
        width: stage.clientWidth || 0,
        height: stage.clientHeight || 0,
    });

    const draw = () => {
        if (previewLayer) {
            applyCamera(previewLayer, state.camera);
        }

        /*
         * ชั้นเนื้อหา: หนึ่งชั้นต่อหนึ่งช่วงของชิ้นงานที่วาดด้วยเทคโนโลยีเดียวกัน
         * เรียงตามลำดับในเอกสาร ลำดับใน DOM จึงเท่ากับลำดับชั้นที่ผู้ใช้เห็น
         * เส้นที่สั่ง "ขึ้นบนสุด" จึงขึ้นไปอยู่เหนือกระดาษโน้ตได้จริง (ดู layers.js)
         */
        syncLayers(layersRoot, layerRuns(state.scene.elements), doc).forEach((layer) => {
            if (layer.kind === 'overlay') {
                applyOverlayCamera(layer.node, state.camera);
                renderOverlay(layer.node, layer.elements, {
                    doc,
                    editable: capabilities.canEdit === true,
                    editingId: state.editingId,
                });

                return;
            }

            applyCamera(layer.node, state.camera);
            renderElements(layer.node, layer.elements, doc);
        });

        renderPreview(previewLayer, state.preview, doc);

        // เคอร์เซอร์มาตรฐานของแต่ละเครื่องมืออยู่ใน workspace/stage.css
        // ที่นี่บอกแค่ว่าเครื่องมือไหน ส่วนดินสอกับยางลบได้ไอคอนลอยตามเมาส์เพิ่ม
        stage.dataset.tool = state.tool;
        toolCursor?.update(state.tool);

        if (selectionLayer) {
            // ระหว่างหมุน เครื่องมือถือกรอบที่หมุนตามไว้ใน draft (ดู tools/select.js)
            renderSelection(
                selectionLayer,
                state.draft?.liveFrame
                    ?? frameOf(state.scene.elements.filter((el) => state.selection.includes(el.id))),
                state.camera,
                {editable: capabilities.canEdit === true}
            );
        }

        toolbar?.sync({
            tool: state.tool,
            style: state.style,
            canUndo: history.canUndo(past),
            canRedo: history.canRedo(past),
            hasSelection: state.selection.length > 0,
            // ชนิดของสิ่งที่เลือกเป็นตัวตัดสินว่าแถบรูปแบบต้องโผล่กลุ่มไหน
            // (ดู toolbar-context.js) ส่งเป็นชนิด ไม่ใช่ตัวชิ้นงาน เพราะกฎนั้น
            // ไม่ต้องรู้อะไรเกี่ยวกับชิ้นงานมากกว่าชนิดของมัน
            selectedTypes: selectedElements().map((element) => element.type),
            scale: state.camera.scale,
            isFullscreen: doc.fullscreenElement === root,
            activeAlign: editingAlign,
            /*
             * ปุ่มจัดบรรทัดกดได้เฉพาะขณะเปิดกล่องไว้พิมพ์ เพราะมันทำงานทีละย่อหน้า ไม่ใช่ทั้งกล่อง
             *
             * ใช้ state.editingId ไม่ใช่ editingTextNode() ที่เข้มกว่า เพราะการโฟกัสกล่อง
             * เกิดหลัง draw() รอบที่เปิดโหมดแก้ไข ถ้าเช็ค activeElement ที่นี่ ปุ่มจะถูกปิด
             * ค้างไว้จนกว่าจะมีเหตุการณ์ถัดไปมาสั่ง draw() อีกรอบ ผู้ใช้จึงเจอปุ่มที่กดไม่ได้ทั้งที่พิมพ์อยู่
             * ส่วนการตรวจว่ามีเคอร์เซอร์จริงหรือไม่ ยังคงอยู่ที่ chooseAlign ซึ่งเป็นตัวบังคับจริง
             */
            editingText: Boolean(state.editingId),
        });

        root.dispatchEvent(new doc.defaultView.CustomEvent('workspace:changed', {
            detail: {elementCount: scene.elementCount(state.scene)},
        }));
    };

    /**
     * นำผลลัพธ์จากเครื่องมือมาใช้กับสถานะ
     *
     * เป็นทางเดียวที่สถานะเปลี่ยนได้ ทำให้มีจุดเดียวที่ต้องดูเวลาสงสัยว่า
     * "ทำไมค่านี้เปลี่ยน" และเป็นจุดเดียวที่ตัดสินว่าเมื่อไรควรบันทึกลงประวัติ
     */
    const apply = (patch) => {
        if (! patch) {
            return;
        }

        if (patch.scene !== undefined) {
            state.scene = patch.scene;
        }

        if (patch.selection !== undefined) {
            state.selection = patch.selection;
        }

        if (patch.camera !== undefined) {
            state.camera = patch.camera;
        }

        if (patch.preview !== undefined) {
            state.preview = patch.preview;
        }

        if (patch.draft !== undefined) {
            state.draft = patch.draft;
        }

        if (patch.editingId !== undefined) {
            state.editingId = patch.editingId;

            if (! patch.editingId) {
                editingAlign = null;
            }
        }

        if (patch.commit) {
            past = history.push(past, state.scene);
        }

        // การเลื่อนกล้องหรือเปลี่ยนการเลือกไม่ใช่การแก้เนื้อหา จึงไม่ต้องบันทึก
        if (patch.scene !== undefined) {
            notifyDirty();
        }

        // กล่องที่เพิ่งสร้างต้องพิมพ์ต่อได้ทันทีโดยไม่ต้องดับเบิลคลิกซ้ำ
        if (patch.focusElement) {
            state.editingId = patch.focusElement;
        }

        draw();

        // ย้ายโฟกัสหลังวาดเสร็จ เพราะกล่องเพิ่งถูกสร้างขึ้นใน DOM ในบรรทัดบน
        if (patch.focusElement) {
            overlay?.focus(patch.focusElement);
        }
    };

    /** ตัวช่วยที่ส่งให้เครื่องมือ เพื่อไม่ให้แต่ละเครื่องมือต้อง import scene เอง */
    const toolContext = (event) => ({
        ...event,
        scene: state.scene,
        selection: state.selection,
        camera: state.camera,
        style: state.style,
        draft: state.draft,
        canEdit: capabilities.canEdit === true,
        idFactory,
        addElement: (current, element) =>
            scene.addElement(current, {...element, z: scene.nextZ(current)}),
        replaceElements: scene.replaceElements,
        removeElements: scene.removeElements,
    });

    const canUseCurrentTool = () => capabilities.canEdit === true || isReadOnlyTool(state.tool);

    /**
     * เปลี่ยนเครื่องมือ ทางเดียวของทั้งปุ่มบนแถบและปุ่มลัด คืน false เมื่อเปลี่ยนไม่ได้
     *
     * ต้องตรวจสิทธิ์ที่นี่ด้วย ไม่ใช่พึ่งปุ่มที่ถูกปิดบนแถบ เพราะปุ่มลัดไม่ผ่านปุ่มนั้น
     * ผู้ที่ดูอย่างเดียวกด P แล้วต้องไม่ได้ดินสอขึ้นมาแม้จะวาดไม่ได้ก็ตาม
     */
    const chooseTool = (name) => {
        if (capabilities.canEdit !== true && ! isReadOnlyTool(name)) {
            return false;
        }

        state.tool = name;

        /*
         * จบการพิมพ์ก่อนเปลี่ยนเครื่องมือ ผ่านการ blur ซึ่งเป็นทางเดียวกับการ
         * คลิกออกจากกล่อง ข้อความที่พิมพ์ไว้จึงถูกบันทึกด้วยเส้นทางเดิมทั้งหมด
         * (ดู initOverlayEditing) ไม่ใช่ล้าง editingId ทิ้งเฉย ๆ แล้วหวังว่า
         * การถอด contenteditable ออกจาก DOM จะยิง blur ตามมาให้เอง
         *
         * ถ้าไม่จบให้ กล่องที่ยังเปิดโหมดแก้ไขค้างไว้จะถือ pointer-events: auto
         * ต่อไปแม้ผู้ใช้เปลี่ยนไปถือมือเลื่อนกระดานแล้ว แล้วการลากที่เริ่มลงบน
         * กล่องนั้นจะไม่เลื่อนกระดานเลย (ดู pointer.js: claimsEditableTarget)
         */
        editingTextNode()?.blur();

        // การเปลี่ยนเครื่องมือกลางท่าลากต้องล้างสถานะร่างทิ้ง ไม่งั้นเครื่องมือ
        // ใหม่จะได้รับ draft ที่มีรูปร่างของเครื่องมือเก่า
        apply({draft: null, preview: null, editingId: null});

        return true;
    };

    /**
     * ใช้ค่าสไตล์กับชิ้นที่เลือกอยู่ และจำไว้เป็นค่าตั้งต้นของชิ้นถัดไป
     *
     * ก่อนหน้านี้การเลือกสีหรือความหนามีผลกับชิ้นที่วาดใหม่เท่านั้น ผู้ใช้จึง
     * แก้สีของเส้นที่วาดไปแล้วไม่ได้เลย ต้องลบทิ้งแล้ววาดใหม่
     *
     * @param {object} styleChange ค่าที่จะจำไว้เป็นค่าตั้งต้น
     * @param {Function} matches   ชิ้นแบบไหนที่รับค่านี้ได้
     * @param {Function} patch     แปลงชิ้นนั้นให้เป็นเวอร์ชันใหม่
     */
    const applyStyle = (styleChange, matches, patch) => {
        state.style = {...state.style, ...styleChange};

        const targets = state.scene.elements.filter(
            (element) => state.selection.includes(element.id) && matches(element)
        );

        if (! targets.length) {
            draw();

            return;
        }

        apply({
            scene: scene.replaceElements(state.scene, targets.map(patch)),
            commit: true,
        });
    };

    /** ชิ้นที่มีเส้นขอบหรือเส้นลาก (ทุกอย่างยกเว้นโน้ตและรูปภาพ) */
    const hasStroke = (element) => ['pen', 'rect', 'ellipse', 'line', 'arrow'].includes(element.type);

    const isTextBox = (element) => element.type === 'sticky' || element.type === 'text';

    /**
     * กล่องข้อความที่ "กำลังพิมพ์อยู่จริง" ในขณะนี้ คืน null เมื่อไม่ได้พิมพ์อยู่
     *
     * ต้องเป็นทั้งกล่องที่เปิดโหมดแก้ไขไว้และกล่องที่ถือโฟกัสอยู่ แค่อย่างใด
     * อย่างหนึ่งไม่พอ เพราะการจัดบรรทัด "เฉพาะย่อหน้าที่เคอร์เซอร์อยู่" ต้องมี
     * เคอร์เซอร์จริง ๆ ถึงจะรู้ว่าย่อหน้าไหน
     */
    const editingTextNode = () => {
        if (! state.editingId) {
            return null;
        }

        const node = layersRoot.querySelector(`[data-el-id="${CSS.escape(state.editingId)}"]`);

        return node && doc.activeElement === node ? node : null;
    };

    /**
     * จัดบรรทัด — ทางเดียวของทั้งปุ่มบนแถบรูปแบบและปุ่มลัด Ctrl+Shift+L/E/R
     *
     * มีผลเฉพาะขณะพิมพ์อยู่ในกล่องเท่านั้น และจัดเฉพาะย่อหน้าที่เคอร์เซอร์หรือ
     * ตัวเลือกแตะอยู่ เหมือนโปรแกรมประมวลผลคำทั่วไป
     *
     * เดิมการกดขณะที่แค่ "เลือก" กล่องไว้จะจัดทุกบรรทัดให้เหมือนกันหมด ซึ่งกลืน
     * การจัดรายบรรทัดที่ผู้ใช้ตั้งใจตั้งไว้ทั้งหมดโดยไม่เตือน ผู้ใช้รายงานว่าปุ่มเดียวกัน
     * ทำสองอย่างโดยหน้าจอไม่บอกว่าตอนนี้อยู่โหมดไหน จึงเหลือความหมายเดียวคือ "ทีละบรรทัด" เสมอ
     * ปุ่มบนแถบถูกปิดไว้จนกว่าจะดับเบิลคลิกเข้าโหมดพิมพ์ (ดู toolbar.js: editingText)
     */
    const chooseAlign = (align) => {
        const node = editingTextNode();

        if (! node || ! applyAlignToSelection(doc, node, align)) {
            return false;
        }

        // จำค่าล่าสุดไว้เป็นค่าตั้งต้นของกล่องถัดไป (sticky.js อ่าน style.align ตอนสร้าง)
        state.style = {...state.style, align};
        editingAlign = align;
        draw();

        return true;
    };

    const toolbar = initToolbar(toolbarRoot, {
        capabilities,
        contextToolbar: contextToolbarRoot,
        // เมนูต้องปิดก่อนแผงค่าจะเปิด ไม่งั้นทั้งสองลอยค้างทับกัน
        onBeforeOpen: () => menus?.close(),
        fontSizeRange: {
            min: design.minFontSize ?? 8,
            max: design.maxFontSize ?? 96,
        },
        letterSpacingRange: {
            min: design.minLetterSpacing ?? -2,
            max: design.maxLetterSpacing ?? 8,
        },
        onSelectTool: chooseTool,
        onSelectColor: (color) => {
            // สีเดียวกันใช้ได้กับทั้งเส้นและตัวอักษร แต่คนละคุณสมบัติ
            // เส้นใช้ stroke ส่วนกล่องข้อความใช้ color
            applyStyle(
                {stroke: color},
                (element) => hasStroke(element) || element.type === 'text',
                (element) => (element.type === 'text'
                    ? {...element, color}
                    : {...element, stroke: color})
            );
        },
        onSelectStickyColor: (color) => {
            // ผู้ใช้มักแปะโน้ตก่อนแล้วค่อยจัดสีทีหลังตอนจัดกลุ่มความคิด
            applyStyle(
                {stickyColor: color},
                (element) => element.type === 'sticky',
                (element) => ({...element, fill: color})
            );
        },
        onSelectFontSize: (size) => {
            applyStyle({fontSize: size}, isTextBox, (element) => ({...element, fontSize: size}));
        },
        onToggleBold: () => {
            const next = ! state.style.bold;

            applyStyle({bold: next}, isTextBox, (element) => ({...element, bold: next}));
        },
        onToggleItalic: () => {
            const next = ! state.style.italic;

            applyStyle({italic: next}, isTextBox, (element) => ({...element, italic: next}));
        },
        onSelectLetterSpacing: (spacing) => {
            applyStyle({letterSpacing: spacing}, isTextBox, (element) => ({...element, letterSpacing: spacing}));
        },
        onSelectAlign: chooseAlign,
        onSelectWidth: (width) => {
            applyStyle({strokeWidth: width}, hasStroke, (element) => ({...element, strokeWidth: width}));
        },
        onCommand: (command) => runCommand(command),
    });

    /**
     * ทำคำสั่งจากแถบเครื่องมือหรือปุ่มลัด
     *
     * คืน true เมื่อคำสั่งมีผลจริง keyboard.js ใช้ค่านี้ตัดสินว่าจะยกเลิกพฤติกรรม
     * เดิมของเบราว์เซอร์หรือไม่ เช่น Backspace ที่ไม่มีอะไรให้ลบต้องปล่อยผ่านไป
     */
    const runCommand = (command) => {
        const canEdit = capabilities.canEdit === true;

        switch (command) {
            case 'undo':
            case 'redo':
                if (! canEdit) {
                    return false;
                }

                past = command === 'undo' ? history.undo(past) : history.redo(past);
                state.scene = history.current(past);
                state.selection = [];
                notifyDirty();
                draw();

                return true;

            case 'delete':
                if (! canEdit || ! state.selection.length) {
                    return false;
                }

                apply({
                    scene: scene.removeElements(state.scene, state.selection),
                    selection: [],
                    commit: true,
                });

                return true;

            case 'copy':
                if (! state.selection.length) {
                    return false;
                }

                state.clipboard = copyElements(state.scene, state.selection, idFactory());

                return true;

            case 'paste': {
                if (! canEdit || ! state.clipboard) {
                    return false;
                }

                const pasted = pasteFromClipboard(state.scene, state.clipboard, idFactory);

                state.clipboard = pasted.clipboard;
                apply({scene: pasted.scene, selection: pasted.selection, commit: true});

                return true;
            }

            case 'duplicate': {
                // ยกเลิก Ctrl+D (บุ๊กมาร์กของเบราว์เซอร์) ทุกครั้งที่แก้กระดานได้
                // แม้ยังไม่ได้เลือกอะไร เพราะผู้ใช้ที่กดบนกระดานตั้งใจทำสำเนาเสมอ
                if (! canEdit) {
                    return false;
                }

                const duplicated = duplicateElements(state.scene, state.selection, idFactory);

                if (duplicated) {
                    apply({scene: duplicated.scene, selection: duplicated.selection, commit: true});
                }

                return true;
            }

            case 'escape':
                apply({selection: [], draft: null, preview: null, editingId: null});

                return true;

            /*
             * จัดบรรทัดด้วยปุ่มลัด ใช้ทางเดียวกับปุ่มบนแถบรูปแบบทุกประการ
             *
             * ปุ่มลัดกลุ่มนี้ต่างจากกลุ่มอื่นตรงที่ต้องทำงานได้ "ระหว่างพิมพ์"
             * ไม่งั้นก็ไม่มีประโยชน์ keyboard.js จึงปล่อยผ่านให้เฉพาะกลุ่มนี้
             * เมื่อเคอร์เซอร์อยู่ในกล่องข้อความของกระดานเท่านั้น
             */
            case 'align-left':
            case 'align-center':
            case 'align-right':
                if (! canEdit) {
                    return false;
                }

                return chooseAlign(command.replace('align-', ''));

            case 'zoom-in':
                apply({camera: zoomAt(state.camera, center(), 1.2, limits)});

                return true;

            case 'zoom-out':
                apply({camera: zoomAt(state.camera, center(), 1 / 1.2, limits)});

                return true;

            case 'zoom-reset':
                apply({camera: {...state.camera, scale: clampScale(1, limits)}});

                return true;

            case 'fullscreen':
                toggleFullscreen();

                return true;

            case 'save':
                autosave?.saveNow?.();

                return true;

            case 'refresh':
                autosave?.refresh?.();

                return true;

            case 'attach-image':
                attachments?.open();

                return true;

            case 'fit':
                apply({
                    camera: fitToBounds(
                        state.camera,
                        unionBounds(state.scene.elements),
                        viewport(),
                        limits
                    ),
                });

                return true;

            /*
             * เลื่อนกล้องไปหาสิ่งที่เลือกไว้ โดยไม่แตะระดับซูมและไม่แตะเอกสาร
             *
             * ต่างจาก fit ที่คำนวณซูมใหม่ให้เห็นงานทั้งกระดาน อันนี้ไว้ตามหาของที่
             * หลงไปไกลจากกรอบสายตา ผู้ใช้จึงต้องได้ระดับซูมเดิมกลับมา
             *
             * ไม่ gate ด้วย canEdit เพราะเป็นคำสั่งมุมมอง ผู้ที่ดูอย่างเดียวต้องใช้ได้
             * และ patch ไม่มี scene กับ commit จึงไม่กินก้าวย้อนกลับและไม่ทำให้
             * การบันทึกอัตโนมัติคิดว่ามีอะไรเปลี่ยน
             */
            case 'center': {
                const bounds = unionBounds(selectedElements());

                if (! bounds) {
                    return false;
                }

                apply({camera: centerOnBounds(state.camera, bounds, viewport())});

                return true;
            }

            /*
             * จัดลำดับชั้นของสิ่งที่เลือก ตรรกะอยู่ใน scene.js ทั้งหมด
             *
             * scene.js คืนฉากตัวเดิมเมื่อขยับไม่ได้ (ชนขอบอยู่แล้ว) จึงเทียบด้วย ===
             * แล้วคืน false ไป ไม่ต้องกินก้าวย้อนกลับและไม่ต้องบันทึกใหม่
             */
            case 'bring-to-front':
            case 'bring-forward':
            case 'send-backward':
            case 'send-to-back': {
                if (! canEdit || ! state.selection.length) {
                    return false;
                }

                const reorder = {
                    'bring-to-front': scene.bringToFront,
                    'bring-forward': scene.bringForward,
                    'send-backward': scene.sendBackward,
                    'send-to-back': scene.sendToBack,
                }[command];

                const next = reorder(state.scene, state.selection);

                if (next === state.scene) {
                    return false;
                }

                apply({scene: next, commit: true});

                return true;
            }

            default:
                return false;
        }
    };

    const selectedElements = () =>
        state.scene.elements.filter((element) => state.selection.includes(element.id));

    const center = () => {
        const size = viewport();

        return {x: size.width / 2, y: size.height / 2};
    };

    const overlay = initOverlayEditing(layersRoot, {
        maxLength: design.maxTextLength || 2000,
        onFocus: (id) => {
            if (id && ! state.selection.includes(id)) {
                state.selection = [id];
                draw();
            }
        },
        onCommit: (id, text, lineAligns) => {
            const element = scene.findById(state.scene, id);

            // บันทึกลงประวัติเฉพาะเมื่อข้อความหรือการจัดบรรทัดเปลี่ยนจริง การคลิก
            // เข้าออกกล่องเฉย ๆ ไม่ควรกินก้าว undo
            const unchanged = element
                && element.text === text
                && JSON.stringify(element.lineAligns || {}) === JSON.stringify(lineAligns || {});

            if (! element || unchanged) {
                apply({editingId: null});

                return;
            }

            apply({
                scene: scene.updateElement(state.scene, id, {text, lineAligns}),
                editingId: null,
                commit: true,
            });
        },
    });

    /*
     * ตามเคอร์เซอร์ระหว่างแก้ไขกล่องข้อความ เพื่อให้ปุ่มจัดบรรทัดบนแถบเครื่องมือ
     * ไฮไลต์ตรงกับย่อหน้าที่เคอร์เซอร์อยู่จริง ไม่ใช่ค้างค่าที่กดครั้งล่าสุด
     * ผู้ใช้จะได้รู้ว่าบรรทัดที่กำลังจะพิมพ์ต่อถูกจัดไว้แบบไหนก่อนเลือกปุ่มใหม่
     */
    doc.addEventListener('selectionchange', () => {
        const node = editingTextNode();

        if (! node) {
            return;
        }

        const align = alignOfSelection(doc, node);

        if (editingAlign !== align) {
            editingAlign = align;
            draw();
        }
    });

    /**
     * สลับโหมดเต็มจอของกล่องกระดาน
     *
     * ขอเต็มจอที่ตัว root ไม่ใช่ที่ผืนผ้าใบ เพื่อให้แถบเครื่องมือติดไปด้วย
     * ไม่งั้นผู้ใช้จะเข้าเต็มจอแล้วเปลี่ยนเครื่องมือไม่ได้เลย
     *
     * requestFullscreen() คืน Promise ที่ปฏิเสธได้เมื่อเบราว์เซอร์ไม่อนุญาต
     * (เช่นอยู่ใน iframe ที่ไม่มี allow="fullscreen") ต้องรับไว้ ไม่งั้นจะเกิด
     * unhandled rejection ที่ผู้ใช้ไม่เห็นอะไรเลยและเราก็ไม่รู้ว่าพลาด
     */
    const toggleFullscreen = () => {
        if (doc.fullscreenElement === root) {
            doc.exitFullscreen?.()?.catch(() => {});

            return;
        }

        const request = root.requestFullscreen || root.webkitRequestFullscreen;

        request?.call(root)?.catch?.(() => {});
    };

    /*
     * ขนาดพื้นที่แสดงผลเปลี่ยนไปตอนเข้าและออกจากเต็มจอ ต้องวาดใหม่เพื่อให้
     * ปุ่มสะท้อนสถานะ และให้การคำนวณ "จัดให้พอดีจอ" ใช้ขนาดใหม่
     */
    doc.addEventListener('fullscreenchange', () => draw());

    /*
     * รูปภาพถูกอัปโหลดก่อน แล้วค่อยแทรกชิ้นงานที่อ้าง attachmentId ที่เซิร์ฟเวอร์
     * ออกให้ ถ้าแทรกก่อนอัปโหลด การบันทึกอัตโนมัติอาจยิงไปตอนที่ยังไม่มี id
     * แล้วโดนตัวตรวจฝั่งเซิร์ฟเวอร์ปฏิเสธด้วยข้อความที่ผู้ใช้อ่านไม่เข้าใจ
     */
    const attachments = initAttachments({
        root,
        doc,
        uploadUrl: routes.attachments,
        canEdit: capabilities.canEdit === true,
        onInsert: (uploaded) => {
            const view = viewport();
            let next = state.scene;

            // วางกลางจอ รูปถัดไปเลื่อนเฉียงลงทีละขั้นเท่าระยะของการวางซ้ำ
            // หลายรูปพร้อมกันจึงไม่ทับกันสนิทจนดูเหมือนมีรูปเดียว
            const images = uploaded.map((attachment, index) => {
                const size = fitInitialSize(attachment.width, attachment.height);
                const topLeft = screenToWorld(state.camera, {
                    x: view.width / 2 - (size.w * state.camera.scale) / 2,
                    y: view.height / 2 - (size.h * state.camera.scale) / 2,
                });
                const element = {
                    id: idFactory(),
                    type: 'image',
                    z: scene.nextZ(next),
                    x: Math.round(topLeft.x) + index * PASTE_OFFSET,
                    y: Math.round(topLeft.y) + index * PASTE_OFFSET,
                    w: size.w,
                    h: size.h,
                    attachmentId: attachment.id,
                    src: attachment.src,
                };

                next = scene.addElement(next, element);

                return element;
            });

            apply({scene: next, selection: images.map((image) => image.id), commit: true});
        },
    });

    /*
     * Ctrl+C / Ctrl+V ผ่านเหตุการณ์ copy/paste ของเบราว์เซอร์ (ดู clipboard-events.js)
     * การวางชิ้นงานและการวางรูปจึงใช้ runCommand และ attachments ตัวเดียวกับปุ่มบนแถบ
     */
    initClipboardEvents(doc, {
        canEdit: capabilities.canEdit === true,
        onCopy: () => (runCommand('copy') ? state.clipboard.token : null),
        getClipboard: () => state.clipboard,
        onPasteBoard: () => runCommand('paste'),
        onPasteImages: (files) => attachments?.upload(files),
    });

    initPointer(stage, {
        getCamera: () => state.camera,

        // มือเลื่อนกระดานต้องได้ท่าลากนี้แม้เริ่มลงบนกล่องข้อความที่เปิดโหมด
        // แก้ไขค้างอยู่ เพราะมันไม่ได้แตะเนื้อหาในกล่องเลย แค่เลื่อนกล้อง
        claimsEditableTarget: () => isViewportTool(state.tool),

        onDown: (event) => {
            if (! canUseCurrentTool()) {
                return;
            }

            state.gestureStart = event;
            beginGesture();
            apply(toolFor(state.tool).onPointerDown?.(toolContext(event)));
        },

        onMove: (event) => {
            if (! state.draft || ! canUseCurrentTool()) {
                return;
            }

            apply(toolFor(state.tool).onPointerMove?.(toolContext({
                ...event,
                start: state.gestureStart?.point,
                screenStart: state.gestureStart?.screenPoint,
            })));
        },

        onUp: (event) => {
            endGesture();

            if (! state.draft) {
                state.gestureStart = null;

                return;
            }

            // นิ้วที่สองแตะลงมาระหว่างท่า ให้ทิ้งท่านั้นไปโดยไม่บันทึก
            if (event.cancelled) {
                apply({draft: null, preview: null});
                state.gestureStart = null;

                return;
            }

            apply(toolFor(state.tool).onPointerUp?.(toolContext({
                ...event,
                start: state.gestureStart?.point,
                screenStart: state.gestureStart?.screenPoint,
            })));

            state.gestureStart = null;
        },

        onZoom: ({screenPoint, factor}) => {
            apply({camera: zoomAt(state.camera, screenPoint, factor, limits)});
        },

        onPan: ({dx, dy}) => {
            apply({camera: panBy(state.camera, dx, dy)});
        },
    });

    /**
     * ชิ้นงานที่อยู่ใต้พิกัดบนหน้าจอ (พิกัดของเหตุการณ์เมาส์ ไม่ใช่พิกัดโลก)
     *
     * ใช้ระบบตรวจการชนจากตัวแบบข้อมูล ไม่ใช่ event.target เพราะชิ้นงานส่วนใหญ่
     * เป็น SVG ชั้นเดียวและกล่องข้อความถูกตั้ง pointer-events: none ไว้ตอนไม่ได้
     * แก้ไข เหตุการณ์จึงไม่เคยไปถึงตัวชิ้นงานเอง
     *
     * @param {number} clientX
     * @param {number} clientY
     * ใช้ระยะผ่อนผันตัวเดียวกับเครื่องมือเลือก (HIT_TOLERANCE_PX) ไม่ใช่ศูนย์
     * ไม่งั้นการคลิกขวาบนเส้นบาง ๆ จะต้องเล็งให้ตรงเป๊ะจนแทบกดไม่ติด ทั้งที่
     * คลิกซ้ายที่จุดเดียวกันเลือกได้สบาย ๆ
     *
     * @param {Function} [filter] กรองชนิดที่สนใจ (ปริยายคือทุกชนิด)
     */
    const hitAt = (clientX, clientY, filter = () => true) => {
        const rect = stage.getBoundingClientRect();
        const point = screenToWorld(state.camera, {
            x: clientX - rect.left,
            y: clientY - rect.top,
        });

        return pickTopmost(
            state.scene.elements.filter(filter),
            point,
            HIT_TOLERANCE_PX / state.camera.scale
        );
    };

    /*
     * ดับเบิลคลิกเปิดการแก้ไขของกระดาษโน้ตหรือกล่องข้อความที่อยู่ใต้เคอร์เซอร์
     * (สนใจเฉพาะชนิดที่มีข้อความให้พิมพ์ ดับเบิลคลิกบนสี่เหลี่ยมไม่ต้องทำอะไร)
     */
    stage.addEventListener('dblclick', (event) => {
        if (capabilities.canEdit !== true) {
            return;
        }

        /*
         * มือเลื่อนกระดานต้องไม่เปิดโหมดพิมพ์ แม้ดับเบิลคลิกตรงกล่องข้อความพอดี
         *
         * ผู้ใช้ที่เลื่อนกระดานมักลากสั้น ๆ ติดกันหลายครั้งที่จุดเดิม และเพราะ
         * พอยน์เตอร์ถูกจับไว้ที่ผืนผ้าใบ (pointer.js) เบราว์เซอร์จึงนับการลาก
         * เป็นคลิกทุกครั้ง ลากสองครั้งเร็ว ๆ จึงกลายเป็นดับเบิลคลิกโดยไม่ตั้งใจ
         * ผลคือกระดาษโน้ตใต้เมาส์เปิดโหมดพิมพ์ขึ้นมา แล้วการลากครั้งถัดไปก็
         * เลื่อนกระดานไม่ได้อีกจนกว่าจะไปคลิกที่อื่น (อาการที่ผู้ใช้รายงาน)
         */
        if (isViewportTool(state.tool)) {
            return;
        }

        const hit = hitAt(event.clientX, event.clientY, (element) => isOverlayType(element.type));

        if (hit) {
            apply({selection: [hit.id], editingId: hit.id});
            overlay?.focus(hit.id);
        }
    });

    /*
     * เมนูบนแถบเครื่องมือ บนหัวเรื่อง และเมนูคลิกขวา ใช้ menu.js ตัวเดียวกันทั้งหมด
     * ผูกที่ root ครั้งเดียวจึงครอบทุกเมนูบนหน้านี้
     *
     * เมนูกับแผงค่าต้องไม่เปิดค้างพร้อมกัน ต่อสายไขว้กันที่นี่จุดเดียว: เมนูจะเปิด
     * ก็สั่งปิดแผงค่า และแผงค่าจะเปิดก็สั่งปิดเมนู (ดู onBeforeOpen ของ initToolbar)
     */
    menus = initMenus(root, {onBeforeOpen: () => toolbar?.closePickers()});

    /*
     * เมนูคลิกขวาเป็นทางเข้าเดียวของคำสั่งจัดลำดับชั้นและ "เลื่อนไปหา" ซึ่งไม่มี
     * ปุ่มบนแถบ (ใช้นาน ๆ ครั้ง และเป็นคำสั่งที่ทำกับชิ้นที่คลิกโดยตรง)
     *
     * ปิดรายการที่แก้เนื้อหาสำหรับผู้ที่ดูอย่างเดียวด้วยตัวเดียวกับแถบเครื่องมือ
     * ส่วนด่านจริงคือ runCommand ซึ่งตรวจ canEdit ทุกคำสั่งอยู่แล้ว
     */
    if (capabilities.canEdit !== true) {
        disableEditControls(contextMenuRoot);
    }

    initContextMenu(stage, contextMenuRoot, {
        menus,
        hitAt: (clientX, clientY) => hitAt(clientX, clientY),
        getSelection: () => state.selection,
        onSelect: (selection) => apply({selection}),
        onCommand: runCommand,
        canEdit: capabilities.canEdit === true,
        hasClipboard: () => Boolean(state.clipboard),
        // ถามฉากจริงว่าชิ้นที่เลือกยังขยับชั้นได้อีกไหม เพื่อปิดปุ่มที่กดแล้วไม่เกิดอะไร
        canReorder: () => scene.canReorder(state.scene, state.selection),
        isGestureActive: () => Boolean(state.draft),
    });

    /*
     * ปุ่มลัดวิ่งผ่าน chooseTool และ runCommand ตัวเดียวกับปุ่มบนแถบเครื่องมือ
     * การตรวจสิทธิ์จึงอยู่ที่เดียว และไม่มีทางที่ปุ่มลัดจะทำสิ่งที่ปุ่มทำไม่ได้
     *
     * keyboard.js ปล่อยผ่านทุกการกดที่เกิดขณะพิมพ์อยู่ในช่องกรอกหรือกล่องข้อความ
     * ไม่งั้นผู้ใช้จะแก้คำผิด คัดลอก หรือวางข้อความในกระดาษโน้ตไม่ได้เลย
     */
    initKeyboardShortcuts(doc, {
        toolShortcuts: toolShortcutsFrom(design.tools),
        onSelectTool: chooseTool,
        onCommand: runCommand,
        isGestureActive: () => Boolean(state.draft),
        // ปุ่มลัดจัดบรรทัดต้องใช้ได้ระหว่างพิมพ์ ต่างจากปุ่มลัดอื่นทั้งหมด
        // ด่านนี้แคบไว้ที่กล่องข้อความของกระดานเท่านั้น ไม่รวมช่องกรอกอื่นบนหน้า
        isTextEditing: () => Boolean(editingTextNode()),
    });

    /*
     * การบันทึกอัตโนมัติถูกต่อสายหลังจากวาดรอบแรกแล้ว เพื่อให้สถานะเริ่มต้นเป็น
     * "บันทึกแล้ว" ไม่ใช่ "ยังไม่ได้บันทึก" ทั้งที่ยังไม่มีใครแตะอะไรเลย
     *
     * ข้ามไปทั้งหมดเมื่อไม่มีปลายทางให้บันทึก ไม่ใช่ตั้งขึ้นมาแล้วปล่อยให้ยิงพลาด
     * เพราะการยิงพลาดจะเข้าสถานะ "ลองใหม่อัตโนมัติ" ซึ่งวนซ้ำไม่รู้จบและถือ
     * ตัวจับเวลาค้างไว้ตลอดอายุของหน้า
     */
    const autosave = routes.save ? initAutosave({
        root,
        doc,
        design,
        capabilities,
        initialVersion,
        client: createClient({routes, doc}),
        getDocument: () => scene.serialize(state.scene),
        onReplaceDocument: (serverDocument) => {
            state.scene = scene.deserialize(serverDocument);
            state.selection = [];
            state.editingId = null;

            // ประวัติเดิมอ้างถึงฉากที่ไม่มีอยู่บนเซิร์ฟเวอร์แล้ว การกด undo ต่อ
            // จะพาผู้ใช้กลับไปหาสถานะที่บันทึกไม่ได้อีกต่อไป
            past = history.reset(past, state.scene);
            draw();
        },
    }) : null;

    // ให้ apply() แจ้งการเปลี่ยนแปลงไปที่ตัวจัดตารางบันทึก
    notifyDirty = () => autosave?.markDirty?.();
    beginGesture = () => autosave?.beginGesture?.();
    endGesture = () => autosave?.endGesture?.();

    /*
     * ปุ่มจัดการกระดานบนหัวหน้า ใช้กล่องใบเดียวกับหน้ารายการผ่าน board-settings.js
     * ไม่ใช่เขียน markup และตรรกะขึ้นมาใหม่อีกชุด
     *
     * หลังเปลี่ยนชื่อสำเร็จ โหลดหน้าเดิมซ้ำแทนการอัปเดตทีละจุด เพราะชื่อกระดาน
     * ปรากฏทั้งบนหัวเรื่อง ชื่อแท็บ และ breadcrumb การไล่แก้ทีละที่จะกลายเป็น
     * เทมเพลตชุดที่สองที่เพี้ยนออกจาก Blade ได้
     */
    const settingsModal = routes.update
        ? createBoardModal(root, {
            doc,
            fetchImpl: globalThis.fetch,
            onSaved: () => reloadPage(),
        })
        : null;

    root.querySelector('[data-workspace-board-settings]')?.addEventListener('click', (event) => {
        settingsModal?.open({
            mode: 'edit',
            action: routes.update,
            title: boardData.title,
            visibility: boardData.visibility,
            trigger: event.currentTarget,
        });
    });

    root.querySelector('[data-workspace-board-delete]')?.addEventListener('click', async () => {
        const deleted = await confirmDeleteBoard({
            title: boardData.title,
            deleteUrl: routes.destroy,
        }, {fetchImpl: globalThis.fetch, doc, swal: globalThis.Swal});

        if (deleted) {
            // กระดานที่เพิ่งลบไม่มีให้เปิดแล้ว ต้องพากลับไปหน้าแผนก
            // ไม่ใช่ปล่อยค้างไว้บนหน้าที่จะได้ 404 ทันทีที่รีเฟรช
            navigateTo(routes.department);
        }
    });

    draw();

    return {
        state,
        runCommand,
        autosave,
        settingsModal,
        /** ใช้โดยเทสต์และโดยชั้นบันทึกในเฟสถัดไป */
        currentDocument: () => scene.serialize(state.scene),
    };
};

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        initBoardEditor({root: document.querySelector('[data-workspace-board]')});
    });
}
