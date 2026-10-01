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

/**
 * ตรวจว่ากำลังพิมพ์ @mention อยู่ที่ตำแหน่ง caret หรือไม่
 *
 * ต้องมีช่องว่าง/ขึ้นบรรทัดใหม่ หรือจุดเริ่มข้อความอยู่ก่อน @ เสมอ ไม่งั้นจะกลายเป็น
 * อีเมลหรือคำกลางประโยคที่บังเอิญมี @ ปน และคำค้นหลัง @ ต้องไม่มีช่องว่าง (แปลว่าเคาะจบคำไปแล้ว)
 */
export function mentionQueryAt(text, caret) {
    const before = String(text ?? '').slice(0, Math.max(0, caret ?? 0));
    const match = before.match(/(?:^|[\s\n])@([^\s@]*)$/);
    if (!match) return null;

    return {start: before.length - match[1].length - 1, query: match[1]};
}

/** ตัดรายชื่อที่ตรงกับคำค้นสูงสุด 8 คน ไม่ให้ popover ยาวจนล้นจอ */
export function filterMentionCandidates(candidates, query) {
    const list = Array.isArray(candidates) ? candidates : [];
    const needle = String(query ?? '').trim().toLowerCase();

    if (!needle) return list.slice(0, 8);

    return list.filter((person) => String(person?.name ?? '').toLowerCase().includes(needle)).slice(0, 8);
}

/** แทรก "@ชื่อ " ทับช่วงคำค้นที่กำลังพิมพ์ และคืนตำแหน่ง caret ใหม่ต่อจากชื่อที่แทรก */
export function insertMention(text, mention, start, end) {
    const value = String(text ?? '');
    const before = value.slice(0, Math.max(0, start ?? 0));
    const after = value.slice(Math.max(0, end ?? start ?? 0));
    const inserted = `@${mention?.name ?? ''} `;

    return {text: `${before}${inserted}${after}`, caret: before.length + inserted.length};
}

/**
 * เช็คว่าอักขระก่อน/หลังตำแหน่งที่พบชื่อเป็นขอบคำจริง ไม่ใช่ชื่อที่ไปโผล่กลางคำอื่น
 * ภาษาไทยไม่มีช่องว่างคั่นคำตามธรรมชาติ จึงเช็คเฉพาะจุดที่ @ชื่อ ถูกแทรกเข้ามาจริง ๆ
 * (ซึ่งมี @ นำหน้าและช่องว่างตามหลังเสมอจาก insertMention) ไม่ใช้ \b ที่ใช้ไม่ได้กับภาษาไทย
 */
function isMentionBoundary(text, start, end) {
    const before = start === 0 ? ' ' : text[start - 1];
    const after = text[end] ?? ' ';

    return (start === 0 || /\s/.test(before)) && (end === text.length || /[\s.,!?;:]/.test(after));
}

/**
 * หา user id ของทุก @ชื่อ ที่ปรากฏจริงในข้อความ เทียบกับรายชื่อคนในงานเท่านั้น
 *
 * ไม่เก็บ state ระหว่างพิมพ์ เพราะผู้ใช้แก้ไข/ลบข้อความที่แทรกไว้ได้อิสระหลังเลือกชื่อ
 * การไล่หาใหม่จากข้อความจริงตอนส่งจึงตรงกับสิ่งที่ผู้ใช้เห็นบนจอเสมอ และฝั่ง server
 * ยังตรวจสิทธิ์ซ้ำอีกชั้นกับ TaskCommentService::mentionCandidates() อยู่ดี
 */
export function extractMentionIds(message, candidates) {
    const text = String(message ?? '');
    const list = Array.isArray(candidates) ? candidates : [];
    const found = [];

    list.forEach((person) => {
        const name = String(person?.name ?? '').trim();
        if (!name || person?.id == null) return;

        const marker = `@${name}`;
        let index = text.indexOf(marker);
        while (index !== -1) {
            if (isMentionBoundary(text, index, index + marker.length)) {
                if (!found.includes(person.id)) found.push(person.id);
                break;
            }
            index = text.indexOf(marker, index + 1);
        }
    });

    return found;
}

/**
 * "@all" กล่าวถึงทุกคนในงานนี้ — คำสำรอง ไม่ใช่ชื่อผู้ใช้จริง จึงตรวจแยกจาก extractMentionIds()
 * ใช้กติกาขอบคำเดียวกับ mention ชื่อคน กัน "@allow" หรือคำอื่นที่ขึ้นต้นด้วย all มาโดนด้วย
 */
export function mentionsEveryone(message) {
    const text = String(message ?? '');
    const marker = 'all';
    const lower = text.toLowerCase();
    let index = lower.indexOf(`@${marker}`);

    while (index !== -1) {
        if (isMentionBoundary(text, index, index + marker.length + 1)) return true;
        index = lower.indexOf(`@${marker}`, index + 1);
    }

    return false;
}

/**
 * แยกข้อความเป็นช่วง ๆ สำหรับเรนเดอร์ไฮไลต์ @ชื่อ โดยไม่ปนกับการ escape HTML
 * (การ escape ทำที่ชั้น DOM ใน task-timeline.js ฟังก์ชันนี้คืนแค่ข้อความดิบเป็นชิ้น ๆ)
 *
 * "all" ถูกรวมไว้ในชุดที่ไฮไลต์เสมอ เพราะเป็นคำสำรองของ "กล่าวถึงทุกคน" ไม่ใช่ชื่อจริงที่มาจาก
 * comment.mentions (ผู้รับของ @all คือทุกคนในงาน ข้อความที่พิมพ์จริงยังเป็น "@all" คำเดียว)
 */
export function splitMentionSegments(note, mentionNames) {
    const text = String(note ?? '');
    const names = (Array.isArray(mentionNames) ? mentionNames : []).map((name) => String(name || '')).filter(Boolean);

    // ชื่อยาวก่อน กันชื่อที่เป็นคำนำหน้าของอีกชื่อหนึ่งมาบังกัน
    const sorted = [...names, 'all'].sort((a, b) => b.length - a.length);
    const segments = [];
    let cursor = 0;

    while (cursor < text.length) {
        const marker = sorted.find((name) => text.startsWith(`@${name}`, cursor)
            && isMentionBoundary(text, cursor, cursor + name.length + 1));

        if (marker) {
            segments.push({text: `@${marker}`, mention: true});
            cursor += marker.length + 1;
            continue;
        }

        const nextAt = text.indexOf('@', cursor + 1);
        const end = nextAt === -1 ? text.length : nextAt;
        segments.push({text: text.slice(cursor, end), mention: false});
        cursor = end;
    }

    return segments;
}
