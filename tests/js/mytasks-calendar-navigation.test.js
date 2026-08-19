import test from 'node:test';
import assert from 'node:assert/strict';
import {buddhistYear, calendarMonthForDate, moveCalendarMonth, resetCalendarMonth} from '../../resources/js/pages/mytasks/calendar-model.js';

test('previous and next month navigation cross year boundaries', () => {
    assert.deepEqual(moveCalendarMonth(2026, 0, -1), {year: 2025, month: 11});
    assert.deepEqual(moveCalendarMonth(2026, 11, 1), {year: 2027, month: 0});
});

test('Today restores the current local month and year', () => {
    assert.deepEqual(calendarMonthForDate(new Date(2026, 7, 19, 12)), {year: 2026, month: 7});
});

test('direct selection keeps Gregorian values and presents Buddhist year', () => {
    const selection = {year: Number('2026'), month: Number('7')};
    assert.deepEqual(selection, {year: 2026, month: 7});
    assert.equal(buddhistYear(selection.year), 2569);
});

test('Reset restores the page-load selection and navigation still works afterward', () => {
    const initial = {year: 2026, month: 7};
    const changed = {year: 2027, month: 11};
    assert.notDeepEqual(changed, initial);

    const reset = resetCalendarMonth(initial);
    assert.deepEqual(reset, {year: 2026, month: 7});
    assert.deepEqual(moveCalendarMonth(reset.year, reset.month, -1), {year: 2026, month: 6});
    assert.deepEqual(moveCalendarMonth(reset.year, reset.month, 1), {year: 2026, month: 8});
});

test('Today remains independent from the page-load Reset selection', () => {
    const initial = {year: 2026, month: 7};
    const actualToday = calendarMonthForDate(new Date(2026, 8, 1, 0, 1));
    assert.deepEqual(resetCalendarMonth(initial), {year: 2026, month: 7});
    assert.deepEqual(actualToday, {year: 2026, month: 8});
});
