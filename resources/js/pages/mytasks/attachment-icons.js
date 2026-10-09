/**
 * ไอคอนและโทนสีตามชนิดไฟล์แนบ — แหล่งความจริงเดียวของฝั่งหน้าจอ
 *
 * allow-list ชุดเดียวกับ App\Support\AttachmentPolicy
 * ที่นี่ทำหน้าที่แค่ "บอกชนิดไฟล์ให้ผู้ใช้เห็นเร็ว" การบังคับจริงยังอยู่ที่ server เสมอ
 *
 * เดิมตารางนี้อยู่ใน mytasks-task-modal.js เท่านั้น การ์ดไฟล์แนบจึงมีไอคอนตามชนิดไฟล์
 * ขณะที่กล่องอื่นที่แสดงไฟล์ชุดเดียวกันต้องใช้ไอคอนกระดาษเปล่าเหมือนกันหมด
 * หรือไม่ก็ต้องคัดลอกตารางไปอีกชุดแล้วหลุดจากกันเมื่อ AttachmentPolicy เปลี่ยน
 */
export const FILE_ICONS = {
    jpg: 'bi-file-earmark-image', jpeg: 'bi-file-earmark-image', png: 'bi-file-earmark-image',
    pdf: 'bi-file-earmark-pdf',
    doc: 'bi-file-earmark-word', docx: 'bi-file-earmark-word',
    xls: 'bi-file-earmark-excel', xlsx: 'bi-file-earmark-excel',
    ppt: 'bi-file-earmark-ppt', pptx: 'bi-file-earmark-ppt',
    zip: 'bi-file-earmark-zip',
};

export const extensionOf = (name) => String(name ?? '').split('.').pop().toLowerCase();

export const iconFor = (name) => FILE_ICONS[extensionOf(name)] || 'bi-file-earmark';

export const toneFor = (name) => FILE_ICONS[extensionOf(name)]?.replace('bi-file-earmark-', '') || 'other';
