(function () {
  'use strict';

  /* ---------- click to reveal a redacted value ---------- */
  function reveal(el) {
    if (el.classList.contains('revealed')) return;
    var raw = el.getAttribute('data-secret') || '';
    var text;
    try {
      // atob gives bytes; decode them as UTF-8.
      var bin = atob(raw);
      var bytes = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
      text = new TextDecoder('utf-8').decode(bytes);
    } catch (e) {
      text = '(could not decode)';
    }
    el.textContent = text;
    el.classList.add('revealed');
    el.removeAttribute('role');
    el.removeAttribute('tabindex');
    el.title = 'Click outside to leave revealed; reload the page to hide again.';
  }

  document.addEventListener('click', function (e) {
    var s = e.target.closest ? e.target.closest('.secret') : null;
    if (s) reveal(s);
  });
  document.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('secret')) {
      e.preventDefault();
      reveal(e.target);
    }
  });

  /* ---------- live filter ---------- */
  var search = document.getElementById('search');
  var noResults = document.getElementById('no-results');

  function applyFilter(q) {
    q = q.trim().toLowerCase();
    var sections = document.querySelectorAll('.section');
    var anyVisible = false;

    sections.forEach(function (section) {
      if (!q) {
        section.classList.remove('hidden-by-filter');
        section.querySelectorAll('.hidden-by-filter').forEach(function (n) {
          n.classList.remove('hidden-by-filter');
        });
        anyVisible = true;
        return;
      }

      var sectionHit = false;

      // Filter table rows.
      section.querySelectorAll('table.data tbody tr').forEach(function (tr) {
        var hit = tr.textContent.toLowerCase().indexOf(q) !== -1;
        // Separator rows follow whatever is around them.
        if (tr.classList.contains('sep')) { hit = false; }
        tr.classList.toggle('hidden-by-filter', !hit);
        if (hit) sectionHit = true;
      });

      // Filter key/value rows.
      section.querySelectorAll('.kv > .kv-row').forEach(function (row) {
        var hit = row.textContent.toLowerCase().indexOf(q) !== -1;
        row.classList.toggle('hidden-by-filter', !hit);
        if (hit) sectionHit = true;
      });

      // Filter count cards on the overview.
      section.querySelectorAll('.count-card').forEach(function (card) {
        var hit = card.textContent.toLowerCase().indexOf(q) !== -1;
        card.classList.toggle('hidden-by-filter', !hit);
        if (hit) sectionHit = true;
      });

      // A section with no filterable widgets still matches on its own text.
      if (!sectionHit && section.textContent.toLowerCase().indexOf(q) !== -1) {
        var hasWidgets = section.querySelector('table.data tbody tr, .kv > .kv-row');
        if (!hasWidgets) sectionHit = true;
      }

      section.classList.toggle('hidden-by-filter', !sectionHit);
      if (sectionHit) anyVisible = true;
    });

    // Grey out nav entries whose section is hidden.
    document.querySelectorAll('.sidenav a[data-nav]').forEach(function (a) {
      var target = document.getElementById(a.getAttribute('data-nav'));
      a.style.opacity = (target && target.classList.contains('hidden-by-filter')) ? '.35' : '';
    });

    if (noResults) noResults.hidden = !(q && !anyVisible);
  }

  if (search) {
    var t;
    search.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () { applyFilter(search.value); }, 120);
    });
    search.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { search.value = ''; applyFilter(''); }
    });
  }

  // "/" focuses the filter box.
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && document.activeElement !== search &&
        ['INPUT', 'TEXTAREA', 'SELECT'].indexOf(document.activeElement.tagName) === -1) {
      e.preventDefault();
      if (search) search.focus();
    }
  });

  /* ---------- expand every <details> ---------- */
  var expand = document.getElementById('expand-all');
  if (expand) {
    expand.addEventListener('click', function () {
      var all = document.querySelectorAll('details');
      var opening = expand.textContent.indexOf('Expand') !== -1;
      all.forEach(function (d) { d.open = opening; });
      expand.textContent = opening ? 'Collapse all' : 'Expand all';
    });
  }

  /* ---------- scroll spy ---------- */
  var links = {};
  document.querySelectorAll('.sidenav a[data-nav]').forEach(function (a) {
    links[a.getAttribute('data-nav')] = a;
  });

  if ('IntersectionObserver' in window && Object.keys(links).length) {
    var visible = new Set();
    var order = Object.keys(links);

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) visible.add(en.target.id);
        else visible.delete(en.target.id);
      });
      var first = order.find(function (id) { return visible.has(id); });
      Object.keys(links).forEach(function (id) {
        links[id].classList.toggle('active', id === first);
      });
    }, { rootMargin: '-80px 0px -70% 0px' });

    document.querySelectorAll('.section').forEach(function (s) { io.observe(s); });
  }
})();
