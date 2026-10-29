/* WP Store Manager — ادمین */
function wsmCopyKey() {
    var el = document.getElementById('wsm-key');
    if (!el) return;
    var text = el.textContent || el.innerText;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
            el.style.borderColor = '#22c55e';
            setTimeout(function () { el.style.borderColor = ''; }, 1200);
        });
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
    }
}

/* WSM Solar Hijri date picker — progressive enhancement for native and Jalali text fields. */
(function () {
    'use strict';
    if (window.WSM_SolarCalendar) return;

    var DAY = 86400000;
    var MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    var WEEKDAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
    var DIGITS = '۰۱۲۳۴۵۶۷۸۹';
    var records = new Set();
    var bySource = new WeakMap();
    var observedRoots = new WeakSet();
    var active = null;

    function pad(n) { return String(n).padStart(2, '0'); }
    function persianDigits(value) { return String(value).replace(/[0-9]/g, function (d) { return DIGITS[Number(d)]; }); }
    function latinDigits(value) {
        return String(value).replace(/[۰-۹٠-٩]/g, function (d) {
            var code = d.charCodeAt(0);
            return String(code >= 0x6f0 && code <= 0x6f9 ? code - 0x6f0 : code - 0x660);
        });
    }
    function isoDate(y, m, d) { return String(y).padStart(4, '0') + '-' + pad(m) + '-' + pad(d); }
    function parseIso(value) {
        var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || '').trim());
        if (!match) return null;
        var y = +match[1], m = +match[2], d = +match[3];
        var check = new Date(Date.UTC(y, m - 1, d, 12));
        if (check.getUTCFullYear() !== y || check.getUTCMonth() + 1 !== m || check.getUTCDate() !== d) return null;
        return [y, m, d];
    }
    function gregorianToJalali(gy, gm, gd) {
        var gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        var jy;
        if (gy > 1600) { jy = 979; gy -= 1600; }
        else { jy = 0; gy -= 621; }
        var gy2 = gm > 2 ? gy + 1 : gy;
        var days = (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) - 80 + gd + gdm[gm - 1];
        jy += 33 * Math.floor(days / 12053);
        days %= 12053;
        jy += 4 * Math.floor(days / 1461);
        days %= 1461;
        if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        if (days < 186) return [jy, 1 + Math.floor(days / 31), 1 + (days % 31)];
        return [jy, 7 + Math.floor((days - 186) / 30), 1 + ((days - 186) % 30)];
    }
    function jalaliToGregorian(jy, jm, jd) {
        var y = jy - 979;
        var days = (365 * y) + Math.floor(y / 33) * 8 + Math.floor(((y % 33) + 3) / 4);
        days += jm < 7 ? (jm - 1) * 31 : ((jm - 7) * 30) + 186;
        days += jd - 1;
        var gdn = days + 79;
        var gy = 1600 + 400 * Math.floor(gdn / 146097);
        gdn %= 146097;
        var leap = true;
        if (gdn >= 36525) {
            gdn--;
            gy += 100 * Math.floor(gdn / 36524);
            gdn %= 36524;
            if (gdn >= 365) gdn++;
            else leap = false;
        }
        gy += 4 * Math.floor(gdn / 1461);
        gdn %= 1461;
        if (gdn >= 366) {
            leap = false;
            gdn--;
            gy += Math.floor(gdn / 365);
            gdn %= 365;
        }
        var gd = gdn + 1;
        var monthDays = [0, 31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        var gm = 1;
        while (gm <= 12 && gd > monthDays[gm]) { gd -= monthDays[gm]; gm++; }
        return [gy, gm, gd];
    }
    function parseJalali(value) {
        var raw = latinDigits(value).trim().replace(/[.\-]/g, '/');
        var match = /^(\d{4})\s*\/\s*(\d{1,2})\s*\/\s*(\d{1,2})$/.exec(raw);
        if (!match) return null;
        var y = +match[1], m = +match[2], d = +match[3];
        if (y < 1200 || y > 1600 || m < 1 || m > 12 || d < 1 || d > (m <= 6 ? 31 : 30)) return null;
        var g = jalaliToGregorian(y, m, d);
        var check = new Date(Date.UTC(g[0], g[1] - 1, g[2], 12));
        if (check.getUTCFullYear() !== g[0] || check.getUTCMonth() + 1 !== g[1] || check.getUTCDate() !== g[2]) return null;
        var back = gregorianToJalali(g[0], g[1], g[2]);
        if (back[0] !== y || back[1] !== m || back[2] !== d) return null;
        return { iso: isoDate(g[0], g[1], g[2]), jalali: [y, m, d], text: persianDigits(String(y).padStart(4, '0') + '/' + pad(m) + '/' + pad(d)) };
    }
    function jalaliFromIso(value) {
        var g = parseIso(value);
        if (!g) return null;
        var j = gregorianToJalali(g[0], g[1], g[2]);
        if (j[0] < 1200 || j[0] > 1600) return null;
        return { iso: isoDate(g[0], g[1], g[2]), jalali: j, text: persianDigits(String(j[0]).padStart(4, '0') + '/' + pad(j[1]) + '/' + pad(j[2])) };
    }
    function formatSolarDisplay(value) {
        var raw = String(value == null ? '' : value).trim();
        if (!raw || raw === '—') return raw || '—';
        var match = /^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2})(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/.exec(raw);
        if (match) {
            var converted = jalaliFromIso(match[1]);
            if (!converted) return raw;
            return converted.text + (match[2] ? ' ' + persianDigits(match[2]) : '');
        }
        var split = raw.split(/\s+/, 2);
        var jalali = parseJalali(split[0]);
        if (jalali) return jalali.text + (split[1] ? ' ' + persianDigits(split[1].slice(0, 5)) : '');
        return raw;
    }
    function monthLength(y, m) {
        if (m <= 6) return 31;
        if (m <= 11) return 30;
        var a = jalaliToGregorian(y, 12, 1);
        var b = jalaliToGregorian(y + 1, 1, 1);
        return Math.round((Date.UTC(b[0], b[1] - 1, b[2]) - Date.UTC(a[0], a[1] - 1, a[2])) / DAY);
    }
    function todayJalali() {
        var now = new Date();
        return gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
    }
    function sourceDate(record) {
        return record.mode === 'iso' ? jalaliFromIso(record.source.value) : parseJalali(record.source.value);
    }
    function fieldLabel(source) {
        var root = source.getRootNode();
        var label = source.closest && source.closest('label');
        if (!label && source.previousElementSibling && source.previousElementSibling.tagName === 'LABEL') label = source.previousElementSibling;
        if (!label && source.id && root && root.querySelectorAll) {
            var labels = root.querySelectorAll('label[for]');
            for (var i = 0; i < labels.length; i++) if (labels[i].htmlFor === source.id) { label = labels[i]; break; }
        }
        var text = label ? label.textContent.replace(/\s+/g, ' ').trim() : '';
        return text || source.getAttribute('aria-label') || source.name || 'تاریخ شمسی';
    }
    function copyAppearance(source, display) {
        try {
            var css = window.getComputedStyle(source);
            display.style.fontFamily = css.fontFamily;
            display.style.fontSize = css.fontSize;
            display.style.fontWeight = css.fontWeight;
            display.style.lineHeight = css.lineHeight;
            display.style.color = css.color;
            display.style.backgroundColor = css.backgroundColor;
            display.style.border = css.border;
            display.style.borderRadius = css.borderRadius;
            display.style.padding = css.padding;
            display.style.minHeight = css.height && css.height !== 'auto' ? css.height : '';
            display.style.boxSizing = 'border-box';
        } catch (e) {}
    }
    function setNativeValue(source, value) {
        var descriptor = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
        if (descriptor && descriptor.set) descriptor.set.call(source, value);
        else source.value = value;
        source.dispatchEvent(new Event('input', { bubbles: true, composed: true }));
        source.dispatchEvent(new Event('change', { bubbles: true, composed: true }));
    }
    function rangeAllows(record, iso) {
        var min = record.source.getAttribute('min') || '';
        var max = record.source.getAttribute('max') || '';
        if (record.mode === 'solar') {
            min = (parseJalali(min) || {}).iso || '';
            max = (parseJalali(max) || {}).iso || '';
        }
        return (!min || iso >= min) && (!max || iso <= max);
    }
    function required(record) { return record.required && !record.source.disabled; }
    function validateDisplay(record) {
        var value = record.display.value.trim();
        if (!value) {
            record.display.setCustomValidity(required(record) ? 'انتخاب تاریخ الزامی است.' : '');
            return null;
        }
        var parsed = parseJalali(value);
        if (!parsed || !rangeAllows(record, parsed.iso)) {
            record.display.setCustomValidity('یک تاریخ شمسی معتبر در بازهٔ مجاز وارد کنید.');
            return null;
        }
        record.display.setCustomValidity('');
        return parsed;
    }
    function syncDisplay(record) {
        if (!record.source.isConnected) return;
        var parsed = sourceDate(record);
        var value = parsed ? parsed.text : (record.mode === 'solar' ? record.source.value : '');
        if (record.display.value !== value) record.display.value = value;
        record.lastValue = record.source.value;
        validateDisplay(record);
    }
    function updateSource(record, parsed) {
        if (!parsed) {
            setNativeValue(record.source, '');
            record.display.value = '';
        } else {
            var value = record.mode === 'iso' ? parsed.iso : parsed.text;
            setNativeValue(record.source, value);
            record.display.value = parsed.text;
        }
        record.lastValue = record.source.value;
        validateDisplay(record);
    }
    function buttonStyle(button, extra) {
        button.type = 'button';
        button.style.cssText = 'border:0;cursor:pointer;font:inherit;display:inline-flex;align-items:center;justify-content:center;';
        if (extra) button.style.cssText += extra;
    }
    function closePicker(restoreFocus) {
        if (!active) return;
        var current = active;
        active = null;
        current.record.display.setAttribute('aria-expanded', 'false');
        document.removeEventListener('pointerdown', current.outside, true);
        document.removeEventListener('keydown', current.keys, true);
        window.removeEventListener('resize', current.position);
        window.removeEventListener('scroll', current.position, true);
        if (current.box.parentNode) current.box.parentNode.removeChild(current.box);
        if (restoreFocus && current.record.display.isConnected) current.record.display.focus({ preventScroll: true });
    }
    function openPicker(record) {
        if (record.source.disabled) return;
        if (active && active.record === record) { closePicker(false); return; }
        closePicker(false);
        var parsed = sourceDate(record);
        var cursor = parsed ? parsed.jalali.slice() : todayJalali();
        if (cursor[0] < 1200 || cursor[0] > 1600) cursor = todayJalali();
        var box = document.createElement('div');
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-label', 'تقویم شمسی فارسی');
        box.setAttribute('aria-modal', 'false');
        box.dir = 'rtl';
        box.style.cssText = 'position:fixed;z-index:2147483000;width:min(318px,calc(100vw - 16px));box-sizing:border-box;padding:12px;background:#fff;color:#17212b;border:1px solid #d7dee7;border-radius:15px;box-shadow:0 14px 42px rgba(15,23,42,.24);font:14px/1.45 Tahoma,Arial,sans-serif;';
        var head = document.createElement('div');
        head.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:9px;';
        var title = document.createElement('div');
        title.style.cssText = 'font-weight:700;font-size:14px;text-align:center;flex:1;';
        var prev = document.createElement('button');
        buttonStyle(prev, 'width:34px;height:34px;border-radius:10px;background:#f1f5f9;color:#253248;font-size:21px;');
        prev.textContent = '›'; prev.setAttribute('aria-label', 'ماه قبل');
        var next = document.createElement('button');
        buttonStyle(next, 'width:34px;height:34px;border-radius:10px;background:#f1f5f9;color:#253248;font-size:21px;');
        next.textContent = '‹'; next.setAttribute('aria-label', 'ماه بعد');
        head.appendChild(prev); head.appendChild(title); head.appendChild(next);
        var weekdays = document.createElement('div');
        weekdays.style.cssText = 'display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:3px;margin-bottom:4px;color:#687588;font-size:11px;text-align:center;';
        WEEKDAYS.forEach(function (day) { var el = document.createElement('div'); el.textContent = day; el.style.padding = '4px 0'; weekdays.appendChild(el); });
        var grid = document.createElement('div');
        grid.style.cssText = 'display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:3px;text-align:center;';
        var footer = document.createElement('div');
        footer.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:10px;padding-top:9px;border-top:1px solid #edf0f4;';
        var todayButton = document.createElement('button');
        buttonStyle(todayButton, 'padding:6px 10px;border-radius:9px;background:#e6f5f3;color:#08766e;font-size:12px;font-weight:700;');
        todayButton.textContent = 'امروز';
        var clearButton = document.createElement('button');
        buttonStyle(clearButton, 'padding:6px 10px;border-radius:9px;background:transparent;color:#687588;font-size:12px;');
        clearButton.textContent = 'پاک کردن';
        clearButton.hidden = required(record);
        var closeButton = document.createElement('button');
        buttonStyle(closeButton, 'padding:6px 10px;border-radius:9px;background:transparent;color:#687588;font-size:12px;');
        closeButton.textContent = 'بستن';
        footer.appendChild(todayButton); footer.appendChild(clearButton); footer.appendChild(closeButton);
        box.appendChild(head); box.appendChild(weekdays); box.appendChild(grid); box.appendChild(footer);
        document.body.appendChild(box);

        function position() {
            if (!record.display.isConnected) { closePicker(false); return; }
            var rect = record.display.getBoundingClientRect();
            var width = Math.min(318, Math.max(240, window.innerWidth - 16));
            var left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
            var top = rect.bottom + 6;
            var height = box.offsetHeight || 360;
            if (top + height > window.innerHeight - 8 && rect.top - height - 6 > 8) top = rect.top - height - 6;
            box.style.width = width + 'px';
            box.style.left = left + 'px';
            box.style.top = Math.max(8, Math.min(top, window.innerHeight - height - 8)) + 'px';
        }
        function choose(parsedDate) {
            if (!parsedDate || !rangeAllows(record, parsedDate.iso)) return;
            updateSource(record, parsedDate);
            closePicker(true);
        }
        function draw() {
            title.textContent = MONTHS[cursor[1] - 1] + ' ' + persianDigits(cursor[0]);
            grid.textContent = '';
            var first = jalaliToGregorian(cursor[0], cursor[1], 1);
            var weekday = new Date(Date.UTC(first[0], first[1] - 1, first[2], 12)).getUTCDay();
            var offset = (weekday + 1) % 7; // هفته از شنبه آغاز می‌شود.
            var selected = sourceDate(record);
            var now = todayJalali();
            var todayIso = isoDate.apply(null, jalaliToGregorian(now[0], now[1], now[2]));
            todayButton.disabled = !rangeAllows(record, todayIso);
            todayButton.style.opacity = todayButton.disabled ? '.45' : '1';
            var firstUtc = Date.UTC(first[0], first[1] - 1, first[2] - offset, 12);
            var focusable = null;
            for (var i = 0; i < 42; i++) {
                var date = new Date(firstUtc + i * DAY);
                var gy = date.getUTCFullYear(), gm = date.getUTCMonth() + 1, gd = date.getUTCDate();
                var j = gregorianToJalali(gy, gm, gd);
                var iso = isoDate(gy, gm, gd);
                var inMonth = j[0] === cursor[0] && j[1] === cursor[1];
                var enabled = j[0] >= 1200 && j[0] <= 1600 && rangeAllows(record, iso);
                var day = document.createElement('button');
                buttonStyle(day, 'width:100%;height:34px;border-radius:10px;background:transparent;color:' + (inMonth ? '#263449' : '#aab3bf') + ';font-size:12px;');
                day.textContent = persianDigits(j[2]);
                day.dataset.iso = iso; day.dataset.index = String(i); day.tabIndex = -1;
                day.disabled = !enabled || record.source.disabled;
                day.setAttribute('aria-label', MONTHS[j[1] - 1] + ' ' + persianDigits(j[2]) + ' ' + persianDigits(j[0]));
                day.setAttribute('aria-pressed', selected && selected.iso === iso ? 'true' : 'false');
                var dayDate = { iso: iso, jalali: j, text: persianDigits(String(j[0]).padStart(4, '0') + '/' + pad(j[1]) + '/' + pad(j[2])) };
                if (!day.disabled) {
                    day.addEventListener('mouseenter', function () { this.style.backgroundColor = '#e9f5f3'; });
                    day.addEventListener('mouseleave', function () { this.style.backgroundColor = (this.getAttribute('aria-pressed') === 'true' ? '#0f766e' : this.dataset.today === '1' ? '#f0fdfa' : 'transparent'); });
                    day.addEventListener('click', (function (target) { return function () { choose(target); }; })(dayDate));
                }
                if (iso === todayIso) {
                    day.dataset.today = '1';
                    if (!(selected && selected.iso === iso)) day.style.boxShadow = 'inset 0 0 0 1px #0f766e';
                    if (!day.disabled) day.style.backgroundColor = '#f0fdfa';
                }
                if (selected && selected.iso === iso) {
                    day.style.backgroundColor = '#0f766e'; day.style.color = '#fff'; day.style.fontWeight = '700';
                    if (!focusable) focusable = day;
                }
                if (!focusable && inMonth && enabled) focusable = day;
                grid.appendChild(day);
            }
            prev.disabled = cursor[0] === 1200 && cursor[1] === 1;
            next.disabled = cursor[0] === 1600 && cursor[1] === 12;
            prev.style.opacity = prev.disabled ? '.4' : '1';
            next.style.opacity = next.disabled ? '.4' : '1';
            position();
            if (focusable) focusable.tabIndex = 0;
        }
        box.addEventListener('keydown', function (event) {
            var currentDay = event.target && event.target.closest ? event.target.closest('button[data-index]') : null;
            if (!currentDay || !grid.contains(currentDay)) return;
            var index = Number(currentDay.dataset.index), step = 0;
            if (event.key === 'ArrowLeft') step = 1;
            else if (event.key === 'ArrowRight') step = -1;
            else if (event.key === 'ArrowDown') step = 7;
            else if (event.key === 'ArrowUp') step = -7;
            else if (event.key === 'Home') step = -(index % 7);
            else if (event.key === 'End') step = 6 - (index % 7);
            if (!step) return;
            event.preventDefault();
            var targetIndex = index + step;
            var direction = step < 0 ? -1 : 1;
            while (targetIndex >= 0 && targetIndex < 42) {
                var target = grid.children[targetIndex];
                if (target && !target.disabled) { target.focus(); return; }
                targetIndex += direction;
            }
        });
        function changeMonth(delta) {
            var m = cursor[1] + delta, y = cursor[0];
            if (m < 1) { m = 12; y--; }
            if (m > 12) { m = 1; y++; }
            if (y < 1200 || y > 1600) return;
            cursor = [y, m, 1]; draw();
        }
        prev.addEventListener('click', function () { changeMonth(-1); });
        next.addEventListener('click', function () { changeMonth(1); });
        todayButton.addEventListener('click', function () {
            var t = todayJalali();
            var parsedToday = parseJalali(t[0] + '/' + t[1] + '/' + t[2]);
            choose(parsedToday);
        });
        clearButton.addEventListener('click', function () { updateSource(record, null); closePicker(true); });
        closeButton.addEventListener('click', function () { closePicker(true); });
        function outside(event) {
            var path = event.composedPath ? event.composedPath() : [];
            if (path.indexOf(box) !== -1 || path.indexOf(record.wrapper) !== -1 || box.contains(event.target) || record.wrapper.contains(event.target)) return;
            closePicker(false);
        }
        function keys(event) {
            if (event.key === 'Escape') { event.preventDefault(); closePicker(true); }
        }
        active = { record: record, box: box, outside: outside, keys: keys, position: position };
        record.display.setAttribute('aria-expanded', 'true');
        document.addEventListener('pointerdown', outside, true);
        document.addEventListener('keydown', keys, true);
        window.addEventListener('resize', position);
        window.addEventListener('scroll', position, true);
        draw();
        var selectedButton = grid.querySelector('[aria-pressed="true"]');
        var firstButton = selectedButton || grid.querySelector('button:not(:disabled)');
        if (firstButton) firstButton.focus({ preventScroll: true });
    }
    function enhance(source) {
        if (!source || source.dataset.wsmSolarReady === '1') return;
        var isNative = source.type === 'date';
        if (!isNative && !source.hasAttribute('data-wsm-jalali-picker')) return;
        var requiredInput = source.required;
        var mode = isNative ? 'iso' : 'solar';
        var record = { source: source, mode: mode, required: requiredInput, lastValue: '', wrapper: null, display: null };
        var wrapper = document.createElement('span');
        wrapper.className = 'wsm-solar-field';
        wrapper.dir = 'ltr';
        var fullWidth = /(^|\s)(w-full|input)(\s|$)/.test(source.className || '');
        wrapper.style.cssText = 'display:flex;align-items:stretch;gap:6px;width:' + (fullWidth ? '100%' : 'auto') + ';max-width:100%;min-width:0;box-sizing:border-box;';
        var display = document.createElement('input');
        display.type = 'text';
        display.className = source.className || '';
        display.dir = 'ltr';
        display.inputMode = 'numeric';
        display.autocomplete = 'off';
        display.required = requiredInput;
        display.disabled = source.disabled;
        display.readOnly = source.readOnly;
        display.spellcheck = false;
        display.placeholder = '۱۴۰۵/۰۷/۱۷';
        display.setAttribute('aria-haspopup', 'dialog');
        display.setAttribute('aria-expanded', 'false');
        display.setAttribute('aria-label', fieldLabel(source));
        display.setAttribute('data-wsm-solar-display', '1');
        display.style.flex = '1 1 auto';
        display.style.minWidth = '0';
        display.style.width = '100%';
        display.style.direction = 'ltr';
        display.style.textAlign = 'left';
        display.style.fontVariantNumeric = 'tabular-nums';
        display.style.margin = '0';
        copyAppearance(source, display);
        var calendar = document.createElement('button');
        buttonStyle(calendar, 'flex:0 0 38px;width:38px;min-height:38px;border-radius:10px;background:#0f766e;color:#fff;');
        calendar.disabled = source.disabled;
        if (source.disabled) calendar.style.opacity = '.5';
        calendar.setAttribute('aria-label', 'باز کردن تقویم شمسی');
        calendar.title = 'انتخاب تاریخ شمسی';
        calendar.innerHTML = '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" aria-hidden="true"><rect x="3.5" y="5" width="17" height="16" rx="2.5" stroke="currentColor" stroke-width="1.8"/><path d="M7.5 3v4M16.5 3v4M4 9h16M8 13h2m4 0h2m-8 4h2m4 0h2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
        wrapper.appendChild(display); wrapper.appendChild(calendar);
        record.wrapper = wrapper; record.display = display;
        var oldId = source.id;
        if (oldId) display.id = oldId + '-wsm-solar';
        source.dataset.wsmSolarReady = '1';
        source.setAttribute('aria-hidden', 'true');
        source.tabIndex = -1;
        source.style.setProperty('display', 'none', 'important');
        source.required = false;
        source.removeAttribute('required');
        source.insertAdjacentElement('afterend', wrapper);
        if (oldId) {
            var root = source.getRootNode();
            if (root && root.querySelectorAll) {
                var labels = root.querySelectorAll('label[for]');
                for (var l = 0; l < labels.length; l++) if (labels[l].htmlFor === oldId) labels[l].htmlFor = display.id;
            }
        }
        bySource.set(source, record);
        records.add(record);
        source.addEventListener('input', function () { syncDisplay(record); });
        source.addEventListener('change', function () { syncDisplay(record); });
        display.addEventListener('input', function () {
            var parsed = validateDisplay(record);
            if (!display.value.trim()) {
                setNativeValue(source, '');
                record.lastValue = source.value;
            } else if (parsed) {
                setNativeValue(source, mode === 'iso' ? parsed.iso : parsed.text);
                record.lastValue = source.value;
                display.value = parsed.text;
            }
        });
        display.addEventListener('blur', function () { validateDisplay(record); });
        display.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || (event.altKey && event.key === 'ArrowDown')) { event.preventDefault(); openPicker(record); }
        });
        calendar.addEventListener('click', function (event) { event.preventDefault(); openPicker(record); });
        syncDisplay(record);
    }
    function isCandidate(el) {
        return el && el.nodeType === 1 && (el.matches('input[type="date"]') || el.hasAttribute('data-wsm-jalali-picker'));
    }
    function scan(root) {
        if (!root || !root.querySelectorAll) return;
        if (root.nodeType === 1 && isCandidate(root)) enhance(root);
        var nodes = root.querySelectorAll('input[type="date"],input[data-wsm-jalali-picker]');
        for (var i = 0; i < nodes.length; i++) enhance(nodes[i]);
        discoverShadows(root);
    }
    function discoverShadows(root) {
        if (!root || !root.querySelectorAll) return;
        var elements = root.querySelectorAll('*');
        for (var i = 0; i < elements.length; i++) if (elements[i].shadowRoot) observeRoot(elements[i].shadowRoot);
    }
    function observeRoot(root) {
        if (!root || observedRoots.has(root)) return;
        observedRoots.add(root);
        scan(root);
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.type === 'childList') {
                    for (var i = 0; i < mutation.addedNodes.length; i++) {
                        var node = mutation.addedNodes[i];
                        if (node.nodeType === 1) scan(node);
                    }
                } else if (mutation.type === 'attributes' && mutation.target.nodeType === 1) {
                    if (isCandidate(mutation.target)) enhance(mutation.target);
                    var record = bySource.get(mutation.target);
                    if (record && mutation.attributeName === 'value') syncDisplay(record);
                }
            });
        });
        observer.observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['type', 'value', 'data-wsm-jalali-picker'] });
        root.addEventListener('click', function (event) {
            var target = event.target && event.target.nodeType === 1 ? event.target : null;
            var label = target && target.closest ? target.closest('label') : null;
            if (!label) return;
            var control = label.control || label.nextElementSibling;
            var record = control ? bySource.get(control) : null;
            if (!record && label.nextElementSibling) record = bySource.get(label.nextElementSibling);
            if (!record && label.querySelector) {
                var source = label.querySelector('input[data-wsm-solar-ready="1"]');
                if (source) record = bySource.get(source);
            }
            if (record && !record.wrapper.contains(target)) { event.preventDefault(); openPicker(record); }
        }, true);
    }

    try {
        var nativeAttachShadow = Element.prototype.attachShadow;
        if (nativeAttachShadow && !nativeAttachShadow.__wsmSolarWrapped) {
            var wrappedAttachShadow = function (options) {
                var root = nativeAttachShadow.call(this, options);
                observeRoot(root);
                return root;
            };
            wrappedAttachShadow.__wsmSolarWrapped = true;
            Element.prototype.attachShadow = wrappedAttachShadow;
        }
    } catch (e) {}

    window.WSM_SolarCalendar = { observe: observeRoot, jalaliToGregorian: jalaliToGregorian, gregorianToJalali: gregorianToJalali, format: formatSolarDisplay };
    observeRoot(document);
    window.setInterval(function () {
        records.forEach(function (record) {
            if (!record.source.isConnected) { records.delete(record); return; }
            if (record.source.value !== record.lastValue) syncDisplay(record);
        });
    }, 900);
})();

