/*
 * แบ่งหน้าตารางรายงานฝั่งผู้ใช้
 *
 * ตารางของรายงานถูก render มาครบทุกแถวแล้ว เพราะข้อมูลเป็นผลสรุปที่คำนวณใน
 * หน่วยความจำ ไม่ใช่ paginator ของฐานข้อมูล การกลับไปถามเซิร์ฟเวอร์ทุกครั้งที่
 * เปลี่ยนหน้าจึงต้องคำนวณสรุปทั้งชุดใหม่โดยไม่ได้ประหยัดอะไรเลย
 *
 * ปุ่มถูก render จาก Blade โมดูลนี้มีหน้าที่ซ่อน/แสดงแถวและอัปเดตข้อความเท่านั้น
 * ไม่สร้าง DOM ของปุ่มเอง จึงไม่มีเทมเพลตปุ่มชุดที่สองให้ต้องดูแล
 */

/**
 * สถานะของการแบ่งหน้า — ฟังก์ชันบริสุทธิ์ ไม่แตะ DOM จึงเขียนเทสต์ได้ตรง ๆ
 *
 * page ถูกบีบให้อยู่ในช่วงที่มีจริงเสมอ การลบตัวกรองจนแถวเหลือน้อยลงขณะที่ผู้ใช้
 * อยู่หน้าท้าย ๆ จึงไม่ทำให้ตารางว่างเปล่าโดยไม่มีอะไรอธิบาย
 */
export function paginationState({total = 0, pageSize = 10, page = 1} = {}) {
    const size = Math.max(1, Number(pageSize) || 1);
    const pages = Math.max(1, Math.ceil(Math.max(0, total) / size));
    const current = Math.min(Math.max(1, Number(page) || 1), pages);
    const start = (current - 1) * size;

    return {
        page: current,
        pages,
        start,
        end: Math.min(start + size, total),
        // ตารางที่พอดีหน้าเดียวไม่ต้องมีปุ่มให้กดเปล่า
        needsPager: total > size,
        label: `${current} / ${pages}`,
    };
}

/**
 * ผูกตารางหนึ่งตารางเข้ากับแถบปุ่มของมัน
 *
 * คืน null เมื่อหา element ไม่ครบ เพื่อให้หน้าที่ไม่มีตารางนี้ไม่พังทั้งไฟล์
 */
export function initTablePager({table, pager, rowSelector, pageLabel, previous, next} = {}) {
    if (! table || ! pager) {
        return null;
    }

    const rows = [...table.querySelectorAll(rowSelector)];
    const pageSize = Number(table.dataset.pageSize) || 10;
    let page = 1;

    const render = () => {
        const state = paginationState({total: rows.length, pageSize, page});
        page = state.page;

        rows.forEach((row, index) => {
            row.hidden = index < state.start || index >= state.end;
        });

        pager.hidden = ! state.needsPager;
        if (pageLabel) pageLabel.textContent = state.label;

        // ปุ่มที่กดไปแล้วไม่เกิดอะไรขึ้นต้องถูกปิด ไม่ใช่ปล่อยให้กดได้แล้วเงียบ
        if (previous) previous.disabled = state.page <= 1;
        if (next) next.disabled = state.page >= state.pages;

        return state;
    };

    previous?.addEventListener('click', () => {
        page -= 1;
        render();
    });

    next?.addEventListener('click', () => {
        page += 1;
        render();
    });

    render();

    return {render, rowCount: rows.length};
}
