import {reportChartAnimation, safeSeries} from './chart-config.js';

/*
 * กราฟของรายงานโปรเจกต์ประจำเดือน — แท่งทั้งหมด อ่านแบบเดียวกันทั้งหน้า
 *
 * trend     — แท่งคู่รายเดือน มกราคม → เดือนที่เลือก งานที่ได้รับ (บริบท) เทียบงานที่เสร็จ (หลัก)
 *             เดือนที่เลือกสีเข้มเต็ม เดือนอื่นจางลง
 * status    — แท่งแนวนอนหนึ่งแถวต่อสถานะ จำนวนและเปอร์เซ็นต์เขียนท้ายแท่ง
 * breakdown — เฉพาะภาพรวมแผนก: โดนัทสัดส่วนงานที่แต่ละคนปิดได้ ยอดรวมกลางวง ชื่อ/จำนวน/% อยู่ในตาราง HTML ข้างวง
 *             สีของแต่ละชิ้นมาจาก server (ProjectReportService::MEMBER_COLORS) ให้ตรงกับตารางเสมอ
 * การ์ด "งานล่าช้าที่ต้องติดตาม" เป็นรายการ HTML จึงไม่อยู่ในไฟล์นี้
 *
 * ชุดสีไม่มีเขียว (ผู้บริหารไม่ต้องการ) ผ่าน scripts/validate_palette.js ของ dataviz skill:
 * ช่วงความสว่าง ความอิ่มสี การแยกแยะตาบอดสี และคอนทราสต์ต่อพื้น 3:1
 *
 * received เป็นอำพัน ไม่ใช่เทาอมฟ้าแบบเดิม — #7087ad ไม่ผ่าน chroma floor (อ่านเป็นสีเทา)
 * พอเดือนที่ไม่ได้เลือกถูกทำให้จางลงอีกชั้น แท่งสองชุดจึงกลืนเป็นสีฟ้าซีดเหมือนกันจนแยกไม่ออก
 * อำพันคู่กับน้ำเงินต่างกันทั้ง hue และความสว่าง (ΔE ปกติ 40.6 · ตาบอดสี 32.9) และยังแยกกันได้
 * แม้ในแท่งที่จางลง แดงยังสงวนไว้สื่อ "ล่าช้า" อย่างเดียว
 */
export const projectChartColors = Object.freeze({
    received: '#d97706',
    completed: '#1d4ed8',
    done: '#1d4ed8',
    doing: '#0891b2',
    review: '#a21caf',
    paused: '#64748b',
    late: '#e11d48',
});

const INK = '#334155';
const MUTED = '#64748b';
const LABEL_LIMIT = 22;
const FONT = '"Prompt", "IBM Plex Sans Thai", "Segoe UI", sans-serif';

const tooltip = {backgroundColor: 'rgba(15,23,42,.95)', padding: 11, titleSpacing: 4, bodySpacing: 5, cornerRadius: 9, usePointStyle: true, boxPadding: 5};
const legend = {position: 'bottom', labels: {usePointStyle: true, pointStyle: 'circle', boxWidth: 7, padding: 14, color: INK}};
const grid = {color: 'rgba(148,163,184,.13)'};
const colorOf = (key) => projectChartColors[key] || projectChartColors.paused;
const labelsOf = (labels) => (Array.isArray(labels) ? labels.map(String) : []);
const shorten = (label) => (label.length > LABEL_LIMIT ? `${label.slice(0, LABEL_LIMIT - 1)}…` : label);

/** สีเดิมแต่โปร่งใส ใช้ลดน้ำหนักเดือนที่ไม่ได้เลือก */
export const tint = (hex, alpha) => {
    const value = Number.parseInt(hex.slice(1), 16);

    return `rgba(${(value >> 16) & 255},${(value >> 8) & 255},${value & 255},${alpha})`;
};

export function normalizeProjectChartData(data = {}) {
    const trend = data.trend || {};
    const status = data.status || {};
    const breakdown = data.breakdown || {};
    const trendLabels = labelsOf(trend.labels);
    const breakdownLabels = labelsOf(breakdown.labels);
    const selected = Number.isInteger(Number(trend.selected)) ? Number(trend.selected) : trendLabels.length - 1;

    return {
        trend: {
            labels: trendLabels,
            created: safeSeries(trend.created).slice(0, trendLabels.length),
            completed: safeSeries(trend.completed).slice(0, trendLabels.length),
            selected: Math.min(Math.max(selected, 0), Math.max(trendLabels.length - 1, 0)),
            // ช่วงวันที่กำหนดเอง: ทุกเดือนในกราฟอยู่ในช่วงที่เลือก จึงเต็มสีทุกแท่ง
            allSelected: trend.all_selected === true,
        },
        status: {
            keys: labelsOf(status.keys),
            labels: labelsOf(status.labels),
            values: safeSeries(status.values),
            total: Number.isFinite(Number(status.total)) ? Number(status.total) : 0,
        },
        breakdown: {
            labels: breakdownLabels,
            values: safeSeries(breakdown.values).slice(0, breakdownLabels.length),
            // รับเฉพาะสี hex จาก server ค่าอื่นตกเป็นเทา
            colors: breakdownLabels.map((_, index) => {
                const color = String(breakdown.colors?.[index] ?? '');

                return /^#[0-9a-f]{6}$/i.test(color) ? color : projectChartColors.paused;
            }),
            total: Number.isFinite(Number(breakdown.total)) ? Number(breakdown.total) : 0,
        },
    };
}