/* Bulk toggle for device app permissions (keeps restricted permissions disabled). */
(function () {
    'use strict';
    function enabledPermissionBoxes(form) {
        return Array.prototype.filter.call(
            form.querySelectorAll('input[type="checkbox"][name="wsm_permissions[]"]'),
            function (input) { return !input.disabled; }
        );
    }
    function syncPermissionMaster(form) {
        if (!form) return;
        var master = form.querySelector('.wsm-permission-select-all');
        if (!master) return;
        var boxes = enabledPermissionBoxes(form);
        var checked = boxes.filter(function (input) { return input.checked; }).length;
        master.checked = boxes.length > 0 && checked === boxes.length;
        master.indeterminate = checked > 0 && checked < boxes.length;
        var status = form.querySelector('.wsm-permission-bulk-status');
        if (status) {
            status.textContent = master.checked
                ? 'همهٔ دسترسی‌های قابل واگذاری انتخاب شده‌اند.'
                : (checked ? checked + ' دسترسی انتخاب شده است.' : '');
        }
    }
    document.addEventListener('change', function (event) {
        var target = event.target;
        if (!target || !target.matches) return;
        if (target.matches('.wsm-permission-select-all')) {
            var form = target.closest('form.wsm-device-permissions');
            if (!form) return;
            /* Child change handlers update the master checkbox; snapshot the requested state first. */
            var checkAll = target.checked;
            enabledPermissionBoxes(form).forEach(function (input) {
                input.checked = checkAll;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
            syncPermissionMaster(form);
            return;
        }
        if (target.matches('form.wsm-device-permissions input[type="checkbox"][name="wsm_permissions[]"]')) {
            syncPermissionMaster(target.closest('form.wsm-device-permissions'));
        }
    });
    function initializePermissionMasters() {
        document.querySelectorAll('form.wsm-device-permissions').forEach(syncPermissionMaster);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializePermissionMasters, { once: true });
    } else {
        initializePermissionMasters();
    }
})();

/* Bulk actions for devices in the WordPress admin table. */
(function () {
    'use strict';
    function initDeviceBulkActions() {
        var toolbar = document.querySelector('[data-wsm-device-bulk]');
        var table = document.querySelector('.wsm-device-table-panel table');
        if (!toolbar || !table) return;

        var selectAll = table.querySelector('.wsm-device-select-all');
        var actionSelect = toolbar.querySelector('select[name="bulk_action"]');
        var applyButton = toolbar.querySelector('.wsm-device-bulk-apply');
        var status = toolbar.querySelector('.wsm-device-bulk-status');
        var endpoint = toolbar.getAttribute('data-ajax-url') || '';
        var nonce = toolbar.getAttribute('data-nonce') || '';
        if (!actionSelect || !applyButton || !status || !endpoint || !nonce) return;

        function selectableRows() {
            return Array.prototype.slice.call(table.querySelectorAll('tbody input.wsm-device-row-select:not(:disabled)'));
        }
        function selectedRows() {
            return selectableRows().filter(function (input) { return input.checked; });
        }
        function setStatus(message, kind) {
            status.textContent = message || '';
            status.className = 'description wsm-device-bulk-status' + (kind ? ' is-' + kind : '');
        }
        function syncSelection() {
            var rows = selectableRows();
            var selected = rows.filter(function (input) { return input.checked; }).length;
            if (selectAll) {
                selectAll.checked = rows.length > 0 && selected === rows.length;
                selectAll.indeterminate = selected > 0 && selected < rows.length;
            }
            applyButton.disabled = selected === 0 || !actionSelect.value;
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                selectableRows().forEach(function (input) { input.checked = selectAll.checked; });
                syncSelection();
            });
        }
        table.addEventListener('change', function (event) {
            if (event.target && event.target.matches('input.wsm-device-row-select')) syncSelection();
        });
        actionSelect.addEventListener('change', syncSelection);
        applyButton.addEventListener('click', function () {
            var selected = selectedRows();
            var operation = actionSelect.value;
            if (!selected.length || (operation !== 'revoke' && operation !== 'delete')) {
                syncSelection();
                return;
            }
            var label = operation === 'delete' ? 'حذف' : 'قطع دسترسی';
            if (!window.confirm('آیا از ' + label + ' ' + selected.length + ' دستگاه انتخاب‌شده مطمئن هستید؟')) return;

            applyButton.disabled = true;
            actionSelect.disabled = true;
            setStatus('در حال پردازش ' + selected.length + ' دستگاه…', '');
            var body = new FormData();
            body.append('action', 'wsm_bulk_devices');
            body.append('nonce', nonce);
            body.append('operation', operation);
            selected.forEach(function (input) { body.append('device_ids[]', input.value); });

            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                body: body
            }).then(function (response) {
                return response.text().then(function (text) {
                    var payload = null;
                    try { payload = JSON.parse(text); } catch (e) {}
                    if (!response.ok || !payload || payload.success !== true) {
                        var details = payload && payload.data ? payload.data : null;
                        throw new Error(details && details.message ? details.message : 'پاسخ معتبر از سرور دریافت نشد.');
                    }
                    return payload.data || {};
                });
            }).then(function (result) {
                var changed = Number(result.changed) || 0;
                var unchanged = Number(result.unchanged) || 0;
                var failed = Number(result.failed) || 0;
                setStatus('نتیجه: ' + changed + ' تغییر، ' + unchanged + ' بدون تغییر، ' + failed + ' ناموفق.', failed ? 'warning' : 'success');
                if (changed > 0) {
                    window.setTimeout(function () { window.location.reload(); }, 900);
                    return;
                }
                actionSelect.disabled = false;
                syncSelection();
            }).catch(function (error) {
                setStatus(error && error.message ? error.message : 'عملیات دسته‌جمعی ناموفق بود.', 'error');
                actionSelect.disabled = false;
                syncSelection();
            });
        });
        syncSelection();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDeviceBulkActions, { once: true });
    } else {
        initDeviceBulkActions();
    }
})();

