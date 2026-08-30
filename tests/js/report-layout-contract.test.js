import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const organizationCss = readFileSync(new URL('../../resources/css/pages/reports/organization.css', import.meta.url), 'utf8');
const employeeCss = readFileSync(new URL('../../resources/css/pages/reports/employee.css', import.meta.url), 'utf8');
const sharedCss = readFileSync(new URL('../../resources/css/pages/reports/shared.css', import.meta.url), 'utf8');

const mediaBlock = (css, query) => {
    const start = css.indexOf(`@media (${query})`);
    assert.notEqual(start, -1, `Missing @media (${query})`);
    const openingBrace = css.indexOf('{', start);
    let depth = 0;
    for (let index = openingBrace; index < css.length; index += 1) {
        if (css[index] === '{') depth += 1;
        if (css[index] === '}') depth -= 1;
        if (depth === 0) return css.slice(openingBrace + 1, index);
    }
    assert.fail(`Unclosed @media (${query})`);
};

const assertGenericRulePrecedesModifier = (block, genericSelector, modifierSelector) => {
    const generic = block.indexOf(`${genericSelector} {`);
    const modifier = block.indexOf(modifierSelector);
    assert.ok(generic >= 0 && modifier > generic, `${modifierSelector} must win the cascade after ${genericSelector}`);
};

test('organization desktop grid owns the required 8/4, 4/4/4, and 3/5/4 spans', () => {
    assert.match(organizationCss, /report-dashboard-card--trend[^}]*grid-column:span 8/);
    assert.match(organizationCss, /report-dashboard-card--status[^}]*grid-column:span 4/);
    assert.match(organizationCss, /report-dashboard-card \{[^}]*grid-column:span 4/);
    assert.match(organizationCss, /report-dashboard-card--priority[^}]*grid-column:span 3/);
    assert.match(organizationCss, /report-dashboard-card--workload[^}]*grid-column:span 5/);
    assert.match(organizationCss, /report-dashboard-card--attention[^}]*grid-column:span 4/);
});

test('employee desktop grid removes the orphan attention column', () => {
    assert.match(employeeCss, /employee-chart-card--trend[^}]*grid-column:span 6/);
    assert.match(employeeCss, /employee-chart-card--status[^}]*grid-column:span 3/);
    assert.match(employeeCss, /employee-chart-card--completed[^}]*grid-column:span 3/);
    assert.match(employeeCss, /employee-chart-card--ontime[^}]*grid-column:span 3/);
    assert.match(employeeCss, /employee-chart-card--priority[^}]*grid-column:span 3/);
    assert.match(employeeCss, /employee-report__attention[^}]*grid-column:span 6/);
});

test('tablet grid cascade preserves full-width primary cards through 991px', () => {
    const organizationTablet = mediaBlock(organizationCss, 'max-width:991px');
    const employeeTablet = mediaBlock(employeeCss, 'max-width:991px');

    assertGenericRulePrecedesModifier(organizationTablet, '.report-dashboard-card', '.report-dashboard-card--trend,.report-dashboard-card--status');
    assertGenericRulePrecedesModifier(employeeTablet, '.employee-chart-card,.employee-report__attention', '.employee-chart-card--trend,.employee-chart-card--status');
    assert.match(organizationTablet, /report-dashboard-card--trend[^}]*grid-column:1\/-1/);
    assert.match(employeeTablet, /employee-chart-card--trend[^}]*grid-column:1\/-1/);
    assert.match(mediaBlock(organizationCss, 'max-width:760px'), /report-dashboard-card[^}]*grid-column:1/);
    assert.match(mediaBlock(employeeCss, 'max-width:760px'), /employee-chart-card[^}]*grid-column:1/);
});

test('custom employee date range fits two columns until its 430px single-column breakpoint', () => {
    const mobile = mediaBlock(employeeCss, 'max-width:760px');
    const narrow = mediaBlock(employeeCss, 'max-width:430px');

    assert.match(mobile, /employee-report__period > div[^}]*display:grid[^}]*grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/);
    assert.match(mobile, /employee-report__period input[^}]*width:100%[^}]*min-width:0/);
    assert.doesNotMatch(mobile, /employee-report__period input[^}]*width:50%/);
    assert.match(narrow, /employee-report__period > div[^}]*grid-template-columns:1fr/);
});

test('report pages constrain ultrawide content and use chart-specific height variables', () => {
    assert.match(organizationCss, /width:min\(1560px,100%\)/);
    assert.match(employeeCss, /width:min\(1560px,100%\)/);
    assert.match(organizationCss, /report-dashboard-card--trend[^}]*--report-chart-height:360px/);
    assert.match(employeeCss, /employee-chart-card--ontime[^}]*--report-chart-height:290px/);
    assert.match(sharedCss, /data-chart-kind="doughnut"/);
    assert.match(sharedCss, /prefers-reduced-motion:reduce/);
});

test('ready-state skeleton handoff shares the chart stagger and reduced motion disables it', () => {
    assert.match(sharedCss, /report-chart-skeleton[^}]*opacity \.22s ease var\(--report-card-delay,0ms\)[^}]*visibility 0s linear calc\(var\(--report-card-delay,0ms\) \+ \.22s\)/);
    assert.match(sharedCss, /data-chart-state="ready"[^}]*visibility:hidden[^}]*opacity:0/);
    assert.doesNotMatch(sharedCss, /data-chart-state="ready"[^}]*display:none/);
    assert.match(mediaBlock(sharedCss, 'prefers-reduced-motion:reduce'), /report-chart-skeleton,.report-chart-wrap[^}]*transition:none/);
});
