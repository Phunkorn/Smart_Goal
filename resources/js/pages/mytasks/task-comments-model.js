/**
 * 'comment-icon' คือการกดคอลัมน์คอมเมนต์บนบอร์ด ซึ่งเป็นเจตนาอ่านคอมเมนต์โดยตรง
 * จึงถือว่าอ่านแล้วเหมือนการกดแท็บ ต่างจาก 'modal' ที่เป็นการเปิดงานเฉย ๆ
 */
export function shouldMarkCommentsRead(source, tab) {
    return tab === 'updates' && (source === 'tab' || source === 'deep-link' || source === 'comment-icon');
}

/**
 * Enter = ส่ง, Shift+Enter = ขึ้นบรรทัดใหม่
 *
 * ระหว่างที่ IME กำลังประกอบคำ (ภาษาไทยและ CJK) ปุ่ม Enter คือการยืนยันคำ ไม่ใช่การส่ง
 * เบราว์เซอร์รุ่นเก่าบางตัวไม่ตั้ง isComposing แต่ยังส่ง keyCode 229 มา จึงต้องกันทั้งสองทาง
 */
export function shouldSubmitOnEnter({key, shiftKey, isComposing, keyCode} = {}) {
    if (key !== 'Enter') return false;
    if (shiftKey) return false;

    return !isComposing && keyCode !== 229;
}

export function commentDeepLink(search) {
    const params = new URLSearchParams(search);
    return {
        taskId: params.get('open_task'),
        tab: params.get('task_tab'),
    };
}

/**
 * Task deep-link เป็น state แบบใช้ครั้งเดียว หลังเปิดงานสำเร็จต้องลบเฉพาะ key
 * ของ deep-link โดยรักษา path, query อื่น และ hash ของหน้าต้นทางไว้ทั้งหมด
 */
export function withoutTaskDeepLink(href) {
    const url = new URL(href);
    url.searchParams.delete('open_task');
    url.searchParams.delete('task_tab');

    return `${url.pathname}${url.search}${url.hash}`;
}

export function prependComment(timeline, taskId, comment) {
    timeline[String(taskId)] ||= {updates: [], activity: []};
    timeline[String(taskId)].updates.unshift(comment);
    return timeline[String(taskId)].updates;
}

export function unreadCountAfterRead() {
    return 0;
}

export function canComposeComment(taskManagement) {
    return taskManagement?.can_comment === true && Boolean(taskManagement.comment_url);
}