/* Settings-only backup and restore workflow. */
(function () {
    'use strict';
    function init() {
        var root = document.getElementById('wsm-backup-control');
        if (!root) return;
        root.querySelectorAll('.wsm-backup-delete-form').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var message = form.getAttribute('data-confirm') || 'حذف نسخهٔ بکاپ را تأیید می‌کنید؟';
                if (!window.confirm(message)) event.preventDefault();
            });
        });
        var endpoint = root.getAttribute('data-endpoint') || '';
        var fileInput = document.getElementById('wsm-restore-file');
        var createButton = document.getElementById('wsm-create-backup');
        var createStatus = document.getElementById('wsm-create-backup-status');
        var createProgress = document.getElementById('wsm-create-backup-progress');
        var createSections = document.getElementById('wsm-create-backup-sections');
        var testPathButton = document.getElementById('wsm-test-backup-path');
        var testPathStatus = document.getElementById('wsm-test-backup-path-status');
        var describeButton = document.getElementById('wsm-describe-backup');
        var restoreStatus = document.getElementById('wsm-restore-status');
        var descriptionBox = document.getElementById('wsm-restore-description');
        var metadataBox = document.getElementById('wsm-restore-metadata');
        var choicesBox = document.getElementById('wsm-restore-choices');
        var previewButton = document.getElementById('wsm-preview-backup');
        var previewBox = document.getElementById('wsm-restore-preview');
        var previewSummaryBox = document.getElementById('wsm-restore-preview-summary');
        var confirmBox = document.getElementById('wsm-restore-confirm');
        var deleteConfirmWrap = document.getElementById('wsm-restore-delete-confirm-wrap');
        var deleteConfirmBox = document.getElementById('wsm-restore-delete-confirm');
        var deleteWarningText = document.getElementById('wsm-restore-delete-warning-text');
        var compatibilityWarning = document.getElementById('wsm-restore-compatibility-warning');
        var versionConfirmWrap = document.getElementById('wsm-restore-version-confirm-wrap');
        var versionConfirmBox = document.getElementById('wsm-restore-version-confirm');
        var applyButton = document.getElementById('wsm-apply-backup');
        var applyStatus = document.getElementById('wsm-apply-status');
        var applyResult = document.getElementById('wsm-apply-result');
        var state = { fileKey: '', preview: null, previewToken: '' };
        if (!fileInput || !describeButton || !previewButton || !applyButton) return;

        function clear(node) { while (node && node.firstChild) node.removeChild(node.firstChild); }
        function setStatus(node, text, kind) {
            if (!node) return;
            node.textContent = text || '';
            node.className = 'wsm-backup-status' + (kind ? ' is-' + kind : '');
        }
        function selectedFile() { return fileInput.files && fileInput.files.length ? fileInput.files[0] : null; }
        function fileKey(file) { return file ? [file.name, file.size, file.lastModified].join('|') : ''; }
        function typeLabel(type) {
            return ({ manual: 'دستی', automatic: 'خودکار', pre_restore: 'پیش از بازیابی' })[type] || String(type || '');
        }
        function addText(parent, tag, text, className) {
            var el = document.createElement(tag || 'p');
            if (className) el.className = className;
            el.textContent = text == null ? '' : String(text);
            parent.appendChild(el);
            return el;
        }
        var backupTableLabels = {
            wsm_access_keys: 'کلیدهای دسترسی (API Keys)',
            wsm_activity_log: 'گزارش فعالیت‌های سیستم',
            wsm_devices: 'دستگاه‌های متصل',
            wsm_auth_attempts: 'تلاش‌های ورود و احراز هویت',
            wsm_blocked_ips: 'نشانی‌های اینترنتی مسدودشده',
            wsm_used_nonces: 'شناسه‌های یک‌بارمصرف امنیتی',
            wsm_security_log: 'گزارش رویدادهای امنیتی',
            wsm_migration_log: 'گزارش تغییرات ساختار پایگاه داده',
            wsm_sync_logs: 'لاگ‌های همگام‌سازی (سینک)',
            wsm_sync_member_map: 'پیوند شناسه‌های اعضا برای همگام‌سازی',
            wsm_store_customers: 'اطلاعات مشتریان فروشگاه',
            wsm_customer_ledger: 'حساب و گردش مالی مشتریان',
            wsm_user_permissions: 'دسترسی‌های کاربران',
            wsm_users_permissions: 'دسترسی‌های کاربران',
            wsm_club_members: 'اطلاعات اعضای باشگاه',
            wsm_club_memberships: 'اشتراک‌ها و عضویت‌های باشگاه',
            wsm_club_plans: 'تعرفه‌ها و طرح‌های باشگاه',
            wsm_club_payments: 'پرداختی‌های باشگاه',
            wsm_club_attendance: 'سوابق ورود و خروج (تردد) اعضا',
            wsm_club_staff: 'اطلاعات کارکنان باشگاه',
            wsm_club_payroll: 'حقوق و دستمزد کارکنان باشگاه',
            wsm_club_finance: 'سوابق مالی باشگاه (جدول قدیمی)',
            wsm_club_financial_ledger: 'دفتر کل و گردش مالی باشگاه',
            wsm_club_financial_accounts: 'حساب‌های مالی باشگاه',
            wsm_club_receivables: 'مطالبات باشگاه',
            wsm_club_payables: 'بدهی‌ها و پرداختنی‌های باشگاه',
            wsm_club_cash_sessions: 'نشست‌ها و عملیات صندوق باشگاه',
            wsm_club_collections: 'مجموعه‌ها و گروه‌های باشگاه',
            wsm_club_sports: 'رشته‌های ورزشی باشگاه',
            wsm_club_phonebook: 'دفتر مخاطبان باشگاه',
            wsm_club_sms_log: 'گزارش پیامک‌های باشگاه',
            wsm_messages: 'پیام‌های افزونه',
            wsm_message_audit: 'گزارش رویدادهای پیام‌ها',
            wsm_backup_logs: 'تاریخچهٔ عملیات بکاپ',
            wsm_products: 'اطلاعات محصولات',
            wsm_orders: 'اطلاعات سفارش‌ها',
            wsm_customers: 'اطلاعات مشتریان'
        };
        var backupTableWordLabels = {
            access: 'دسترسی', key: 'کلید', keys: 'کلیدها', activity: 'فعالیت',
            log: 'گزارش', logs: 'گزارش‌ها', device: 'دستگاه', devices: 'دستگاه‌ها',
            auth: 'احراز هویت', attempts: 'تلاش‌ها', blocked: 'مسدودشده', ips: 'نشانی‌های اینترنتی',
            ip: 'نشانی اینترنتی', used: 'استفاده‌شده', nonces: 'شناسه‌های یک‌بارمصرف',
            migration: 'تغییر ساختار', store: 'فروشگاه', customer: 'مشتری', customers: 'مشتریان',
            ledger: 'دفتر کل و گردش مالی', sync: 'همگام‌سازی', member: 'عضو', members: 'اعضا',
            map: 'پیوند', permission: 'دسترسی', permissions: 'دسترسی‌ها', product: 'محصول',
            products: 'محصولات', order: 'سفارش', orders: 'سفارش‌ها', backup: 'بکاپ',
            settings: 'تنظیمات', club: 'باشگاه', finance: 'امور مالی', financial: 'مالی',
            account: 'حساب', accounts: 'حساب‌ها', payment: 'پرداختی', payments: 'پرداختی‌ها',
            attendance: 'تردد و ورود و خروج', membership: 'عضویت', memberships: 'اشتراک‌ها و عضویت‌ها',
            plan: 'طرح و تعرفه', plans: 'طرح‌ها و تعرفه‌ها', staff: 'کارکنان',
            payroll: 'حقوق و دستمزد', receivables: 'مطالبات', payables: 'بدهی‌ها',
            cash: 'صندوق', sessions: 'نشست‌ها', collections: 'مجموعه‌ها', sports: 'رشته‌های ورزشی',
            phonebook: 'دفتر مخاطبان', sms: 'پیامک', message: 'پیام', messages: 'پیام‌ها',
            audit: 'رویدادها', security: 'امنیت', user: 'کاربر', users: 'کاربران',
            token: 'شناسهٔ امنیتی', tokens: 'شناسه‌های امنیتی', queue: 'صف', event: 'رویداد',
            events: 'رویدادها', file: 'فایل', files: 'فایل‌ها', notification: 'اعلان',
            notifications: 'اعلان‌ها', profile: 'نمایه', media: 'رسانه'
        };
        var backupOptionLabels = {
            wsm_club_settings: 'تنظیمات باشگاه',
            wsm_finance_control_config: 'تنظیمات کنترل مالی',
            wsm_athlete_portal_intents: 'درخواست‌های پرتال ورزشکار',
            wsm_athlete_portal_renewal_reviews: 'بررسی تمدیدهای پرتال ورزشکار',
            wsm_athlete_portal_raffles: 'قرعه‌کشی‌های پرتال ورزشکار',
            wsm_athlete_portal_coupons: 'کوپن‌های پرتال ورزشکار'
        };
        function backupTableLabel(name) {
            var key = String(name || '').toLowerCase();
            if (Object.prototype.hasOwnProperty.call(backupTableLabels, key)) return backupTableLabels[key];
            if (key.indexOf('wsm_') !== 0) return 'جدول داده‌های افزونه';
            var translated = key.slice(4).split('_').map(function (part) { return backupTableWordLabels[part] || ''; }).filter(Boolean);
            return translated.length ? 'داده‌های افزونهٔ ' + translated.join(' ') : 'سایر داده‌های افزونه';
        }
        function backupOptionLabel(name) {
            var key = String(name || '').toLowerCase();
            return Object.prototype.hasOwnProperty.call(backupOptionLabels, key) ? backupOptionLabels[key] : 'تنظیم اختصاصی افزونه';
        }
        function addTechnicalText(parent, tag, text, technical, className) {
            var el = document.createElement(tag || 'p');
            if (className) el.className = className;
            el.appendChild(document.createTextNode(text == null ? '' : String(text)));
            if (technical != null && String(technical) !== '') {
                el.appendChild(document.createTextNode(' '));
                var small = document.createElement('small');
                small.style.fontSize = '.85em';
                small.style.opacity = '.75';
                var code = document.createElement('code');
                code.dir = 'ltr';
                code.textContent = String(technical);
                small.appendChild(code);
                el.appendChild(small);
            }
            parent.appendChild(el);
            return el;
        }
        function addSafeDownload(parent, url, label, marker) {
            if (!url) return false;
            try {
                var target = new URL(String(url), window.location.href);
                if ((target.protocol !== 'http:' && target.protocol !== 'https:') || target.origin !== window.location.origin) return false;
                var link = document.createElement('a');
                link.href = target.href;
                link.className = 'button button-secondary';
                if (marker) link.setAttribute('data-wsm-download-type', String(marker));
                link.textContent = label || 'دانلود نسخهٔ پشتیبان پیش از بازیابی';
                parent.appendChild(link);
                return true;
            } catch (e) { return false; }
        }
        function request(action, nonce, options) {
            options = options || {};
            var body = new FormData();
            body.append('action', action);
            body.append('_wpnonce', nonce || '');
            if (options.file) body.append('backup_file', options.file, options.file.name);
            if (options.selection) body.append('selection', JSON.stringify(options.selection));
            if (options.previewToken) body.append('preview_token', options.previewToken);
            if (Object.prototype.hasOwnProperty.call(options, 'confirmed')) body.append('confirmed', String(options.confirmed));
            if (Object.prototype.hasOwnProperty.call(options, 'confirmTableReplacement')) body.append('confirm_table_replacement', String(options.confirmTableReplacement));
            if (Object.prototype.hasOwnProperty.call(options, 'confirmLegacy')) body.append('confirm_legacy', String(options.confirmLegacy));
            if (Object.prototype.hasOwnProperty.call(options, 'storageMode')) body.append('storage_mode', String(options.storageMode));
            if (Object.prototype.hasOwnProperty.call(options, 'storagePath')) body.append('storage_path', String(options.storagePath));
            if (Object.prototype.hasOwnProperty.call(options, 'externalStoragePath')) body.append('external_storage_path', String(options.externalStoragePath));
            return fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body: body })
                .then(function (response) {
                    return response.text().then(function (text) {
                        var payload = null;
                        try { payload = JSON.parse(text); } catch (e) {}
                        if (!response.ok || !payload || payload.success !== true) {
                            var detail = payload && payload.data ? payload.data : null;
                            var error = new Error(detail && detail.message ? detail.message : 'پاسخ معتبر از سرور دریافت نشد.');
                            error.payload = detail;
                            throw error;
                        }
                        return payload.data;
                    });
                });
        }
        if (testPathButton) {
            testPathButton.addEventListener('click', function () {
                var modeInput = document.getElementById('wsm-backup-storage-mode');
                var pathInput = document.getElementById('wsm-backup-storage-path');
                var externalInput = document.getElementById('wsm-backup-external-path');
                if (!modeInput || !pathInput || !externalInput) { setStatus(testPathStatus, 'فیلدهای مسیر برای آزمون پیدا نشدند.', 'error'); return; }
                testPathButton.disabled = true;
                setStatus(testPathStatus, 'در حال آزمون مسیر؛ فایل موقت ساخته، نوشته، خوانده و حذف می‌شود…', '');
                request('wsm_test_backup_path', root.getAttribute('data-test-path-nonce'), {
                    storageMode: modeInput.value,
                    storagePath: pathInput.value,
                    externalStoragePath: externalInput.value
                })
                    .then(function (result) { setStatus(testPathStatus, result.message || 'آزمون مسیر با موفقیت انجام شد.', 'success'); })
                    .catch(function (error) { setStatus(testPathStatus, error.message || 'آزمون مسیر انجام نشد.', 'error'); })
                    .then(function () { testPathButton.disabled = false; });
            });
        }
        function resetPreview(clearDescription) {
            state.preview = null;
            state.previewToken = '';
            previewBox.hidden = true;
            applyButton.disabled = true;
            confirmBox.checked = false;
            if (deleteConfirmBox) deleteConfirmBox.checked = false;
            if (deleteConfirmWrap) deleteConfirmWrap.hidden = true;
            if (deleteWarningText) deleteWarningText.textContent = '';
            if (clearDescription) {
                if (versionConfirmBox) versionConfirmBox.checked = false;
                if (versionConfirmWrap) versionConfirmWrap.hidden = true;
                if (compatibilityWarning) { compatibilityWarning.textContent = ''; compatibilityWarning.hidden = true; }
            }
            setStatus(applyStatus, '', '');
            clear(applyResult);
            if (clearDescription) {
                state.fileKey = '';
                descriptionBox.hidden = true;
                previewButton.disabled = true;
                clear(choicesBox);
                metadataBox.textContent = '';
            } else {
                previewButton.disabled = !state.fileKey;
            }
        }
        function buildSelection() {
            var selection = { database_tables: [], plugin_options: [] };
            choicesBox.querySelectorAll('.wsm-restore-section-choice:checked').forEach(function (input) {
                if (input.getAttribute('data-kind') === 'table') selection.database_tables.push(input.value);
                else if (input.getAttribute('data-kind') === 'option') selection.plugin_options.push(input.value);
            });
            return selection;
        }
        function buildCreateSelection() {
            var selection = { database_tables: [], plugin_paths: [] };
            if (!createSections) return selection;
            createSections.querySelectorAll('.wsm-backup-create-table:checked').forEach(function (input) { selection.database_tables.push(input.value); });
            return selection;
        }
        function addChoice(parent, kind, value, text, checked, disabled, technicalName) {
            var label = document.createElement('label');
            label.className = 'wsm-restore-choice';
            var input = document.createElement('input');
            input.type = 'checkbox';
            input.className = 'wsm-restore-section-choice';
            input.setAttribute('data-kind', kind);
            input.value = String(value || '');
            input.checked = !!checked;
            input.disabled = !!disabled;
            label.appendChild(input);
            var span = document.createElement('span');
            span.appendChild(document.createTextNode(text == null ? '' : String(text)));
            if (technicalName != null && String(technicalName) !== '') {
                span.appendChild(document.createTextNode(' '));
                var small = document.createElement('small');
                small.style.fontSize = '.85em';
                small.style.opacity = '.75';
                var code = document.createElement('code');
                code.dir = 'ltr';
                code.textContent = String(technicalName);
                small.appendChild(code);
                span.appendChild(small);
            }
            label.appendChild(span);
            parent.appendChild(label);
        }
        function renderDescription(info) {
            var meta = info && info.metadata ? info.metadata : {};
            var parts = [];
            if (meta.format_version != null) parts.push('نسخهٔ سند داده: ' + meta.format_version);
            if (info.archive && Number(info.archive.archive_version) > 0) parts.push('نسخهٔ بسته: ' + info.archive.archive_version);
            if (meta.created_at) parts.push('زمان محلیِ ثبت‌شده در فایل: ' + meta.created_at);
            if (meta.created_at_gmt) parts.push('زمان هماهنگ جهانی (UTC): ' + meta.created_at_gmt);
            if (meta.backup_type) parts.push('نوع: ' + typeLabel(meta.backup_type));
            if (meta.table_count != null) parts.push('تعداد جدول‌ها: ' + meta.table_count);
            if (meta.record_count != null) parts.push('تعداد رکوردها: ' + meta.record_count);
            if (meta.plugin_version) parts.push('نسخهٔ افزونه: ' + meta.plugin_version);
            if (info.archive && info.archive.database_version) parts.push('نسخهٔ پایگاه داده: ' + info.archive.database_version);
            if (info.archive && info.archive.file_count != null) parts.push('فایل‌های افزونه در بسته: ' + info.archive.file_count);
            if (info.archive && info.archive.manifest_checksum) parts.push('چکیدهٔ SHA-256 فهرست داخلی: ' + info.archive.manifest_checksum);
            if (info.checksum) parts.push('چکیدهٔ SHA-256 سند داده‌ها: ' + info.checksum);
            if (info.archive_checksum) parts.push('چکیدهٔ SHA-256 کل بسته: ' + info.archive_checksum);
            metadataBox.textContent = parts.join(' · ');
            var compatibility = info.compatibility || {};
            if (compatibilityWarning) {
                compatibilityWarning.textContent = compatibility.warning || '';
                compatibilityWarning.hidden = !compatibility.requires_confirmation;
            }
            if (versionConfirmWrap) versionConfirmWrap.hidden = !compatibility.requires_confirmation;
            if (versionConfirmBox) versionConfirmBox.checked = false;
            clear(choicesBox);
            var tables = Array.isArray(info.database_tables) ? info.database_tables : [];
            var options = Array.isArray(info.plugin_options) ? info.plugin_options : [];
            var messagesCompatible = info.message_site_compatible === true;
            var hasMessageTables = tables.some(function (table) { return table && (table.name === 'wsm_messages' || table.name === 'wsm_message_audit'); });
            if (hasMessageTables && !messagesCompatible) {
                addText(choicesBox, 'p', 'پیام‌ها و ممیزی آن‌ها در این فایل قابل انتخاب نیستند: نشانۀ همان نصب وجود ندارد یا نشانی/کلیدهای احراز هویت با مقصد یکسان نیست.', 'notice notice-warning inline');
            }
            if (tables.length) {
                addText(choicesBox, 'h4', 'جداول دادهٔ افزونه');
                tables.forEach(function (table) {
                    var tableName = String(table.name || '');
                    var tableLabel = backupTableLabel(tableName) + ' — ' + (table.record_count || 0) + ' رکورد';
                    if (Array.isArray(table.credential_columns_omitted) && table.credential_columns_omitted.length) {
                        tableLabel += ' (فیلدهای احراز هویت هنگام بازیابی کنار گذاشته می‌شوند)';
                    }
                    var protectedLogTable = table.name === 'wsm_backup_logs';
                    if (protectedLogTable) tableLabel += ' (تاریخچهٔ داخلی؛ برای حفظ گزارش بکاپ بازیابی نمی‌شود)';
                    var messageTable = tableName === 'wsm_messages' || tableName === 'wsm_message_audit';
                    var blockedMessageTable = messageTable && !messagesCompatible;
                    if (blockedMessageTable) tableLabel += ' (فقط همان نصب؛ این فایل با مقصد سازگار نیست)';
                    addChoice(choicesBox, 'table', table.name, tableLabel, !protectedLogTable && !blockedMessageTable, protectedLogTable || blockedMessageTable, tableName);
                });
            } else addText(choicesBox, 'p', 'جدول قابل‌بازیابی در این فایل پیدا نشد.', 'description');
            var archive = info.archive || {};
            if (Number(archive.file_count || 0) > 0) {
                addText(choicesBox, 'p', 'این بستۀ قدیمی شامل فایل کد افزونه است؛ فایل‌ها فقط خوانده و در پیش‌نمایش شمرده می‌شوند و هرگز انتخاب یا بازیابی نخواهند شد.', 'description');
            } else {
                addText(choicesBox, 'p', 'این فایل هیچ کد افزونه‌ای برای بازیابی ندارد؛ بازیابی فقط داده‌های مجاز را شامل می‌شود.', 'description');
            }
            if (options.length) {
                addText(choicesBox, 'h4', 'تنظیمات افزونه');
                options.forEach(function (option) { var optionName = String(option.name || ''); addChoice(choicesBox, 'option', option.name, backupOptionLabel(optionName), true, false, optionName); });
            }
            descriptionBox.hidden = false;
            previewButton.disabled = false;
            previewBox.hidden = true;
            state.preview = null;
            state.previewToken = '';
            applyButton.disabled = true;
        }
        function numberValue(value) {
            var number = Number(value);
            return isFinite(number) ? number : 0;
        }
        function renderPreview(data) {
            state.preview = data;
            state.previewToken = data.preview_token || '';
            clear(previewSummaryBox);
            var summary = data.summary || {};
            var statLabels = {
                table_count: 'تعداد جدول‌های انتخاب‌شده', source_rows: 'رکوردهای منبع', inserts: 'درج', updates: 'به‌روزرسانی',
                unchanged: 'بدون تغییر', conflicts: 'تعارض جدول', deletes: 'رکوردهای حذف‌شونده',
                options_new: 'تنظیم جدید', options_changed: 'تنظیم تغییریافته', options_unchanged: 'تنظیم بدون تغییر', options_conflicts: 'تعارض تنظیمات'
            };
            var stats = document.createElement('div');
            stats.className = 'wsm-restore-stats';
            Object.keys(statLabels).forEach(function (key) {
                var item = document.createElement('div');
                item.className = 'wsm-restore-stat';
                addText(item, 'span', statLabels[key]);
                addText(item, 'strong', numberValue(summary[key]));
                stats.appendChild(item);
            });
            previewSummaryBox.appendChild(stats);
            var tables = Array.isArray(data.tables) ? data.tables : [];
            if (tables.length) {
                addText(previewSummaryBox, 'h5', 'جزئیات جدول‌ها');
                tables.forEach(function (table) {
                    var statusMap = { ready: 'سازگار', partial: 'دارای تعارض', missing: 'جدول مقصد مفقود', incompatible: 'ساختار ناسازگار' };
                    var tableName = String(table.name || '');
                    var line = backupTableLabel(tableName) + ' — ' + (statusMap[table.status] || table.status || '') +
                        '؛ درج ' + numberValue(table.inserts) + '، به‌روزرسانی ' + numberValue(table.updates) +
                        '، بدون تغییر ' + numberValue(table.unchanged) + '، تعارض ' + numberValue(table.conflicts) + '، حذف ' + numberValue(table.deletes);
                    if (Array.isArray(table.warnings) && table.warnings.length) line += '؛ هشدار: ' + table.warnings.join('، ');
                    addTechnicalText(previewSummaryBox, 'p', line, tableName, 'wsm-restore-detail');
                });
            }
            var options = Array.isArray(data.plugin_options) ? data.plugin_options : [];
            if (options.length) {
                addText(previewSummaryBox, 'h5', 'تنظیمات انتخاب‌شده');
                var optionLabels = { new: 'جدید', changed: 'تغییر می‌کند', unchanged: 'بدون تغییر', conflict: 'تعارض' };
                options.forEach(function (option) {
                    var optionName = String(option.name || '');
                    addTechnicalText(previewSummaryBox, 'p', backupOptionLabel(optionName) + ' — ' + (optionLabels[option.status] || option.status || ''), optionName, 'wsm-restore-detail');
                });
            }
            var tableDeleteCount = numberValue(summary.deletes);
            if (deleteConfirmWrap) deleteConfirmWrap.hidden = tableDeleteCount <= 0;
            if (deleteWarningText) deleteWarningText.textContent = tableDeleteCount > 0 ? 'هشدار حذف: در صورت ادامه، ' + tableDeleteCount + ' رکورد فعلی که در نسخهٔ بکاپ نیستند از جدول‌های انتخاب‌شده حذف می‌شوند. پس از ثبت، بازگردانی آن‌ها فقط از نسخهٔ پشتیبان پیش از بازیابی ممکن است.' : '';
            confirmBox.checked = false;
            if (deleteConfirmBox) deleteConfirmBox.checked = false;
            previewBox.hidden = false;
            var conflicts = numberValue(summary.conflicts) > 0 || numberValue(summary.options_conflicts) > 0;
            if (tables.some(function (table) { return table.status !== 'ready' || numberValue(table.conflicts) > 0; })) conflicts = true;
            setStatus(restoreStatus, conflicts ? 'تعارض یا ناسازگاری وجود دارد؛ این پیش‌نمایش قابل‌اعمال نیست.' : 'پیش‌نمایش فقط‌خواندنی آماده است. برای ادامه تأیید کنید.', conflicts ? 'error' : 'success');
            updateApplyButton();
        }
        function updateApplyButton() {
            var data = state.preview;
            if (!data || !state.previewToken) { applyButton.disabled = true; return; }
            var summary = data.summary || {};
            var tables = Array.isArray(data.tables) ? data.tables : [];
            var safe = data.read_only === true && numberValue(summary.conflicts) === 0 && numberValue(summary.options_conflicts) === 0;
            if (tables.some(function (table) { return table.status !== 'ready' || numberValue(table.conflicts) > 0; })) safe = false;
            var versionNeedsConsent = !!(versionConfirmWrap && !versionConfirmWrap.hidden);
            var replacementNeedsConsent = numberValue(summary.deletes) > 0;
            applyButton.disabled = !(safe && confirmBox.checked && (!replacementNeedsConsent || (deleteConfirmBox && deleteConfirmBox.checked)) && (!versionNeedsConsent || (versionConfirmBox && versionConfirmBox.checked)));
        }
        function showApplyError(error) {
            setStatus(applyStatus, error && error.message ? error.message : 'اجرای بازیابی انجام نشد.', 'error');
            var payload = error && error.payload ? error.payload : null;
            if (payload && payload.pre_restore_download_url) {
                addText(applyResult, 'p', 'نسخهٔ پشتیبان پیش از بازیابی برای بررسی یا بازیابی دستی حفظ شده است.');
                addSafeDownload(applyResult, payload.pre_restore_download_url, 'دانلود نسخهٔ پشتیبان پیش از بازیابی');
            }
        }

        if (createSections) {
            createSections.addEventListener('click', function (event) {
                var action = event.target && event.target.closest ? event.target.closest('[data-wsm-backup-select-all],[data-wsm-backup-clear-all]') : null;
                if (!action || !createSections.contains(action)) return;
                event.preventDefault();
                var checked = action.hasAttribute('data-wsm-backup-select-all');
                createSections.querySelectorAll('.wsm-backup-create-table').forEach(function (input) { if (!input.hasAttribute('data-wsm-backup-required')) input.checked = checked; });
            });
        }
        createButton.addEventListener('click', function () {
            var backupSelection = buildCreateSelection();
            if (!backupSelection.database_tables.length) {
                setStatus(createStatus, 'برای ساخت بکاپ دست‌کم یک جدول دادهٔ باشگاه را انتخاب کنید.', 'error');
                return;
            }
            var previousDownload = createStatus && createStatus.parentNode ? createStatus.parentNode.querySelector('[data-wsm-download-type="manual"]') : null;
            if (previousDownload && previousDownload.parentNode) previousDownload.parentNode.removeChild(previousDownload);
            createButton.disabled = true;
            createButton.setAttribute('aria-busy', 'true');
            if (createProgress) createProgress.hidden = false;
            setStatus(createStatus, 'در حال ساخت و بررسی بکاپ…', '');
            request('wsm_create_backup', root.getAttribute('data-create-nonce'), { selection: backupSelection })
                .then(function (result) {
                    result = result || {};
                    var metadata = result.metadata && typeof result.metadata === 'object' ? result.metadata : {};
                    var bytes = parseInt(result.size_bytes, 10);
                    if (!isFinite(bytes) || bytes < 0) bytes = 0;
                    var date = result.created_at_display || metadata.created_at || '—';
                    var humanSize = result.size_display || (bytes + ' بایت');
                    var archive = result.archive && typeof result.archive === 'object' ? result.archive : {};
                    var message = 'بستهٔ داده‌ای ZIP با موفقیت ساخته شد: ' + (result.filename || '—') + '؛ زمان: ' + date + '؛ حجم: ' + humanSize + ' (' + bytes + ' بایت)؛ فایل کد افزونه: ' + numberValue(archive.file_count) + '. گزارش در تاریخچه ثبت شد؛ برای به‌روزرسانی جدول، صفحه را تازه‌سازی کنید.';
                    if (result.download_url && addSafeDownload(createStatus.parentNode, result.download_url, 'دانلود بکاپ ساخته‌شده', 'manual')) {
                        setStatus(createStatus, message, 'success');
                    } else {
                        setStatus(createStatus, 'بکاپ ساخته شد، اما پیوند دانلود معتبر دریافت نشد. صفحه را تازه‌سازی کنید و از تاریخچهٔ بکاپ دریافت کنید.', 'error');
                    }
                })
                .catch(function (error) { setStatus(createStatus, error.message || 'ساخت بکاپ ناموفق بود.', 'error'); })
                .then(function () {
                    createButton.disabled = false;
                    createButton.removeAttribute('aria-busy');
                    if (createProgress) createProgress.hidden = true;
                });
        });
        fileInput.addEventListener('change', function () {
            resetPreview(true);
            setStatus(restoreStatus, selectedFile() ? 'فایل انتخاب شد؛ آن را برای مشاهدهٔ بخش‌ها بررسی کنید.' : '', '');
            setStatus(applyStatus, '', '');
        });
        describeButton.addEventListener('click', function () {
            var file = selectedFile();
            if (!file) { setStatus(restoreStatus, 'ابتدا فایل بکاپ را انتخاب کنید.', 'error'); return; }
            resetPreview(true);
            describeButton.disabled = true;
            setStatus(restoreStatus, 'در حال اعتبارسنجی فایل و خواندن فهرست بخش‌ها…', '');
            var requestedKey = fileKey(file);
            request('wsm_describe_backup', root.getAttribute('data-describe-nonce'), { file: file })
                .then(function (description) {
                    if (fileKey(selectedFile()) !== requestedKey) return;
                    state.fileKey = requestedKey;
                    renderDescription(description);
                    setStatus(restoreStatus, 'فایل معتبر است. بخش‌های موردنظر را انتخاب کنید.', 'success');
                })
                .catch(function (error) { setStatus(restoreStatus, error.message || 'بررسی فایل بکاپ ناموفق بود.', 'error'); })
                .then(function () { describeButton.disabled = false; });
        });
        choicesBox.addEventListener('change', function (event) {
            if (!event.target || !event.target.classList.contains('wsm-restore-section-choice')) return;
            resetPreview(false);
            setStatus(restoreStatus, 'انتخاب بخش‌ها تغییر کرد؛ پیش‌نمایش قبلی باطل شد. پیش‌نمایش تازه بگیرید.', '');
        });
        previewButton.addEventListener('click', function () {
            var file = selectedFile();
            if (!file || fileKey(file) !== state.fileKey) { setStatus(restoreStatus, 'فایل تغییر کرده است؛ دوباره آن را بررسی کنید.', 'error'); resetPreview(true); return; }
            var selection = buildSelection();
            if (!selection.database_tables.length && !selection.plugin_options.length) {
                setStatus(restoreStatus, 'حداقل یک جدول داده یا تنظیم مجاز را برای پیش‌نمایش انتخاب کنید.', 'error'); return;
            }
            previewButton.disabled = true;
            resetPreview(false);
            previewButton.disabled = true;
            setStatus(restoreStatus, 'در حال مقایسهٔ انتخاب با داده‌های فعلی؛ هیچ تغییری اعمال نمی‌شود…', '');
            request('wsm_preview_backup', root.getAttribute('data-preview-nonce'), { file: file, selection: selection })
                .then(function (preview) {
                    if (fileKey(selectedFile()) !== state.fileKey) return;
                    renderPreview(preview);
                })
                .catch(function (error) { setStatus(restoreStatus, error.message || 'پیش‌نمایش ناموفق بود.', 'error'); })
                .then(function () { previewButton.disabled = false; });
        });
        confirmBox.addEventListener('change', updateApplyButton);
        if (deleteConfirmBox) deleteConfirmBox.addEventListener('change', updateApplyButton);
        if (versionConfirmBox) versionConfirmBox.addEventListener('change', updateApplyButton);
        applyButton.addEventListener('click', function () {
            var file = selectedFile();
            if (!file || fileKey(file) !== state.fileKey || !state.previewToken) { setStatus(applyStatus, 'پیش‌نمایش منقضی یا نامعتبر است؛ دوباره پیش‌نمایش بگیرید.', 'error'); return; }
            if (applyButton.disabled) return;
            var deleteCount = numberValue(state.preview && state.preview.summary ? state.preview.summary.deletes : 0);
            var confirmMessage = 'بازیابی فقط بخش‌های انتخاب‌شده را اعمال می‌کند. پیش از هر تغییری نسخهٔ پشتیبان محافظتی ساخته می‌شود؛ ادامه می‌دهید؟';
            if (deleteCount > 0) confirmMessage += '\n\nهشدار: ' + deleteCount + ' رکورد فعلی که در بکاپ نیستند حذف خواهند شد.';
            if (!window.confirm(confirmMessage)) return;
            applyButton.disabled = true;
            setStatus(applyStatus, 'در حال ایجاد نسخهٔ پشتیبان و اجرای بازیابی…', '');
            clear(applyResult);
            var selection = buildSelection();
            var token = state.previewToken;
            request('wsm_apply_backup', root.getAttribute('data-apply-nonce'), {
                file: file, selection: selection, previewToken: token, confirmed: '1',
                confirmTableReplacement: deleteConfirmBox && deleteConfirmBox.checked ? '1' : '0',
                confirmLegacy: versionConfirmBox && versionConfirmBox.checked ? '1' : '0'
            }).then(function (result) {
                state.previewToken = '';
                setStatus(applyStatus, 'بازیابی با موفقیت پایان یافت.', 'success');
                addText(applyResult, 'p', 'پیش از بازیابی، نسخهٔ پشتیبان محافظتی ساخته شد: ' + (result.pre_restore_backup_id || ''));
                addSafeDownload(applyResult, result.pre_restore_download_url, 'دانلود نسخهٔ پشتیبان پیش از بازیابی');
                var restoredTables = Array.isArray(result.table_results) ? result.table_results : [];
                if (restoredTables.length) {
                    addText(applyResult, 'h5', 'نتیجهٔ جدول‌های انتخاب‌شده');
                    restoredTables.forEach(function (table) {
                        var tableName = String(table.name || '');
                        addTechnicalText(applyResult, 'p', backupTableLabel(tableName) + ' — درج ' + numberValue(table.inserts) + '، به‌روزرسانی ' + numberValue(table.updates) + '، بدون تغییر ' + numberValue(table.unchanged) + '، حذف ' + numberValue(table.deletes), tableName, 'wsm-restore-detail');
                    });
                }
                if (result.audit_warning) addText(applyResult, 'p', result.audit_warning, 'description');
            }).catch(function (error) {
                state.previewToken = '';
                showApplyError(error);
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();

/* Employee permissions: searchable accordions, group/global selection, and bulk employee actions. */
(function () {
    'use strict';

    function initEmployeePermissionTools(form) {
        if (!form || form.dataset.wsmEmployeePermissionsReady === '1') return;
        var tree = form.querySelector('.wsm-employee-permissions');
        var search = form.querySelector('#wsm-employee-permission-search');
        if (!tree || !search) return;
        form.dataset.wsmEmployeePermissionsReady = '1';

        var groups = Array.prototype.slice.call(tree.querySelectorAll('details.wsm-employee-permission-group'));
        var count = form.querySelector('[data-wsm-permission-count]');
        var empty = form.querySelector('[data-wsm-permission-empty]');
        var statusSelect = form.querySelector('#wsm-employee-status');
        var preset = form.querySelector('#wsm-employee-preset');
        var total = count ? Number(count.getAttribute('data-total')) || form.querySelectorAll('.wsm-permission-check').length : form.querySelectorAll('.wsm-permission-check').length;
        var topActions = Array.prototype.slice.call(form.querySelectorAll('[data-wsm-permission-action="select-all"],[data-wsm-permission-action="clear-all"]'));

        function permissionBoxes(scope) {
            return Array.prototype.slice.call((scope || tree).querySelectorAll('.wsm-permission-check'));
        }
        function syncPermissionCount() {
            var boxes = permissionBoxes(form);
            var selected = boxes.filter(function (box) { return box.checked; }).length;
            if (count) count.textContent = selected + ' از ' + total;
            topActions.forEach(function (button) {
                button.disabled = boxes.length === 0 || boxes.every(function (box) { return box.disabled; });
            });
            groups.forEach(function (group) {
                var actions = group.querySelectorAll('[data-wsm-permission-action="select-group"],[data-wsm-permission-action="clear-group"]');
                var groupBoxes = permissionBoxes(group);
                Array.prototype.forEach.call(actions, function (button) {
                    button.disabled = groupBoxes.length === 0 || groupBoxes.every(function (box) { return box.disabled; });
                });
            });
        }
        function filterPermissions() {
            var query = String(search.value || '').trim().toLocaleLowerCase();
            var matched = 0;
            groups.forEach(function (group) {
                var summary = group.querySelector('summary');
                var groupNameMatches = !!query && summary && String(summary.textContent || '').toLocaleLowerCase().indexOf(query) !== -1;
                var groupMatches = 0;
                Array.prototype.forEach.call(group.querySelectorAll('.wsm-employee-permission-item'), function (item) {
                    var hit = !query || groupNameMatches || String(item.textContent || '').toLocaleLowerCase().indexOf(query) !== -1;
                    item.hidden = !hit;
                    if (hit) { groupMatches++; matched++; }
                });
                group.hidden = !!query && groupMatches === 0;
                if (query) group.open = groupMatches > 0;
                else group.open = false;
            });
            if (empty) empty.hidden = !query || matched > 0;
        }
        function applySelection(scope, selected) {
            permissionBoxes(scope).forEach(function (box) {
                var item = box.closest('.wsm-employee-permission-item');
                if (box.disabled || (item && item.hidden)) return;
                box.checked = selected;
                box.dispatchEvent(new Event('change', { bubbles: true }));
            });
            syncPermissionCount();
        }

        form.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('[data-wsm-permission-action]') : null;
            if (!button || !form.contains(button)) return;
            event.preventDefault();
            var action = button.getAttribute('data-wsm-permission-action');
            var scope = button.closest('details.wsm-employee-permission-group');
            if (action === 'select-all' || action === 'select-group') applySelection(action === 'select-all' ? tree : scope, true);
            else if (action === 'clear-all' || action === 'clear-group') applySelection(action === 'clear-all' ? tree : scope, false);
        });
        form.addEventListener('change', function (event) {
            if (event.target && event.target.matches && event.target.matches('.wsm-permission-check')) syncPermissionCount();
        });
        search.addEventListener('input', filterPermissions);
        search.addEventListener('keydown', function (event) { if (event.key === 'Enter') event.preventDefault(); });
        if (preset) {
            preset.addEventListener('change', function () {
                var option = preset.options[preset.selectedIndex];
                var permissions = [];
                try { permissions = JSON.parse(option.getAttribute('data-permissions') || '[]'); } catch (e) {}
                var locked = preset.value === 'super_admin';
                permissionBoxes(form).forEach(function (box) {
                    box.checked = permissions.indexOf(box.value) !== -1;
                    box.disabled = locked;
                });
                if (statusSelect) statusSelect.disabled = locked;
                filterPermissions();
                syncPermissionCount();
            });
        }
        filterPermissions();
        syncPermissionCount();
    }

    function initEmployeeBulkActions(root) {
        if (!root || root.dataset.wsmEmployeeBulkReady === '1') return;
        var table = root.querySelector('.wsm-employee-table-wrap table');
        var search = root.querySelector('#wsm-employee-search');
        var selectAll = table && table.querySelector('.wsm-employee-select-all');
        var actionSelect = root.querySelector('#wsm-employee-bulk-action');
        var applyButton = root.querySelector('.wsm-employee-bulk-apply');
        var status = root.querySelector('.wsm-employee-bulk-status');
        var endpoint = root.getAttribute('data-ajax-url') || '';
        var nonce = root.getAttribute('data-nonce') || '';
        if (!table || !actionSelect || !applyButton || !status || !endpoint || !nonce) return;
        root.dataset.wsmEmployeeBulkReady = '1';

        var tbody = table.querySelector('tbody');
        var noResults = tbody.querySelector('.wsm-employee-no-results');
        var emptyRow = tbody.querySelector('.wsm-employee-empty-row');
        var busy = false;

        function rows() {
            return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-wsm-employee-row]'));
        }
        function visibleCheckboxes() {
            return rows().filter(function (row) { return !row.hidden; }).map(function (row) { return row.querySelector('.wsm-employee-row-select'); }).filter(function (input) { return input && !input.disabled; });
        }
        function selectedCheckboxes() {
            return visibleCheckboxes().filter(function (input) { return input.checked; });
        }
        function setStatus(message, kind) {
            status.textContent = message || '';
            status.className = 'description wsm-employee-bulk-status' + (kind ? ' is-' + kind : '');
        }
        function syncSelection() {
            var available = visibleCheckboxes();
            var selected = available.filter(function (input) { return input.checked; }).length;
            if (selectAll) {
                selectAll.checked = available.length > 0 && selected === available.length;
                selectAll.indeterminate = selected > 0 && selected < available.length;
                selectAll.disabled = busy || available.length === 0;
            }
            actionSelect.disabled = busy;
            applyButton.disabled = busy || selected === 0 || !actionSelect.value;
        }
        function filterEmployees() {
            var query = String(search && search.value || '').trim().toLocaleLowerCase();
            var visible = 0;
            rows().forEach(function (row) {
                var searchable = String(row.getAttribute('data-employee-name') || '') + ' ' + String(row.getAttribute('data-employee-email') || '');
                var matches = !query || searchable.toLocaleLowerCase().indexOf(query) !== -1;
                row.hidden = !matches;
                if (!matches) {
                    var checkbox = row.querySelector('.wsm-employee-row-select');
                    if (checkbox) checkbox.checked = false;
                } else { visible++; }
            });
            if (emptyRow) emptyRow.hidden = !!query;
            if (noResults) noResults.hidden = !query || visible > 0;
            syncSelection();
        }
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                visibleCheckboxes().forEach(function (input) { input.checked = selectAll.checked; });
                syncSelection();
            });
        }
        table.addEventListener('change', function (event) {
            if (event.target && event.target.matches && event.target.matches('.wsm-employee-row-select')) syncSelection();
        });
        if (search) search.addEventListener('input', filterEmployees);
        actionSelect.addEventListener('change', syncSelection);
        applyButton.addEventListener('click', function () {
            var selected = selectedCheckboxes();
            var operation = actionSelect.value;
            if (busy || !selected.length || !['activate', 'deactivate', 'delete'].includes(operation)) { syncSelection(); return; }
            var operationLabel = operation === 'activate' ? 'فعال‌سازی' : (operation === 'deactivate' ? 'غیرفعال‌سازی' : 'حذف کامل');
            var confirmation = 'آیا از ' + operationLabel + ' ' + selected.length + ' کارمند انتخاب‌شده مطمئن هستید؟';
            if (operation === 'delete') confirmation += '\n\nاین کار حساب WordPress را به‌طور دائمی حذف می‌کند. محتوای منتسب به کارمند به حساب مدیر جاری منتقل می‌شود؛ گزارش فعالیت و سابقهٔ دستگاه‌ها حفظ خواهد شد.';
            if (!window.confirm(confirmation)) return;

            var selectedIds = selected.map(function (input) { return input.value; });
            busy = true;
            syncSelection();
            setStatus('در حال پردازش ' + selectedIds.length + ' کارمند…', '');
            var body = new FormData();
            body.append('action', 'wsm_bulk_employees');
            body.append('nonce', nonce);
            body.append('operation', operation);
            selectedIds.forEach(function (id) { body.append('employee_ids[]', id); });

            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                body: body
            }).then(function (response) {
                return response.text().then(function (text) {
                    var payload = null;
                    try { payload = JSON.parse(text); } catch (e) {}
                    if (!response.ok || !payload || payload.success !== true) {
                        var details = payload && payload.data ? payload.data : null;
                        throw new Error(details && details.message ? details.message : 'پاسخ معتبر از سرور دریافت نشد.');
                    }
                    return payload.data || {};
                });
            }).then(function (result) {
                var changed = Number(result.changed) || 0;
                var unchanged = Number(result.unchanged) || 0;
                var failed = Number(result.failed) || 0;
                var errors = Array.isArray(result.errors) ? result.errors.map(function (item) { return item && item.message ? String(item.message) : ''; }).filter(Boolean) : [];
                var message = 'نتیجه: ' + changed + ' تغییر، ' + unchanged + ' بدون تغییر، ' + failed + ' ناموفق.';
                if (errors.length) message += ' ' + errors.join(' · ');
                setStatus(message, failed ? 'warning' : 'success');
                if (changed > 0) {
                    window.setTimeout(function () { window.location.reload(); }, failed ? 1800 : 900);
                    return;
                }
                busy = false;
                syncSelection();
            }).catch(function (error) {
                setStatus(error && error.message ? error.message : 'عملیات دسته‌جمعی کارمندان ناموفق بود.', 'error');
                busy = false;
                syncSelection();
            });
        });
        filterEmployees();
    }

    function init() {
        document.querySelectorAll('form.wsm-employee-form').forEach(initEmployeePermissionTools);
        document.querySelectorAll('[data-wsm-employee-bulk]').forEach(initEmployeeBulkActions);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();

