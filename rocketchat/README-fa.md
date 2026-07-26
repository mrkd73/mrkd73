# پیام‌رسان سبک — نصب روی سیستم خودت (اوبونتو)

این نسخه **پروکسی ابر ندارد**. Rocket.Chat مثل همیشه روی پورت `3000` اجرا می‌شود و فقط یک API کوچک احراز هویت روی `3001` کنارش می‌آید.

## چه چیزی دارید؟

- ورود / ثبت‌نام با **شماره موبایل + کد**
- کد تست: **`1234`** (بعداً به پیامک وصل می‌شود)
- ثبت‌نام مرحله‌ای: نام → نام‌خانوادگی → عکس → یوزرنیم → رمز
- ذخیره در پنل ادمین Rocket.Chat (`name`, `username`, `customFields.phone/firstName/lastName`)
- ورود بعدی با **رمز** یا **کد 1234**
- ظاهر داخل چت فعلاً همان Rocket.Chat است (تلگرامی نشده)

## نصب سریع روی اوبونتو

از داخل پوشه `rocketchat`:

```bash
bash install-on-ubuntu.sh
bash scripts/start-stack.sh
```

بعد در مرورگر:

`http://localhost:3000`

## اگر ادمین را خودت ساختی

قبل از `start-stack.sh`:

```bash
export RC_ADMIN_USER='your-admin'
export RC_ADMIN_PASS='your-password'
```

## فقط API (اگر RC از قبل بالاست)

```bash
cd auth-gateway
npm install
PORT=3001 RC_URL=http://127.0.0.1:3000 RC_ADMIN_USER=... RC_ADMIN_PASS=... node server.js
# در ترمینال دیگر:
cd ..
TG_AUTH_BASE=http://127.0.0.1:3001 node scripts/deploy-auth-ui.js
```

## نکته تم قبلی

تم آبی قبلی فقط ظاهر فرم ایمیل/رمز را عوض می‌کرد و **ورود با موبایل نداشت**. مشکل اصلی همان بود. الان فلوی موبایل جایگزین شده است.
