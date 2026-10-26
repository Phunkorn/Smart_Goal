# Smart Ticket — Implementation Plan (ฉบับปรับปรุงรอบ 2)

> สถานะ: แผนได้รับอนุมัติแล้ว · Phase 1–5 ผ่านการตรวจรับ · Phase 6 (Step 1–10) ดำเนินการแล้ว · ผลประเมิน READY WITH CONDITIONS รออนุมัติ Production Release
> ทำทีละ Phase → ทดสอบ → สรุปผล → รออนุมัติ Phase ถัดไป · ไม่ commit / push / deploy / migrate ฐานจริงเอง

## Context

Smart Goals ต้องการระบบแจ้งปัญหา IT (Helpdesk) ที่ใช้ users / departments เดิม รับแจ้งได้ทั้งผู้ใช้ที่ login และบุคคลภายนอก ให้ทีม IT หลายคนช่วยกันทำ Ticket เดียวกันได้ แจ้งเตือนผ่านระบบเดิม (in-app + Telegram + เสียง) และมีรายงาน KPI โดยไม่กระทบ Task / Project / Meeting เดิม และต้องใช้งานบนมือถือได้ดีเป็นหลัก

### การตัดสินใจทางธุรกิจที่ยืนยันแล้ว
| เรื่อง | ข้อสรุป |
|---|---|
| สิทธิ์เจ้าหน้าที่ IT (agent) | Admin เลือก "แผนกที่รับผิดชอบ" → สมาชิก active ทุกคนเป็น agent อัตโนมัติ; Admin ปิดรายคน หรือเพิ่มคนจากแผนกอื่นได้ |
| สาขา/สถานที่ | Admin จัดการรายการสาขา + ตัวเลือก "อื่น ๆ" พิมพ์เอง |
| เปิดงานที่ปิดแล้วใหม่ | agent เท่านั้น พร้อมเหตุผลบังคับ ประวัติเดิมคงอยู่ |
| หัวหน้าแผนก | เห็น Ticket ที่สมาชิกแผนกตนแจ้ง แบบอ่านอย่างเดียว ไม่เห็นแผนกอื่น |

### สิ่งที่ปรับปรุงในรอบนี้ (ตาม review 10 ข้อ)
| # | ประเด็น | การเปลี่ยนแปลงในแผน |
|---|---|---|
| 1 | การมองเห็นตามบทบาท | `TicketQueryService::visibleTo()` เป็นแหล่งเดียวของ scope รายการ และ `TicketPolicy::view()` ใช้กติกาเดียวกัน (§5) · ทดสอบทั้ง List และ Detail ทุกบทบาท รวมหัวหน้าแผนก A เปิด Ticket แผนก B → ไม่อยู่ในรายการ + 403 |
| 2 | ต้องรับงานก่อนปิด | ตัด `new → closed` ออก · `first_responded_at` ตั้งครั้งเดียวตอน `new → active` และไม่ถูกเขียนทับเมื่อ reopen · join ไม่เปลี่ยนสถานะ ไม่มี exclusive lock (§4) |
| 3 | Snapshot | เพิ่ม `requester_department_name` และ `location_name` ณ เวลาสร้าง · ตัด `location_other` ออก (ชื่อที่พิมพ์เองเก็บใน `location_name` ช่องเดียว `location_id = null`) · รายงานใช้ snapshot (§3) |
| 4 | FK / Delete behavior | พบว่า `trash:purge-expired` **force-delete ผู้ใช้จริง** หลังพ้นระยะเก็บ → FK ไปที่ users ทุกตัวเป็น `nullOnDelete` + snapshot ชื่อบนแถว (`user_name`) · FK ไปที่ `tickets` เป็น `restrictOnDelete` (ไม่มีฟีเจอร์ลบ Ticket ประวัติจึงหายเงียบ ๆ ไม่ได้) (§3) |
| 5 | เสียงจากทุกหน้า | ย้ายเป็น `resources/js/components/ticket-alert.js` โหลดใน layout เฉพาะ agent · ฟัง event ของ `realtime-sync.js` เดิม ไม่มี polling ใหม่ · Web Locks + localStorage กันซ้ำข้ามแท็บ · ปลดล็อก audio ด้วย user gesture (§8) |
| 6 | Public form | บังคับ 3 ช่อง: ชื่อผู้แจ้ง, สาขา, รายละเอียด · ช่องทางติดต่อ + รูป optional · **ตัดช่อง "หัวข้อ" ออก** ใช้บรรทัดแรกของรายละเอียดเป็นหัวการ์ด (accessor ไม่เก็บซ้ำ) · มาตรการ spam + upload ครบ (§6, Phase 2) |
| 7 | Policy ↔ Route ↔ Controller ↔ Sidebar | ตารางจับคู่ครบทุก route (§5.3) · เมนูใช้ ability เดียวกับ route · test ยิง URL ตรงโดยไม่ผ่านเมนู และ test ว่าเมนูแสดงตรงกับ policy ทุกบทบาท |
| 8 | การตั้งชื่อ | ปรับตามแบบแผนของ repo: `requester_id`, `coordinator_id`, `follow_up_date`, `PublicTicketController`, `TicketNotificationService`, `TicketAgentAccess`, รายงานอยู่ใต้ `reports.tickets` + `pages/reports/tickets.*`, `report-tickets.css`, `admin-tickets.css`, `components/ticket-alert.js` (§7, §12) |
| 9 | Mobile First | เปลี่ยนรายการจากตาราง→การ์ด เป็น **การ์ดล้วนทุกขนาดจอ** (grid บน desktop) · **ตัด sticky action bar ออก** ปุ่มหลักอยู่ในการ์ด "การดำเนินการ" ต่อจากหัว Ticket · ห้าม `overflow:hidden`/line-clamp/`display:none` กับข้อมูลการ์ด พร้อม contract test + วัด `scrollWidth` ในเบราว์เซอร์จริง (§6) |
| 10 | Test baseline | ขั้นตอนบังคับก่อน Phase 1: วัด baseline ที่ HEAD แยกโฟลเดอร์ บันทึกชื่อ test ที่ fail แบบเรียงลำดับ แล้วรายงานทุก Phase เป็น 3 กลุ่ม: existing failure / regression ใหม่ / ผ่านขึ้นมาเอง · ห้ามแก้ test เพื่อให้ผ่าน (§10) |

---

## 1. Existing Codebase Findings

**กฎโปรเจกต์** — `CLAUDE.md` + `AGENTS.md`: Support = กติกาไร้สถานะ, Services = orchestration, Policy = บังคับสิทธิ์จริง, viewer ห้ามเป็นผู้รับงาน/ผู้รับแจ้งเตือน, ไม่มี admin bypass ถ้าธุรกิจไม่ระบุ, CSS ผ่าน entry ของหน้า + namespace, ห้าม `<style>`/JS ก้อนใหญ่ใน Blade, ห้าม `alert()/confirm()` ใช้ `window.Swal`, migration ห้ามอ้าง Model constant และ `down()` ที่ทำลายข้อมูลต้อง throw

**ผู้ใช้ / แผนก / สิทธิ์**
- `users.role` = `admin | user | viewer`; หัวหน้าแผนก = `role=user` + `is_department_head` → ใช้ `User::isDepartmentHead()` / `overseesDepartment()` เสมอ
- `User` ใช้ SoftDeletes และ **`App\Support\TrashRetention::forceDeleteEntity()` (คำสั่ง `trash:purge-expired` ทุกวัน 02:30) ลบผู้ใช้ถาวรเมื่อพ้นระยะเก็บ** → FK แบบ cascade จะลบประวัติ, แบบ restrict จะทำให้ purge ล้ม
- แบบอย่างที่ใกล้ที่สุด = **Computer Check**: `departments.computer_check_enabled` + `App\Support\ComputerCheckAccess` + `ComputerCheckSessionPolicy` + `DepartmentController::updateComputerCheckAccess()` (lockForUpdate + `AuditTrail::log` เมื่อค่าเปลี่ยน) + snapshot `department_id` บนรอบตรวจ
- `task_visibility_scope` / `DepartmentTaskVisibility` เป็นของ Task — ไม่นำมาใช้

**Attachments / Media** — `AttachmentPolicy` (จับคู่นามสกุล↔MIME จากเนื้อไฟล์; เพดานปัจจุบัน 1 GB / 20 ไฟล์ ไม่มีชุด "รูปเท่านั้น"), `ValidatesAttachments::collectStoredAttachments()`, `ProtectedMedia::storeAttachment()` (disk `local` private, ชื่อไฟล์สุ่มจาก `store()`), `MediaController::attachmentResponse()` + `Gate::authorize('view', $parent)`; route typed ต้องอยู่ก่อน catch-all `/media/{path}`

**Notification** — `NotificationService::create()` จุดเดียว → `system_notifications` (`data` JSON, `category`, unique `(user_id, dedupe_key)`); งานนอก WorkOrder ใช้ `notifyDetached()` (กรอง inactive/viewer/actor ให้แล้ว); category ใหม่ต้องแก้ ~8 จุด (`SystemNotification::TYPE_CATEGORIES`, `NotificationService::target()/targetUnavailable()/paginate()`, `NotificationController::index`, `notifications/index.blade.php`, `notifications/components/category-icon.blade.php`, `realtime-sync.js`, `TelegramMessageComposer::CATEGORY_ICONS`, CSS `layout/shared-ui.css` + `pages/notifications.css`)

**Telegram** — `App\Services\Telegram\TelegramOutbox` (scoped) + ตาราง `telegram_messages`; `create()` enqueue อัตโนมัติเมื่อแถวเพิ่งถูกสร้าง (dedupe_key กันส่งซ้ำ), enqueue อยู่ใน try/catch, ส่ง HTTP ใน `app()->terminating()` หลังตอบ response; chat id รายบุคคลเท่านั้น

**Realtime** — `components/realtime-sync.js` poll `GET /realtime/sync` ทุก 3 วิ, cursor เริ่มที่ `latestId` (ไม่ส่งของเก่าซ้ำตอน refresh), **หยุดเมื่อแท็บถูกซ่อน**, ไม่มีการประสานหลายแท็บ, ยิง `smartgoal:realtime-notification` ต่อ event ใหม่; ยังไม่มีโค้ดเสียงในโปรเจกต์

