import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {mountDom} from './helpers/dom.js';
import {chartHasData, initializeChartCards, parseChartData, prefersReducedMotion, scheduleAfterFirstPaint} from '../../resources/js/pages/reports/chart-lifecycle.js';

const markup = (ids) => `<!doctype html><html><body>${ids.map((id) => `<article data-report-chart data-chart-state="loading"><canvas id="${id}"></canvas></article>`).join('')}</body></html>`;
const config = (values = [1]) => ({type: 'bar', data: {datasets: [{data: values}]}, options: {animation: {duration: 460}}});
const immediately = (callback) => callback();

test('chart data parser accepts objects and safely rejects malformed data', () => {
    const env = mountDom('<script id="valid">{"value":1}</script><script id="invalid">{bad</script><script id="array">[]</script>');
    try {
        assert.deepEqual(parseChartData(env.document.getElementById('valid')), {value: 1});
        assert.deepEqual(parseChartData(env.document.getElementById('invalid')), {});
        assert.deepEqual(parseChartData(env.document.getElementById('array')), {});
    } finally { env.cleanup(); }
});

test('first-paint scheduler crosses two animation frames without a timeout', () => {
    const frames = [];
    let called = false;
    const windowObject = {requestAnimationFrame: (callback) => frames.push(callback)};

    scheduleAfterFirstPaint(() => { called = true; }, windowObject);
    assert.equal(called, false);
    assert.equal(frames.length, 1);
    frames.shift()();
    assert.equal(called, false);
    assert.equal(frames.length, 1);
    frames.shift()();
    assert.equal(called, true);

    const source = readFileSync(new URL('../../resources/js/pages/reports/chart-lifecycle.js', import.meta.url), 'utf8');
    assert.doesNotMatch(source, /setTimeout\s*\(/);
});

test('chart lifecycle moves loading to initializing and reveals on the first chart draw', () => {
    const env = mountDom(markup(['chart']));
    let chartConfig;
    class ChartStub { constructor(canvas, receivedConfig) { chartConfig = receivedConfig; } }
    try {
        initializeChartCards({root: env.document, ChartCtor: ChartStub, configs: {main: config()}, definitions: [{id: 'chart', key: 'main'}], schedule: immediately});
        const card = env.document.querySelector('[data-report-chart]');
        assert.equal(card.dataset.chartState, 'initializing');
        chartConfig.plugins.at(-1).afterDraw();
        assert.equal(card.dataset.chartState, 'ready');
    } finally { env.cleanup(); }
});

test('chart initializes each canvas once even before its render hook runs', () => {
    const env = mountDom(markup(['chart']));
    let instances = 0;
    class ChartStub { constructor() { instances += 1; } }
    try {
        const options = {root: env.document, ChartCtor: ChartStub, configs: {main: config()}, definitions: [{id: 'chart', key: 'main'}], schedule: immediately};
        assert.equal(initializeChartCards(options).length, 1);
        assert.equal(initializeChartCards(options).length, 0);
        assert.equal(instances, 1);
    } finally { env.cleanup(); }
});

test('empty datasets render empty state without constructing a chart', () => {
    const env = mountDom(markup(['empty']));
    let instances = 0;
    class ChartStub { constructor() { instances += 1; } }
    try {
        initializeChartCards({root: env.document, ChartCtor: ChartStub, configs: {main: config([0, 0])}, definitions: [{id: 'empty', key: 'main'}], schedule: immediately});
        assert.equal(instances, 0);
        assert.equal(env.document.querySelector('[data-report-chart]').dataset.chartState, 'empty');
        assert.equal(chartHasData(config(['bad', -1])), false);
    } finally { env.cleanup(); }
});

test('explicit report metadata keeps a meaningful zero-percent chart visible', () => {
    assert.equal(chartHasData({...config([0]), reportHasData: true}), true);
});

test('one failed chart enters error state without preventing another chart', () => {
    const env = mountDom(markup(['bad', 'good']));
    class ChartStub { constructor(canvas, receivedConfig) { if (canvas.id === 'bad') throw new Error('broken'); receivedConfig.plugins.at(-1).afterDraw(); } }
    try {
        const charts = initializeChartCards({root: env.document, ChartCtor: ChartStub, configs: {bad: config(), good: config()}, definitions: [{id: 'bad', key: 'bad'}, {id: 'good', key: 'good'}], schedule: immediately});
        assert.equal(charts.length, 1);
        assert.equal(env.document.getElementById('bad').closest('article').dataset.chartState, 'error');
        assert.equal(env.document.getElementById('good').closest('article').dataset.chartState, 'ready');
    } finally { env.cleanup(); }
});

test('reduced motion disables chart motion without mutating the shared config', () => {
    const env = mountDom(markup(['chart']));
    const originalConfig = config();
    let receivedConfig;
    class ChartStub { constructor(canvas, configValue) { receivedConfig = configValue; configValue.plugins.at(-1).afterDraw(); } }
    try {
        env.window.matchMedia = () => ({matches: true});
        assert.equal(prefersReducedMotion(env.window), true);
        initializeChartCards({root: env.document, ChartCtor: ChartStub, configs: {main: originalConfig}, definitions: [{id: 'chart', key: 'main'}], reducedMotion: true, schedule: immediately});
        assert.equal(receivedConfig.options.animation, false);
        assert.equal(receivedConfig.options.animations, false);
        assert.deepEqual(originalConfig.options.animation, {duration: 460});
        assert.equal(env.document.querySelector('[data-report-chart]').dataset.chartState, 'ready');
    } finally { env.cleanup(); }
});
