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

export const reportChartAnimation = Object.freeze({duration: 460, easing: 'easeOutQuart'});
