const initializedCanvases = new WeakSet();
const claimedCanvases = new WeakSet();

export function chartHasData(config = {}) {
    if (config.reportHasData === true) return true;
    const datasets = Array.isArray(config?.data?.datasets) ? config.data.datasets : [];

    return datasets.some((dataset) => Array.isArray(dataset.data)
        && dataset.data.some((value) => Number.isFinite(Number(value)) && Number(value) > 0));
}

export function prefersReducedMotion(windowObject = globalThis.window) {
    return Boolean(windowObject?.matchMedia?.('(prefers-reduced-motion: reduce)').matches);
}

export function scheduleAfterFirstPaint(callback, windowObject = globalThis.window) {
    const requestFrame = windowObject?.requestAnimationFrame?.bind(windowObject);
    if (!requestFrame) {
        callback();
        return;
    }

    requestFrame(() => requestFrame(callback));
}

export function createChartReadyPlugin(card, id) {
    let revealed = false;

    return {
        id: `report-ready-${id}`,
        afterDraw() {
            if (revealed) return;
            revealed = true;
            card.dataset.chartState = 'ready';
            card.dispatchEvent(new card.ownerDocument.defaultView.CustomEvent('report:chart-ready', {detail: {id}}));
        },
    };
}

export function initializeChartCards({
    root = document,
    ChartCtor,
    configs = {},
    definitions = [],
    reducedMotion = prefersReducedMotion(),
    schedule = (callback) => scheduleAfterFirstPaint(callback, root.defaultView ?? root.ownerDocument?.defaultView),
} = {}) {
    const charts = [];

    definitions.forEach(({id, key}) => {
        const canvas = root.getElementById(id);
        if (!canvas || claimedCanvases.has(canvas) || initializedCanvases.has(canvas)) return;

        const card = canvas.closest('[data-report-chart]');
        const config = configs[key];
        claimedCanvases.add(canvas);
        if (!card || !config || !chartHasData(config)) {
            if (card) card.dataset.chartState = 'empty';
            return;
        }

        schedule(() => {
            card.dataset.chartState = 'initializing';

            try {
                const readyPlugin = createChartReadyPlugin(card, id);
                const chartConfig = {
                    ...config,
                    options: reducedMotion ? {...config.options, animation: false, animations: false} : {...config.options},
                    plugins: [...(Array.isArray(config.plugins) ? config.plugins : []), readyPlugin],
                };
                const chart = new ChartCtor(canvas, chartConfig);
                initializedCanvases.add(canvas);
                charts.push(chart);
            } catch (error) {
                card.dataset.chartState = 'error';
                card.dispatchEvent(new card.ownerDocument.defaultView.CustomEvent('report:chart-error', {detail: {id, error}}));
            }
        });
    });

    return charts;
}

export function parseChartData(element) {
    if (!element) return {};

    try {
        const parsed = JSON.parse(element.textContent || '{}');
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch {
        return {};
    }
}
