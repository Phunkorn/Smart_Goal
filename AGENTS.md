# Smart Goal — Project Instructions

คู่มือนี้เป็นมาตรฐานสำหรับผู้พัฒนาและ AI ที่แก้ไขโปรเจกต์ Smart Goal
ให้ปฏิบัติตามกฎเหล่านี้เมื่อสร้างหรือแก้ไขฟีเจอร์ เว้นแต่ผู้ใช้จะระบุเป็นอย่างอื่น

## Technology

- โปรเจกต์ใช้ Laravel, Blade และ Vite
- ใช้ Bootstrap 5 และ Bootstrap Icons เป็นระบบ UI หลัก
- ใช้ Custom CSS เฉพาะส่วนที่ Bootstrap รองรับไม่เพียงพอ
- อย่าเพิ่ม Tailwind utility ในหน้าใหม่โดยไม่จำเป็น
- อย่าเพิ่ม frontend framework หรือ dependency ใหม่โดยไม่ได้รับอนุญาต

## Component Structure

- แยกฟีเจอร์ที่มี UI และพฤติกรรมของตัวเองเป็น component เช่น table, board, calendar, modal และ filter
- แยก Blade, CSS และ JavaScript ตามหน้าที่เมื่อ component มีความซับซ้อนเพียงพอ
- Blade component/partial ให้อยู่ใต้โฟลเดอร์ `components` ของฟีเจอร์หรือหน้านั้น
- CSS component ให้อยู่ในโฟลเดอร์ของหน้า เช่น `resources/css/pages/board/`
- JavaScript component ให้อยู่ในโฟลเดอร์ของหน้า เช่น `resources/js/pages/board/`
- อย่าแยกไฟล์ย่อยเกินความจำเป็น หากมี style เพียงเล็กน้อยและไม่มีขอบเขตการทำงานของตัวเอง

ตัวอย่างโครงสร้าง:

```text
resources/
├── views/tasks/
│   ├── index.blade.php
│   └── components/
│       ├── table.blade.php
│       ├── board.blade.php
│       └── calendar.blade.php
├── css/pages/mytasks/
│   ├── table.css
│   ├── board.css
│   ├── calendar.css
│   └── modal.css
└── js/pages/mytasks/
    ├── table.js
    ├── board.js
    └── calendar.js
```

## Reuse Before Create

ก่อนสร้าง Blade, CSS, JavaScript, Controller, Service หรือ endpoint ใหม่ ต้องตรวจ source of truth เดิมก่อนเสมอ

- ค้นหาไฟล์, class, selector, handler, route, endpoint และ helper ที่ทำหน้าที่ใกล้เคียงก่อนสร้างของใหม่
- หาก component เดิมรองรับการขยายได้ ให้แก้หรือ extend component เดิมก่อนสร้าง component ใหม่
- ห้ามสร้างไฟล์ชื่อแนว `*-new`, `*-v2`, `*-fix`, `*-final`, `*-copy`
- ห้ามสร้าง CSS entry point ใหม่ หากหน้าปัจจุบันมี entry point ผ่าน Vite อยู่แล้ว
- CSS component ใหม่ต้อง import ผ่าน entry point ของหน้าที่มีอยู่
- ห้ามประกาศสี, status mapping, priority mapping, spacing, radius หรือ shadow ซ้ำ หากมี source of truth อยู่แล้ว
- ห้ามสร้าง JavaScript handler ใหม่ หาก handler เดิมสามารถ reuse หรือ extend ได้
- ห้ามสร้าง endpoint ใหม่ หาก endpoint เดิมรองรับ action เดียวกันอยู่แล้ว
- หาก Controller มี helper/collection สำหรับข้อมูลชุดเดียวกัน ให้ extend ของเดิมแทนการ aggregate ซ้ำ
- ก่อนสร้างไฟล์ใหม่ต้องตรวจว่าไม่มีไฟล์เดิมที่เหมาะสมจริง
- ก่อนจบงานให้ตรวจ dead CSS, dead JavaScript และ duplicate selector ที่เกิดจากงานนี้

## CSS Rules

- ไฟล์ CSS หลักของแต่ละหน้าต้องเป็น entry point สำหรับ import component ของหน้านั้น
- รักษาลำดับ `@import` ให้สอดคล้องกับ cascade: base ก่อน component และ responsive/override หลัง component
- ห้ามนำ selector ใหม่ไปกองในไฟล์ขนาดใหญ่ หากมีไฟล์ component ที่ตรงกับหน้าที่นั้นอยู่แล้ว
- ก่อนสร้างไฟล์ใหม่ ให้ตรวจว่ามี component เดิมที่เหมาะสมหรือไม่
- selector ของ component ต้องอยู่ภายใต้ namespace ของหน้า เพื่อไม่ให้กระทบหน้าอื่น
- ใช้ชื่อ class ที่สื่อถึง component และหลีกเลี่ยง selector กลางที่กำกวม
- หลีกเลี่ยง `!important` เว้นแต่ต้อง override library หรือ inline style ที่ควบคุมไม่ได้ และต้องมีเหตุผลชัดเจน
- หลีกเลี่ยง selector ที่ผูกกับโครงสร้าง DOM ลึกเกินไป
- เก็บสี, radius, spacing และ shadow ที่ใช้ซ้ำเป็น CSS custom properties
- อย่าเปลี่ยน selector เดิมระหว่างการจัดโครงสร้าง เว้นแต่ได้ตรวจทุกจุดที่เรียกใช้แล้ว

