# Smart Goal UI Design Contract

เอกสารนี้เป็นสัญญากลางสำหรับหน้า dashboard และ report ของ Smart Goal โดยหน้า User Personal Report เป็น implementation อ้างอิงแรก เป้าหมายคือ UI ที่สะอาด เป็นมืออาชีพ อ่านข้อมูลได้เร็ว และยังคงใช้ Bootstrap 5, Bootstrap Icons และ design tokens เดิมของระบบ

## Visual principles

- ใช้พื้นหลังเทาอมฟ้าอ่อน (`--bg`) และ surface สีขาว (`--surface`) เพื่อแยกชั้นข้อมูล
- ใช้เส้นขอบบาง (`--border`) กับ `--shadow-sm`; หลีกเลี่ยงเงาหนัก gradient, glass, glow และ neon
- สี accent หลักคือ `--accent`; semantic colors ใช้เฉพาะสื่อสถานะ ความเร่งด่วน และ feedback
- ลำดับข้อมูลต้องเป็น: ชื่อหน้าและ action → filters → KPI → actionable work → analytics → supporting list
- จำกัด chart ไม่เกิน 2 กราฟต่อ dashboard เว้นแต่มีเหตุผลด้านงานที่ชัดเจน และต้องมีข้อมูลแบบข้อความรองรับ

## Foundations

### Color

ใช้ custom properties จาก `resources/css/foundations/tokens.css` เป็น source of truth:

- Page: `--bg`
- Card: `--surface`; secondary area: `--surface-2`
- Border: `--border`
- Text: `--text`; secondary text: `--text-muted`
- Primary action: `--accent`, `--accent-dim`, `--accent-strong`
- Semantic: `--green`, `--amber`, `--red`, `--blue` และตัวแปร `*-dim`

ห้ามประกาศชุดสี brand/status ซ้ำใน Blade หรือ JavaScript หาก token/metadata กลางรองรับอยู่แล้ว งานที่ต้อง map priority และ status ต้องใช้ `WorkBoardDesign` ฝั่ง PHP

### Typography

- ใช้ Prompt จาก layout หลัก
- Page title: 26–34px, weight 800
- Section title: 16–18px, weight 800
- Body: 14–15px
- Supporting label: 12–13px, color `--text-muted`
- ตัวเลข KPI ต้องเด่นกว่าป้ายกำกับ แต่ไม่ใช้ขนาดจนแย่ง page title

### Spacing, radius, shadow

- Spacing rhythm: 4, 8, 12, 16, 20, 24, 32px
- Controls สูงอย่างน้อย 40px; primary touch target อย่างน้อย 42px
- ใช้ `--radius-sm` กับ controls และ `--radius` กับ cards
- ใช้ `--shadow-sm` เป็นค่าเริ่มต้น; `--shadow-md` เฉพาะ overlay หรือ floating surface

## Components

### Page header

ชื่อและคำอธิบายอยู่ซ้าย action อยู่ขวาบน desktop และ stack บน mobile ปุ่ม export เป็น secondary ส่วน action หลักใช้ accent เพียงหนึ่งปุ่ม

### Filter bar

เป็น card แถวเดียวแบบ compact บน desktop มี label ที่มองเห็นได้ทุก control และ wrap เป็น grid บน tablet/mobile ค่าไม่ถูกต้องต้อง normalize เป็นค่าเริ่มต้นที่ปลอดภัย ห้ามทำให้ query error หรือเผยข้อมูลนอก scope

### KPI cards

ใช้ 4 ใบต่อแถวเมื่อพื้นที่พอ แต่ละใบมี label, value และ icon semantic หนึ่งอัน หลีกเลี่ยง decoration ที่ไม่มีความหมาย KPI ทุกใบต้องมาจาก dataset เดียวกับ filter ที่แสดง

### Data cards and lists