**Frontend** — layout เดียว `layouts/app.blade.php` (sidebar inline แยก branch ตาม role, ลำดับเมนูถูกทดสอบโดย `SidebarNavigationTest`, render สำหรับ guest ไม่ได้ → หน้า public ต้อง standalone แบบ `auth/login.blade.php`); tokens `foundations/tokens.css`; shared `.panel`, `.badge-soft.*`, `.btn-accent`, `.page-head`; entry + โฟลเดอร์แบบ `pages/announcements.css`; components `auto-submit-filter.js`, `period-range.js`, `date-picker.js`, `select-dropdown.js`; report ใช้ `ReportPeriod` + `pages/reports/chart-lifecycle.js` + `chart-config.js`; nav รายงานอยู่ใน `layouts/partials/report-nav.blade.php`

**อื่น ๆ** — ยังไม่มี `app/Http/Requests` (Ticket จะเป็นชุดแรกตาม spec), rate limiter แบบข้อความไทยใน `AppServiceProvider`, `TodayWorkspace::BUSINESS_TIMEZONE`, `ThaiDate`, คอลัมน์วันที่ใน repo ใช้รูปแบบ `*_date`; **test baseline แดงอยู่แล้วที่ HEAD**

---

## 2. Proposed Architecture

```
HTTP (บางที่สุด)                     Domain                                    Shared (reuse)
TicketController ─────────┐   TicketSubmissionService   สร้าง + เลข + snapshot + ไฟล์ + activity
PublicTicketController ───┤   TicketWorkflowService      transition() ทางเดียวที่เปลี่ยน status
TicketParticipantController┼─► TicketParticipantService  join / leave / coordinator
TicketStatusController ───┤   TicketQueryService         visibleTo() + filter + paginate + eager-load
TicketNoteController ─────┤   TicketNotificationService  → NotificationService::notifyDetached()
TicketReportController ───┤   TicketReportService        KPI ด้วย ReportPeriod
TicketSettingsController ─┘
             │
      TicketPolicy (ทุก ability)  ◄── App\Support\TicketAgentAccess (ใครเป็น agent / ใครรับแจ้งเตือน)
                                      App\Support\TicketDesign (label/tone ของสถานะ — ที่เดียว)
```
- ไม่แตะ `TaskStatusTransitionService`, `WorkBoardDesign`, `job_status`
- mutation ทุกตัวตอบผ่าน `RespondsWithTaskResult::jsonOrBack()`
- แจ้งเตือนส่ง **หลัง commit** ห่อ try/catch + `report()` — การแจ้งเตือนล้มไม่ทำให้ Ticket บันทึกไม่ได้

## 3. Database Schema & Relationships

ทุก migration ใหม่ (ไม่แก้ของเก่า, ไม่ import Model constant, ตั้งชื่อ index เองเมื่อยาวเกิน 64 ตัวอักษร)

| Migration | คอลัมน์หลัก |
|---|---|
| `create_ticket_locations_table` | `id`, `name` (150, unique), `is_active` (default true), `sort_order`, timestamps — ไม่มีการลบ มีแต่ปิดใช้งาน |
| `create_tickets_table` | `id`, `ticket_number` (20, unique), `source` (10: `internal`/`public`), `description` (text), `status` (20, default `new`), `requester_id` (→users **nullOnDelete**), `requester_name` (150, snapshot), `requester_department_id` (→departments nullOnDelete), `requester_department_name` (150, nullable, snapshot), `contact` (150, nullable), `location_id` (→ticket_locations nullOnDelete, null = "อื่น ๆ"), `location_name` (150, snapshot หรือข้อความที่พิมพ์เอง), `coordinator_id` (→users nullOnDelete), `follow_up_date` (date, nullable), `first_responded_at`, `closed_at`, `closed_by` (→users nullOnDelete), `reopen_count` (unsigned smallint, default 0), `submitter_ip` (45, nullable), timestamps · index `(status, created_at)`, `requester_id`, `requester_department_id`, `location_id` |
| `create_ticket_participants_table` | `id`, `ticket_id` (→tickets **restrictOnDelete**), `user_id` (→users nullOnDelete, nullable), `user_name` (150, snapshot), `joined_at`, `left_at` (nullable), timestamps · **unique `(ticket_id, user_id)`** |
| `create_ticket_activities_table` | `id`, `ticket_id` (restrict), `user_id` (nullOnDelete, null = ผู้แจ้งภายนอก/ระบบ), `user_name` (150, snapshot), `type` (30), `from_status`, `to_status` (20, nullable), `body` (text, nullable), `data` (json, nullable), `created_at` · index `(ticket_id, created_at)` — append-only |
| `create_ticket_attachments_table` | `id`, `ticket_id` (restrict), `ticket_activity_id` (→ticket_activities nullOnDelete, รูปที่แนบกับบันทึก), `file_path`, `original_name`, `file_type`, `file_size`, `uploaded_by` (nullOnDelete), timestamps |
| `create_ticket_agents_table` | `id`, `user_id` (→users **cascade** — เป็นค่าตั้งค่า ไม่ใช่ประวัติ), `is_enabled`, `receives_alerts` (default true), `updated_by` (nullOnDelete), timestamps · unique `user_id` |
| `add_ticket_responsible_to_departments_table` | `departments.ticket_responsible` (bool, default false) |

**Snapshot ที่เก็บ (และที่ตั้งใจไม่เก็บ)**
- เก็บ: `requester_name`, `requester_department_name`, `location_name` บน tickets และ `user_name` บน participants/activities — เพราะต้นทางเปลี่ยนชื่อ/ถูกลบได้ และรายงานย้อนหลังต้องคงค่าเดิม
- ไม่เก็บ: ชื่อ coordinator / ผู้ปิด (อ่านจาก participant / activity snapshot ที่มีอยู่แล้ว), ไม่ copy ข้อมูลผู้ใช้อื่นใด
- `location_other` ถูกรวมเข้า `location_name` — ไม่มีสองช่องที่ความหมายซ้อนกัน

**Delete / deactivate behavior**
| เหตุการณ์ | ผล |
|---|---|
| ผู้ใช้ถูก soft-delete | แถวทั้งหมดอยู่ครบ relation ใช้ `withTrashed()` ชื่อจึงยังแสดง |
| ผู้ใช้ถูก force-delete (trash purge) | `user_id` → null, `user_name` snapshot คงอยู่, KPI รายคนยังแสดงชื่อเดิม, purge ไม่ล้ม |
| ผู้ใช้ถูกปิดบัญชี (`is_active=false`) | ไม่แตะประวัติ; หลุดจากการเป็น agent/ผู้รับแจ้งเตือน, ทำ action ไม่ได้, แสดง "(ปิดใช้งาน)" |
| แผนก/สาขาถูกลบหรือเปลี่ยนชื่อ | FK → null หรือคงไว้, ชื่อ snapshot คงเดิม |
| ลบ Ticket | ไม่มีฟีเจอร์นี้; FK restrict กันการลบที่ทำให้ประวัติลูกหาย |

**กติกา agent** (`TicketAgentAccess`): active + ไม่ใช่ viewer แล้ว → มีแถว `ticket_agents` ใช้ `is_enabled` / ไม่มีแถว ใช้ `department.ticket_responsible`; migration ตั้ง false ทุกแผนก (ไม่ hardcode IT) ระหว่างยังไม่ตั้ง Ticket ใหม่แจ้ง admin แทน
**Ticket number**: insert แล้วตั้ง `TK-` + id เติมศูนย์ 6 หลัก ในทรานแซกชันเดียว — ไม่ชน หมายเลขไม่ให้สิทธิ์เข้าถึงใด ๆ
**Rollback**: `down()` ของตารางข้อมูล throw เมื่อมีแถว; คอลัมน์ `ticket_responsible` throw เมื่อมีแผนกถูกตั้งเป็นผู้รับผิดชอบแล้ว ไม่งั้น drop ได้
**ลิงก์ติดตามสถานะ (อนาคต)**: migration `add_tracking_token_to_tickets_table` (เก็บ hash) + `/support/track/{token}` — โครงสร้างปัจจุบันไม่ขวาง

## 4. Ticket Workflow

```
  new ──start──► active ──follow_up──► follow_up
                   ▲  ◄────resume─────     │
                   │                       │
                   │  close         close  │
                   │    ▼             ▼    │
                   └─reopen── closed ◄─────┘
```
| Action | จาก → ไป | เงื่อนไข | บันทึก |
|---|---|---|---|
| `start` (ปุ่ม "รับงาน") | new → active | agent; ถ้ายังไม่ได้เข้าร่วม ระบบเพิ่มเป็นผู้เข้าร่วมก่อนในทรานแซกชันเดียว | `first_responded_at` (ครั้งเดียว), activity `joined` + `status_changed` |
| `follow_up` | active → follow_up | ผู้เข้าร่วมปัจจุบัน; เหตุผลบังคับ, `follow_up_date` optional | activity พร้อมเหตุผล |
| `resume` | follow_up → active | ผู้เข้าร่วมปัจจุบัน | ล้าง `follow_up_date` |
| `close` | active / follow_up → closed | ผู้เข้าร่วมปัจจุบัน; ผลการแก้ไขบังคับ | `closed_at`, `closed_by` |
| `reopen` | closed → active | agent; เหตุผลบังคับ | ล้าง `closed_at/closed_by`, +1 `reopen_count`, **ไม่แตะ `first_responded_at`** |

- **ไม่มี `new → closed`** — ทุก Ticket ต้องถูกรับก่อนปิด (Ticket ซ้ำ/สแปมก็รับแล้วปิดพร้อมผล "ซ้ำ/สแปม")
- `TicketWorkflowService::transition()` เป็นทางเดียวที่เปลี่ยน `status`: lockForUpdate → ตรวจตารางข้างบน → **ตรวจ `expected_status` ที่ client ส่งมา** (สองคนกดพร้อมกัน → คนหลังได้ 409 "สถานะเปลี่ยนแล้ว กรุณารีเฟรช")
- **Join แยกจาก status**: agent เข้าร่วมได้ทุกสถานะที่ยังไม่ปิด การ join ไม่เปลี่ยนสถานะ (รวมถึงบน `new`) ไม่มีผู้ถือ Ticket แต่เพียงผู้เดียว ไม่มีการล็อก ใครเข้าร่วมก็ทำ action ได้เท่ากัน; ออกได้โดยแถวยังอยู่ (`left_at`), เข้าใหม่ได้ (ล้าง `left_at`); ถ้าผู้ประสานงานออก `coordinator_id` ถูกล้าง
- Ticket ที่ปิดแล้ว join/leave/note ไม่ได้ จนกว่าจะ reopen

