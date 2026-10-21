import {reportChartAnimation, safeSeries} from './chart-config.js';

/*
 * กราฟของรายงานโปรเจกต์ประจำเดือน — แต่ละใบใช้รูปแบบที่ตอบคำถามของตัวเอง ไม่ซ้ำกัน
 *
 * trend     — กราฟเส้นรายเดือน มกราคม → เดือนที่เลือก งานที่ได้รับ (อำพัน) เทียบงานที่เสร็จ (น้ำเงิน)
 *             เดือนที่เลือกเป็นจุดทึบขนาดใหญ่ เดือนอื่นเป็นจุดกลวงเล็ก
 * status    — โดนัทสัดส่วนสถานะของช่วงที่เลือก ยอดรวมกลางวง ป้าย/จำนวน/% อยู่ในตาราง HTML ข้างวง
 *             สีมาจาก server (ProjectReportService::STATUS_COLORS) ให้ตรงกับตารางเสมอ
 * breakdown — เฉพาะภาพรวมแผนก: โดนัทสัดส่วนงานที่แต่ละคนปิดได้ รูปแบบเดียวกับโดนัทสถานะ
 *             สีของแต่ละชิ้นมาจาก server (ProjectReportService::MEMBER_COLORS) ให้ตรงกับตารางเสมอ
 * การ์ด "งานล่าช้าที่ต้องติดตาม" เป็นรายการ HTML จึงไม่อยู่ในไฟล์นี้
 *
 * ชุดสีไม่มีเขียว (ผู้บริหารไม่ต้องการ) ผ่าน scripts/validate_palette.js ของ dataviz skill:
 * ช่วงความสว่าง ความอิ่มสี การแยกแยะตาบอดสี และคอนทราสต์ต่อพื้น 3:1
 *
 * received เป็นอำพัน ไม่ใช่เทาอมฟ้าแบบเดิม — #7087ad ไม่ผ่าน chroma floor (อ่านเป็นสีเทา)
 * อำพันคู่กับน้ำเงินต่างกันทั้ง hue และความสว่าง (ΔE ปกติ 40.6 · ตาบอดสี 32.9) เส้นสองเส้นจึงแยกกันได้
 * แม้ตัดกัน แดงยังสงวนไว้สื่อ "ล่าช้า" อย่างเดียว
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
const FONT = '"Prompt", "IBM Plex Sans Thai", "Segoe UI", sans-serif';

const tooltip = {backgroundColor: 'rgba(15,23,42,.95)', padding: 11, titleSpacing: 4, bodySpacing: 5, cornerRadius: 9, usePointStyle: true, boxPadding: 5};
const legend = {position: 'bottom', labels: {usePointStyle: true, pointStyle: 'circle', boxWidth: 7, padding: 14, color: INK}};
const grid = {color: 'rgba(148,163,184,.13)'};
const colorOf = (key) => projectChartColors[key] || projectChartColors.paused;
const labelsOf = (labels) => (Array.isArray(labels) ? labels.map(String) : []);
const isHex = (color) => /^#[0-9a-f]{6}$/i.test(String(color ?? ''));

export function normalizeProjectChartData(data = {}) {
    const trend = data.trend || {};
    const status = data.status || {};
    const breakdown = data.breakdown || {};
    const trendLabels = labelsOf(trend.labels);
    const breakdownLabels = labelsOf(breakdown.labels);
    const selected = Number.isInteger(Number(trend.selected)) ? Number(trend.selected) : trendLabels.length - 1;
    const statusKeys = labelsOf(status.keys);

    return {
        trend: {
            labels: trendLabels,
            created: safeSeries(trend.created).slice(0, trendLabels.length),
            completed: safeSeries(trend.completed).slice(0, trendLabels.length),
            selected: Math.min(Math.max(selected, 0), Math.max(trendLabels.length - 1, 0)),
            // ช่วงวันที่กำหนดเอง: ทุกเดือนในกราฟอยู่ในช่วงที่เลือก จึงเป็นจุดทึบทุกเดือน
            allSelected: trend.all_selected === true,
        },
        status: {
            keys: statusKeys,
            labels: labelsOf(status.labels),
            values: safeSeries(status.values).slice(0, statusKeys.length),
            // สีจาก server ที่ไม่ใช่ hex ตกกลับไปเป็นสีของสถานะนั้นในชุดของหน้านี้
            colors: statusKeys.map((key, index) => (isHex(status.colors?.[index]) ? String(status.colors[index]) : colorOf(key))),
            total: Number.isFinite(Number(status.total)) ? Number(status.total) : 0,
        },
        breakdown: {
            labels: breakdownLabels,
            values: safeSeries(breakdown.values).slice(0, breakdownLabels.length),
            // รับเฉพาะสี hex จาก server ค่าอื่นตกเป็นเทา
            colors: breakdownLabels.map((_, index) => (isHex(breakdown.colors?.[index]) ? String(breakdown.colors[index]) : projectChartColors.paused)),
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

/** ส่วนแบ่งเป็นเปอร์เซ็นต์ปัดเศษ — ใช้ทั้ง tooltip ของโดนัทและตารางข้างวง (ปัดแบบเดียวกับ Blade) */
export const shareOf = (value, total) => (total > 0 ? Math.round((Number(value) / total) * 100) : 0);