/** ยอดรวมกลางวงโดนัท — ตัวเลขใหญ่สีหมึกหลัก คำอธิบายสีรอง */
export function centerTotalPlugin(total, caption) {
    return {
        id: 'projectCenterTotal',
        afterDraw(chart) {
            const {ctx, chartArea} = chart;
            if (!chartArea) return;

            const x = (chartArea.left + chartArea.right) / 2;
            const y = (chartArea.top + chartArea.bottom) / 2;

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = '#0f172a';
            ctx.font = `700 24px ${FONT}`;
            ctx.fillText(String(total), x, y - 8);
            ctx.fillStyle = MUTED;
            ctx.font = `400 12px ${FONT}`;
            ctx.fillText(caption, x, y + 14);
            ctx.restore();
        },
    };
}

/** จำนวนและเปอร์เซ็นต์ท้ายแท่งสถานะ — สีหมึกของข้อความ ไม่ใช้สีของแท่ง */
export function statusValueLabels(total) {
    return {
        id: 'projectStatusValueLabels',
        afterDatasetsDraw(chart) {
            const {ctx} = chart;
            const values = chart.data.datasets?.[0]?.data || [];

            ctx.save();
            ctx.fillStyle = INK;
            ctx.font = `600 11px ${FONT}`;
            ctx.textBaseline = 'middle';
            ctx.textAlign = 'left';
            chart.getDatasetMeta(0).data.forEach((element, index) => {
                const value = Number(values[index]) || 0;
                const share = total > 0 ? Math.round((value / total) * 100) : 0;
                ctx.fillText(`${value} งาน · ${share}%`, element.x + 8, element.y);
            });
            ctx.restore();
        },
    };
}

function trendDataset(label, values, color, selected, allSelected = false) {
    return {
        label,
        data: values,
        // เดือนที่เลือกสีเต็ม เดือนอื่นจางลง ความหมายของสียังเหมือนเดิมทุกเดือน
        backgroundColor: values.map((_, index) => (allSelected || index === selected ? color : tint(color, 0.45))),
        hoverBackgroundColor: color,
        borderRadius: 4,
        borderSkipped: 'bottom',
        maxBarThickness: 18,
        categoryPercentage: 0.7,
        barPercentage: 0.9,
    };
}

const shortTicks = {color: INK, callback(value) { return shorten(String(this.getLabelForValue(value))); }};

export function buildProjectChartConfigs(data = {}) {
    const n = normalizeProjectChartData(data);

    return {
        trend: {
            type: 'bar',
            data: {
                labels: n.trend.labels,
                datasets: [
                    trendDataset('งานที่ได้รับ', n.trend.created, projectChartColors.received, n.trend.selected, n.trend.allSelected),
                    trendDataset('งานที่เสร็จ', n.trend.completed, projectChartColors.completed, n.trend.selected, n.trend.allSelected),
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reportChartAnimation,
                interaction: {mode: 'index', intersect: false},
                plugins: {legend, tooltip: {...tooltip, callbacks: {label: (item) => `${item.dataset.label}: ${item.raw} งาน`}}},
                scales: {
                    x: {grid: {display: false}, border: {display: false}, ticks: {color: MUTED}},
                    y: {beginAtZero: true, ticks: {precision: 0, color: MUTED}, border: {display: false}, grid},
                },
            },
        },
        status: {
            type: 'bar',
            data: {
                labels: n.status.labels,
                datasets: [{
                    label: 'จำนวนงาน',
                    data: n.status.values,
                    backgroundColor: n.status.keys.map(colorOf),
                    borderRadius: 4,
                    borderSkipped: 'left',
                    maxBarThickness: 20,
                }],
            },
            plugins: [statusValueLabels(n.status.total)],
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: reportChartAnimation,
                // เผื่อที่ทางขวาให้จำนวนและเปอร์เซ็นต์ท้ายแท่ง
                layout: {padding: {right: 84}},
                plugins: {legend: {display: false}, tooltip: {...tooltip, callbacks: {label: (item) => `${item.label}: ${item.raw} งาน`}}},
                scales: {
                    x: {beginAtZero: true, ticks: {precision: 0, color: MUTED}, border: {display: false}, grid},
                    y: {grid: {display: false}, border: {display: false}, ticks: {color: INK}},
                },
            },
        },
        breakdown: {
            type: 'doughnut',
            data: {
                labels: n.breakdown.labels,
                datasets: [{
                    data: n.breakdown.values,
                    backgroundColor: n.breakdown.colors,
                    // เส้นคั่นสีพื้นระหว่างชิ้น เป็นตัวช่วยแยกนอกเหนือจากสี
                    borderColor: '#fff',
                    borderWidth: 2,
                    hoverOffset: 4,
                }],
            },
            plugins: [centerTotalPlugin(n.breakdown.total, 'งานที่ปิดได้')],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                animation: {...reportChartAnimation, animateRotate: true, animateScale: false},
                plugins: {
                    // ชื่อ จำนวน และเปอร์เซ็นต์อยู่ในตาราง HTML ข้างวงแล้ว
                    legend: {display: false},
                    tooltip: {
                        ...tooltip,
                        callbacks: {
                            label(item) {
                                const share = n.breakdown.total > 0 ? Math.round((Number(item.raw) / n.breakdown.total) * 100) : 0;

                                return `${item.label}: ${item.raw} งาน (${share}%)`;
                            },
                        },
                    },
                },
            },
        },
    };
}
