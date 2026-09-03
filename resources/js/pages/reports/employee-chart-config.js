import {buildReportChartConfigs} from './chart-config.js';

/**
 * รายงานรายบุคคลใช้ชุดกราฟเดียวกับรายงานภาพรวม ต่างแค่ขอบเขตข้อมูล
 *
 * เดิมสองหน้ามีกราฟคนละชนิดคนละลำดับ (หน้านี้เป็นเส้นและโดนัทสองใบ)
 * หัวหน้าที่สลับไปมาจึงต้องเรียนรู้การอ่านใหม่ทุกครั้ง และการเปลี่ยนดีไซน์
 * ต้องแก้สองที่เสมอ ซึ่งเป็นเหตุให้ทั้งสองหน้าเพี้ยนออกจากกันมาตลอด
 */
export function buildEmployeeChartConfigs(data = {}) {
    const {trend, status, completed, priority} = buildReportChartConfigs(data);

    // ไม่มีกราฟงานค้างรายแผนกในหน้ารายบุคคล เพราะขอบเขตเป็นคนเดียว
    return {trend, status, completed, priority};
}