Card มี header ที่ประกอบด้วย title, description และ optional count เนื้อหารายการต้องรองรับ empty state ลิงก์ทั้งแถวได้เมื่อเป็น navigation และต้องมี focus state จาก browser/Bootstrap ที่มองเห็นได้

### Status and priority

ใช้ pill ขนาดเล็กพร้อมข้อความเสมอ ห้ามสื่อความหมายด้วยสีเพียงอย่างเดียว Priority งานมี 5 ระดับตาม `WorkBoardDesign::TASK_PRIORITIES`; project priority เป็นคนละ domain และห้ามนำมาปะปน

### Charts

ใช้ Chart.js ที่ติดตั้งอยู่แล้ว สีเรียบ animation ต่ำหรือปิด และแกนเริ่มที่ศูนย์เมื่อเป็นจำนวน กราฟต้องรับ empty array/ค่าผิดรูปแบบได้โดยไม่เกิด `NaN` และมี summary/legend แบบข้อความที่ screen reader หรือผู้ใช้ที่ไม่เห็นกราฟเข้าถึงได้

## Responsive contract

- Desktop ≥ 1180px: filters compact, KPI 4 columns, analytics 2 columns
- Tablet 820–1179px: filters wrap, KPI 2 columns, analyticsตามพื้นที่
- Mobile < 820px: header/actions stack, analytics/list stack, controls ใช้ความกว้างเต็มตาม grid
- Mobile < 560px: KPI 1 column และลด metadata รองที่ทำให้แถวล้น โดยยังเก็บชื่อ สถานะ และ action หลัก
- ตารางหรือ list กว้างต้อง scroll ได้หรือเปลี่ยนเป็น stacked rows; ห้ามบีบข้อความหลักจนอ่านไม่ได้

## Accessibility contract

- Form controls ต้องมี label ที่มองเห็นได้
- Icon-only button ต้องมี accessible name; decorative icons ใช้ `aria-hidden="true"`
- Canvas ต้องมี `role="img"` และ label พร้อมข้อมูล text fallback
- สี semantic ต้องมาพร้อม label; contrast ของข้อความต้องอ่านได้บนพื้นหลังที่เลือก
- รองรับ keyboard navigation และ focus indicator; ห้ามปิด outline โดยไม่มี replacement
- Empty state ต้องอธิบายสิ่งที่ไม่มีและไม่ใช้ภาพอย่างเดียว

## Personal Report data semantics

- Scope: งานที่ผู้ใช้เป็นผู้รับผิดชอบ ผู้สร้าง หัวหน้า หรือ accepted collaborator เท่านั้น
- Period/filter dataset ใช้ `created_at` ใน business timezone เพื่อรักษาความหมายของรายงานเดิม; date-oriented widgets ใช้ `job_due_at`
- In progress: `job_status = 2`
- Due soon: งานยังไม่เสร็จและครบกำหนดตั้งแต่ปัจจุบันถึงสิ้นวันที่ 7
- Overdue: งานยังไม่เสร็จและเลยสิ้นวันครบกำหนดแล้ว
- High priority attention: priority 3 (`สำคัญด่วน`); ไม่มีการสร้างนิยาม stuck ใหม่
- 3-month workload: จำนวนงานที่ครบกำหนดในเดือนปัจจุบันและ 2 เดือนถัดไป
- Export CSV/PDF เป็น behavior เดิม: CSV ตามปี และ PDF ผ่าน print stylesheet

## Change checklist

1. ตรวจ source of truth และ entry point เดิมก่อนสร้าง component
2. จำกัด selector ภายใต้ page namespace
3. ตรวจ desktop/tablet/mobile, keyboard, focus และ empty state
4. ตรวจ authorization, data scope, filter normalization และ semantic date boundaries
5. รัน `npm run build`, JavaScript tests และ `php artisan test`
6. ตรวจ dead CSS/JavaScript, duplicate selector, inline style และ asset loading ซ้ำ
