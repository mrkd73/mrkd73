(function () {
  if (window.__tgPhoneAuth) return;
  window.__tgPhoneAuth = true;

  // Auth API base. Empty string = same origin (/tg-auth via combine-proxy).
  // Override with window.__TG_AUTH_BASE__ (deploy-auth-ui sets this).
  var API_BASE = (typeof window.__TG_AUTH_BASE__ === 'string'
    ? window.__TG_AUTH_BASE__
    : 'http://127.0.0.1:3001').replace(/\/$/, '');

  var state = {
    phone: '',
    displayPhone: '',
    code: '',
    status: '', // new | existing
    firstName: '',
    lastName: '',
    username: '',
    password: '',
    avatarBase64: null,
    avatarType: 'image/jpeg',
    step: 'phone',
  };

  var LOGO =
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">' +
    '<circle cx="50" cy="50" r="50" fill="#2AABEE"/>' +
    '<path fill="#fff" d="M22 49.5c18.5-8.1 43.7-18.2 55.3-22.7 5.3-2 11.7-.8 10.3 6.3-2.3 12.4-8.5 42.5-10.2 50.2-1 4.5-5.4 5.1-8.5 3.3-7.2-4.1-19.3-12.4-24.2-16.1-1.4-1.1-0.2-3.4 1.5-4.7 6.7-5.1 19.7-13.8 23.7-16.7 1.9-1.4.3-3.6-1.7-2.5-11.5 6.4-28.2 15.3-31.5 17.1-3.2 1.7-6.5.4-8.9-1.1-5.5-3.4-10.7-6.3-5.8-13.1z"/>' +
    '</svg>';

  function api(path, body) {
    return fetch(API_BASE + '/tg-auth/' + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {}),
    }).then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok || data.ok === false) {
          var err = new Error(data.error || 'خطا');
          err.code = data.code;
          throw err;
        }
        return data;
      });
    });
  }

  function finishLogin(data) {
    var expires = new Date(Date.now() + 90 * 24 * 60 * 60 * 1000).toISOString();
    try {
      localStorage.setItem('Meteor.loginToken', data.authToken);
      localStorage.setItem('Meteor.loginTokenExpires', expires);
      localStorage.setItem('Meteor.userId', data.userId);
      localStorage.setItem('userIdOrNull', JSON.stringify(data.userId));
    } catch (e) {}
    window.location.replace('/home');
  }

  function el(html) {
    var d = document.createElement('div');
    d.innerHTML = html.trim();
    return d.firstChild;
  }

  function setError(root, msg) {
    var box = root.querySelector('.tg-error');
    if (!box) return;
    if (msg) {
      box.textContent = msg;
      box.classList.add('show');
    } else {
      box.textContent = '';
      box.classList.remove('show');
    }
  }

  function card(title, sub, bodyHtml, footerHtml) {
    return (
      '<div class="tg-card">' +
      '<div class="tg-logo">' +
      LOGO +
      '</div>' +
      '<h1 class="tg-title">' +
      title +
      '</h1>' +
      '<p class="tg-sub">' +
      sub +
      '</p>' +
      '<div class="tg-error"></div>' +
      bodyHtml +
      (footerHtml || '') +
      '</div>'
    );
  }

  function render() {
    var root = document.getElementById('tg-auth-root');
    if (!root) return;
    var html = '';

    if (state.step === 'phone') {
      html = card(
        'تلگرام',
        'شماره موبایل خود را وارد کنید. کد تایید برایتان ارسال می‌شود.',
        '<div class="tg-field"><label>شماره موبایل</label>' +
          '<input id="tg-phone" type="tel" inputmode="tel" placeholder="0912xxxxxxx" value="' +
          (state.displayPhone || '') +
          '"/></div>' +
          '<button type="button" class="tg-btn" id="tg-next">ادامه</button>' +
          '<div class="tg-hint">حالت تست: کد همیشه 1234 است</div>'
      );
    } else if (state.step === 'code') {
      html = card(
        'کد تایید',
        'کد ارسال‌شده به ' + state.displayPhone + ' را وارد کنید.',
        '<div class="tg-field"><label>کد تایید</label>' +
          '<input id="tg-code" type="text" inputmode="numeric" maxlength="6" placeholder="1234"/></div>' +
          '<button type="button" class="tg-btn" id="tg-next">تایید</button>' +
          '<button type="button" class="tg-btn secondary" id="tg-back">تغییر شماره</button>'
      );
    } else if (state.step === 'existing-method') {
      html = card(
        'ورود',
        'سلام ' + (state.name || '') + ' — چطور وارد می‌شوید؟',
        '<div class="tg-methods">' +
          '<div class="tg-method" data-method="otp"><strong>ورود با کد تست</strong><span>کد 1234</span></div>' +
          '<div class="tg-method" data-method="password"><strong>ورود با رمز عبور</strong><span>رمزی که هنگام ثبت‌نام گذاشتید</span></div>' +
          '</div>' +
          '<button type="button" class="tg-btn secondary" id="tg-back">بازگشت</button>'
      );
    } else if (state.step === 'existing-password') {
      html = card(
        'ورود با رمز',
        state.displayPhone,
        '<div class="tg-field"><label>رمز عبور</label>' +
          '<input id="tg-password" type="password" placeholder="رمز عبور"/></div>' +
          '<button type="button" class="tg-btn" id="tg-next">ورود</button>' +
          '<button type="button" class="tg-btn secondary" id="tg-back">بازگشت</button>'
      );
    } else if (state.step === 'reg-name') {
      html = card(
        'نام شما',
        'نام و نام خانوادگی‌تان را وارد کنید.',
        '<div class="tg-field"><label>نام</label><input id="tg-first" type="text" value="' +
          state.firstName +
          '"/></div>' +
          '<div class="tg-field"><label>نام خانوادگی</label><input id="tg-last" type="text" value="' +
          state.lastName +
          '"/></div>' +
          '<button type="button" class="tg-btn" id="tg-next">ادامه</button>'
      );
    } else if (state.step === 'reg-photo') {
      html = card(
        'عکس پروفایل',
        'یک عکس انتخاب کنید (اختیاری).',
        '<div class="tg-avatar-wrap"><div class="tg-avatar-preview" id="tg-avatar-preview">بدون عکس</div>' +
          '<div class="tg-field" style="width:100%"><input id="tg-avatar" type="file" accept="image/*"/></div></div>' +
          '<button type="button" class="tg-btn" id="tg-next">ادامه</button>' +
          '<button type="button" class="tg-btn secondary" id="tg-skip">رد کردن</button>'
      );
    } else if (state.step === 'reg-username') {
      html = card(
        'یوزرنیم',
        'یک یوزرنیم یکتا مثل تلگرام انتخاب کنید.',
        '<div class="tg-field"><label>یوزرنیم</label>' +
          '<input id="tg-username" type="text" dir="ltr" placeholder="username" value="' +
          state.username +
          '"/></div>' +
          '<button type="button" class="tg-btn" id="tg-next">ادامه</button>'
      );
    } else if (state.step === 'reg-password') {
      html = card(
        'رمز عبور',
        'رمزی بگذارید تا بعداً هم با رمز وارد شوید هم با کد.',
        '<div class="tg-field"><label>رمز عبور</label><input id="tg-password" type="password" placeholder="حداقل ۶ کاراکتر"/></div>' +
          '<div class="tg-field"><label>تکرار رمز</label><input id="tg-password2" type="password"/></div>' +
          '<button type="button" class="tg-btn" id="tg-next">شروع پیام‌رسانی</button>'
      );
    } else if (state.step === 'busy') {
      html = card('لطفاً صبر کنید', 'در حال ورود...', '<div class="tg-hint">چند لحظه...</div>');
    }

    root.innerHTML = html;
    bind(root);
  }

  function bind(root) {
    var next = root.querySelector('#tg-next');
    var back = root.querySelector('#tg-back');
    var skip = root.querySelector('#tg-skip');

    if (back) {
      back.onclick = function () {
        setError(root, '');
        if (state.step === 'code') state.step = 'phone';
        else if (state.step === 'existing-method') state.step = 'code';
        else if (state.step === 'existing-password') state.step = 'existing-method';
        render();
      };
    }

    if (skip) {
      skip.onclick = function () {
        state.avatarBase64 = null;
        state.step = 'reg-username';
        render();
      };
    }

    root.querySelectorAll('.tg-method').forEach(function (node) {
      node.onclick = function () {
        var method = node.getAttribute('data-method');
        if (method === 'otp') {
          state.step = 'busy';
          render();
          api('login', { phone: state.phone, code: state.code || '1234' })
            .then(finishLogin)
            .catch(function (e) {
              state.step = 'existing-method';
              render();
              setError(document.getElementById('tg-auth-root'), e.message);
            });
        } else {
          state.step = 'existing-password';
          render();
        }
      };
    });

    var file = root.querySelector('#tg-avatar');
    if (file) {
      file.onchange = function () {
        var f = file.files && file.files[0];
        if (!f) return;
        if (f.size > 5 * 1024 * 1024) {
          setError(root, 'حجم عکس حداکثر ۵ مگابایت');
          return;
        }
        var reader = new FileReader();
        reader.onload = function () {
          state.avatarBase64 = String(reader.result);
          state.avatarType = f.type || 'image/jpeg';
          var preview = root.querySelector('#tg-avatar-preview');
          preview.innerHTML = '<img alt="avatar" src="' + state.avatarBase64 + '"/>';
        };
        reader.readAsDataURL(f);
      };
    }

    if (!next) return;
    next.onclick = function () {
      setError(root, '');
      handleNext(root).catch(function (e) {
        setError(root, e.message || 'خطا');
      });
    };

    root.querySelectorAll('input').forEach(function (input) {
      input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          next.click();
        }
      });
    });
  }

  async function handleNext(root) {
    if (state.step === 'phone') {
      var phone = (root.querySelector('#tg-phone').value || '').trim();
      var sent = await api('send-code', { phone: phone });
      state.phone = sent.phone;
      state.displayPhone = sent.displayPhone;
      state.step = 'code';
      render();
      return;
    }

    if (state.step === 'code') {
      var code = (root.querySelector('#tg-code').value || '').trim();
      var verified = await api('verify-code', { phone: state.phone, code: code });
      state.code = code;
      state.status = verified.status;
      if (verified.status === 'existing') {
        state.name = verified.name || verified.username || '';
        state.step = 'existing-method';
      } else {
        state.step = 'reg-name';
      }
      render();
      return;
    }

    if (state.step === 'existing-password') {
      var password = root.querySelector('#tg-password').value || '';
      state.step = 'busy';
      render();
      try {
        var logged = await api('login', { phone: state.phone, password: password });
        finishLogin(logged);
      } catch (e) {
        state.step = 'existing-password';
        render();
        throw e;
      }
      return;
    }

    if (state.step === 'reg-name') {
      state.firstName = (root.querySelector('#tg-first').value || '').trim();
      state.lastName = (root.querySelector('#tg-last').value || '').trim();
      if (!state.firstName) throw new Error('نام لازم است');
      if (!state.lastName) throw new Error('نام خانوادگی لازم است');
      state.step = 'reg-photo';
      render();
      return;
    }

    if (state.step === 'reg-photo') {
      state.step = 'reg-username';
      render();
      return;
    }

    if (state.step === 'reg-username') {
      var username = (root.querySelector('#tg-username').value || '').trim();
      await api('check-username', { username: username });
      state.username = username.replace(/^@/, '').toLowerCase();
      state.step = 'reg-password';
      render();
      return;
    }

    if (state.step === 'reg-password') {
      var p1 = root.querySelector('#tg-password').value || '';
      var p2 = root.querySelector('#tg-password2').value || '';
      if (p1.length < 6) throw new Error('رمز عبور حداقل ۶ کاراکتر باشد');
      if (p1 !== p2) throw new Error('تکرار رمز مطابقت ندارد');
      state.password = p1;
      state.step = 'busy';
      render();
      try {
        var reg = await api('register', {
          phone: state.phone,
          code: state.code,
          firstName: state.firstName,
          lastName: state.lastName,
          username: state.username,
          password: state.password,
          avatarBase64: state.avatarBase64,
          avatarType: state.avatarType,
        });
        finishLogin(reg);
      } catch (e) {
        state.step = 'reg-password';
        render();
        throw e;
      }
    }
  }

  function isLoggedInUi() {
    // Sidebar / room list means we are inside the app
    return !!(
      document.querySelector('[data-qa-id="sidebar"]') ||
      document.querySelector('nav.sidebar') ||
      document.querySelector('#rocket-chat') ||
      document.querySelector('.main-content')
    );
  }

  function hasResumeToken() {
    try {
      return !!(localStorage.getItem('Meteor.loginToken') && localStorage.getItem('Meteor.userId'));
    } catch (e) {
      return false;
    }
  }

  function mount() {
    if (document.getElementById('tg-auth-root')) {
      // Remove overlay once the real logged-in shell appears
      if (isLoggedInUi() || (hasResumeToken() && !document.querySelector('input[name="usernameOrEmail"]'))) {
        var old = document.getElementById('tg-auth-root');
        if (old) old.remove();
        document.body.classList.remove('tg-auth-overlay-on');
      }
      return;
    }
    if (isLoggedInUi()) return;

    var path = location.pathname || '';
    var loginForm =
      !!document.querySelector('form.rcx-tile') ||
      !!document.querySelector('input[name="usernameOrEmail"]');

    // If we already have a session token, let Rocket.Chat resume — don't cover /home
    if (hasResumeToken() && !loginForm) return;

    var looksLoggedOut =
      loginForm ||
      path === '/' ||
      path === '/home' ||
      path.indexOf('/login') === 0 ||
      path.indexOf('/register') === 0;

    if (!looksLoggedOut) return;

    document.body.classList.add('tg-auth-overlay-on');
    var root = document.createElement('div');
    root.id = 'tg-auth-root';
    document.documentElement.appendChild(root);
    render();
  }

  // CSS is injected via Rocket.Chat theme-custom-css (deploy-auth-ui.js)

  var tries = 0;
  var timer = setInterval(function () {
    tries += 1;
    mount();
    // Keep watching a bit longer so overlay can be removed after resume login
    if (tries > 80) clearInterval(timer);
  }, 250);

  document.addEventListener('DOMContentLoaded', mount);
  mount();
})();
