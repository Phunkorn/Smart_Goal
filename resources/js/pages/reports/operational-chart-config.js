/*
 * กราฟของรายงานภาระงานปฏิบัติการ
 *
 * ใช้ชุดสีและตัวช่วยจาก chart-config.js ที่มีอยู่แล้ว ไม่ประกาศสีชุดใหม่
 * เพราะลำดับสีนั้นผ่านการตรวจคอนทราสต์และการแยกแยะสำหรับตาบอดสีมาแล้ว
 * (ดูคำอธิบายบนหัวไฟล์ chart-config.js)
 *
 * หน่วยของกราฟชุดนี้เป็น "ชั่วโมง" ไม่ใช่ "จำนวนงาน" ทูลทิปจึงต้องเขียนหน่วยเอง
 * แทนการใช้ shareTooltip ของรายงานโครงการที่ต่อท้ายคำว่า "งาน"
 */
import {reportChartAnimation, reportChartColors, safeSeries} from './chart-config.js';

/**
 * สีของรายงานนี้
 *
 * สองข้อที่ต่างจากรายงานโครงการ:
 *
 * 1. ไม่ใช้สีเขียวเลย ตามการตัดสินใจด้านการออกแบบของฟีเจอร์นี้ ทั้งในกราฟและ
 *    หน้ารายการ งานนอกสถานที่จึงเป็น teal และงานประจำ/รายคนแยกกันด้วย blue
 *    กับ purple ซึ่งห่างกันพอให้แยกออกโดยไม่ต้องพึ่งเขียว
 *
 * 2. หมวดงานเก็บ tone ไว้ในฐานข้อมูลและมีค่าที่ชุดสีของรายงานโครงการไม่มี
 *    (teal, cyan) ถ้าปล่อยให้ตกไปใช้สีเทาสำรอง หมวดงานหลายหมวดจะกลายเป็น
 *    สีเดียวกันหมดจนแยกไม่ออก จึงเติมค่าที่ขาดไว้ที่นี่ โดยเลือกเฉดเข้ม (700)
 *    ให้คอนทราสต์บนพื้นขาวใกล้เคียงกับสีในชุดเดิม
 */
const operationalToneColors = {
    ...reportChartColors,
    teal: '#0f766e',
    cyan: '#0e7490',
};

const colorForTone = (tone) => operationalToneColors[tone] || operationalToneColors.gray;

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

const hourTooltip = {
    ...tooltipBase,
    callbacks: {
        label(context) {
            const value = Number(context.raw) || 0;

            return `${context.dataset.label || context.label}: ${value} ชม.`;
        },
    },
};

const hourShareTooltip = {
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
};

const legend = {position: 'bottom', labels: {usePointStyle: true, pointStyle: 'circle', boxWidth: 7, padding: 14}};

const hourScales = {
    x: {grid: {display: false}, border: {display: false}, ticks: {color: '#64748b'}},
    y: {
        beginAtZero: true,
        border: {display: false},
        grid: {color: 'rgba(148,163,184,.13)'},
        ticks: {color: '#64748b', callback: (value) => `${value} ชม.`},
    },
};

/**
 * แปลงข้อมูลดิบจาก JSON island ให้ปลอดภัยต่อการวาด
 *
 * ค่าที่ไม่ใช่ตัวเลขหรือเป็นลบถูกปัดเป็นศูนย์ เพื่อไม่ให้กราฟพังทั้งใบ
 * เมื่อข้อมูลชุดหนึ่งมีปัญหา
 */
export function normalizeOperationalChartData(data = {}) {
    const daily = data.daily || {};
    const categories = data.categories || {};
    const members = data.members || {};
    const interrupts = data.interrupts || {};

    return {
        daily: {
            labels: safeLabels(daily.labels),
            routine: safeSeries(daily.routine),
            interrupt: safeSeries(daily.interrupt),
            field: safeSeries(daily.field),
        },
        categories: {
            labels: safeLabels(categories.labels),
            values: safeSeries(categories.values),
            colors: Array.isArray(categories.tones) ? categories.tones.map(colorForTone) : [],
        },
        members: {
            labels: safeLabels(members.labels),
            values: safeSeries(members.values),
        },
        interrupts: {
            labels: safeLabels(interrupts.labels),
            values: safeSeries(interrupts.values),
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
                    {label: 'งานประจำ', data: normalized.daily.routine, backgroundColor: reportChartColors.blue, borderRadius: 3, maxBarThickness: 26},
                    {label: 'งานแทรก', data: normalized.daily.interrupt, backgroundColor: reportChartColors.amber, borderRadius: 3, maxBarThickness: 26},
                    {label: 'งานนอกสถานที่', data: normalized.daily.field, backgroundColor: operationalToneColors.teal, borderRadius: 3, maxBarThickness: 26},
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reportChartAnimation,
                plugins: {legend, tooltip: hourTooltip},
                scales: {
                    x: {...hourScales.x, stacked: true},
                    y: {...hourScales.y, stacked: true},
                },
            },
        },
        categories: {
            type: 'doughnut',
            data: {
                labels: normalized.categories.labels,
                datasets: [{
                    data: normalized.categories.values,
                    backgroundColor: normalized.categories.colors,
                    borderColor: '#fff',
                    borderWidth: 3,
                    hoverOffset: 6,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                animation: {...reportChartAnimation, animateRotate: true, animateScale: true},
                plugins: {legend: {...legend, labels: {...legend.labels, padding: 12}}, tooltip: hourShareTooltip},
            },
        },
        members: {
            type: 'bar',
            data: {
                labels: normalized.members.labels,
                datasets: [{
                    label: 'ชั่วโมง',
                    data: normalized.members.values,
                    backgroundColor: reportChartColors.purple,
                    borderRadius: 4,
                    maxBarThickness: 22,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: reportChartAnimation,
                plugins: {legend: {display: false}, tooltip: hourTooltip},
                scales: {
                    x: {
                        beginAtZero: true,
                        border: {display: false},
                        grid: {color: 'rgba(148,163,184,.13)'},
                        ticks: {color: '#64748b', callback: (value) => `${value} ชม.`},
                    },
                    y: {grid: {display: false}, border: {display: false}, ticks: {color: '#334155'}},
                },
            },
        },
        interrupts: {
            type: 'line',
            data: {
                labels: normalized.interrupts.labels,
                datasets: [{
                    label: 'งานแทรก',
                    data: normalized.interrupts.values,
                    borderColor: reportChartColors.red,
                    backgroundColor: 'rgba(225,29,72,.12)',
                    fill: true,
                    tension: .3,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reportChartAnimation,
                plugins: {legend: {display: false}, tooltip: tooltipBase},
                scales: {
                    x: {grid: {display: false}, border: {display: false}, ticks: {color: '#64748b'}},
                    y: {
                        beginAtZero: true,
                        border: {display: false},
                        grid: {color: 'rgba(148,163,184,.13)'},
                        ticks: {precision: 0, color: '#64748b'},
                    },
                },
            },
        },
    };
}
