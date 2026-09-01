/**
 * ชุดสีของกราฟรายงาน
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

/**
 * ลำดับการวาดสไลซ์ของโดนัทสถานะ
 *
 * ลำดับตามความหมายคือ กำลังทำ → รอตรวจสอบ → เสร็จสิ้น → พักงาน → ล่าช้า
 * แต่ blue กับ purple ติดกันแล้วตาบอดสีแยกไม่ออก (ΔE 5.4) จึงสลับให้ green มาคั่น
 * เป็นการเรียงเฉพาะตอนวาดเท่านั้น ป้ายยังผูกกับสไลซ์เดิม และ WorkBoardDesign::STATUSES ไม่ถูกแตะ
 */
export const statusSliceOrder = Object.freeze(['blue', 'green', 'purple', 'amber', 'red']);

export const safeSeries = (values) => Array.isArray(values)
    ? values.map((value) => Number.isFinite(Number(value)) && Number(value) >= 0 ? Number(value) : 0)
    : [];
const safeLabels = (labels) => Array.isArray(labels) ? labels.map(String) : [];
const colorsFromTones = (tones) => Array.isArray(tones) ? tones.map((tone) => reportChartColors[tone] || reportChartColors.gray) : [];
export const reportChartAnimation = Object.freeze({duration: 460, easing: 'easeOutQuart'});
export const lineAnimations = Object.freeze({x: {duration: 500, easing: 'easeOutQuart'}, y: {duration: 420, easing: 'easeOutQuart'}});

const tooltip = {backgroundColor: 'rgba(15,23,42,.95)', padding: 11, titleSpacing: 4, bodySpacing: 5, cornerRadius: 9, usePointStyle: true, boxPadding: 5};

/** เปอร์เซ็นต์ในทูลทิปช่วยให้อ่านสัดส่วนได้โดยไม่ต้องกะจากขนาดสไลซ์ */
const shareTooltip = {
    ...tooltip,
    callbacks: {
        label(context) {
            const values = context.dataset.data || [];
            const total = values.reduce((sum, value) => sum + (Number(value) || 0), 0);
            const value = Number(context.raw) || 0;
            const share = total > 0 ? Math.round((value / total) * 100) : 0;

            return `${context.label}: ${value} งาน (${share}%)`;
        },
    },
};

const legend = {position: 'bottom', labels: {usePointStyle: true, pointStyle: 'circle', boxWidth: 7, padding: 14}};
const cartesianPlugins = {legend, tooltip};
const cartesianScales = {
    x: {grid: {display: false}, border: {display: false}, ticks: {color: '#64748b'}},
    y: {beginAtZero: true, ticks: {precision: 0, color: '#64748b'}, border: {display: false}, grid: {color: 'rgba(148,163,184,.13)'}},
};

export function normalizeReportChartData(data = {}) {
    const trend = data.trend || {}, status = data.status || {};
    const completed = data.completed || {}, priority = data.priority || {}, workload = data.workload || {};

    return {
        trend: {labels: safeLabels(trend.labels), created: safeSeries(trend.created), completed: safeSeries(trend.completed)},
        status: {
            labels: safeLabels(status.labels),
            values: safeSeries(status.values),
            colors: colorsFromTones(status.tones),
            tones: Array.isArray(status.tones) ? status.tones.map(String) : [],
        },
        completed: {labels: safeLabels(completed.labels), values: safeSeries(completed.values)},
        priority: {labels: safeLabels(priority.labels), values: safeSeries(priority.values), colors: colorsFromTones(priority.tones)},
        workload: {labels: safeLabels(workload.labels), doing: safeSeries(workload.doing), review: safeSeries(workload.review), late: safeSeries(workload.late)},
    };
}

/**
 * เรียงสไลซ์ตาม statusSliceOrder โดยพาป้าย ค่า และสีไปพร้อมกันเสมอ
 * ถ้าเรียงเฉพาะสีโดยไม่พาป้ายไปด้วย ความหมายของกราฟจะผิดทั้งใบ
 *
 * โทนที่ไม่อยู่ในลำดับ เช่น gray ของสถานะที่ไม่รองรับ จะถูกต่อท้ายตามลำดับเดิม
 */