## 5. Access Control Design

### 5.1 การมองเห็น (List = Detail เสมอ)
`TicketQueryService::visibleTo(User)` สร้าง scope รายการ และ `TicketPolicy::view()` ใช้กติกาเดียวกันแบบรายใบ — test ยืนยันว่าสองทางให้คำตอบตรงกัน

| บทบาท | เห็นในรายการ + เปิด Detail ได้ | ทำ action ได้ |
|---|---|---|
| viewer / inactive | ไม่มี (403) | ไม่มี |
| พนักงาน (`user`) | Ticket ที่ตนแจ้ง (`requester_id`) | เพิ่มบันทึกใน Ticket ของตนที่ยังไม่ปิด |
| หัวหน้าแผนก | ของตน + `requester_department_id` = แผนกตน (snapshot ตอนแจ้ง) — **ไม่เห็นแผนกอื่น และไม่เห็น public ticket** | อ่านอย่างเดียว (บันทึกได้เฉพาะ Ticket ที่ตนแจ้งเอง) |
| agent | ทั้งหมด | ตาม §4 |
| admin (ไม่ใช่ agent) | ทั้งหมด | อ่าน + ตั้งค่า + รายงาน; รับ/ปิดงานต้องถูกตั้งเป็น agent ก่อน (ไม่มี bypass) |

### 5.2 Abilities ของ `TicketPolicy`
`viewAny` (active admin/user) · `view` · `create` (admin/user) · `join` · `leave` · `setCoordinator` · `start` · `followUp` · `resume` · `close` · `reopen` · `addNote` · `viewReport` (admin, agent) · `manageSettings` (admin)
- ฟิลด์ผู้แจ้ง/แผนกของ Ticket ภายในมาจาก `auth()->user()` ฝั่ง server เท่านั้น — Form Request ไม่รับฟิลด์เหล่านี้
- `$fillable` ไม่มี `status`, `requester_*`, `ticket_number`, `closed_*` — ตั้งใน service เท่านั้น

### 5.3 Route ↔ Middleware ↔ Policy ↔ Controller ↔ Sidebar
ทุก route ในกลุ่ม `auth, active, password.changed` + ชั้นแรก `role:admin,user`; **การตัดสินจริงคือ `authorize()` ใน controller** — เมนูเพียงแสดงตาม ability เดียวกัน

| Route (name) | Method + URI | Ability | Controller@action | เมนู |
|---|---|---|---|---|
| `tickets.index` | GET `/tickets` | `viewAny` + scope `visibleTo` | TicketController@index | "แจ้งปัญหา IT" `@can('viewAny', Ticket::class)` |
| `tickets.create` / `tickets.store` | GET `/tickets/create`, POST `/tickets` | `create` | TicketController@create/store | ปุ่มในหน้า |
| `tickets.show` | GET `/tickets/{ticket}` | `view` | TicketController@show | — |
| `tickets.participants.store` / `.destroy` | POST / DELETE `/tickets/{ticket}/participants` | `join` / `leave` | TicketParticipantController | — |
| `tickets.coordinator.update` | PATCH `/tickets/{ticket}/coordinator` | `setCoordinator` | TicketParticipantController@coordinator | — |
| `tickets.status.update` | PATCH `/tickets/{ticket}/status` | ability ตาม action (`start/followUp/resume/close/reopen`) | TicketStatusController | — |
| `tickets.notes.store` | POST `/tickets/{ticket}/notes` | `addNote` | TicketNoteController | — |
| `reports.tickets` | GET `/reports/tickets` | `viewReport` | TicketReportController | ใน `report-nav` `@can('viewReport', Ticket::class)` |
| `admin.tickets.*` | GET/PATCH/POST `/admin/tickets/...` | `manageSettings` (+ middleware `admin`) | TicketSettingsController | กลุ่ม admin `@can('manageSettings', Ticket::class)` |
| `media.ticket-attachments.show` | GET `/media/ticket-attachments/{attachment}` | `view` ของ Ticket แม่ | MediaController@ticketAttachment | — |
| `support.create` / `support.store` / `support.submitted` | GET/POST `/support`, GET `/support/submitted` | สาธารณะ (นอกกลุ่ม auth) + `throttle:ticket-public` | PublicTicketController | ลิงก์จากหน้า login (optional) |

## 6. UI/UX & Mobile Responsive Approach

- โทนเดิม: พื้นขาว, `--accent` ฟ้า, ไม่มี gradient, ฟอนต์ Prompt, `--radius`/`--radius-sm`, ปุ่ม ≥ 42px (`docs/UI_DESIGN_SYSTEM.md`)
- **รายการ = การ์ดทุกขนาดจอ** (markup ชุดเดียว): 1 คอลัมน์บนมือถือ → CSS grid `repeat(auto-fill, minmax(320px, 1fr))` บนจอใหญ่ · การ์ดแสดง เลข Ticket, หัวการ์ด (บรรทัดแรกของรายละเอียด ตัดฝั่ง server ที่ 120 ตัวอักษรพร้อม "…" — รายละเอียดเต็มอยู่หน้า Detail), ผู้แจ้ง/แผนก/สาขา, สถานะ (ข้อความ + สีจาก `TicketDesign`), วันที่แจ้ง (เวลากรุงเทพ), ชื่อผู้เข้าร่วมทุกคนแบบตัดบรรทัดได้, ปุ่มเปิด
- กติกาการ์ด: สูงตามเนื้อหา, `overflow-wrap: anywhere` สำหรับข้อความยาว, **ไม่มี** `overflow:hidden` บน container/การ์ด, ไม่มี line-clamp, ไม่มี `display:none` กับฟิลด์ข้อมูลที่ breakpoint ใด, ไม่มี fixed width, `min-width: 0` ใน grid item
- Filter (สถานะ, ค้นหา, สาขา, "ที่ฉันเข้าร่วม" สำหรับ agent, "ของแผนก" สำหรับหัวหน้า) เป็น GET form + `auto-submit-filter.js`; `paginate(20)->withQueryString()` คง filter ทุกหน้า
- **หน้ารายละเอียด**: ลำดับบนมือถือ = หัว Ticket → **การ์ด "การดำเนินการ"** (ปุ่มเต็มความกว้าง กดง่าย) → รายละเอียด + รูป → ผู้เข้าร่วม → timeline → ฟอร์มบันทึก · Desktop 2 คอลัมน์ (เนื้อหา | ข้อมูล + การดำเนินการ) จาก markup เดียว · **ไม่มี sticky/fixed action bar**
- Follow-up / Close / Reopen = Bootstrap modal ที่มีฟอร์มจริง (submit แบบ form ทำงานได้แม้ JS ล้ม)
- **Public form**: standalone แบบหน้า login, คอลัมน์เดียว, ช่องบังคับ 3 ช่องมาก่อน (ชื่อ `autocomplete="name"`, สาขา dropdown → "อื่น ๆ" เปิดช่องพิมพ์, รายละเอียด) ตามด้วยช่อง optional ใต้หัว "ไม่บังคับ" (ช่องทางติดต่อ, รูปภาพ ≤ 3 เลือกจากกล้องหรือคลังได้), ข้อความ error ใต้ช่อง, ปุ่มส่งเต็มความกว้าง · หน้ายืนยันแสดงเลข Ticket จาก session flash
- State: empty / loading (ปุ่ม disabled + spinner, กัน double submit) / error / success (Swal จาก JSON island)
- Viewport ตรวจ: 320, 375, 390, 768, 1024, 1440

## 7. CSS / JavaScript File Structure

```
resources/css/pages/
├── tickets.css            entry: list, detail, ฟอร์มภายใน
├── tickets-public.css     entry: หน้า public (import foundations/tokens + typography + tickets/form + tickets/public)
├── report-tickets.css     entry: รายงาน (ตามแบบ report-operational.css / report-projects.css)
├── admin-tickets.css      entry: หน้าตั้งค่า admin (ตามแบบ admin-departments.css)
├── tickets/
│   ├── base.css           .ticket-page namespace, status pill, ชื่อผู้เข้าร่วม
│   ├── list.css           การ์ด + grid
│   ├── detail.css         detail, การ์ดการดำเนินการ, timeline
│   ├── form.css           ฟอร์ม + photo picker (ใช้ร่วม internal/public; ไม่ถูกโหลดซ้ำในหน้าเดียวกัน)
│   ├── public.css         layout หน้า public
│   └── responsive.css     media queries ของ tickets.css (import ท้ายสุด)
├── reports/tickets.css    KPI ของ Ticket (คู่กับ reports/shared.css เดิม)
└── admin/tickets.css      หน้าตั้งค่า

resources/js/
├── components/ticket-alert.js   เสียงแจ้งเตือน — โหลดใน layout ทุกหน้าเฉพาะ agent
└── pages/
    ├── tickets/index.js         init filter
    ├── tickets/detail.js        Swal ยืนยันออกจาก Ticket, modal, กัน double submit
    ├── tickets/form.js          photo picker + preview + ตรวจชนิด/ขนาดฝั่ง client (เพดานจาก data-attribute)
    ├── reports/tickets.js       กราฟผ่าน chart-lifecycle.js (≤ 2 กราฟ)
    └── admin/tickets.js         toggle แผนก / agent / สาขา
```
- สีสถานะใช้ `.badge-soft.{blue,amber,green,gray}` เดิม — ไม่ประกาศสีใหม่; ไม่มี `!important`, `<style>`, inline style
- ลงทะเบียนทุก entry ใน `vite.config.js` (มี test ตรวจ `@vite` ↔ config)

## 8. Notification Integration

