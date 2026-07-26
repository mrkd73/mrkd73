/* After login: hide extra Rocket.Chat surfaces; keep chat basics */
(function () {
  if (window.__tgSlimUi) return;
  window.__tgSlimUi = true;

  var HIDE_SELECTORS = [
    'a[href="/admin/apps"]',
    'a[href="/marketplace"]',
    'a[href*="omnichannel"]',
    'a[href="/live"]',
    'a[href="/directory/marketplace"]',
    '[data-qa="sidebar-item-marketplace"]',
    '[data-qa*="omnichannel"]',
  ];

  function slim() {
    HIDE_SELECTORS.forEach(function (sel) {
      document.querySelectorAll(sel).forEach(function (el) {
        el.style.display = 'none';
      });
    });
  }

  slim();
  var t = null;
  var mo = new MutationObserver(function () {
    if (t) return;
    t = setTimeout(function () {
      t = null;
      slim();
    }, 400);
  });
  mo.observe(document.documentElement, { childList: true, subtree: true });
})();
