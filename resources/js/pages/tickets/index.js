/**
 * หน้า /tickets — หน้าผู้แจ้ง (tickets/landing), คิวงานของทีม IT (tickets/index) และรายการแผนก (tickets/department)
 *
 * - modal แจ้งปัญหา: ขั้นตอน (stepper.js) + สาขา/รูปภาพ/กันกดส่งซ้ำ (form.js เริ่มตัวเองตอนโหลด)
 * - คิวงาน: ตัวกรองส่งเองเมื่อเปลี่ยนค่า ด้วย component เดิม การกรองและแบ่งหน้าทำที่ server (TicketQueryService)
 *   ปุ่ม "ตัวกรอง" เปิด/พับแผงสาขา/ขอบเขต (components/ticket-filter-toggle.js ค้นหาอยู่นอกแผง ใช้ได้เสมอ)
 * - ข้อมูลล่าสุดแบบไม่รีเฟรชหน้า, modal ดูรายละเอียด และรับงาน/เข้าร่วมจากคิว: desk.js
 *   (คิวงานของ Handler/admin และรายการแผนกของหัวหน้าแผนก)
 * - การ์ดสถานะและ modal อ่านอย่างเดียวของผู้แจ้ง: reporter.js
 */
import {initAutoSubmitFilters} from '../../components/auto-submit-filter.js';
import {initTicketFilterToggle} from '../../components/ticket-filter-toggle.js';
import {initTicketDesk} from './desk.js';
import './form.js';
import {initReporterPage} from './reporter.js';
import {initTicketStepper} from './stepper.js';

export {initTicketFilterToggle};

export function initTicketList(root = document) {
    const page = root.querySelector('[data-ticket-list]');
    if (! page) return false;

    // initAutoSubmitFilters ผูกได้ครั้งเดียวต่อฟอร์มอยู่แล้ว (data-auto-submit-ready)
    initAutoSubmitFilters(page);

    return true;
}

if (typeof document !== 'undefined') {
    initTicketList(document);
    initTicketFilterToggle(document);
    initTicketDesk(document);
    initReporterPage(document);
    initTicketStepper(document);
}