| เหตุการณ์ | type | ผู้รับ | dedupe |
|---|---|---|---|
| Ticket ใหม่ | `ticket_created` | agent ที่ `receives_alerts` (ยังไม่มี agent → admin) | `ticket-created:{id}` |
| เปลี่ยนสถานะ | `ticket_status_changed` | ผู้แจ้ง (ภายใน) + ผู้เข้าร่วมปัจจุบัน ยกเว้นผู้กด | `ticket-status:{activity_id}` |
| บันทึกใหม่ | `ticket_note_added` | ผู้เข้าร่วมปัจจุบัน + ผู้แจ้ง ยกเว้นผู้เขียน | `ticket-note:{activity_id}` |

- **In-app**: category `ticket` ครบ ~8 จุด; `target()` → `tickets.show` เมื่อผู้รับยัง `view` ได้ ไม่งั้น `targetUnavailable()`
- **Telegram**: ได้อัตโนมัติจาก `create()` — เพิ่มแค่ไอคอนใน `TelegramMessageComposer`; dedupe กันส่งซ้ำ; ล้มเหลวไม่กระทบ Ticket
- **เสียง** (`components/ticket-alert.js`)
  - layout ใส่ `@vite` + `data-ticket-alert` บน `<body>` เฉพาะเมื่อ `TicketAgentAccess` ตอบว่าเป็น agent (ฝั่ง server) → เสียงดังได้จาก **ทุกหน้า** ของ Smart Goals
  - ฟัง `smartgoal:realtime-notification` จาก `realtime-sync.js` เดิม เฉพาะ `type === 'ticket_created'` — **ไม่มี polling/request ใหม่**
  - ของเก่าไม่ดัง: cursor ของ realtime เริ่มที่ `latestId` ตอนโหลดหน้า refresh จึงไม่เล่นซ้ำ
  - กันซ้ำหลายแท็บ: `navigator.locks.request('smartgoal-ticket-alert')` ครอบการเช็ก/เขียน `lastPlayedId` ใน localStorage (fallback ไม่มี Web Locks = check-and-set ใน localStorage) และ throttle ไม่เกิน 1 เสียง/3 วินาทีเมื่อมาพร้อมกันหลายใบ
  - Browser autoplay: สร้าง/resume `AudioContext` เมื่อผู้ใช้กด/แตะครั้งแรกในหน้า; ยังไม่ได้ปลดล็อก = ไม่ดัง แต่ toast เดิมยังขึ้น ไม่มี error
  - เสียงสร้างด้วย Web Audio (ไม่ต้องมีไฟล์เสียง); สวิตช์เปิด/ปิดอยู่หัวหน้า Ticket เก็บใน localStorage (ค่าส่วนตัวต่อเบราว์เซอร์ ห่อ try/catch)
  - ข้อจำกัดแท็บซ่อน: realtime หยุด poll เมื่อแท็บถูกซ่อน — เสนอ opt-in `data-realtime-hidden-interval` (เช่น 20 วิ) ใน `realtime-sync.js` ตัวเดิม ตั้งเฉพาะ agent (ไม่ใช่ polling ชุดที่สอง; ผู้ใช้อื่นทำงานเหมือนเดิม) — **จะแสดง diff ให้อนุมัติก่อนแก้ใน Phase 4**
- **Admin**: toggle `receives_alerts` รายคน

## 9. Phase-by-Phase Implementation

### Phase 0 — Audit & Architecture ✅ (เอกสารนี้)

### Phase 1 — Database & Core Backend
- **ขั้นตอนบังคับก่อนเริ่ม**: `git status --short` + `git diff`; คัดลอกแผนไป `docs/plans/smart-ticket.md`; **วัด baseline ที่ HEAD** ใน scratchpad (`git archive HEAD` + vendor shell แบบไม่ junction ทั้งโฟลเดอร์) บันทึกชื่อ test ที่ fail แบบเรียงลำดับ (PHP + JS)
- **Scope**: schema, models, สิทธิ์, state machine, participants, activities, scope การมองเห็น — ยังไม่มี UI/route
- **Files**: 7 migrations (§3); `app/Models/{Ticket,TicketParticipant,TicketActivity,TicketAttachment,TicketAgent,TicketLocation}.php`; `Department` (+cast, relation), `User` (+relations); `app/Support/{TicketAgentAccess,TicketDesign}.php`; `app/Policies/TicketPolicy.php`; `app/Services/{TicketWorkflowService,TicketParticipantService,TicketQueryService}.php` (เฉพาะ `visibleTo()`); factories
- **Develop**: relationships (`withTrashed()` สำหรับ user), snapshot helper ตอนสร้าง (ใช้ใน factory/test ก่อน Phase 2), ticket number, `transition()` + `expected_status`, join (insertOrIgnore บน unique + ล้าง `left_at` ใต้ lock) / leave / setCoordinator, `visibleTo()`
- **Dependencies**: ไม่มี
- **Tests** (`tests/Feature/Tickets/`):
  - `TicketPolicyTest` — ทุกบทบาท × ทุก ability (viewer, inactive, พนักงาน, หัวหน้าแผนก A/B, agent จากแผนก, agent override, admin ที่ไม่ใช่ agent)
  - `TicketVisibilityTest` — `visibleTo()` ต่อบทบาท และ **ตรงกับ `view()` ทุกใบ** (หัวหน้า A ไม่เห็น B และ public)
  - `TicketAgentAccessTest` — แผนก default / override ปิด / override เปิดข้ามแผนก / inactive / viewer
  - `TicketWorkflowTest` — transition ถูก/ผิดทุกคู่, `new → closed` ถูกปฏิเสธ, stale `expected_status`, `first_responded_at` ตั้งครั้งเดียวและคงเดิมหลัง reopen, `reopen_count`, activity ครบ
  - `TicketParticipantTest` — join ซ้ำ = 1 แถว (รวมกรณีแถวมีอยู่ก่อนเรียก), join ไม่เปลี่ยนสถานะ, agent หลายคนทำ action ได้เท่ากัน, leave แล้ว join ใหม่, ปิดแล้ว join ไม่ได้
  - `TicketHistoryRetentionTest` — force-delete ผู้ใช้ → participants/activities คงอยู่พร้อม `user_name`; ปิดบัญชี → ประวัติคงอยู่; เปลี่ยนชื่อแผนก/สาขา → snapshot คงเดิม; ลบ Ticket ที่มีลูก → ถูก FK กัน
  - Migration `down()` throw เมื่อมีข้อมูล
- **Acceptance**: tests ใหม่ผ่าน; full suite ไม่มี regression เทียบ baseline; `migrate --pretend` ตรวจ SQL (ชื่อ index < 64); Pint ผ่านไฟล์ที่แตะ
- **ไม่แตะ**: routes, views, CSS/JS, Notification, Task/WorkOrder/Meeting ทั้งหมด

#### Phase 1 — สิ่งที่ต่างจากแผนเดิม (ระหว่างลงมือ)
- **ไม่สร้าง factory ใหม่** — repo มี factory เฉพาะ `UserFactory` และเทสต์ทุกไฟล์สร้างข้อมูลด้วย helper ของตัวเอง จึงใช้ trait `tests/Feature/Tickets/BuildsTickets.php` ที่สร้าง Ticket ผ่าน service จริงแทน
- **`TicketSubmissionService` ส่วนแกนถูกสร้างใน Phase 1** (ออกเลข Ticket + snapshot + activity `created`) เพราะเลข Ticket และ snapshot เป็นงานของ Phase 1 · Phase 2 เพิ่ม HTTP, Form Request, ไฟล์แนบ และกันสแปมต่อจากเมธอดเดิม
- **ตาราง transition อยู่ที่ `Ticket::TRANSITIONS`** — ทั้ง `TicketWorkflowService` และ `TicketPolicy` อ่านจากที่เดียว
- **`down()` ของ `add_ticket_responsible_to_departments_table` หยุดเมื่อมีแผนกถูกตั้งเป็นผู้รับผิดชอบแล้ว** (เดิมเขียนว่า drop ได้) ตามกฎ CLAUDE.md เรื่อง down() ที่ทำลายข้อมูล
- Service ตรวจสิทธิ์เองกับแถวที่ล็อกไว้ (`Gate::forUser()->authorize()`) — controller ใน Phase 3 จึงไม่ต้องเขียนการตรวจซ้ำสำหรับ mutation เหล่านี้
- เพิ่ม `App\Exceptions\TicketStatusConflict` (409) และ `TicketCreationTest`, `TicketMigrationRollbackTest`

### Phase 2 — Ticket Submission
- **Files**: `app/Http/Requests/Tickets/{StoreTicketRequest,StorePublicTicketRequest}.php`; `app/Services/TicketSubmissionService.php`; `TicketController@create/store`, `PublicTicketController`; `AttachmentPolicy` (+เมธอดชุดรูป jpg/jpeg/png ≤ 10 MB — ไม่เปลี่ยนเมธอดเดิม); `MediaController::ticketAttachment` + route ก่อน catch-all; rate limiter `ticket-public` ใน `AppServiceProvider`; views `tickets/create.blade.php`, `tickets/public/{create,submitted}.blade.php`, `tickets/components/form-fields.blade.php`; `pages/tickets.css` (base, form), `pages/tickets-public.css`, `pages/tickets/form.js`; `vite.config.js`
- **Develop**: ภายใน — ชื่อ/แผนก/snapshot จากบัญชี; public — บังคับ ชื่อ (≤150), สาขา (รายการ active หรือ "อื่น ๆ" + ข้อความ ≤150), รายละเอียด (10–5000); optional ช่องทางติดต่อ (≤150), รูป ≤ 3
- **Spam**: honeypot, เวลากรอกขั้นต่ำ 3 วินาที (timestamp เข้ารหัสในฟอร์ม), throttle ต่อ IP 5 ครั้ง/10 นาที และ 20 ครั้ง/วัน (ข้อความไทย), CSRF, จำกัดความยาวทุกช่อง
- **Upload**: MIME จากเนื้อไฟล์ จับคู่นามสกุล, `getimagesize()` ยืนยันเป็นรูปจริง + จำกัดขนาดภาพ (กัน decompression bomb), เก็บ private ชื่อสุ่มใต้ `ticket-attachments/{ticket_id}/`, เสิร์ฟผ่าน policy เท่านั้น, ไม่มี path ใน HTML/JSON
- **Tests**: department มาจากบัญชีแม้ส่ง `requester_department_id` ปลอม; snapshot ถูกต้อง; viewer สร้างไม่ได้; public ส่งได้โดยไม่ login ด้วยช่องบังคับ 3 ช่อง; ขาดช่องบังคับ → error; ไม่มี contact/รูป → ผ่าน; honeypot/เร็วเกิน → ไม่สร้าง; 429; ไฟล์ปลอมนามสกุล/เกินขนาด/ไม่ใช่รูป → ปฏิเสธ; ผู้ไม่มีสิทธิ์เปิดรูป → 403; หน้ายืนยันไม่มีข้อมูลเมื่อไม่มี flash; JS test ของ `form.js` (คลิกเลือกรูป → preview → ลบ)
- **Acceptance**: ส่งได้ทั้งสองช่องทาง; build ผ่าน; ตรวจเบราว์เซอร์ 6 viewport
- **ไม่แตะ**: `ValidatesAttachments` / เพดานไฟล์ของงานเดิม

