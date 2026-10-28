/**
 * ฟอร์มแจ้งปัญหาสาธารณะ /support (tickets/public/create.blade.php) — 2 ขั้น
 *
 * ประกอบโมดูลเดิมโดยไม่มีตรรกะเพิ่ม:
 *   - tickets/form.js     สาขา "อื่น ๆ" · เบอร์โทรตัวเลขล้วน · รูปภาพ (ตัวอย่าง ลบทีละรูป เพดาน) · สถานะกำลังส่งและกันกดซ้ำ
 *   - tickets/stepper.js  ขั้นตอน (ถัดไป/ย้อนกลับ ตรวจเฉพาะขั้นปัจจุบัน ข้อผิดพลาดใต้ช่อง เปิดขั้นที่ server ตีกลับ)
 *   - components/help-dialog.js  dialog "วิธีหาเลข AnyDesk"
 *   - components/select-dropdown.js  แผนกและสาขาเปิดแบบสไลด์ โดยใช้ select เดิมส่งค่า
 * ทุกตัวผูกได้ครั้งเดียว เรียกซ้ำจึงไม่เกิด listener ซ้ำ · ไม่มี polling และไม่เรียก network เอง
 */
import {initHelpDialogs} from '../../components/help-dialog.js';
import {initSelectDropdowns} from '../../components/select-dropdown.js';
import {initTicketForm} from './form.js';
import {initTicketStepper} from './stepper.js';

export function initPublicTicketForm(root = document) {
    initTicketForm(root);
    initHelpDialogs(root);
    initSelectDropdowns(root, '#ticket-department, #ticket-location');

    return initTicketStepper(root);
}

if (typeof document !== 'undefined') initPublicTicketForm(document);
