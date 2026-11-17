import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const source = readFileSync(new URL('../../resources/views/components/app/date-input.blade.php', import.meta.url), 'utf8');
const context = { window: {} };
vm.runInNewContext(source.match(/<script>([\s\S]*?)<\/script>/)[1], context);

function dateInput(value = '', options = {}) {
    const writes = [];
    const input = context.window.schoolDateInput({ model: 'date', live: false, min: '', max: '', ...options });
    input.$wire = { $get: () => value, $set: (...args) => writes.push(args), intercept: callback => { input.interceptor = callback; return () => { input.unsubscribed = true; }; } };
    input.$el = { closest: () => ({ getAttribute: () => 'save' }), querySelector: () => null };
    input.$watch = () => {};
    input.$dispatch = () => {};
    input.$nextTick = callback => callback();
    input.$refs = Object.fromEntries(['year', 'month', 'day'].map(part => [part, { focus: () => {}, reportValidity: () => {}, setCustomValidity: message => { input.validity = message; } }]));
    input.init();
    return { input, writes };
}

test('saved dates prepopulate without writing or changing them', () => {
    const { input, writes } = dateInput('1888-05-07');
    assert.equal(input.year, '1888');
    assert.equal(input.month, '05');
    assert.equal(input.day, '07');
    assert.ok(input.years.includes('1888'));
    assert.equal(input.current, '1888-05-07');
    assert.equal(writes.length, 0);
});

for (const [year, days] of [['2024',29], ['2025',28], ['1900',28], ['2000',29]]) {
    test(`February ${year} has ${days} days`, () => {
        const { input } = dateInput(`${year}-02-01`);
        assert.equal(input.days.length, days);
    });
}

test('changing month clears an invalid day instead of substituting one', () => {
    const { input, writes } = dateInput('2024-01-31');
    input.month = '02';
    input.change();
    assert.equal(input.day, '');
    assert.equal(input.current, '');
    assert.match(input.validity, /complete this date/);
    assert.equal(writes.length, 0);
});

test('changing year clears leap day but preserves other valid days', () => {
    const { input } = dateInput('2024-02-29');
    input.year = '2025';
    input.change();
    assert.equal(input.day, '');
    input.day = '28';
    input.change();
    input.year = '2026';
    input.change();
    assert.equal(input.current, '2026-02-28');
});

test('selections can be made day first and only a complete live date sends a request', () => {
    const { input, writes } = dateInput('', { live: true });
    input.day = '29'; input.change();
    input.month = '02'; input.change();
    assert.ok(writes.every(write => write[2] === false));
    input.year = '2024'; input.change();
    assert.equal(input.current, '2024-02-29');
    assert.equal(input.validity, '');
    assert.equal(writes.at(-1)[2], true);
});

test('optional dates can be cleared and external resets replace all selections', () => {
    const { input, writes } = dateInput('2015-09-21', { live: true });
    input.clear();
    assert.equal(input.current, '');
    assert.equal(input.validity, '');
    assert.equal(writes.at(-1)[2], true);
    input.receive('2022-01-03');
    assert.equal(input.year, '2022');
    input.receive('');
    assert.equal(input.year + input.month + input.day, '');
});

test('partial selections survive receiving the same value after validation', () => {
    const { input } = dateInput();
    input.month = '05'; input.change();
    input.receive(input.synced);
    assert.equal(input.month, '05');
    assert.match(input.message, /complete this date/);
});

test('minimum and maximum dates constrain years months and days', () => {
    const { input } = dateInput('2024-02-15', { min: '2024-02-10', max: '2024-03-05' });
    assert.equal(input.years.join(','), '2024');
    assert.equal(input.monthAllowed('01'), false);
    assert.equal(input.monthAllowed('04'), false);
    assert.equal(input.days[0], '10');
    input.month = '03'; input.change();
    assert.equal(input.day, '');
    assert.equal(input.days.at(-1), '05');
});

test('historical and future year options expand locally without changing the date', () => {
    const { input, writes } = dateInput('1988-05-07');
    const first = input.firstYear, last = input.lastYear;
    input.selectYear({ target: { value: 'earlier' } });
    input.selectYear({ target: { value: 'later' } });
    assert.equal(input.firstYear, first - 100);
    assert.equal(input.lastYear, last + 100);
    assert.equal(input.year, '1988');
    assert.equal(writes.length, 0);
});