#### Phase 2 — สิ่งที่ต่างจากแผนเดิม (ระหว่างลงมือ)
- **ไม่มี `tickets/responsive.css` ในรอบนี้** — media query ของฟอร์มอยู่ท้าย `tickets/form.css` และของหน้าสาธารณะอยู่ท้าย `tickets/public.css` เพราะทั้งสองไฟล์ถูกใช้โดย entry ต่างกัน (ไฟล์ responsive รวมจะทำให้หน้าสาธารณะโหลดกฎของหน้าภายในที่ไม่ใช้) · Phase 3 สร้าง `tickets/responsive.css` สำหรับ list/detail ตามแผน
- **ปุ่มส่งกำหนดสีใน `tickets/form.css` เอง ไม่ใช้ `.btn-accent`** — `.btn-accent` อยู่ใน `layout.css` ที่หน้าสาธารณะไม่โหลด (พบจากภาพหน้าจอจริง)
- **หลังส่งจากหน้าภายใน กลับมาที่ฟอร์มพร้อม SweetAlert แสดงเลข Ticket** เพราะหน้ารายละเอียดยังไม่มี · Phase 3 เปลี่ยนให้ไปหน้ารายละเอียดของ Ticket
- **เมนู sidebar ยังไม่เพิ่ม** (อยู่ใน Phase 3 ตามแผน) — ฟอร์มภายในเข้าได้ที่ `/tickets/create`
- **หน้ายืนยันสาธารณะเก็บเลขใน session (ไม่ใช่ flash)** เพื่อให้ refresh แล้วยังเห็นเลขเดิม — เป็นเลขของ session ผู้ส่งเองเท่านั้น
- **กันกดส่งซ้ำ**: `submission_key` (UUID ต่อการเปิดฟอร์ม) จองใน cache แบบ atomic 30 นาที แยก scope ต่อบัญชี / ต่อ IP
- **เพดานภาพ**: ด้านละไม่เกิน 10,000 px และรวมไม่เกิน 50 ล้านพิกเซล ตรวจด้วย `getimagesize()` (อ่านเฉพาะส่วนหัว ไม่ถอดรหัสภาพ)
- Rate limit ผู้ใช้ภายใน 10 ครั้ง/นาที/บัญชี (นอกเหนือจากที่แผนระบุไว้เฉพาะหน้าสาธารณะ)

### Phase 3 — IT Ticket Workspace
- **Files**: `TicketQueryService` (filter/paginate/eager-load); `TicketController@index/show`, `TicketParticipantController`, `TicketStatusController`, `TicketNoteController`, `TicketSettingsController` + Form Requests; routes (§5.3); views `tickets/{index,show}.blade.php`, `tickets/components/{ticket-card,timeline,participants,actions,status-modals,note-form}.blade.php`, `admin/tickets/index.blade.php`; `pages/tickets/{list,detail,responsive}.css`, `pages/admin-tickets.css` + `admin/tickets.css`; `pages/tickets/{index,detail}.js`, `pages/admin/tickets.js`; sidebar ใน `layouts/app.blade.php` + ป้ายจำนวน `new` สำหรับ agent ผ่าน view composer เดิม
- **Tests**:
  - List + Detail ต่อบทบาท (ชุดเดียวกับ §5.1) ผ่าน HTTP จริง รวมยิง URL ตรงโดยไม่ผ่านเมนู
  - เมนูแสดงตรงกับ policy ทุกบทบาท (viewer ไม่เห็น + ยิง URL ได้ 403)
  - pagination คง filter, N+1 (จำนวน query คงที่เมื่อ Ticket เพิ่ม)
  - ทุก mutation ทั้ง form และ JSON, 409 เมื่อ `expected_status` เก่า, IDOR
  - admin settings + `AuditTrail` เมื่อค่าเปลี่ยน
  - CSS contract: ไฟล์ tickets ไม่มี `position: sticky|fixed`, `overflow: hidden` บน list/card, line-clamp, `display: none` กับฟิลด์การ์ด
  - JS (jsdom): คลิกปุ่ม → modal เปิด → submit; ยืนยันออกจาก Ticket ผ่าน Swal
  - `SidebarNavigationTest` — เพิ่มเมนูใหม่ในลำดับที่คาด (เพิ่ม assertion ไม่ลดของเดิม)
- **Acceptance**: ทีม IT หลายคนทำ Ticket เดียวกันได้; วัดในเบราว์เซอร์จริงทุก viewport ว่า `document.documentElement.scrollWidth <= clientWidth`, ข้อมูลการ์ดครบ, ปุ่มไม่ทับเนื้อหา
- **ไม่แตะ**: หน้า Task/Meeting/Board, `DepartmentController` เดิม

#### Phase 3 — สิ่งที่ต่างจากแผนเดิม (ระหว่างลงมือ)
- **`ticket_agents.is_enabled` เป็น nullable** (แก้ migration ของ Phase 1 ซึ่งยังไม่เคยรันกับฐานจริงใด) — `null` = "ตามแผนก" เพื่อให้ admin ปิดการแจ้งเตือนของ agent ตามแผนกได้โดยไม่ตรึงสิทธิ์ agent ของคนนั้นไว้
- **หน้าตั้งค่าของ admin ใช้ entry `tickets.css` เดิม + `tickets/settings.css`** แทน `admin-tickets.css` (AGENTS.md: ใช้ entry เดิมเมื่อรองรับได้ — ปุ่ม panel ฟอร์ม และแถบผลลัพธ์อยู่ใน entry นี้อยู่แล้ว)
- **ผลการกระทำแสดงเป็นแถบในหน้า (`tickets/components/flash.blade.php`) ไม่ใช่ SweetAlert** — อ่านซ้ำได้ เข้าถึงได้ด้วย screen reader ไม่ต้องใช้ JS · SweetAlert ใช้เฉพาะยืนยันก่อน "ออกจาก Ticket" · โค้ด feedback ใน `form.js` ถูกลบ
- **หลังแจ้งปัญหาจากภายใน ไปหน้ารายละเอียดของ Ticket ใบใหม่** (แผน Phase 2 ระบุไว้) — `TicketInternalSubmissionTest` เปลี่ยน assertion ปลายทาง redirect ตามพฤติกรรมใหม่
- **แยกบริการ** `TicketPhotoStore` (ตรวจ/เก็บ/ล้างรูป ใช้ร่วมกับบันทึก), `TicketNoteService`, `TicketSettingsService` · Form Request ของรูปเป็น trait `ValidatesTicketPhotos`
- **ปุ่มทั้งหมดของ Ticket อยู่ที่ `tickets/buttons.css`** ใช้ทั้งหน้าภายในและหน้าสาธารณะ
- เพิ่ม route `POST /admin/tickets/agents` (เพิ่ม agent จากแผนกอื่นด้วยการเลือกจากรายการ)
- ผู้ประสานงานต้อง **ยังเป็น agent** และกำลังเข้าร่วมอยู่ (เพิ่มการตรวจ `isAgent` ใน `setCoordinator`)
- `TicketAgentAccess::isStaff()` ตรวจ `trashed()` ด้วย — ความสัมพันธ์ของ Ticket โหลดผู้ใช้แบบ withTrashed
- **ข้อควรระวังสำหรับการ deploy/dev**: view composer ของ layout อ่านตาราง `ticket_agents` ทุกหน้า (ป้ายจำนวน Ticket รอรับงาน) — ต้อง migrate ก่อนรันโค้ดนี้กับฐานข้อมูลใด ๆ

### Phase 4 — Notifications
- **Files**: `app/Services/TicketNotificationService.php`; `SystemNotification`, `NotificationService` (target/targetUnavailable/paginate), `NotificationController`, `notifications/index.blade.php`, `notifications/components/category-icon.blade.php`, `realtime-sync.js` (category/icon + opt-in hidden interval หากอนุมัติ diff), `TelegramMessageComposer`, `layout/shared-ui.css`, `pages/notifications.css`; `components/ticket-alert.js`; `layouts/app.blade.php` (`@vite` + `data-ticket-alert` เฉพาะ agent); `vite.config.js`
- **Tests**: ผู้รับถูกต้อง (ไม่มี viewer/inactive/ผู้กด/agent ที่ปิด alert), fallback admin, dedupe ไม่สร้างซ้ำและ Telegram ไม่ enqueue ซ้ำ, notification/Telegram throw แล้ว Ticket ยังถูกบันทึก, `target()` ของ ticket + ผู้รับหมดสิทธิ์, realtime payload มี category `ticket`; agent เห็น `data-ticket-alert` บนหน้าอื่น (เช่น `/my-tasks`) ส่วนผู้ไม่ใช่ agent ไม่เห็น; JS: `ticket_created` → เล่น 1 ครั้ง, id ซ้ำไม่เล่น, ปิดเสียงไม่เล่น, ยังไม่ปลดล็อก audio ไม่ throw, type อื่นไม่เล่น, ไม่มี `fetch`/timer ใหม่ใน `ticket-alert.js`
- **Acceptance**: แจ้งเตือนครบ 3 ช่องทาง; ทดสอบเสียงจริงในเบราว์เซอร์ (หน้าอื่น, autoplay, 2 แท็บ)
- **ไม่แตะ**: logic แจ้งเตือนของ Task/Meeting; ปัญหา category `appointment` ที่ขาดอยู่เดิม (พบ — แจ้งแยก ไม่แก้ในงานนี้)

