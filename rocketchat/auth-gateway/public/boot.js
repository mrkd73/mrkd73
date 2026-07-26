/* Always boot phone-auth UI on logged-out pages (does not wait for RC custom-script events) */
(function () {
  if (window.__tgBoot) return;
  window.__tgBoot = true;

  function load(src, isCss) {
    if (isCss) {
      if (document.querySelector('link[data-tg-auth-css]')) return;
      var l = document.createElement('link');
      l.rel = 'stylesheet';
      l.href = src;
      l.setAttribute('data-tg-auth-css', '1');
      (document.head || document.documentElement).appendChild(l);
      return;
    }
    if (document.querySelector('script[data-tg-auth-js="' + src + '"]')) return;
    var s = document.createElement('script');
    s.src = src;
    s.defer = true;
    s.setAttribute('data-tg-auth-js', src);
    (document.head || document.documentElement).appendChild(s);
  }

  load('/tg-auth/app.css', true);
  load('/tg-auth/app.js?v=3', false);
})();