for (const mode of ['disabled', 'readonly']) {
    test(`${mode} dates do not write to Livewire`, () => {
        const { input, writes } = dateInput('2015-09-21', { [mode]: true });
        input.change();
        assert.equal(writes.length, 0);
    });
}

test('multiple date fields keep independent state', () => {
    const first = dateInput('2024-01-31').input;
    const second = dateInput('2024-02-29').input;
    first.clear();
    assert.equal(second.current, '2024-02-29');
});

test('malformed existing import values remain unchanged until corrected', () => {
    const { input, writes } = dateInput('not a date');
    assert.equal(input.current, 'not a date');
    assert.match(input.validity, /valid year/);
    assert.equal(writes.length, 0);
});

test('incomplete live filters never send malformed dates into database queries', () => {
    const { input, writes } = dateInput('2024-01-31', { live: true });
    input.month = '02'; input.change();
    assert.equal(writes.length, 0);
    assert.equal(writes.length, 0);
    input.receive(input.synced);
    assert.equal(input.month, '02');
    assert.equal(input.day, '');
});

test('invalid imported month values are shown as invalid without overwriting them', () => {
    const { input, writes } = dateInput('2024-13-05');
    assert.match(input.message, /month number/);
    assert.equal(writes.length, 0);
});

test('an ancient saved year keeps the initial options bounded', () => {
    const { input } = dateInput('1000-01-01');
    assert.ok(input.years.includes('1000'));
    assert.ok(input.years.length <= 121);
});

for (const [typed, expected] of Array.from({ length: 12 }, (_, index) => [String(index + 1), String(index + 1).padStart(2, '0')]).concat([['01', '01'], ['09', '09']])) {
    test(`typed month ${typed} normalizes to ${expected} on blur`, () => {
        const { input, writes } = dateInput();
        input.type('month', typed);
        assert.equal(input.draft.month, typed);
        assert.equal(writes.length, 0);
        input.commit('month');
        assert.equal(input.month, expected);
        assert.equal(input.draft.month, expected);
        assert.equal(writes.length, 0);
    });
}

test('mixed typing and selection publish a complete padded date', () => {
    const { input, writes } = dateInput('', { live: true });
    input.type('year', '2022'); input.commit('year');
    input.choose('month', '09');
    input.type('day', '15');
    assert.equal(writes.length, 0);
    input.commit('day');
    assert.equal(input.current, '2022-09-15');
    assert.deepEqual(writes, [['date', '2022-09-15', true]]);
});

test('dropdown year typed numeric month and dropdown leap day share state', () => {
    const { input, writes } = dateInput();
    input.choose('year', '2024');
    input.type('month', '2'); input.commit('month');
    input.choose('day', '29');
    assert.equal(input.current, '2024-02-29');
    assert.deepEqual(writes, [['date', '2024-02-29', false]]);
});

test('partial year and month text remain editable without requests or normalization', () => {
    const { input, writes } = dateInput('2020-05-15', { live: true });
    for (const text of ['2','20','202','2022']) {
        input.type('year', text);
        assert.equal(input.draft.year, text);
        assert.equal(input.year, '2020');
    }
    for (const text of ['0','01','1','12']) {
        input.type('month', text);
        assert.equal(input.draft.month, text);
        assert.equal(input.month, '05');
    }
    assert.equal(writes.length, 0);
    assert.equal(input.showErrors, false);
});

for (const [part, text] of [['year','20'], ['year','0000'], ['year','202a'], ['month','Ma'], ['month','13'], ['month','0'], ['month','Febru'], ['day','0'], ['day','32'], ['day','1.5']]) {
    test(`invalid ${part} ${text} is rejected at the appropriate input boundary`, () => {
        const { input, writes } = dateInput('2024-02-15');
        const previous = input.draft[part];
        input.type(part, text); input.commit(part);
        if (/[^0-9]/.test(text)) {
            assert.equal(input.draft[part], previous);
            assert.equal(writes.length, 0);
            return;
        }
        assert.equal(input.draft[part], text);
        assert.notEqual(input.message, '');
        assert.equal(input.confirmDate(), false);
        assert.equal(writes.length, 0);
    });
}