#### Phase 4 — สิ่งที่ทำเพิ่ม/ต่างจากแผนเดิม (ระหว่างลงมือ)
- **Deployment safety (ก่อน Phase 4)**: `App\Services\TicketSchemaStatus` (scoped) ตรวจว่าตาราง Smart Ticket ติดตั้งครบแล้วหรือยัง จำเฉพาะผลบวกใน cache store `config('tickets.schema_cache_store')` (ค่าเริ่มต้น file → ไม่มีคิวรีเพิ่มต่อ request หลังติดตั้ง) ล้างเองเมื่อ `MigrationsEnded` · layout แสดงเมนู/ตัวนับ/เสียง เฉพาะเมื่อติดตั้งแล้ว · route ของ Ticket ผ่าน middleware `tickets.installed` (ตอบ 503 พร้อมคำอธิบาย แทน 500) · ไม่จับ exception ของฐานข้อมูลแบบกว้าง · เทสต์ `TicketNotInstalledTest` ลบตารางในทรานแซกชันแล้วเปิดหน้าเดิมทุกบทบาท
- `phpunit.xml` เพิ่ม `TICKET_SCHEMA_CACHE_STORE=array` — กันค่าที่จำไว้บนดิสก์ค้างข้ามเทสต์
- **ใน-ระบบ**: `TicketNotificationService` ส่งผ่าน `NotificationService::notifyDetached()` · ผู้รับทุกคนผ่าน `TicketPolicy::view()` · dedupe ต่อเหตุการณ์ · admin fallback เฉพาะเมื่อ "ไม่มี agent เลย" (ถ้ามี agent แต่ทุกคนปิดแจ้งเตือน จะไม่ส่งให้ admin) · เพิ่มหมวด `ticket` ครบทุกจุดของระบบแจ้งเตือนเดิม
- **Telegram**: ไม่ต้องแก้ outbox — ได้จาก `NotificationService::create()` เดิม · ข้อความมีเลข Ticket, ปัญหาโดยย่อ, สาขา, เวลาแจ้ง, ลิงก์ · ไม่มีช่องทางติดต่อและ IP
- **เสียง**: `components/ticket-alert.js` + ปุ่มเปิด/ปิดเสียงที่ topbar (เฉพาะ agent ที่รับแจ้งเตือน) · ไม่มี polling ใหม่ · **ยังไม่เปลี่ยนพฤติกรรม polling ของแท็บที่ถูกซ่อน** — เสนอ diff ไว้ในรายงาน Phase 4 รออนุมัติ
- เทสต์เดิมที่ปรับตามพฤติกรรมจริง: `NotificationCenterTest` (เพิ่ม `ticket` ใน allow-list หมวด), `TicketListPageTest` (ตรวจการ์ดด้วย id แทนข้อความทั้งหน้า เพราะเลข Ticket ปรากฏในกระดิ่งของ agent ได้ และอุ่นเครื่องก่อนวัดคิวรีต่อการ์ด)

### Phase 5 — Reporting & KPI
- **Files**: `app/Services/TicketReportService.php`; `TicketReportController`; `config/tickets.php` (SLA `response_minutes`, `resolution_hours` — ว่าง = ไม่แสดง SLA); views `reports/tickets.blade.php` + `reports/components/tickets/*`; `layouts/partials/report-nav.blade.php`; `pages/report-tickets.css`, `pages/reports/tickets.css`, `pages/reports/tickets.js`
- **Develop**: ช่วงวันที่ด้วย `ReportPeriod` (เวลากรุงเทพ); **Team KPI** (Ticket ละ 1): ทั้งหมด/รอรับ/กำลังทำ/ติดตาม/ปิด, เวลาตอบรับเฉลี่ย (`first_responded_at − created_at`), เวลาแก้เฉลี่ย (`closed_at − created_at`), ตามแผนก (`requester_department_name` snapshot; public = "บุคคลภายนอก"), ตามสาขา (`location_name` snapshot), SLA %; **Individual KPI** (ตารางแยก): จำนวน Ticket ที่แต่ละคนเคยเข้าร่วม (distinct, รวมที่ออกแล้ว, ใช้ `user_name` เมื่อบัญชีถูกลบ) + จำนวนที่ปิดเอง; คำนวณระยะเวลาใน PHP จากคอลัมน์แคบ (เลี่ยงฟังก์ชันวันที่ต่างกันระหว่าง SQLite/MySQL); กราฟ ≤ 2 + ตาราง
- **ชั่วโมงทำงานจริง**: ไม่อนุมานจากเวลาเข้าร่วม — หากต้องการภายหลังเพิ่ม `minutes_spent` บนบันทึก (นอกขอบเขต)
- **Tests**: 1 Ticket 3 คน → ทีม 1 / รายคนคนละ 1; reopen ไม่นับซ้ำ; เปลี่ยนชื่อแผนก/สาขาแล้วรายงานย้อนหลังคงชื่อเดิม; ขอบวันตามเวลากรุงเทพ; SLA ไม่แสดงเมื่อไม่ตั้ง; สิทธิ์เปิดรายงาน; JS chart config
- **ไม่แตะ**: รายงาน operational/projects เดิม, palette contract

#### Phase 5 — สิ่งที่ทำเพิ่ม/ต่างจากแผนเดิม (ระหว่างลงมือ)
- **ก่อนเริ่ม (Step 1)**: ปุ่มเปิด/ปิดเสียงที่ topbar มีพื้นที่กด 44×44 ด้วย `.icon-btn--touch-target::before` (ขนาดที่เห็นยังเป็น 36px, ความสูง topbar เดิม, ปุ่มอื่นไม่เปลี่ยน) · **ไม่ได้เพิ่ม background polling / `data-realtime-hidden-interval`** (ยังไม่อนุมัติ)
- **ชุด Ticket เดียว**: การ์ด กราฟ และตารางทุกส่วนใช้ Ticket ที่ `created_at` อยู่ในช่วง (เวลากรุงเทพ → UTC ตอนคิวรี) + ตัวกรอง สถานะ/แผนก/สาขา/เจ้าหน้าที่ · "ปิดงานภายในช่วง" (ตาม `closed_at`) แสดงแยกเป็นแถบอธิบายใต้การ์ด ไม่ปนกับยอดหลัก
- **นิยาม**: เวลาตอบรับ = `first_responded_at − created_at` · เวลาแก้ไข = `closed_at` ครั้งล่าสุด − `created_at` (เวลาปฏิทิน เฉพาะใบที่ปิดอยู่ตอนนี้) · ข้อมูลที่ไม่มี (ยังไม่รับ/ยังไม่ปิด) ไม่อยู่ในค่าเฉลี่ยและแสดง "—" ไม่ใช่ 0 · นิยามทั้งหมดแสดงในหน้า (ส่วน "วิธีคำนวณ") ตรงกับ docblock ของ `TicketReportService`
- **SLA**: `TICKET_SLA_RESPONSE_MINUTES`, `TICKET_SLA_RESOLUTION_HOURS` (ว่าง = ไม่ได้กำหนด → ไม่มีการ์ด % และหน้าบอกว่ายังไม่ได้กำหนด) · ผ่าน/ไม่ผ่าน/รอผล โดย "รอผล" ไม่อยู่ในตัวหาร · รายการ "Ticket ที่ต้องดูแล" (ยังไม่รับ, ยังไม่ปิด, ยังไม่ปิดและเกิน SLA, เคยเปิดใหม่, งานที่ต้องติดตาม) พร้อมสูตรใต้ตัวเลข
- **รายบุคคล**: เข้าร่วม (COUNT DISTINCT ticket) / ปิดงาน (`closed_by` ของใบที่ปิดอยู่) / ยังดำเนินการ (ยังไม่ปิดและยังไม่ออก) · แยกตาม `user_id` (ชื่อซ้ำไม่รวม) · บัญชีลบชั่วคราว/ปิดใช้งานแสดงพร้อมป้าย · **บัญชีที่ลบถาวร (`user_id` = null) แยกทีละแถวการเข้าร่วม และ "ปิดงาน" = "—"** เพราะหลังลบถาวรระบุตัวตนไม่ได้ — ข้อเสนอสำหรับอนาคต (ยังไม่ทำ ต้องอนุมัติ schema): เพิ่ม `user_ref_id` (ไม่มี FK, ไม่ถูก null) บน participants/activities/tickets.closed_by เพื่อรวมแถวของคนเดียวกันได้ถูกต้อง · agent ปัจจุบันที่ยังไม่มีส่วนร่วมแสดงเป็น 0 · กรองเจ้าหน้าที่ = แสดงคนนั้นคนเดียว
- **สิทธิ์**: `TicketPolicy::viewReport` (admin + agent ที่ active) + middleware `role:admin,user` + `tickets.installed` · บัญชีที่ปิดใช้งานถูก middleware `active` เดิมออกจากระบบก่อนถึง policy (หน้าเว็บ → หน้า login, JSON → 403) — ไม่ได้เปลี่ยน middleware กลาง · เมนูใน `report-nav` ใช้ ability เดียวกัน + ตรวจ `$ticketsInstalled`
- **UI**: ใช้ `report-kpi-band` / `report-chart-*` / `report-empty` ของ `reports/shared.css` เดิม · กราฟ 2 ใบผ่าน `chart-lifecycle.js` เดิม (แท่งรายวัน — ช่วง > 62 วันเป็นรายเดือน, แท่งแนวนอนตามสาขา 8 อันดับ) · ป้ายสาขายาวแบ่งบรรทัดตามขอบคำไทย (`Intl.Segmenter`) แทนการตัดทิ้ง · ตาราง (รายบุคคล, รายการ Ticket) เป็นการ์ดเมื่อจอ < 1200px จาก markup ชุดเดียว · แถบสัดส่วนใช้ `<progress>` (ไม่มี inline style)
- **ตัวเลือกเดือน**: ใช้ `ReportMonth::options()` เดิม (ปีปัจจุบัน) + กำหนดช่วงเองสูงสุด 366 วัน


