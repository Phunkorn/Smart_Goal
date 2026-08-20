export const boardFloatingMenuRootSelector = '[data-project-board]';

const boardFloatingMenuTypes = [
    '[data-board-status-menu]',
    '[data-board-priority-menu]',
    '[data-project-priority-menu]',
    '.board-reference-menu',
    '[data-project-attachments]',
];

export const boardFloatingMenuSelector = boardFloatingMenuTypes
    .map((selector) => `${boardFloatingMenuRootSelector} ${selector}`)
    .join(', ');

export const boardFloatingMenuSummarySelector = boardFloatingMenuTypes
    .map((selector) => `${boardFloatingMenuRootSelector} ${selector} > summary`)
    .join(', ');

export const resolveBoardFloatingMenu = (summary) => {
    if (!summary || typeof summary.closest !== 'function') return null;

    return summary.closest(boardFloatingMenuSelector);
};

const clamp = (value, minimum, maximum) => Math.min(
    Math.max(value, minimum),
    Math.max(minimum, maximum),
);

export const calculateBoardFloatingMenuPosition = (
    triggerRect,
    menuRect,
    viewport,
    {align = 'start', gap = 6, margin = 8} = {},
) => {
    const viewportWidth = Math.max(0, Number(viewport?.width) || 0);
    const viewportHeight = Math.max(0, Number(viewport?.height) || 0);
    const menuWidth = Math.max(0, Number(menuRect?.width) || 0);
    const menuHeight = Math.max(0, Number(menuRect?.height) || 0);
    const triggerLeft = Number(triggerRect?.left) || 0;
    const triggerRight = Number(triggerRect?.right) || triggerLeft;
    const triggerTop = Number(triggerRect?.top) || 0;
    const triggerBottom = Number(triggerRect?.bottom) || triggerTop;
    const preferredLeft = align === 'end' ? triggerRight - menuWidth : triggerLeft;
    const left = clamp(preferredLeft, margin, viewportWidth - menuWidth - margin);
    const belowTop = triggerBottom + gap;
    const aboveTop = triggerTop - gap - menuHeight;
    const maximumTop = viewportHeight - menuHeight - margin;
    const fitsBelow = belowTop <= maximumTop;
    const fitsAbove = aboveTop >= margin;
    const spaceBelow = viewportHeight - margin - triggerBottom - gap;
    const spaceAbove = triggerTop - margin - gap;
    const placeAbove = !fitsBelow && (fitsAbove || spaceAbove > spaceBelow);
    const top = placeAbove
        ? clamp(aboveTop, margin, maximumTop)
        : clamp(belowTop, margin, maximumTop);

    return {
        left,
        top,
        side: placeAbove ? 'above' : 'below',
    };
};