ตัวอย่าง:

```css
/* Good */
.my-tasks-page .calendar-grid {}
.my-tasks-page .calendar-day {}
.my-tasks-page .calendar-event {}

/* Avoid */
.calendar {}
.card {}
.title {}
.active {}
```

## Loading CSS and JavaScript

- โหลด asset ผ่าน Vite
- ห้ามฝัง CSS ด้วย `<style>` ใน Blade สำหรับ style ที่ควรอยู่ในไฟล์
- ห้ามโหลด CSS ด้วย `file_get_contents()`
- หลีกเลี่ยง inline `style="..."`; ใช้ class และไฟล์ component แทน
- หน้าเดิมที่มี entry point แล้ว ให้ import component ใหม่ผ่าน entry point นั้น
- เมื่อต้องสร้าง entry point ใหม่ ให้ลงทะเบียนใน `vite.config.js`
- ตรวจว่า Blade เรียก entry point ถูกต้องด้วย `@vite`
- อย่าโหลด component CSS เดียวกันซ้ำจากหลาย entry point ในหน้าเดียวกัน

ตัวอย่าง entry point:

```css
@import './mytasks/table.css';
@import './mytasks/board.css';
@import './mytasks/calendar.css';
@import './mytasks/modal.css';
```

## Bootstrap Usage

- ใช้ Bootstrap grid, spacing, form, button, dropdown และ modal ก่อนเขียนระบบใหม่เอง
- Custom CSS ควรใช้สำหรับรูปลักษณ์เฉพาะผลิตภัณฑ์ ไม่ควรทำซ้ำ utility ที่ Bootstrap มีอยู่แล้ว
- เมื่อ override Bootstrap ให้จำกัดขอบเขตด้วย page/component namespace
- รักษา accessibility attributes และ keyboard behavior ของ Bootstrap component

## Responsive and Accessibility

- UI ใหม่ต้องใช้งานได้บน desktop, tablet และ mobile
- ตารางขนาดใหญ่ต้องมีแนวทางสำหรับจอเล็ก เช่น horizontal scroll หรือ responsive view
- ใช้ semantic HTML และ label สำหรับ form control
- ปุ่มที่มีเฉพาะ icon ต้องมี accessible name
- รักษา focus state และ keyboard navigation

## Safe Changes

- ตรวจ `git status` ก่อนแก้ไขเสมอ
- งานที่มีอยู่ใน working tree เป็นของผู้ใช้ ห้ามลบหรือเขียนทับโดยไม่ตรวจสอบ
- จำกัดการแก้ไขให้อยู่ในขอบเขตงานที่ได้รับมอบหมาย
- การ refactor โครงสร้างต้องรักษาหน้าตา พฤติกรรม selector และลำดับ cascade เดิม
- อย่าแก้ controller, route หรือ database หากงานเป็นเพียงการจัดโครงสร้าง frontend
- ก่อนแก้ทุกงานให้รัน `git status` และตรวจ `git diff` ก่อน
- ห้าม revert, overwrite หรือลบ uncommitted work ของผู้ใช้
- ถ้าพบไฟล์ใหม่ที่ยังไม่ track ต้องตรวจเนื้อหาก่อนสร้างไฟล์ชื่อเดียวกันหรือไฟล์หน้าที่ซ้ำกัน

## Verification

หลังแก้ frontend ให้ตรวจอย่างน้อย:

1. รัน `npm run build`
2. รัน `php artisan test`
3. ตรวจว่า Vite หา entry point และ import ทุกไฟล์ได้
4. ตรวจว่าไม่มี `<style>`, `file_get_contents()` หรือ asset loading แบบใหม่ที่ขัดกับกฎนี้
5. ตรวจ responsive state ของ component ที่แก้ หากมีเครื่องมือสำหรับเปิดหน้าเว็บ

เมื่อส่งมอบงาน ให้สรุป:

- ไฟล์ที่สร้างหรือแก้ไข
- component และ namespace ที่ใช้
- จุดที่เพิ่ม import หรือ Vite entry
- ผล build และ tests
- งานเดิมของผู้ใช้ที่ตั้งใจไม่แตะต้อง
