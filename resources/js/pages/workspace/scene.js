/*
 * ตัวแบบข้อมูลของกระดาน — รายการชิ้นงานและการเปลี่ยนแปลงที่กระทำกับมัน
 *
 * ฉากเป็นค่าที่ไม่เปลี่ยนแปลงในที่ (immutable) ทุกฟังก์ชันคืนฉากใหม่ ไม่แก้ของเดิม
 * ประวัติ undo/redo จึงเก็บเป็นการอ้างถึงฉากแต่ละรุ่นได้ตรง ๆ โดยไม่ต้องคัดลอกลึก
 *
 * ตัวสร้างรหัสถูกฉีดเข้ามา (idFactory) แทนการเรียก crypto.randomUUID โดยตรง
 * เพราะ jsdom ที่ใช้ทดสอบไม่ได้มี crypto.randomUUID บน window เสมอไป และการ
 * ฉีดยังทำให้เทสต์ได้รหัสที่คาดเดาได้
 */

/** ตัวสร้างรหัสเริ่มต้น: ตัวนับที่นับต่อจากศูนย์ในแต่ละหน้าจอ */
export const sequentialIdFactory = (prefix = 'e') => {
    let counter = 0;

    return () => {
        counter += 1;

        return `${prefix}-${counter}-${Math.random().toString(36).slice(2, 8)}`;
    };
};

export const createScene = (elements = []) => ({elements: [...elements]});

/**
 * อ่านฉากจากเอกสารที่เซิร์ฟเวอร์ส่งมา
 *
 * รับรูปแบบที่ไม่สมบูรณ์ได้โดยไม่ล้ม เพราะหน้าจอต้องเปิดได้เสมอ แม้เอกสารจะ
 * เสียหาย ผู้ใช้ยังวาดใหม่ทับได้ ดีกว่าเจอหน้าขาว
 */
export const deserialize = (stored) => {
    // ตั้งชื่อพารามิเตอร์ว่า stored ไม่ใช่ document เพราะ document เป็นชื่อของ
    // global ในเบราว์เซอร์ การบังชื่อไว้ทำให้คนอ่านทีหลังเข้าใจผิดได้ง่ายว่า
    // โมดูลนี้แตะ DOM ทั้งที่เป็นตรรกะบริสุทธิ์ล้วน ๆ
    const elements = Array.isArray(stored?.elements) ? stored.elements : [];

    return createScene(elements);
};

/** แปลงกลับเป็นรูปแบบที่ส่งให้เซิร์ฟเวอร์ */
export const serialize = (scene) => ({schema: 1, elements: scene.elements});

/** ค่าลำดับชั้นของชิ้นถัดไป (ชิ้นใหม่อยู่บนสุดเสมอ) */
export const nextZ = (scene) =>
    scene.elements.reduce((highest, element) => Math.max(highest, element.z || 0), 0) + 1;

export const findById = (scene, id) => scene.elements.find((element) => element.id === id) || null;

export const findManyById = (scene, ids) => {
    const wanted = new Set(ids);

    return scene.elements.filter((element) => wanted.has(element.id));
};

/**
 * เพิ่มชิ้นงานใหม่ต่อท้าย
 *
 * ต่อท้ายเสมอ เพราะลำดับในรายการคือลำดับการเรนเดอร์ ชิ้นที่เพิ่งวาดจึงอยู่บนสุด
 * ตรงกับที่ผู้ใช้คาดหวัง
 */
export const addElement = (scene, element) => createScene([...scene.elements, element]);

export const updateElement = (scene, id, changes) => createScene(
    scene.elements.map((element) => (element.id === id ? {...element, ...changes} : element))
);

/**
 * แทนที่หลายชิ้นพร้อมกันด้วยเวอร์ชันใหม่ของแต่ละชิ้น
 *
 * ใช้ตอนลากย้ายหรือย่อขยายกลุ่ม ซึ่งต้องเปลี่ยนหลายชิ้นในก้าวเดียว การเรียก
 * updateElement ทีละชิ้นจะสร้างอาร์เรย์ใหม่หลายรอบโดยไม่จำเป็น
 */
export const replaceElements = (scene, replacements) => {
    const byId = new Map(replacements.map((element) => [element.id, element]));

    return createScene(scene.elements.map((element) => byId.get(element.id) || element));
};

export const removeElements = (scene, ids) => {
    const unwanted = new Set(ids);

    return createScene(scene.elements.filter((element) => ! unwanted.has(element.id)));
};

/**
 * ย้ายชิ้นงานไปบนสุดหรือล่างสุดของลำดับการเรนเดอร์
 *
 * ปรับทั้งลำดับในอาร์เรย์และค่า z ให้ตรงกัน เพราะ z ถูกบันทึกลงเซิร์ฟเวอร์
 * ส่วนลำดับในอาร์เรย์คือสิ่งที่ตัวเรนเดอร์ใช้จริง สองอย่างต้องไม่ขัดกัน
 */
export const bringToFront = (scene, ids) => reorder(scene, ids, 'front');

export const sendToBack = (scene, ids) => reorder(scene, ids, 'back');

const reorder = (scene, ids, position) => {
    const moving = new Set(ids);
    const stay = scene.elements.filter((element) => ! moving.has(element.id));
    const move = scene.elements.filter((element) => moving.has(element.id));
    const ordered = position === 'front' ? [...stay, ...move] : [...move, ...stay];

    return createScene(ordered.map((element, index) => ({...element, z: index + 1})));
};

/** จำนวนชิ้นงานทั้งหมด ใช้แสดงบนหัวกระดานและเทียบกับเพดานของเซิร์ฟเวอร์ */
export const elementCount = (scene) => scene.elements.length;

/** ฉากสองรุ่นนี้เนื้อหาเหมือนกันหรือไม่ (ใช้ตัดสินว่ามีอะไรต้องบันทึกไหม) */
export const isSameContent = (a, b) =>
    a === b || JSON.stringify(serialize(a)) === JSON.stringify(serialize(b));
