// public/assets/js/date_range.js
document.addEventListener('DOMContentLoaded', function () {

  function parseDate(str) {
    if (!str) return null;
    var parts = String(str).split('-');
    if (parts.length !== 3) return null;
    var y = parseInt(parts[0], 10);
    var m = parseInt(parts[1], 10);
    var d = parseInt(parts[2], 10);
    if (!y || !m || !d) return null;
    return new Date(y, m - 1, d);
  }

  function formatDate(d) {
    var y = d.getFullYear();
    var m = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

  function sameDay(a, b) {
    return a && b &&
      a.getFullYear() === b.getFullYear() &&
      a.getMonth() === b.getMonth() &&
      a.getDate() === b.getDate();
  }

  function inRange(d, start, end) {
    if (!start || !end) return false;
    var t = d.getTime();
    return t >= start.getTime() && t <= end.getTime();
  }

  function getMonday(d) {
    var day = d.getDay(); // 0=Sun..6=Sat
    if (day === 0) day = 7;
    var diff = d.getDate() - day + 1;
    return new Date(d.getFullYear(), d.getMonth(), diff);
  }

  document.querySelectorAll('[data-drp]').forEach(function (root) {
    var display    = root.querySelector('[data-drp-display]');
    var container  = root.querySelector('[data-drp-container]');
    var grid       = root.querySelector('[data-drp-grid]');
    var monthLabel = root.querySelector('[data-drp-month]');
    var inputFrom  = root.querySelector('[data-drp-from]');
    var inputTo    = root.querySelector('[data-drp-to]');
    var inputAll   = root.querySelector('[data-drp-all]'); // NEW

    if (!display || !container || !grid || !monthLabel || !inputFrom || !inputTo || !inputAll) return;

    var startDate = parseDate(inputFrom.value);
    var endDate   = parseDate(inputTo.value);
    var today     = new Date();

    // 是否 All
    var isAll = (String(inputAll.value || '0') === '1');

    // from==to 视为只选 start
    if (startDate && endDate && sameDay(startDate, endDate)) {
      endDate = null;
      inputTo.value = '';
    }

    var currentMonth = startDate
      ? new Date(startDate.getFullYear(), startDate.getMonth(), 1)
      : (endDate ? new Date(endDate.getFullYear(), endDate.getMonth(), 1)
                 : new Date(today.getFullYear(), today.getMonth(), 1));

    function updateDisplayText() {
      var df = (inputFrom.value || '').trim();
      var dt = (inputTo.value || '').trim();
      var allv = (String(inputAll.value || '0') === '1');

      if (allv) {
        display.value = 'All';
        return;
      }
      if (df && dt) display.value = df + ' to ' + dt;
      else if (df) display.value = df + ' to';
      else if (dt) display.value = 'to ' + dt;
      else display.value = '';
    }

    function renderCalendar() {
      grid.innerHTML = '';

      var year  = currentMonth.getFullYear();
      var month = currentMonth.getMonth();
      monthLabel.textContent = year + '-' + String(month + 1).padStart(2, '0');

      var firstDay = new Date(year, month, 1);
      var weekday  = firstDay.getDay();      // 0=Sun..6=Sat
      weekday      = (weekday + 6) % 7;      // Mon=0..Sun=6

      var daysInMonth = new Date(year, month + 1, 0).getDate();

      for (var i = 0; i < weekday; i++) {
        var blank = document.createElement('div');
        blank.className = 'drp-day is-empty';
        grid.appendChild(blank);
      }

      for (var d = 1; d <= daysInMonth; d++) {
        (function (dayNum) {
          var cell = document.createElement('div');
          var dateObj = new Date(year, month, dayNum);

          var cls = ['drp-day'];
          if (startDate && sameDay(dateObj, startDate)) cls.push('is-start');
          if (endDate && sameDay(dateObj, endDate)) cls.push('is-end');
          if (inRange(dateObj, startDate, endDate)) cls.push('is-in-range');

          cell.className = cls.join(' ');
          cell.textContent = String(dayNum);

          cell.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            onSelectDate(dateObj);
          });

          grid.appendChild(cell);
        })(d);
      }
    }

    function setAll(flag) {
      inputAll.value = flag ? '1' : '0';
    }

    function onSelectDate(dateObj) {
      // 一旦手动选日期，就不是 All
      setAll(false);

      if (!startDate || (startDate && endDate)) {
        startDate = dateObj;
        endDate = null;
      } else {
        if (dateObj.getTime() < startDate.getTime()) {
          endDate = startDate;
          startDate = dateObj;
        } else {
          endDate = dateObj;
        }
      }

      inputFrom.value = startDate ? formatDate(startDate) : '';
      inputTo.value   = endDate ? formatDate(endDate) : '';
      updateDisplayText();

      if (startDate) currentMonth = new Date(startDate.getFullYear(), startDate.getMonth(), 1);
      else if (endDate) currentMonth = new Date(endDate.getFullYear(), endDate.getMonth(), 1);

      renderCalendar();
    }

    function applyRange(range) {
      var now = new Date();
      var from = null, to = null;

      if (range === 'all') {
        // ✅ 真正 All：清空 from/to + 写 date_all=1
        startDate = null;
        endDate = null;
        inputFrom.value = '';
        inputTo.value = '';
        setAll(true);
        updateDisplayText();
        currentMonth = new Date(today.getFullYear(), today.getMonth(), 1);
        renderCalendar();
        return;
      }

      setAll(false);

      if (range === 'today') {
        from = to = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      } else if (range === 'yesterday') {
        var y = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
        from = to = y;
      } else if (range === 'this_week') {
        var mon = getMonday(now);
        var sun = new Date(mon.getFullYear(), mon.getMonth(), mon.getDate() + 6);
        from = mon; to = sun;
      } else if (range === 'last_week') {
        var thisMon = getMonday(now);
        var lastMon = new Date(thisMon.getFullYear(), thisMon.getMonth(), thisMon.getDate() - 7);
        var lastSun = new Date(lastMon.getFullYear(), lastMon.getMonth(), lastMon.getDate() + 6);
        from = lastMon; to = lastSun;
      } else if (range === 'this_month') {
        from = new Date(now.getFullYear(), now.getMonth(), 1);
        to   = new Date(now.getFullYear(), now.getMonth() + 1, 0);
      } else if (range === 'last_month') {
        from = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        to   = new Date(now.getFullYear(), now.getMonth(), 0);
      } else if (range === 'this_year') {
        from = new Date(now.getFullYear(), 0, 1);
        to   = new Date(now.getFullYear(), 11, 31);
      } else if (range === 'last_year') {
        var yLast = now.getFullYear() - 1;
        from = new Date(yLast, 0, 1);
        to   = new Date(yLast, 11, 31);
      }

      startDate = from;
      endDate = to;

      inputFrom.value = from ? formatDate(from) : '';
      inputTo.value   = to ? formatDate(to) : '';
      updateDisplayText();

      if (from) currentMonth = new Date(from.getFullYear(), from.getMonth(), 1);
      else currentMonth = new Date(today.getFullYear(), today.getMonth(), 1);

      renderCalendar();
    }

    // ✅ 防止点日历内部触发 document click 关掉/跳走
    container.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
    });

    // quick
    container.querySelectorAll('.drp-quick-item').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        applyRange(btn.getAttribute('data-range'));
      });
    });

    // nav
    container.querySelectorAll('.drp-nav').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var dir = parseInt(btn.getAttribute('data-dir'), 10) || 0;
        currentMonth.setMonth(currentMonth.getMonth() + dir);
        renderCalendar();
      });
    });

    // open/close
    display.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      container.style.display = (container.style.display === 'block') ? 'none' : 'block';
    });

    document.addEventListener('click', function (e) {
      if (!root.contains(e.target)) container.style.display = 'none';
    });

    // init normalize
    if (startDate && endDate && endDate.getTime() < startDate.getTime()) {
      var tmp = startDate; startDate = endDate; endDate = tmp;
      inputFrom.value = formatDate(startDate);
      inputTo.value   = formatDate(endDate);
    }

    // 如果后端给了 all=1，就清空日期显示 All
    if (isAll) {
      startDate = null;
      endDate = null;
      inputFrom.value = '';
      inputTo.value = '';
      setAll(true);
    }

    updateDisplayText();
    renderCalendar();
  });
});