/**
 * เส้นหนึ่งเส้นของกราฟแนวโน้ม — เดือนที่เลือกเป็นจุดทึบใหญ่ เดือนอื่นเป็นจุดกลวงเล็ก
 * ความหมายของสียังเหมือนเดิมทุกเดือน ไม่ใช้สีจางแยกเดือนเพราะเส้นจางอ่านยากกว่าแท่งจาง
 */
function trendLine(label, values, color, selected, allSelected = false) {
    const isEmphasised = (index) => allSelected || index === selected;

    return {
        label,
        data: values,
        borderColor: color,
        backgroundColor: color,
        borderWidth: 2.5,
        // โค้งแบบ monotone ไม่พุ่งเกินค่าจริงระหว่างจุด และไม่ลงต่ำกว่าศูนย์
        cubicInterpolationMode: 'monotone',
        fill: false,
        pointStyle: 'circle',
        pointRadius: values.map((_, index) => (isEmphasised(index) ? 5 : 3.5)),
        pointHoverRadius: 7,
        pointBorderWidth: 2,
        pointBorderColor: color,
        pointBackgroundColor: values.map((_, index) => (isEmphasised(index) ? color : '#fff')),
    };
}

/**
 * ป้ายของกราฟแนวโน้ม — จุดทึบสีของเส้น
 * ค่าเริ่มต้นของ Chart.js ใช้สีจุดแรกของเส้น (เดือนแรกเป็นจุดกลวงสีขาว) ป้ายจึงกลายเป็นวงกลวง
 */
export const trendLegend = {
    ...legend,
    labels: {
        ...legend.labels,
        generateLabels: (chart) => chart.data.datasets.map((dataset, index) => ({
            text: dataset.label,
            fillStyle: dataset.borderColor,
            strokeStyle: dataset.borderColor,
            fontColor: INK,
            lineWidth: 0,
            pointStyle: 'circle',
            hidden: !chart.isDatasetVisible(index),
            datasetIndex: index,
        })),
    },
};

/** โดนัทหนึ่งวง — ยอดรวมกลางวง ป้ายอยู่ในตาราง HTML ข้างวง tooltip บอกจำนวนและ % */
function doughnut({labels, values, colors, total}, caption) {
    return {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: colors,
                // เส้นคั่นสีพื้นระหว่างชิ้น เป็นตัวช่วยแยกนอกเหนือจากสี
                borderColor: '#fff',
                borderWidth: 2,
                hoverOffset: 4,
            }],
        },
        plugins: [centerTotalPlugin(total, caption)],
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            animation: {...reportChartAnimation, animateRotate: true, animateScale: false},
            plugins: {
                legend: {display: false},
                tooltip: {...tooltip, callbacks: {label: (item) => `${item.label}: ${item.raw} งาน (${shareOf(item.raw, total)}%)`}},
            },
        },
    };
}

export function buildProjectChartConfigs(data = {}) {
    const n = normalizeProjectChartData(data);

    return {
        trend: {
            type: 'line',
            data: {
                labels: n.trend.labels,
                datasets: [
                    trendLine('งานที่ได้รับ', n.trend.created, projectChartColors.received, n.trend.selected, n.trend.allSelected),
                    trendLine('งานที่เสร็จสิ้น', n.trend.completed, projectChartColors.completed, n.trend.selected, n.trend.allSelected),
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reportChartAnimation,
                // ตอนเข้าหน้า Chart.js เริ่มทุกจุดจากกึ่งกลางแกน x แล้วกระจายออกด้านข้าง ซึ่งดูวุ่นเกินไป
                // ตรึงแกน x ไว้ที่ตำแหน่งจริง ให้เส้นยกขึ้นจากฐานในแนวตั้งอย่างเดียว
                animations: {x: {duration: 0}},
                interaction: {mode: 'index', intersect: false},
                plugins: {legend: trendLegend, tooltip: {...tooltip, callbacks: {label: (item) => `${item.dataset.label}: ${item.raw} งาน`}}},
                scales: {
                    x: {grid: {display: false}, border: {display: false}, ticks: {color: MUTED}},
                    // เว้นหัวกราฟเล็กน้อยไม่ให้จุดสูงสุดชนขอบ
                    y: {beginAtZero: true, grace: '8%', ticks: {precision: 0, color: MUTED}, border: {display: false}, grid},
                },
            },
        },
        status: doughnut(n.status, 'งานทั้งหมด'),
        breakdown: doughnut(n.breakdown, 'งานที่ปิดได้'),
    };
}
