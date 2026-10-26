import {reportChartAnimation, reportChartColors, safeSeries} from './chart-config.js';

/*
 * กราฟของรายงาน Smart Ticket — 2 ใบ ข้อมูลชุดเดียวกับตารางที่ server render (TicketReportService)
 *
 * trend       — แท่งแนวตั้ง จำนวน Ticket ที่แจ้งรายวัน (ช่วงยาวเป็นรายเดือน) ผลรวมทุกแท่ง = Ticket ทั้งหมด
 * departments — โดนัท สัดส่วน Ticket ตามแผนกผู้แจ้ง: อันดับแรก 5 ชิ้นใช้ชุดสีรายงานตามลำดับ (chart-config.js)
 *               ชิ้นสุดท้าย "อื่น ๆ" (other = true) รวมแผนกที่เหลือและเป็นสีเทา ผลรวมทุกชิ้น = Ticket ทั้งหมด
 *               ไม่มี legend ใน canvas — ชื่อ จำนวน และสัดส่วนเป็นรายการ HTML ที่ server render (อ่านได้แม้กราฟล้ม)
 *
 * ไม่ประกาศสีใหม่ในไฟล์นี้ (ยกเว้นสีตัวอักษรของแกน) ค่าที่ไม่ใช่ตัวเลขหรือติดลบกลายเป็น 0 (safeSeries)
 * และความยาวข้อมูลถูกตัดให้เท่ากับป้าย
 */
const INK = '#334155';
const tooltip = {backgroundColor: 'rgba(15,23,42,.95)', padding: 11, cornerRadius: 9, displayColors: false};
const grid = {color: 'rgba(148,163,184,.13)'};
const labelsOf = (labels) => (Array.isArray(labels) ? labels.map(String) : []);

/** สีชิ้นโดนัทตามลำดับ — ต้องตรงกับ --tr-slice-* ใน reports/tickets.css (มีเทสต์เทียบ) */
export const departmentSliceColors = Object.freeze([
    reportChartColors.blue, reportChartColors.green, reportChartColors.purple, reportChartColors.amber, reportChartColors.red,
]);

export function normalizeTicketChartData(data = {}) {
    const trend = data?.trend || {};
    const departments = data?.departments || {};
    const trendLabels = labelsOf(trend.labels);
    const departmentLabels = labelsOf(departments.labels);

    return {
        trend: {
            labels: trendLabels,
            values: safeSeries(trend.values).slice(0, trendLabels.length),
            unit: trend.unit === 'เดือน' ? 'เดือน' : 'วัน',
        },
        departments: {
            labels: departmentLabels,
            values: safeSeries(departments.values).slice(0, departmentLabels.length),
            other: departments.other === true,
        },
    };
}

/** สีของแต่ละชิ้น: ลำดับชุดสี และชิ้น "อื่น ๆ" ท้ายสุดเป็นเทา */
export function departmentColors({labels, other}) {
    return labels.map((_, index) => (other && index === labels.length - 1
        ? reportChartColors.gray
        : departmentSliceColors[index % departmentSliceColors.length]));
}

const countText = (value) => `${Number(value).toLocaleString('th-TH')} Ticket`;

export function buildTicketChartConfigs(raw = {}) {
    const data = normalizeTicketChartData(raw);

    return {
        trend: {
            type: 'bar',
            data: {
                labels: data.trend.labels,
                datasets: [{
                    label: `Ticket ที่แจ้งต่อ${data.trend.unit}`,
                    data: data.trend.values,
                    backgroundColor: reportChartColors.blue,
                    borderRadius: 4,
                    maxBarThickness: 28,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reportChartAnimation,
                plugins: {legend: {display: false}, tooltip: {...tooltip, callbacks: {label: (context) => countText(context.parsed.y)}}},
                scales: {
                    x: {grid: {display: false}, ticks: {color: INK, autoSkip: true, maxRotation: 0}},
                    y: {beginAtZero: true, grid, ticks: {precision: 0, color: INK}},
                },
            },
        },
        departments: {
            type: 'doughnut',
            data: {
                labels: data.departments.labels,
                datasets: [{
                    label: 'Ticket ตามแผนก',
                    data: data.departments.values,
                    backgroundColor: departmentColors(data.departments),
                    borderColor: '#fff',
                    borderWidth: 2,
                    hoverOffset: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                animation: {...reportChartAnimation, animateRotate: true, animateScale: false},
                plugins: {
                    legend: {display: false},
                    tooltip: {
                        ...tooltip,
                        callbacks: {
                            label(context) {
                                const total = context.dataset.data.reduce((sum, value) => sum + (Number(value) || 0), 0);
                                const share = total > 0 ? Math.round((Number(context.raw) / total) * 1000) / 10 : 0;

                                return `${context.label}: ${countText(context.raw)} (${share.toLocaleString('th-TH')}%)`;
                            },
                        },
                    },
                },
            },
        },
    };
}
