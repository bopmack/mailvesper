/* MailVesper API reference: base URL of this installation, copy buttons, language tabs, the active section in the nav. */
(function () {
  'use strict';
  // The examples are written for mail.example.com; each copy shows its own address.
  var WRITTEN = 'https://mail.example.com/api/v1';
  var base = location.protocol === 'https:' || location.protocol === 'http:' ? location.origin + '/api/v1' : WRITTEN;
  if (base !== WRITTEN) {
    document.querySelectorAll('pre, .base').forEach(function (el) {
      var walk = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
      for (var n = walk.nextNode(); n; n = walk.nextNode()) n.nodeValue = n.nodeValue.split(WRITTEN).join(base);
    });
  }

  // Each code block: a copy button, and tabs when it holds several languages (the choice is remembered on the page).
  document.querySelectorAll('.code').forEach(function (box) {
    var pres = box.querySelectorAll('pre');
    var bar = document.createElement('div');
    bar.className = 'bar';
    var label = document.createElement('span');
    label.className = 'lbl';
    label.textContent = box.dataset.label || '';
    bar.appendChild(label);
    if (pres.length > 1) {
      pres.forEach(function (pre, i) {
        var t = document.createElement('button');
        t.className = 'tab' + (i === 0 ? ' on' : '');
        t.textContent = pre.dataset.lang;
        t.dataset.lang = pre.dataset.lang;
        t.addEventListener('click', function () { pickLang(pre.dataset.lang); });
        bar.appendChild(t);
        if (i) pre.hidden = true;
      });
    }
    var copy = document.createElement('button');
    copy.className = 'copy';
    copy.textContent = 'Copy';
    copy.addEventListener('click', function () {
      var shown = Array.prototype.find.call(pres, function (p) { return !p.hidden; });
      navigator.clipboard.writeText(shown.textContent).then(function () {
        copy.textContent = 'Copied';
        setTimeout(function () { copy.textContent = 'Copy'; }, 1400);
      });
    });
    bar.appendChild(copy);
    box.insertBefore(bar, box.firstChild);
  });
  function pickLang(lang) {
    document.querySelectorAll('.code').forEach(function (box) {
      var has = box.querySelector('pre[data-lang="' + lang + '"]');
      if (!has) return;
      box.querySelectorAll('pre').forEach(function (p) { p.hidden = p !== has; });
      box.querySelectorAll('.tab').forEach(function (t) { t.classList.toggle('on', t.dataset.lang === lang); });
    });
  }

  // Highlight the section being read.
  var links = {};
  document.querySelectorAll('nav.side a[href^="#"]').forEach(function (a) { links[a.getAttribute('href').slice(1)] = a; });
  var current = null;
  var spy = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting || !links[e.target.id]) return;
      if (current) current.classList.remove('on');
      current = links[e.target.id];
      current.classList.add('on');
    });
  }, { rootMargin: '-70px 0px -70% 0px' });
  document.querySelectorAll('main section[id]').forEach(function (s) { spy.observe(s); });

  // Small screens: the nav opens from the top bar and closes on a pick.
  var nav = document.querySelector('nav.side');
  document.getElementById('navtoggle').addEventListener('click', function () { nav.classList.toggle('open'); });
  nav.addEventListener('click', function (e) { if (e.target.closest('a')) nav.classList.remove('open'); });
})();
