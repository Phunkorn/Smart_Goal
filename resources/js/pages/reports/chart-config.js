/**
 * ชุดสีและค่าร่วมของกราฟรายงาน — ใช้โดย operational-chart-config.js และ project-chart-config.js
 * และ project-chart-config.js
 *
 * ลำดับ blue → green → purple → amber → red เป็นลำดับคงที่ที่ผ่านการตรวจครบทุกเกณฑ์
 * (ช่วงความสว่าง, ความอิ่มสี, การแยกแยะสำหรับตาบอดสี, การแยกแยะสำหรับสายตาปกติ และคอนทราสต์ต่อพื้น)
 * ห้ามเปลี่ยนค่าหรือสลับลำดับโดยไม่ตรวจซ้ำ ชุดเดิมมี amber ที่คอนทราสต์เพียง 2.09 และ
 * คู่ amber↔green ที่ตาบอดสีแยกไม่ออก
 *
 * gray เป็นสีสำรองสำหรับสถานะที่ไม่รองรับเท่านั้น ไม่นับเป็นสีของชุดข้อมูล
 * และต้องมีป้ายกำกับเสมอเพราะความอิ่มสีต่ำเกินกว่าจะสื่อความหมายด้วยตัวเอง
 */
export const reportChartColors = {
    gray: '#64748b', blue: '#1d4ed8', green: '#059669', purple: '#a21caf', amber: '#d97706', red: '#e11d48',
};

export const safeSeries = (values) => Array.isArray(values)
    ? values.map((value) => Number.isFinite(Number(value)) && Number(value) >= 0 ? Number(value) : 0)
    : [];

/**
 * การวาดกราฟตอนเข้าหน้า — 700ms ด้วย easeOutQuint ซึ่งเป็นเส้นโค้งเดียวกับ cubic-bezier(0.22, 1, 0.36, 1)
 * ที่การ์ดและตารางของหน้าใช้ (shared.css: --report-ease) ทั้งหน้าจึงชะลอเข้าที่ด้วยจังหวะเดียวกัน
 *
 * เล่นครั้งเดียวตอนสร้างกราฟ: initializeChartCards สร้างแต่ละ canvas ครั้งเดียว และการย่อขยายหน้าต่าง
 * ใช้โหมด resize ของ Chart.js ซึ่งไม่มีแอนิเมชัน ผู้ที่ตั้ง prefers-reduced-motion ถูกปิดที่ chart-lifecycle.js
 */
export const reportChartAnimation = Object.freeze({duration: 700, easing: 'easeOutQuint'});