for (const date of ['2025-02-15','2024-04-15']) {
    test(`impossible typed day is rejected for ${date}`, () => {
        const { input, writes } = dateInput(date);
        input.type('day', '31'); input.commit('day');
        assert.equal(input.draft.day, '31');
        assert.match(input.message, /does not exist/);
        assert.equal(writes.length, 0);
    });
}

test('typing a non-leap year clears the previously selected leap day', () => {
    const { input, writes } = dateInput('2024-02-29');
    input.type('year', '2025'); input.commit('year');
    assert.equal(input.day, '');
    assert.equal(input.draft.day, '');
    assert.equal(writes.length, 0);
});

test('manual entry cannot bypass date boundaries', () => {
    const { input, writes } = dateInput('2024-02-15', { min: '2024-02-10', max: '2024-02-20' });
    input.type('day', '21'); input.commit('day');
    assert.match(input.message, /allowed range/);
    assert.equal(input.confirmDate(), false);
    assert.equal(writes.length, 0);
});

test('keyboard arrows and Enter select options while Escape can dismiss without editing', () => {
    const { input } = dateInput();
    input.move('month', 1);
    assert.equal(input.open, 'month');
    assert.equal(input.active, 0);
    let prevented = false;
    input.enter('month', { preventDefault: () => { prevented = true; } });
    assert.equal(input.month, '01');
    assert.equal(input.open, '');
    assert.equal(prevented, true);
});

test('dropdown months display all twelve two-digit numeric values', () => {
    const { input } = dateInput();
    assert.equal(input.options('month').map(option => option.label).join(','), '01,02,03,04,05,06,07,08,09,10,11,12');
});

test('pending edits survive unrelated Livewire responses and clear without malformed values', () => {
    const { input, writes } = dateInput('2024-02-15');
    input.type('month', '13'); input.commit('month');
    input.receive('2024-02-15');
    assert.equal(input.draft.month, '13');
    input.clear();
    assert.equal(input.draft.year + input.draft.month + input.draft.day, '');
    assert.deepEqual(writes, [['date', '', false]]);
});

test('invalid drafts block submit actions but allow reset actions and unregister on removal', () => {
    const { input } = dateInput('2024-02-15');
    input.type('month', '13');
    let cancelled = false;
    input.interceptor({ action: { name: 'save', cancel: () => { cancelled = true; } } });
    assert.equal(cancelled, true);
    cancelled = false;
    input.interceptor({ action: { name: 'resetFilters', cancel: () => { cancelled = true; } } });
    assert.equal(cancelled, false);
    input.destroy();
    assert.equal(input.unsubscribed, true);
});

test('required empty dates are blocked while optional empty dates are valid', () => {
    assert.equal(dateInput('', { required: true }).input.confirmDate(), false);
    assert.equal(dateInput().input.confirmDate(), true);
});

test('confirming an edited day never publishes intermediate stale dates', () => {
    const { input, writes } = dateInput('2024-02-15', { live: true });
    input.type('day', '7');
    assert.equal(input.confirmDate(), true);
    assert.deepEqual(writes, [['date', '2024-02-07', true]]);
});

test('Arrow Up opens the last option and empty day lists do not activate invalid options', () => {
    const { input } = dateInput();
    input.move('month', -1);
    assert.equal(input.options('month')[input.active].label, '12');
    const bounded = dateInput('2024-03-01', { min: '2024-02-10', max: '2024-02-20' }).input;
    bounded.move('day', 1);
    assert.equal(bounded.active, -1);
});

test('explicit button actions reject invalid drafts without blocking unrelated actions', () => {
    const { input, writes } = dateInput('2024-02-15', { validateActions: 'previewInvoices,generate' });
    input.type('day', '99');
    let cancelled = false;
    input.interceptor({ action: { name: 'previewInvoices', cancel: () => { cancelled = true; } } });
    assert.equal(cancelled, true);
    assert.equal(writes.length, 0);
});

