const fetch = require('node-fetch');
const crypto = require('crypto');

function createRcClient({ rcUrl, adminUser, adminPass, db }) {
  let cached = null;
  let cachedAt = 0;

  async function adminAuth(force = false) {
    if (!force && cached && Date.now() - cachedAt < 10 * 60 * 1000) return cached;
    const res = await fetch(`${rcUrl}/api/v1/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ user: adminUser, password: adminPass }),
    });
    const data = await res.json();
    if (data.status !== 'success') {
      throw new Error(data.message || data.error || 'admin login failed');
    }
    cached = {
      authToken: data.data.authToken,
      userId: data.data.userId,
    };
    cachedAt = Date.now();
    return cached;
  }

  function adminHeaders(auth) {
    return {
      'Content-Type': 'application/json',
      'X-Auth-Token': auth.authToken,
      'X-User-Id': auth.userId,
    };
  }

  async function adminFetch(path, options = {}, retry = true) {
    const auth = await adminAuth();
    const res = await fetch(`${rcUrl}${path}`, {
      ...options,
      headers: {
        ...adminHeaders(auth),
        ...(options.headers || {}),
      },
    });
    const data = await res.json().catch(() => ({}));
    if (retry && (res.status === 401 || data.status === 'error' && /unauthorized/i.test(data.message || ''))) {
      await adminAuth(true);
      return adminFetch(path, options, false);
    }
    return { res, data };
  }

  async function createUser({ name, email, username, password, customFields }) {
    const body = {
      name,
      email,
      username,
      password,
      joinDefaultChannels: true,
      verified: true,
      requirePasswordChange: false,
      sendWelcomeEmail: false,
    };
    if (customFields) body.customFields = customFields;
    const { data } = await adminFetch('/api/v1/users.create', {
      method: 'POST',
      body: JSON.stringify(body),
    });
    if (!data.success) {
      const err = new Error(data.error || 'users.create failed');
      err.details = data;
      throw err;
    }
    return data.user;
  }

  async function updateUser(userId, fields) {
    const { data } = await adminFetch('/api/v1/users.update', {
      method: 'POST',
      body: JSON.stringify({ userId, data: fields }),
    });
    if (!data.success) {
      const err = new Error(data.error || 'users.update failed');
      err.details = data;
      throw err;
    }
    return data.user;
  }

  async function setAvatar(userId, imageBase64, contentType = 'image/png') {
    const { data } = await adminFetch('/api/v1/users.setAvatar', {
      method: 'POST',
      body: JSON.stringify({
        userId,
        avatarUrl: `data:${contentType};base64,${imageBase64}`,
      }),
    });
    // Some RC versions want image differently; ignore soft failures
    return data;
  }

  async function loginWithPassword(usernameOrEmail, password) {
    const res = await fetch(`${rcUrl}/api/v1/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ user: usernameOrEmail, password }),
    });
    const data = await res.json();
    if (data.status !== 'success') {
      const err = new Error(data.message || data.error || 'login failed');
      err.code = data.errorType || 'login_failed';
      throw err;
    }
    return {
      authToken: data.data.authToken,
      userId: data.data.userId,
      me: data.data.me,
    };
  }

  async function checkUsernameAvailable(username) {
    const { data } = await adminFetch(
      `/api/v1/users.checkUsernameAvailability?username=${encodeURIComponent(username)}`
    );
    return !!data.result;
  }

  function makeLoginToken() {
    // Meteor Random.secret()-like token
    return crypto.randomBytes(30).toString('base64').replace(/[+/=]/g, '');
  }

  function hashLoginToken(token) {
    return crypto.createHash('sha256').update(token).digest('base64');
  }

  async function issueResumeToken(userId) {
    const authToken = makeLoginToken();
    const hashedToken = hashLoginToken(authToken);
    await db.collection('users').updateOne(
      { _id: userId },
      {
        $push: {
          'services.resume.loginTokens': {
            when: new Date(),
            hashedToken,
          },
        },
        $set: {
          'services.email2fa.enabled': false,
        },
      }
    );
    return { authToken, userId };
  }

  async function findUserById(userId) {
    return db.collection('users').findOne({ _id: userId });
  }

  async function findUserByUsername(username) {
    return db.collection('users').findOne({ username });
  }

  return {
    adminAuth,
    createUser,
    updateUser,
    setAvatar,
    loginWithPassword,
    checkUsernameAvailable,
    issueResumeToken,
    findUserById,
    findUserByUsername,
  };
}

module.exports = { createRcClient };
