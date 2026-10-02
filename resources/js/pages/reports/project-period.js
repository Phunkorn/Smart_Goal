/*
 * ช่วงเวลาของรายงานโปรเจกต์ — ย้ายตรรกะไปเป็นของกลางที่ components/period-range.js แล้ว
 * เพื่อให้หน้าประชุมใช้พฤติกรรม "กำหนดช่วงวันที่เอง" ชุดเดียวกันจริง ไม่ใช่เขียนซ้ำ
 * ชื่อเดิมยัง export ไว้เพื่อไม่ให้ผู้เรียกและ test ที่มีอยู่ต้องเปลี่ยน import
 */
export {CUSTOM_PERIOD, initPeriodRange as initProjectPeriod} from '../../components/period-range.js';