test('option lists open above fields near the viewport bottom and close on dismissal', () => {
    const { input } = dateInput();
    context.window.innerHeight = 600;
    context.window.innerWidth = 400;
    let shown = false;
    const list = { style: {}, scrollHeight: 400, matches: () => shown, showPopover: () => { shown = true; }, hidePopover: () => { shown = false; } };
    input.$refs.monthList = list;
    input.$refs.month.getBoundingClientRect = () => ({ left: 300, top: 500, bottom: 540, width: 120 });
    input.popup('month', 'month');
    assert.equal(shown, true);
    assert.equal(list.style.top, '256px');
    assert.equal(list.style.left, '264px');
    input.popup('month', '');
    assert.equal(shown, false);
});

for (const part of ['year', 'month', 'day']) {
    for (const text of ['abc', '@', '-5', '1.5', '2e1', '12ab', ' 1', '1\n']) {
        test(`${part} rejects nonnumeric keyboard paste and drop content ${JSON.stringify(text)}`, () => {
            const { input, writes } = dateInput();
            for (const inputType of ['insertText', 'insertFromPaste', 'insertFromDrop']) {
                let prevented = false;
                const target = { value: '', selectionStart: 0, selectionEnd: 0 };
                input.beforeInput(part, { target, inputType, data: text, preventDefault: () => { prevented = true; } });
                assert.equal(prevented, true);
            }
            input.type(part, text);
            assert.equal(input.draft[part], '');
            assert.equal(writes.length, 0);
        });
    }
}

test('excess digits are rejected rather than truncating into another date', () => {
    const { input, writes } = dateInput();
    for (const [part, value] of [['year','12345'], ['month','123'], ['day','123']]) {
        let prevented = false;
        input.beforeInput(part, { target: { value: '', selectionStart: 0, selectionEnd: 0 }, data: value, inputType: 'insertFromPaste', preventDefault: () => { prevented = true; } });
        assert.equal(prevented, true);
        input.type(part, value);
        assert.equal(input.draft[part], '');
    }
    assert.equal(writes.length, 0);
});

test('replacement paste and intermediate digits preserve normal editing', () => {
    const { input, writes } = dateInput('1988-05-07');
    let prevented = false;
    input.beforeInput('year', { target: { value: '1988', selectionStart: 0, selectionEnd: 4 }, data: '2024', inputType: 'insertFromPaste', preventDefault: () => { prevented = true; } });
    assert.equal(prevented, false);
    for (const value of ['', '2', '20', '202', '2024']) {
        input.type('year', value);
        assert.equal(input.draft.year, value);
    }
    for (const inputType of ['deleteContentBackward', 'deleteContentForward', 'deleteByCut', 'historyUndo']) {
        input.beforeInput('year', { target: { value: '2024', selectionStart: 1, selectionEnd: 2 }, inputType, data: null, preventDefault: () => { prevented = true; } });
    }
    assert.equal(prevented, false);
    assert.equal(writes.length, 0);
});

test('input fallback restores the prior numeric value and selection for noncancelable edits', () => {
    const { input, writes } = dateInput('1988-05-07');
    const target = { value: '1988', selectionStart: 1, selectionEnd: 3, setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; } };
    input.beforeInput('year', { target, data: 'x', inputType: 'insertText', preventDefault: () => {} });
    target.value = '1x8';
    input.type('year', target.value, target);
    assert.equal(target.value, '1988');
    assert.equal(target.selectionStart, 1);
    assert.equal(target.selectionEnd, 3);
    assert.equal(input.draft.year, '1988');
    assert.equal(writes.length, 0);
});

test('saved and dropdown-selected dates display four two two digits', () => {
    const saved = dateInput('1988-05-07').input;
    assert.equal([saved.draft.year, saved.draft.month, saved.draft.day].join('|'), '1988|05|07');
    const { input, writes } = dateInput();
    input.choose('year', '1988'); input.choose('month', '05'); input.choose('day', '07');
    assert.equal([input.draft.year, input.draft.month, input.draft.day].join('|'), '1988|05|07');
    assert.equal(input.options('day')[0].label, '01');
    assert.deepEqual(writes, [['date', '1988-05-07', false]]);
});

test('single-digit month and day normalize only when editing finishes', () => {
    const { input } = dateInput();
    input.type('month', '5'); input.type('day', '7');
    assert.equal(input.draft.month, '5');
    assert.equal(input.draft.day, '7');
    input.commit('month'); input.commit('day');
    assert.equal(input.draft.month, '05');
    assert.equal(input.draft.day, '07');
});
