import {useDatePickers} from '../../components/date-picker.js';
import {initSelectDropdowns} from '../../components/select-dropdown.js';
// กล่องรายชื่องานย่อยของการ์ดสรุปใต้ปฏิทิน ใช้ตัวเดียวกับตารางรายงาน
import '../../components/subtask-modal.js';
import '../../mytasks-notion.js';
import '../../mytasks-task-modal.js';
import '../../mytasks-views.js';
import '../../mytasks-management.js';
import '../../mytasks-project-board.js';
import './table-kanban.js';
import './kanban-card-limit.js';
import './task-timeline.js';
import './table-controls.js';
import './admin-assignment-markers.js';
import './calendar.js';
import './task-request.js';
import './user-task-create.js';
import './task-details.js';
import './task-share.js';

// ช่องวันที่ทุกช่องที่ประกาศ data-date-picker ใช้ปฏิทินของระบบแทนของเบราว์เซอร์
useDatePickers();
initSelectDropdowns(document, '[data-board-status-filter] select');