### Phase 6 — QA, Security & Cleanup
- **Release concern — Telegram production delivery**: ข้อความ Telegram ถูกเข้าคิวใน `telegram_messages` และส่งใน `app()->terminating()` ของ request ถัดไปที่ผ่านระบบ — production ไม่มี cron/queue worker จึงไม่มีอะไรระบายคิวถ้าไม่มี request เข้ามา (เช่น Ticket public ตอนกลางคืน) ต้องตรวจบนเซิร์ฟเวอร์จริงก่อนปล่อย: มี scheduler/cron หรือไม่, outbox ส่งออกจริงหรือไม่, และตัดสินใจว่าจะเพิ่ม cron `schedule:run` หรือยอมรับความหน่วงนี้
- full suite เทียบ baseline, authorization matrix, ทดสอบ join พร้อมกัน (unique constraint), responsive 6 viewport ในเบราว์เซอร์จริง, `/security-review`, dead CSS/JS + duplicate selector, `migrate --pretend` (MySQL), deployment checklist (migrate บนเซิร์ฟเวอร์, `npm run build`, Admin ตั้งแผนกรับผิดชอบ + สาขา, ตรวจ `TELEGRAM_*` เดิม)

#### Phase 6 — ผลการดำเนินงาน (Step 1–2)
- **Step 1 Audit**: แก้ N+1 ของการแจ้งเตือน — ตรวจสิทธิ์ผู้รับทีละคนเคยโหลด `ticketAgent` + `department` คนละ 2 คิวรี ตอนนี้ eager-load พร้อมกัน (`TicketNotificationService::ACCESS_RELATIONS`, เทสต์จำนวนคิวรีคงที่เมื่อผู้รับเพิ่ม) · ไม่พบ authorization ที่ขาด (ทุก mutation ตรวจ policy ใต้ล็อกแถว), visibleTo ↔ view ตรงกัน, ไม่มี CSS class ที่ไม่ได้ใช้ · ชื่อ hook `data-ticket-list` ของรายงานเปลี่ยนเป็น `data-ticket-report-list` (ไม่ชนกับหน้ารายการ) · ข้อสังเกตที่ไม่แก้: เพิ่ม agent คนเดียวกันพร้อมกันสองครั้งอาจชน unique แล้วได้ 500 (admin เท่านั้น)
- **Step 2 ตัวตนทางประวัติ**: migration `2026_10_23_000008_add_identity_refs_to_ticket_history` เพิ่ม `ticket_participants.user_ref_id`, `ticket_activities.user_ref_id`, `tickets.closed_by_ref_id` (bigint unsigned nullable + index, ไม่มี FK) · backfill จาก `user_id`/`closed_by` ที่ยังมีค่าเท่านั้น ไม่เดาค่าให้แถวที่เป็น null แล้ว · `down()` หยุดเมื่อ ref เป็นข้อมูลตัวตนเดียวที่เหลือ
  - ตั้งค่าตอนสร้าง: `TicketParticipant`/`TicketActivity::booted()` (creating) + insertOrIgnore ของ `TicketParticipantService` ใส่เอง · ผู้ปิดตั้ง/ล้างใน `TicketWorkflowService::changesFor()` (ไม่ใช้ dirty-check เพราะหลังบัญชีผู้ปิดถูกลบถาวร closed_by เป็น null อยู่แล้ว การเปิดงานใหม่จะไม่ล้าง ref — เทสต์จับได้)
  - รายงานจัดกลุ่มด้วย `coalesce(ref, FK)`: คนเดียวกันหลาย Ticket = แถวเดียวแม้ลบถาวร, ชื่อซ้ำ = คนละแถว, ผู้ปิดยังระบุได้ · ตัวกรองเจ้าหน้าที่เลือกบัญชีที่ลบถาวรได้ · เฉพาะประวัติที่ลบถาวร "ก่อน" migration นี้ยังแยกทีละการเข้าร่วม (ปิดงาน = "—")
  - `TicketSchemaStatus` ตรวจคอลัมน์ใหม่ด้วย และเปลี่ยน cache key เป็น `v2` — ขึ้นโค้ดก่อน migrate จะได้หน้า "ยังไม่ติดตั้ง" (503) ไม่ใช่ 500 ตอนบันทึก
  - **ยังไม่ได้ migrate ฐาน dev/production** · `migrate --pretend` บน MySQL ผ่าน (ชื่อ index < 64) · ฐาน dev มี migration 000001–000007 (batch 39) อยู่แล้ว 000008 ค้าง → หน้า Smart Ticket บน dev จะตอบ 503 จนกว่าจะ migrate
  - **Release check**: id ของผู้ใช้ต้องไม่ถูกนำกลับมาใช้ซ้ำ (MySQL ≥ 8.0 เก็บ auto-increment ถาวร — dev เป็น 8.4.3) ต้องยืนยันรุ่นฐานข้อมูล production ก่อนปล่อย

#### Phase 6 — ผลการดำเนินงาน (Step 3–9)
- **Step 3 Telegram**: ใช้ outbox เดิม · ลองซ้ำห่างขึ้น (`TelegramMessage::RETRY_DELAYS_MINUTES` 1/5/15/30 นาที, ค่าเริ่ม `max_attempts` 5) · รอบ drain มีเพดานเวลา (`TELEGRAM_DRAIN_SECONDS` 20) และหยุดทันทีเมื่อไปไม่ถึง Telegram โดยคืนข้อความที่ยังไม่ได้ลองเข้าคิว · `telegram:drain` ทุกนาที · `telegram:status` + แถบเตือนในหน้า Audit Log (จำนวนเท่านั้น) · deploy/README แก้ข้อความ "ไม่ต้องมี cron" ให้ถูก — **การส่งที่ไม่ต้องรอ request ต้องมี cron บนเซิร์ฟเวอร์ (ยังไม่ได้ตั้ง/ยังไม่ได้ยืนยัน)**
- **Step 4 Security**: แก้การเพิ่ม agent พร้อมกัน (insertOrIgnore + อ่านแถวใต้ล็อก + `DB::transaction(..., attempts: 3)` สำหรับ deadlock) · ลบ EXIF/GPS/XMP/IPTC/ข้อความ PNG แบบ lossless คง Orientation (`App\Support\ImageMetadata`) · เทสต์ XSS ของข้อความผู้แจ้งภายนอกในรายงาน · ข้อสังเกต: `trustProxies('*')` (ตั้งไว้สำหรับ Cloudflare Tunnel) — ถ้าเซิร์ฟเวอร์ต้นทางเข้าถึงตรงได้ IP ปลอมได้และข้าม rate limit ได้ ต้องยืนยันโครงสร้างเครือข่าย
- **Step 5 UI**: วัด 31 สถานะหน้า × 6 ความกว้างใน headless Chrome (186 รอบ) — หน้า Ticket ไม่มี overflow/ซ้อน/รูปเสีย/JS error, CLS ≤ 0.1 · แก้ปุ่มปิด modal 31→44px · ปัญหาที่พบในหน้าเดิมของ Smart Goals (My Tasks, ศูนย์แจ้งเตือน) บันทึกแยก ไม่แก้
- **Step 6**: ไม่พบ !important, gradient, overflow:hidden, inline style/script, init ซ้ำ หรือ entry ที่ไม่ได้ใช้
- **Step 7**: `SmartTicketEndToEndTest` (Scenario A–E ผ่าน HTTP บน SQLite ที่ migrate ครบ 8 ไฟล์) + เสียงสองแท็บจริงใน Chrome (ดังครั้งเดียว)
- **Step 8**: failure เดิม 11 + 4 ไม่เกี่ยวกับ Smart Ticket (fail ที่ HEAD ก่อนมีโค้ด Ticket) — test ค้างจาก UI/ข้อความที่ถูกเปลี่ยนโดยตั้งใจ 14 รายการ และ test มีบั๊กเอง 1 รายการ
- **Step 9**: ฐาน dev — migration 000008 ถูกรันแล้ว (batch 40) นอก session นี้ · backfill บน dev ไม่มีข้อมูลให้คัดลอก (0 แถว) · `tickets:identity-check` ตรวจ id ผู้ใช้ที่ถูกนำกลับมาใช้ซ้ำ (อ่านอย่างเดียว) · SQL ฉบับร่างสำหรับ phpMyAdmin สร้างจาก `migrate --pretend` (ยังไม่รัน)

#### Deployment checklist (ห้ามรันจนกว่าจะอนุมัติ)
1. **สำรอง**: export ฐาน production ทั้งฐาน + โฟลเดอร์ `storage/app` (รูปแนบเป็น private) เก็บนอกเซิร์ฟเวอร์
2. **ยืนยันฐานข้อมูล**: รุ่น MySQL/MariaDB ของ production (`SELECT VERSION();`) — ต้องเก็บตัวนับ AUTO_INCREMENT ถาวร (MySQL ≥ 8.0) ถ้าเก่ากว่าให้เสนอทางเลือก (ดูรายงาน) · ยืนยันว่า `.env` ชี้ฐาน production ถูกตัว
3. **Migration**: ตรวจตาราง `migrations` ว่ายังไม่มี `2026_10_23_*` → รัน SQL 8 migration (ฉบับที่ตรวจทานแล้ว) ใน phpMyAdmin หรือ `php artisan migrate` ถ้ามี SSH → ตรวจ `migrate:status`
4. **Cache**: `php artisan config:cache`, `route:cache`, `view:clear` (โฮสต์ใช้ config:cache) — สถานะติดตั้งของ Ticket ไม่จำผลลบ จึงเห็นผลทันทีหลัง migrate
5. **Assets**: `npm run build` แล้วอัปโหลด `public/build/` ตามแบบแพ็กเกจเดิม (ไม่แตะ smart_private)
6. **Cron (ต้องอนุมัติแยก)**: DirectAdmin › Cron Jobs ทุกนาที `cd <path> && <php> artisan schedule:run` → ตรวจ `/admin/audit` แถบสีเขียวภายใน 5 นาที
7. **ตั้งค่า**: Admin เลือกแผนกที่รับผิดชอบ + สาขา ที่ `/admin/tickets` · ตรวจ `TELEGRAM_*` และ `APP_URL`
8. **ตรวจสิทธิ์**: viewer/พนักงาน/หัวหน้าแผนก/agent/admin เปิดรายการ รายละเอียด รายงาน ตามตารางสิทธิ์
9. **Smoke test**: ส่ง `/support` หนึ่งใบ → agent ได้แจ้งเตือน/เสียง/Telegram → รับงาน → ปิด → รายงานนับ 1 · `php artisan telegram:status` = 0 ค้าง · `php artisan tickets:identity-check` = ไม่พบ
10. **Rollback**: ปิดเมนูได้ทันทีด้วยการคืนโค้ดเดิม (ตาราง Ticket ไม่กระทบหน้าอื่น) · ห้ามลบตาราง/คอลัมน์ Ticket ที่มีข้อมูล (down() ของ migration ปฏิเสธเอง) · กู้จากข้อ 1 เมื่อจำเป็นเท่านั้น

