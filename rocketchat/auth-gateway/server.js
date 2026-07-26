/**
 * Phone OTP auth API for Rocket.Chat (local-friendly).
 * Does NOT proxy Meteor — Rocket.Chat stays on :3000, this API on :3001.
 */
const path = require('path');
const express = require('express');
const { MongoClient } = require('mongodb');
const { normalizePhone, displayPhone } = require('./lib/phone');
const { createRcClient } = require('./lib/rc');

const PORT = Number(process.env.PORT || 3001);
const RC_URL = process.env.RC_URL || process.env.RC_TARGET || 'http://127.0.0.1:3000';
const MONGO_URL =
  process.env.MONGO_URL ||
  'mongodb://127.0.0.1:27017/rocketchat?replicaSet=rs0&directConnection=true';
const TEST_OTP = process.env.TEST_OTP || '1234';
const RC_ADMIN_USER = process.env.RC_ADMIN_USER || 'mrkd';
const RC_ADMIN_PASS = process.env.RC_ADMIN_PASS || 'Admin123!';

async function main() {
  const mongo = new MongoClient(MONGO_URL);
  await mongo.connect();
  const db = mongo.db('rocketchat');
  const phones = db.collection('tg_phone_users');
  await phones.createIndex({ phone: 1 }, { unique: true });
  await phones.createIndex({ userId: 1 }, { unique: true });

  const rc = createRcClient({
    rcUrl: RC_URL,
    adminUser: RC_ADMIN_USER,
    adminPass: RC_ADMIN_PASS,
    db,
  });

  const app = express();
  app.use((req, res, next) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
    res.setHeader('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
    if (req.method === 'OPTIONS') return res.sendStatus(204);
    next();
  });
  app.use(express.json({ limit: '8mb' }));
  app.use('/tg-auth', express.static(path.join(__dirname, 'public')));

  app.get('/tg-auth/health', (_req, res) => {
    res.json({ ok: true, testOtp: true, rcUrl: RC_URL });
  });

  function ok(res, payload) {
    res.json({ ok: true, ...payload });
  }
  function fail(res, status, message, code) {
    res.status(status).json({ ok: false, error: message, code: code || 'error' });
  }

  app.post('/tg-auth/send-code', async (req, res) => {
    try {
      const phone = normalizePhone(req.body.phone);
      ok(res, {
        phone,
        displayPhone: displayPhone(phone),
        message: 'کد تایید ارسال شد (حالت تست: 1234)',
        testMode: true,
      });
    } catch (e) {
      fail(res, 400, e.message, e.code);
    }
  });

  app.post('/tg-auth/verify-code', async (req, res) => {
    try {
      const phone = normalizePhone(req.body.phone);
      const code = String(req.body.code || '').trim();
      if (code !== TEST_OTP) return fail(res, 400, 'کد تایید نادرست است', 'invalid_code');

      const link = await phones.findOne({ phone });
      if (link) {
        const user = await rc.findUserById(link.userId);
        if (!user || user.active === false) {
          return fail(res, 403, 'حساب کاربری غیرفعال است', 'inactive');
        }
        return ok(res, {
          status: 'existing',
          phone,
          displayPhone: displayPhone(phone),
          username: user.username,
          name: user.name || '',
          methods: ['otp', 'password'],
        });
      }
      return ok(res, { status: 'new', phone, displayPhone: displayPhone(phone) });
    } catch (e) {
      fail(res, 400, e.message, e.code);
    }
  });

  app.post('/tg-auth/login', async (req, res) => {
    try {
      const phone = normalizePhone(req.body.phone);
      const code = req.body.code != null ? String(req.body.code).trim() : null;
      const password = req.body.password != null ? String(req.body.password) : null;
      const link = await phones.findOne({ phone });
      if (!link) return fail(res, 404, 'حسابی با این شماره یافت نشد', 'not_found');
      const user = await rc.findUserById(link.userId);
      if (!user) return fail(res, 404, 'کاربر یافت نشد', 'not_found');

      if (code != null && code !== '') {
        if (code !== TEST_OTP) return fail(res, 400, 'کد تایید نادرست است', 'invalid_code');
        const tokens = await rc.issueResumeToken(user._id);
        return ok(res, {
          ...tokens,
          username: user.username,
          name: user.name || '',
          method: 'otp',
        });
      }
      if (!password) return fail(res, 400, 'رمز عبور یا کد تایید لازم است', 'missing_secret');
      const tokens = await rc.loginWithPassword(user.username, password);
      return ok(res, {
        authToken: tokens.authToken,
        userId: tokens.userId,
        username: user.username,
        name: user.name || '',
        method: 'password',
      });
    } catch (e) {
      fail(res, 400, e.message, e.code || 'login_failed');
    }
  });

  app.post('/tg-auth/check-username', async (req, res) => {
    try {
      const username = String(req.body.username || '')
        .trim()
        .replace(/^@/, '')
        .toLowerCase();
      if (!/^[a-z][a-z0-9_.]{2,31}$/.test(username)) {
        return fail(res, 400, 'یوزرنیم معتبر نیست (حروف انگلیسی، ۳ تا ۳۲ کاراکتر)', 'invalid_username');
      }
      const available = await rc.checkUsernameAvailable(username);
      if (!available) return fail(res, 409, 'این یوزرنیم گرفته شده', 'taken');
      ok(res, { username, available: true });
    } catch (e) {
      fail(res, 400, e.message, e.code);
    }
  });

  app.post('/tg-auth/register', async (req, res) => {
    try {
      const phone = normalizePhone(req.body.phone);
      const code = String(req.body.code || '').trim();
      if (code !== TEST_OTP) return fail(res, 400, 'کد تایید نادرست است', 'invalid_code');
      if (await phones.findOne({ phone })) {
        return fail(res, 409, 'این شماره قبلاً ثبت شده', 'exists');
      }

      const firstName = String(req.body.firstName || '').trim();
      const lastName = String(req.body.lastName || '').trim();
      const username = String(req.body.username || '')
        .trim()
        .replace(/^@/, '')
        .toLowerCase();
      const password = String(req.body.password || '');
      const avatarBase64 = req.body.avatarBase64
        ? String(req.body.avatarBase64).replace(/^data:[^;]+;base64,/, '')
        : null;
      const avatarType = String(req.body.avatarType || 'image/jpeg');

      if (firstName.length < 1) return fail(res, 400, 'نام لازم است', 'missing_name');
      if (lastName.length < 1) return fail(res, 400, 'نام خانوادگی لازم است', 'missing_last_name');
      if (!/^[a-z][a-z0-9_.]{2,31}$/.test(username)) {
        return fail(res, 400, 'یوزرنیم معتبر نیست', 'invalid_username');
      }
      if (password.length < 6) return fail(res, 400, 'رمز عبور حداقل ۶ کاراکتر باشد', 'weak_password');
      if (!(await rc.checkUsernameAvailable(username))) {
        return fail(res, 409, 'این یوزرنیم گرفته شده', 'taken');
      }

      const fullName = `${firstName} ${lastName}`.trim();
      const user = await rc.createUser({
        name: fullName,
        email: `${phone}@phone.local`,
        username,
        password,
        customFields: { phone, firstName, lastName },
      });

      try {
        await rc.updateUser(user._id, {
          name: fullName,
          customFields: { phone, firstName, lastName },
        });
      } catch (_) {}

      if (avatarBase64) {
        try {
          await rc.setAvatar(user._id, avatarBase64, avatarType);
        } catch (_) {}
      }

      await phones.insertOne({
        phone,
        userId: user._id,
        username,
        firstName,
        lastName,
        name: fullName,
        createdAt: new Date(),
        updatedAt: new Date(),
      });

      await db.collection('users').updateOne(
        { _id: user._id },
        {
          $set: {
            name: fullName,
            'customFields.phone': phone,
            'customFields.firstName': firstName,
            'customFields.lastName': lastName,
            'services.email2fa.enabled': false,
          },
        }
      );

      const tokens = await rc.loginWithPassword(username, password);
      ok(res, {
        authToken: tokens.authToken,
        userId: tokens.userId,
        username,
        name: fullName,
        phone,
        method: 'register',
      });
    } catch (e) {
      fail(res, 400, e.message, e.code || 'register_failed');
    }
  });

  app.listen(PORT, '0.0.0.0', () => {
    console.log(`[tg-auth] API on :${PORT}  (Rocket.Chat at ${RC_URL})`);
  });
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
