/*
 * เครื่องมือเลื่อนกระดาน
 *
 * ไม่แก้เนื้อหาใด ๆ จึงเป็นเครื่องมือที่ผู้ดูอย่างเดียวใช้ได้ และเป็นเครื่องมือ
 * เริ่มต้นบนอุปกรณ์สัมผัส เพราะการแตะแล้วเลื่อนเป็นสิ่งที่คนคาดหวังจากหน้าจอ
 * ก่อนจะคาดหวังว่ามันจะวาด
 */
export const handTool = {
    name: 'hand',
    cursor: 'grab',

    onPointerDown({camera}) {
        return {draft: {camera}};
    },

    onPointerMove({draft, screenPoint, screenStart}) {
        if (! draft) {
            return undefined;
        }

        // คำนวณจากพิกัดหน้าจอ ไม่ใช่พิกัดโลก เพราะพิกัดโลกเปลี่ยนไปพร้อมกับกล้อง
        // ที่เรากำลังเลื่อนอยู่ ทำให้ได้ผลป้อนกลับที่วิ่งหนีตัวเอง
        return {
            camera: {
                ...draft.camera,
                x: draft.camera.x + (screenPoint.x - screenStart.x),
                y: draft.camera.y + (screenPoint.y - screenStart.y),
            },
        };
    },

    onPointerUp() {
        return {draft: null};
    },
};
