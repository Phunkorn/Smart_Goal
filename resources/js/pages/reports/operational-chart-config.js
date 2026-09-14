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
 * 1. งานนอกสถานที่เป็น teal และงานประจำ/รายคนแยกกันด้วย blue กับ purple
 *    ซึ่งห่างกันพอให้แยกออกจากกันได้ ส่วนเขียวถูกเพิ่มเข้ามาเฉพาะความหมาย
 *    "ทำแล้ว" ของกราฟงานวันนี้ ให้ตรงกับแถบความคืบหน้าในการ์ดรายคน
 *    การให้กราฟกับการ์ดใช้คนละสีสำหรับสถานะเดียวกันทำให้อ่านเป็นคนละเรื่อง
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
    /*
     * เขียวของ "ทำแล้ว" — ค่าเดียวกับแถบความคืบหน้าในการ์ดรายคน
     * (report-people-card__bar > span.is-green) กราฟกับการ์ดจึงอ่านเป็นเรื่องเดียวกัน
     */
    green: '#10b981',
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

/* กราฟงานของวันนี้นับเป็น "รายการ" ไม่ใช่ชั่วโมง ทูลทิปจึงต้องมีหน่วยของตัวเอง */
const countTooltip = {
    ...tooltipBase,
    callbacks: {
        label(context) {
            return `${context.dataset.label || context.label}: ${Number(context.raw) || 0} รายการ`;
        },
    },
};

/*
 * ป้ายเวลาที่เลือกหน่วยตามขนาดของค่าเอง
 *
 * งานปฏิบัติการหลายรายการกินเวลาไม่กี่นาที การบังคับหน่วยเป็นชั่วโมงทำให้แกน
 * เต็มไปด้วย 0.01–0.1 ซึ่งอ่านไม่ได้ ส่วนงานที่กินเวลาทั้งวันถ้าแสดงเป็นนาที
 * ก็กลายเป็นเลขสี่หลัก กติกาเดียวกับ WorkLogDesign::durationLabel() ฝั่ง PHP
 */
const minuteLabel = (minutes) => {
    const value = Math.max(0, Math.round(Number(minutes) || 0));

    if (value < 60) {
        return `${value} น.`;
    }

    const hours = Math.floor(value / 60);
    const rest = value % 60;

    return rest === 0 ? `${hours} ชม.` : `${hours} ชม. ${rest} น.`;
};

const minuteTooltip = {
    ...tooltipBase,
    callbacks: {
        label(context) {
            return `ใช้เวลา ${minuteLabel(context.raw)}`;
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

/*
 * แกนของกราฟที่นับเป็น "รายการ" ไม่ใช่ชั่วโมง
 *
 * ของเดิมกราฟงานประจำยืมแกนของชั่วโมงมาใช้ทั้งดุ้น แกนจึงเขียนว่า "100 ชม."
 * ทับค่าที่เป็นจำนวนรายการ คนอ่านกราฟเลยเข้าใจหน่วยผิดตั้งแต่แรกเห็น
 */
const countScales = {
    x: hourScales.x,
    y: {
        ...hourScales.y,
        ticks: {color: '#64748b', precision: 0},
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

    return {
        daily: {
            labels: safeLabels(daily.labels),
            routine: safeSeries(daily.routine),
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
        todayMembers: {
            labels: safeLabels((data.todayMembers || {}).labels),
            done: safeSeries((data.todayMembers || {}).done),
            pending: safeSeries((data.todayMembers || {}).pending),
            // ไฮไลต์คนที่กำลังดูอยู่ เพื่อให้หาตัวเองเจอในกราฟของทั้งแผนก
            highlight: safeSeries((data.todayMembers || {}).highlight),
        },
    };
}

export function buildOperationalChartConfigs(data = {}) {
    const normalized = normalizeOperationalChartData(data);

    return {
        /*
         * กราฟใบเดียวของหน้านี้ — งานของวันนี้รายคน
         *
         * เป็นภาพรวมของกระดานการ์ดที่อยู่ใต้มันโดยตรง: ชุดข้อมูลเดียวกัน ลำดับเดียวกัน
         * หน่วยเดียวกัน (รายการ) และใช้สีชุดเดียวกับแถบความคืบหน้าในการ์ด — เขียวคือ
         * ทำแล้ว แดงคือยังไม่เสร็จ คนอ่านจึงโยงแท่งกับการ์ดได้โดยไม่ต้องแปลงหน่วยในหัว
         *
         * ซ้อนแท่งเพราะผลรวมของสองค่าคือ "งานทั้งหมดของวันนี้" ความสูงจึงอ่านเป็นภาระ
         * ของคนนั้น และสัดส่วนสีอ่านเป็นผลการทำ ซึ่งเป็นสองคำถามที่หัวหน้าถามพร้อมกัน
         */
        todayMembers: {
            type: 'bar',
            data: {
                labels: normalized.todayMembers.labels,
                datasets: [
                    {
                        label: 'ทำแล้ว',
                        data: normalized.todayMembers.done,
                        backgroundColor: operationalToneColors.green,
                        borderRadius: 3,
                        maxBarThickness: 30,
                    },
                    {
                        label: 'ยังไม่เสร็จ',
                        data: normalized.todayMembers.pending,
                        backgroundColor: operationalToneColors.red,
                        borderRadius: 3,
                        maxBarThickness: 30,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reportChartAnimation,
                plugins: {legend, tooltip: countTooltip},
                scales: {
                    x: {...countScales.x, stacked: true},
                    y: {...countScales.y, stacked: true},
                },
            },
        },
        daily: {
            type: 'bar',
            data: {
                labels: normalized.daily.labels,
                datasets: [
                    {label: 'งานประจำ', data: normalized.daily.routine, backgroundColor: reportChartColors.blue, borderRadius: 3, maxBarThickness: 26},
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
    };
}