### UI/UX Redesign (UI-1 – UI-5) — ไม่เปลี่ยน schema, policy หรือ workflow
- **แยกประสบการณ์ตามบทบาท** ด้วย `TicketAgentAccess::seesAllTickets` (admin หรือ agent) — กติกาเดียวกับ `viewReport`
  - ผู้แจ้ง (รวมหัวหน้าแผนก): `/tickets` เป็นหน้า `tickets/landing` มีปุ่มเดียว "แจ้งปัญหา IT" เปิด modal 3 ขั้น · ไม่มีรายการ/ตัวกรอง/KPI · หน้า Detail ยังเปิดได้ตาม `TicketPolicy::view` เหมือนเดิม
  - agent/admin: `/tickets` เป็นโต๊ะงาน IT — ภาพรวม 4 สถานะจริง + ปิดวันนี้, คิว (ตารางบนจอ ≥ 1200px, การ์ดบนจอเล็ก), ติดตามผล, เกิน SLA (เฉพาะเมื่อตั้ง `config/tickets.php`), ภาระงานทีม (นับผู้เข้าร่วมหลายคนต่อ Ticket)
  - ไม่มี polling ใหม่ — ปุ่มรีเฟรชโหลดหน้าเดิม และแถบ "มี Ticket ใหม่" ฟัง event ของ `realtime-sync.js`
- **Sidebar**: `layouts/partials/ticket-nav.blade.php` — agent/admin ได้กลุ่ม "Smart Ticket" (`<details class="report-nav">` ชุดเดียวกับเมนูรายงาน) มี "รับแจ้งปัญหา" และ "รายงานแจ้งปัญหา" · ลิงก์รายงาน Ticket ถูกย้ายออกจาก `report-nav` (ไม่ซ้ำ) · ผู้แจ้งได้ลิงก์เดียว "แจ้งปัญหา IT"
- **Modal แจ้งปัญหา** (`tickets/components/submit-modal`, `pages/tickets/stepper.js`): ผู้แจ้ง (อ่านอย่างเดียว) → สาขา → รายละเอียด + รูป · ฟอร์มเดียว ส่งครั้งเดียว ใช้ `required` ของเบราว์เซอร์ (ขั้นที่ซ่อนถูกเปิดเมื่อมีช่องไม่ผ่าน) · error จาก server เปิด modal ที่ขั้นที่ผิด · `#new-ticket` รองรับปุ่ม Back/รีเฟรช · `/tickets/create` ยังเป็นหน้าสำรองเมื่อไม่มี JS · `/support` ไม่เปลี่ยน
- **รายงาน**: ตัวกรองและสรุปช่องทางที่แจ้ง (`TicketDesign::SOURCES`), คอลัมน์ผู้ดำเนินงาน (ผู้เข้าร่วมทุกคน, ผู้ประสานงาน, ผู้ปิด) โหลดด้วย eager-load เดียว · `App\Support\TicketSla` เป็นที่เดียวที่อ่านค่า SLA (โต๊ะงาน + รายงาน)
- **ตั้งค่า**: `.ticket-settings` กว้างสุด 1040px กึ่งกลางพื้นที่เนื้อหา
- ตรวจใน headless Chrome ที่ 320/375/390/768/1024/1440 — ยังไม่ได้ทดสอบบนอุปกรณ์จริง

## 10. Test Plan, Baseline & Acceptance Criteria (ทุก Phase)
1. `git status --short` + `git diff` ก่อนเริ่ม — งานของผู้ใช้ไม่ถูกแตะ
2. **Baseline** (ครั้งเดียวก่อน Phase 1 และวัดใหม่ถ้า HEAD เปลี่ยน): สกัด HEAD ลง scratchpad แบบอ่านอย่างเดียว (ไม่ stash), รัน PHP + JS, เก็บชื่อ test ที่ fail แบบเรียงลำดับ
3. Targeted tests → `php artisan test` เต็ม → `npm test` → เทียบชุดชื่อกับ baseline แล้วรายงานเป็น 3 กลุ่ม:
   - **Existing failures** — fail ที่ HEAD และยัง fail ด้วย assertion เดิม
   - **Regression ใหม่** — ต้องแก้ที่ต้นเหตุก่อนปิด Phase
   - **ผ่านขึ้นมาเอง** — รายงานและอธิบายสาเหตุ
4. ห้ามแก้/ลบ/skip assertion เพื่อให้ผ่าน; test เดิมที่ต้องเปลี่ยนเพราะพฤติกรรมเปลี่ยนจริง (เช่นลำดับเมนู) ต้องแจ้งชื่อและเหตุผลในสรุป
5. `npm run build` เมื่อแตะ frontend; `vendor/bin/pint --test` กับไฟล์ที่แตะ (repo-wide แดงอยู่แล้วที่ HEAD)
6. ตรวจเบราว์เซอร์จริง 320/375/390/768/1024/1440 (dev server `127.0.0.1:8001` หรือ harness ใน scratchpad — ไม่สร้างบัญชีในฐานจริง)
7. สรุป: ไฟล์ที่เปลี่ยน, ผล test/build ตามกลุ่มข้างบน, สิ่งที่ยังต้องตรวจด้วยตา, ยืนยันไม่มี git/database mutation

## 11. Risks & Existing Feature Impact
| ความเสี่ยง | การรับมือ |
|---|---|
| Trash purge force-delete ผู้ใช้ | FK users เป็น nullOnDelete + snapshot `user_name` + test จำลอง force-delete |
| เพิ่ม category `ticket` แตะไฟล์ notification ที่ใช้ร่วม | แก้แบบเพิ่มเท่านั้น + test notification เดิมต้องผ่าน |
| เมนูใหม่ทำ `SidebarNavigationTest` เปลี่ยน | เพิ่ม assertion ตามลำดับใหม่ ไม่ลดของเดิม แจ้งในสรุป |
| แท็บซ่อน → ไม่มีเสียง | opt-in hidden interval เฉพาะ agent (อนุมัติ diff ก่อน) + Telegram ครอบคลุม |
| Public form ถูกสแปม / guest session เพิ่มแถวใน sessions | honeypot + เวลาขั้นต่ำ + throttle IP; Laravel session GC เดิม |
| รูปจากมือถือมี EXIF/GPS | เก็บ private เปิดได้เฉพาะผู้มีสิทธิ์; ลบ EXIF ต้องใช้ GD — ตรวจเซิร์ฟเวอร์ก่อนเสนอ |
| ชื่อ index ยาวเกิน 64 บน MySQL | ตั้งชื่อเอง + `migrate --pretend` |
| ยังไม่ตั้งแผนกรับผิดชอบหลัง deploy | แจ้ง admin แทน + empty state ในหน้าตั้งค่า |
| Form Request เป็นรูปแบบใหม่ของ repo | จำกัดใน `app/Http/Requests/Tickets/` ไม่ย้ายของเดิม |

**ไม่กระทบ**: WorkOrder/`job_status`/TaskStatusTransitionService, Project, Meeting/Appointment, Daily log/Computer Check, task visibility, รายงานเดิม

## 12. Files Expected to Change

**ใหม่**: 7 migrations; 6 models; `TicketPolicy`; `Support/{TicketAgentAccess,TicketDesign}`; `Services/{TicketSubmissionService,TicketWorkflowService,TicketParticipantService,TicketQueryService,TicketNotificationService,TicketReportService}`; controllers `{Ticket,PublicTicket,TicketParticipant,TicketStatus,TicketNote,TicketReport,TicketSettings}Controller`; `Http/Requests/Tickets/*`; `config/tickets.php`; views `tickets/**`, `reports/tickets.blade.php` + `reports/components/tickets/*`, `admin/tickets/index.blade.php`; CSS `pages/{tickets,tickets-public,report-tickets,admin-tickets}.css` + `pages/tickets/*`, `pages/reports/tickets.css`, `pages/admin/tickets.css`; JS `components/ticket-alert.js`, `pages/tickets/*`, `pages/reports/tickets.js`, `pages/admin/tickets.js`; factories; tests `tests/Feature/Tickets/*`, `tests/js/tickets-*.test.js`; `docs/plans/smart-ticket.md`

**แก้ (เพิ่มเท่านั้น)**: `routes/web.php`, `vite.config.js`, `app/Providers/AppServiceProvider.php`, `app/Models/{User,Department,SystemNotification}.php`, `app/Services/NotificationService.php`, `app/Http/Controllers/{MediaController,NotificationController}.php`, `app/Support/{AttachmentPolicy,TelegramMessageComposer}.php`, `resources/views/layouts/app.blade.php`, `resources/views/layouts/partials/report-nav.blade.php`, `resources/views/notifications/{index,components/category-icon}.blade.php`, `resources/js/components/realtime-sync.js`, `resources/css/components/layout/shared-ui.css`, `resources/css/pages/notifications.css`, `tests/Feature/SidebarNavigationTest.php`

## Verification (end-to-end)
- ทุก Phase: tests ของ Phase + full suite เทียบ baseline (3 กลุ่ม) + `npm test` + `npm run build` + Pint ไฟล์ที่แตะ
- Manual ในเบราว์เซอร์: ส่ง Ticket public (ไม่ login, กรอกแค่ 3 ช่อง) → agent ที่อยู่หน้าอื่นได้ยินเสียง + เห็นแจ้งเตือน + Telegram → agent A รับงาน (`first_responded_at`), agent B เข้าร่วม (สถานะไม่เปลี่ยน) → follow-up → close → reopen → timeline ครบ → หัวหน้าแผนกผู้แจ้งเห็นแบบอ่านอย่างเดียว / หัวหน้าแผนกอื่นไม่เห็น → รายงานทีม = 1, รายคน A = 1, B = 1 — ทุกขั้นที่ 375px และ 1440px
