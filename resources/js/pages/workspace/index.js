/*
 * ตัวประกอบของหน้าวาด - จุดเดียวที่ถือสถานะจริง
 *
 * โมดูลอื่นทั้งหมดเป็นฟังก์ชันของอินพุต ที่นี่คือที่เดียวที่มีตัวแปรที่เปลี่ยนค่าได้
 * และเป็นที่เดียวที่ต่อสายระหว่างเหตุการณ์จากผู้ใช้ ตรรกะบริสุทธิ์ และ DOM
 *
 *      pointer.js / toolbar.js   (รับเหตุการณ์)
 *                 |
 *              index.js          (สถานะ + apply)
 *          /      |      \
 *   tools/*   history.js   camera.js      (ตรรกะบริสุทธิ์)
 *                 |
 *   renderer.js / selection-ui.js         (วาดลง DOM)
 *
 * เฟสนี้ยังใช้ปุ่มบันทึกแบบกดเอง การบันทึกอัตโนมัติและการกันเขียนทับฝั่งหน้าจอ
 * จะเข้ามาในเฟสถัดไปผ่าน save-state.js
 */

import {
    clampScale,
    createCamera,
    fitToBounds,
    panBy,
    screenToWorld,
    zoomAt,
} from './camera.js';
import {pickTopmost, unionBounds} from './geometry.js';
import {fitInitialSize, initAttachments} from './attachments.js';
import {initAutosave} from './autosave.js';
import {confirmDeleteBoard, createBoardModal} from './board-settings.js';
import {createClient} from './client.js';
import * as history from './history.js';
import {initPointer} from './pointer.js';
import {
    applyOverlayCamera,
    initOverlayEditing,
    isOverlayType,
    renderOverlay,
} from './overlay-text.js';
import {applyCamera, renderElements, renderPreview} from './renderer.js';
import * as scene from './scene.js';
import {renderSelection} from './selection-ui.js';
import {initToolbar} from './toolbar.js';
import {isReadOnlyTool, toolFor} from './tools/index.js';

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
    const vectorLayer = root.querySelector('[data-workspace-vector]');
    const previewLayer = root.querySelector('[data-workspace-preview]');
    const selectionLayer = root.querySelector('[data-workspace-selection]');
    const overlayLayer = root.querySelector('[data-workspace-overlay]');
    const toolbarRoot = root.querySelector('[data-workspace-toolbar]');

    if (! stage || ! vectorLayer) {
        return null;
    }

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
        },
        preview: null,
        draft: null,
        gestureStart: null,
        // กล่องข้อความที่กำลังแก้อยู่ (มีได้ทีละกล่อง) ดู overlay-text.js ว่าทำไม
        editingId: null,
    };

    let past = history.createHistory(state.scene);

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
        applyCamera(vectorLayer, state.camera);

        if (previewLayer) {
            applyCamera(previewLayer, state.camera);
        }

        // กระดาษโน้ตและกล่องข้อความอยู่ในชั้น HTML ส่วนที่เหลืออยู่ในชั้น SVG
        // การแบ่งอยู่ที่ isOverlayType จุดเดียว ทั้งสองชั้นจึงไม่มีทางวาดชิ้นเดียวกันซ้อนกัน
        renderElements(
            vectorLayer,
            state.scene.elements.filter((element) => ! isOverlayType(element.type)),
            doc
        );
        renderPreview(previewLayer, state.preview, doc);

        if (overlayLayer) {
            applyOverlayCamera(overlayLayer, state.camera);
            renderOverlay(
                overlayLayer,
                state.scene.elements.filter((element) => isOverlayType(element.type)),
                {doc, editable: capabilities.canEdit === true, editingId: state.editingId}
            );
        }

        if (selectionLayer) {
            renderSelection(
                selectionLayer,
                unionBounds(state.scene.elements.filter((el) => state.selection.includes(el.id))),
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
            scale: state.camera.scale,
            isFullscreen: doc.fullscreenElement === root,
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
        idFactory,
        addElement: (current, element) =>
            scene.addElement(current, {...element, z: scene.nextZ(current)}),
        replaceElements: scene.replaceElements,
        removeElements: scene.removeElements,
    });

    const canUseCurrentTool = () => capabilities.canEdit === true || isReadOnlyTool(state.tool);

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

    const toolbar = initToolbar(toolbarRoot, {
        capabilities,
        fontSizeRange: {
            min: design.minFontSize ?? 8,
            max: design.maxFontSize ?? 96,
        },
        onSelectTool: (name) => {
            state.tool = name;
            // การเปลี่ยนเครื่องมือกลางท่าลากต้องล้างสถานะร่างทิ้ง ไม่งั้นเครื่องมือ
            // ใหม่จะได้รับ draft ที่มีรูปร่างของเครื่องมือเก่า
            state.draft = null;
            state.preview = null;
            draw();
        },
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
        onSelectWidth: (width) => {
            applyStyle({strokeWidth: width}, hasStroke, (element) => ({...element, strokeWidth: width}));
        },
        onCommand: (command) => runCommand(command),
    });

    const runCommand = (command) => {
        switch (command) {
            case 'undo':
                past = history.undo(past);
                state.scene = history.current(past);
                state.selection = [];
                notifyDirty();
                draw();
                break;

            case 'redo':
                past = history.redo(past);
                state.scene = history.current(past);
                state.selection = [];
                notifyDirty();
                draw();
                break;

            case 'delete':
                if (capabilities.canEdit && state.selection.length) {
                    apply({
                        scene: scene.removeElements(state.scene, state.selection),
                        selection: [],
                        commit: true,
                    });
                }

                break;

            case 'zoom-in':
                apply({camera: zoomAt(state.camera, center(), 1.2, limits)});
                break;

            case 'zoom-out':
                apply({camera: zoomAt(state.camera, center(), 1 / 1.2, limits)});
                break;

            case 'zoom-reset':
                apply({camera: {...state.camera, scale: clampScale(1, limits)}});
                break;

            case 'fullscreen':
                toggleFullscreen();
                break;

            case 'save':
                autosave?.saveNow?.();
                break;

            case 'refresh':
                autosave?.refresh?.();
                break;

            case 'attach-image':
                attachments?.open();
                break;

            case 'fit':
                apply({
                    camera: fitToBounds(
                        state.camera,
                        unionBounds(state.scene.elements),
                        viewport(),
                        limits
                    ),
                });
                break;

            default:
                break;
        }
    };

    const center = () => {
        const size = viewport();

        return {x: size.width / 2, y: size.height / 2};
    };

    const overlay = initOverlayEditing(overlayLayer, {
        maxLength: design.maxTextLength || 2000,
        onFocus: (id) => {
            if (id && ! state.selection.includes(id)) {
                state.selection = [id];
                draw();
            }
        },
        onCommit: (id, text) => {
            const element = scene.findById(state.scene, id);

            // บันทึกลงประวัติเฉพาะเมื่อข้อความเปลี่ยนจริง การคลิกเข้าออกกล่อง
            // เฉย ๆ ไม่ควรกินก้าว undo
            if (! element || element.text === text) {
                apply({editingId: null});

                return;
            }

            apply({
                scene: scene.updateElement(state.scene, id, {text}),
                editingId: null,
                commit: true,
            });
        },
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
        stage,
        doc,
        uploadUrl: routes.attachments,
        canEdit: capabilities.canEdit === true,
        onInsert: (attachment) => {
            const size = fitInitialSize(attachment.width, attachment.height);
            const view = viewport();
            const topLeft = screenToWorld(state.camera, {
                x: view.width / 2 - (size.w * state.camera.scale) / 2,
                y: view.height / 2 - (size.h * state.camera.scale) / 2,
            });

            const element = {
                id: idFactory(),
                type: 'image',
                z: scene.nextZ(state.scene),
                x: Math.round(topLeft.x),
                y: Math.round(topLeft.y),
                w: size.w,
                h: size.h,
                attachmentId: attachment.id,
                src: attachment.src,
            };

            apply({
                scene: scene.addElement(state.scene, element),
                selection: [element.id],
                commit: true,
            });
        },
    });

    initPointer(stage, {
        getCamera: () => state.camera,

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

    /*
     * ดับเบิลคลิกเปิดการแก้ไขของกระดาษโน้ตหรือกล่องข้อความที่อยู่ใต้เคอร์เซอร์
     *
     * ต้องผูกที่ stage ไม่ใช่ที่ตัวกล่อง เพราะตอนยังไม่ได้แก้ไข กล่องถูกตั้ง
     * pointer-events: none ไว้ เหตุการณ์จึงไม่มีทางไปถึงตัวมันเอง การหาว่ากด
     * โดนกล่องไหนใช้ระบบตรวจการชนจากตัวแบบข้อมูลตัวเดียวกับการเลือก
     */
    stage.addEventListener('dblclick', (event) => {
        if (capabilities.canEdit !== true) {
            return;
        }

        const rect = stage.getBoundingClientRect();
        const point = screenToWorld(state.camera, {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top,
        });

        const hit = pickTopmost(
            state.scene.elements.filter((element) => isOverlayType(element.type)),
            point
        );

        if (hit) {
            apply({selection: [hit.id], editingId: hit.id});
            overlay?.focus(hit.id);
        }
    });

    doc.addEventListener('keydown', (event) => {
        // ปล่อยให้ undo ของเบราว์เซอร์ทำงานเมื่อโฟกัสอยู่ในช่องข้อความ ไม่งั้น
        // ผู้ใช้จะแก้คำผิดในกระดาษโน้ตไม่ได้เลย (สำคัญมากเมื่อชั้นข้อความเข้ามา)
        if (doc.activeElement?.isContentEditable || isFormField(doc.activeElement)) {
            return;
        }

        const ctrl = event.ctrlKey || event.metaKey;

        if (ctrl && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            runCommand(event.shiftKey ? 'redo' : 'undo');

            return;
        }

        if (ctrl && event.key.toLowerCase() === 'y') {
            event.preventDefault();
            runCommand('redo');

            return;
        }

        if (event.key === 'Delete' || event.key === 'Backspace') {
            if (state.selection.length) {
                event.preventDefault();
                runCommand('delete');
            }

            return;
        }

        // F = เต็มจอ ปุ่มเดียวไม่มีตัวช่วย จึงต้องไม่ชนกับการพิมพ์ ซึ่งถูกกัน
        // ไปแล้วด้านบนด้วยการตรวจว่าโฟกัสอยู่ในช่องข้อความหรือไม่
        if (! ctrl && event.key.toLowerCase() === 'f') {
            event.preventDefault();
            runCommand('fullscreen');

            return;
        }

        if (event.key === 'Escape') {
            apply({selection: [], draft: null, preview: null, editingId: null});
        }
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

const isFormField = (node) =>
    Boolean(node) && ['INPUT', 'TEXTAREA', 'SELECT'].includes(node.tagName);

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        initBoardEditor({root: document.querySelector('[data-workspace-board]')});
    });
}
