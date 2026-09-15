/*
 * กราฟของรายงานปฏิบัติงานประจำเดือน
 *
 * ใช้โทนน้ำเงินเป็นหลักตามการออกแบบของหน้านี้ — งานประจำเป็นน้ำเงินอ่อน งานนอกสถานที่
 * เป็นน้ำเงินเข้มของชุดสีร่วม สีเขียว/ส้ม/แดงสงวนไว้สื่อความหมายของสถานะเท่านั้น
 *
 * หน่วยของกราฟชุดนี้เป็น "ชั่วโมง" ทูลทิปจึงเขียนหน่วยเอง ไม่ใช้ทูลทิปของรายงานโครงการ
 * ที่ต่อท้ายคำว่า "งาน"
 */
import {reportChartAnimation, reportChartColors, safeSeries} from './chart-config.js';

export const operationalChartColors = Object.freeze({
    routine: '#60a5fa',
    field: reportChartColors.blue,
});

const safeLabels = (labels) => Array.isArray(labels) ? labels.map(String) : [];

const tooltipBase = {
    backgroundColor: 'rgba(15,23,42,.95)',
    padding: 11,
    titleSpacing: 4,
    bodySpacing: 5,
    cornerRadius: 9,
    usePointStyle: true,
    boxPadding: 5,
};

/**
 * แปลงข้อมูลดิบจาก JSON island ให้ปลอดภัยต่อการวาด
 *
 * ค่าที่ไม่ใช่ตัวเลขหรือเป็นลบถูกปัดเป็นศูนย์ เพื่อไม่ให้กราฟพังทั้งใบ
 */
export function normalizeOperationalChartData(data = {}) {
    const daily = data.daily || {};
    const workTypes = data.workTypes || {};

    return {
        daily: {
            labels: safeLabels(daily.labels),
            titles: safeLabels(daily.titles),
            routine: safeSeries(daily.routine),
            field: safeSeries(daily.field),
        },
        workTypes: {
            labels: safeLabels(workTypes.labels),
            values: safeSeries(workTypes.values),
        },
    };
}

export function buildOperationalChartConfigs(data = {}) {
    const normalized = normalizeOperationalChartData(data);

    return {
        daily: {
            type: 'bar',
            data: {
                labels: normalized.daily.labels,
                datasets: [
                    {label: 'งานประจำ', data: normalized.daily.routine, backgroundColor: operationalChartColors.routine, borderRadius: 3, maxBarThickness: 18},
                    {label: 'นอกสถานที่', data: normalized.daily.field, backgroundColor: operationalChartColors.field, borderRadius: 3, maxBarThickness: 18},
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reportChartAnimation,
                plugins: {
                    legend: {position: 'top', align: 'end', labels: {usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 9, padding: 14, color: '#475569'}},
                    tooltip: {
                        ...tooltipBase,
                        callbacks: {
                            // แกนแสดงแค่เลขวัน ทูลทิปจึงบอกวันที่เต็มให้รู้ว่าเป็นวันไหนของเดือน
                            title(items) {
                                const index = items[0]?.dataIndex ?? 0;

                                return normalized.daily.titles[index] || items[0]?.label || '';
                            },
                            label(context) {
                                return `${context.dataset.label}: ${Number(context.raw) || 0} ชม.`;
                            },
                        },
                    },
                },
                scales: {
                    x: {stacked: true, grid: {display: false}, border: {display: false}, ticks: {color: '#64748b'}},
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        border: {display: false},
                        grid: {color: 'rgba(148,163,184,.16)'},
                        ticks: {color: '#64748b', precision: 0, callback: (value) => `${value} ชม.`},
                    },
                },
            },
        },
        workTypes: {
            type: 'doughnut',
            data: {
                labels: normalized.workTypes.labels,
                datasets: [{
                    data: normalized.workTypes.values,
                    backgroundColor: [operationalChartColors.routine, operationalChartColors.field],
                    borderColor: '#fff',
                    borderWidth: 3,
                    hoverOffset: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                animation: {...reportChartAnimation, animateRotate: true, animateScale: false},
                plugins: {
                    // คำอธิบายสีอยู่ใต้กราฟใน Blade พร้อมชั่วโมงและสัดส่วน จึงไม่ต้องมี legend ซ้ำ
                    legend: {display: false},
                    tooltip: {
                        ...tooltipBase,
                        callbacks: {
                            label(context) {
                                const values = context.dataset.data || [];
                                const total = values.reduce((sum, value) => sum + (Number(value) || 0), 0);
                                const value = Number(context.raw) || 0;
                                const share = total > 0 ? Math.round((value / total) * 100) : 0;

                                return `${context.label}: ${value} ชม. (${share}%)`;
                            },
                        },
                    },
                },
            },
        },
    };
}
