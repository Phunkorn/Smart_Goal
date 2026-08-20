import test from 'node:test';
import assert from 'node:assert/strict';
import {
    boardFloatingMenuRootSelector,
    boardFloatingMenuSelector,
    boardFloatingMenuSummarySelector,
    calculateBoardFloatingMenuPosition,
    resolveBoardFloatingMenu,
} from '../../resources/js/pages/mytasks/board-floating-menu.js';

test('each board summary resolves its actual floating menu type', () => {
    for (const selector of [
        '[data-board-status-menu]',
        '[data-board-priority-menu]',
        '[data-project-priority-menu]',
        '.board-reference-menu',
        '[data-project-attachments]',
    ]) {
        const menu = {selector};
        const summary = {
            closest(combinedSelector) {
                assert.equal(combinedSelector, boardFloatingMenuSelector);
                return combinedSelector.includes(`${boardFloatingMenuRootSelector} ${selector}`) ? menu : null;
            },
        };

        assert.equal(resolveBoardFloatingMenu(summary), menu);
    }
});

test('floating selectors are scoped to the project board', () => {
    for (const selector of boardFloatingMenuSelector.split(', ')) {
        assert.match(selector, /^\[data-project-board\] /);
    }

    for (const selector of boardFloatingMenuSummarySelector.split(', ')) {
        assert.match(selector, /^\[data-project-board\] /);
        assert.match(selector, /> summary$/);
    }
});

test('native details outside the board floating infrastructure are not menu summaries', () => {
    assert.equal(boardFloatingMenuSummarySelector.includes('.board-completed-group'), false);
    assert.equal(boardFloatingMenuSummarySelector.includes('.mytasks-kanban__project-files'), false);
});

test('menu resolution guards missing or unsupported summary elements', () => {
    assert.equal(resolveBoardFloatingMenu(null), null);
    assert.equal(resolveBoardFloatingMenu({}), null);
    assert.equal(resolveBoardFloatingMenu({closest: () => null}), null);
});

test('floating menu opens below its trigger when the viewport has room', () => {
    assert.deepEqual(calculateBoardFloatingMenuPosition(
        {left: 100, right: 180, top: 100, bottom: 130},
        {width: 164, height: 200},
        {width: 800, height: 600},
    ), {left: 100, top: 136, side: 'below'});
});

test('floating menu flips above a trigger near the viewport bottom', () => {
    assert.deepEqual(calculateBoardFloatingMenuPosition(
        {left: 100, right: 180, top: 500, bottom: 530},
        {width: 164, height: 180},
        {width: 800, height: 600},
    ), {left: 100, top: 314, side: 'above'});
});

test('floating menu stays inside both horizontal viewport edges', () => {
    const rightCollision = calculateBoardFloatingMenuPosition(
        {left: 760, right: 790, top: 100, bottom: 130},
        {width: 190, height: 120},
        {width: 800, height: 600},
    );
    const leftCollision = calculateBoardFloatingMenuPosition(
        {left: 4, right: 24, top: 100, bottom: 130},
        {width: 190, height: 120},
        {width: 800, height: 600},
        {align: 'end'},
    );

    assert.equal(rightCollision.left, 602);
    assert.equal(leftCollision.left, 8);
});