export function orderStatusSlices({labels = [], values = [], colors = [], tones = []} = {}) {
    const rank = (index) => {
        const position = statusSliceOrder.indexOf(tones[index]);

        return position === -1 ? statusSliceOrder.length : position;
    };

    const indexes = labels
        .map((_, index) => index)
        .sort((left, right) => rank(left) - rank(right) || left - right);

    return {
        labels: indexes.map((index) => labels[index]),
        values: indexes.map((index) => values[index]),
        colors: indexes.map((index) => colors[index]),
    };
}

export function doughnutConfig(data, {cutout = '68%'} = {}) {
    return {
        type: 'doughnut',
        // เส้นคั่นสีพื้นระหว่างสไลซ์คือตัวช่วยแยกแยะนอกเหนือจากสี จำเป็นเมื่อสีข้างเคียงใกล้กัน
        data: {labels: data.labels, datasets: [{data: data.values, backgroundColor: data.colors, borderColor: '#fff', borderWidth: 3, hoverOffset: 6}]},
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout,
            animation: {...reportChartAnimation, animateRotate: true, animateScale: true},
            layout: {padding: {top: 2, right: 8, bottom: 0, left: 8}},
            plugins: {legend: {...legend, labels: {...legend.labels, padding: 12}}, tooltip: shareTooltip},
        },
    };
}

export function buildReportChartConfigs(data = {}) {
    const n = normalizeReportChartData(data);

    return {
        trend: {
            type: 'line',
            data: {
                labels: n.trend.labels,
                datasets: [
                    {label: 'งานที่สร้าง', data: n.trend.created, borderColor: reportChartColors.blue, backgroundColor: 'rgba(29,78,216,.10)', borderWidth: 2, fill: true, tension: .35, pointRadius: 3, pointHoverRadius: 6, pointBackgroundColor: '#fff', pointBorderWidth: 2},
                    {label: 'งานที่เสร็จ', data: n.trend.completed, borderColor: reportChartColors.green, backgroundColor: 'rgba(5,150,105,.09)', borderWidth: 2, fill: true, tension: .35, pointRadius: 3, pointHoverRadius: 6, pointBackgroundColor: '#fff', pointBorderWidth: 2},
                ],
            },
            options: {responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false}, animation: reportChartAnimation, animations: lineAnimations, plugins: cartesianPlugins, scales: cartesianScales},
        },
        status: doughnutConfig(orderStatusSlices(n.status)),
        priority: doughnutConfig(n.priority),
        completed: {
            type: 'bar',
            data: {labels: n.completed.labels, datasets: [{label: 'งานเสร็จ', data: n.completed.values, backgroundColor: reportChartColors.blue, borderRadius: 4, maxBarThickness: 42}]},
            options: {responsive: true, maintainAspectRatio: false, animation: reportChartAnimation, plugins: {legend: {display: false}, tooltip}, scales: cartesianScales},
        },
        workload: {
            type: 'bar',
            // ชั้นซ้อนใช้เส้นคั่นสีพื้น 2px เป็นตัวช่วยแยกแยะนอกเหนือจากสี
            // เพราะ blue↔purple อยู่ในเกณฑ์ที่ยอมรับได้ก็ต่อเมื่อมีตัวช่วยอื่นนอกจากสี
            data: {
                labels: n.workload.labels,
                datasets: [
                    {label: 'กำลังทำ', data: n.workload.doing, backgroundColor: reportChartColors.blue, borderColor: '#fff', borderWidth: 2, borderRadius: 3},
                    {label: 'รอตรวจสอบ', data: n.workload.review, backgroundColor: reportChartColors.purple, borderColor: '#fff', borderWidth: 2, borderRadius: 3},
                    {label: 'ล่าช้า', data: n.workload.late, backgroundColor: reportChartColors.red, borderColor: '#fff', borderWidth: 2, borderRadius: 3},
                ],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: reportChartAnimation,
                plugins: cartesianPlugins,
                scales: {
                    x: {stacked: true, beginAtZero: true, ticks: {precision: 0, color: '#64748b'}, grid: {color: 'rgba(148,163,184,.13)'}},
                    y: {stacked: true, grid: {display: false}, border: {display: false}, ticks: {color: '#334155'}},
                },
            },
        },
    };
}
