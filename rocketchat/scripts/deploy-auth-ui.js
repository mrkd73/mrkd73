#!/usr/bin/env node
/**
 * Inlines phone-auth CSS/JS into Rocket.Chat settings (no proxy, works on local Ubuntu).
 */
const fs = require('fs');
const path = require('path');
const { MongoClient } = require('../auth-gateway/node_modules/mongodb');

const MONGO_URL =
  process.env.MONGO_URL ||
  'mongodb://127.0.0.1:27017/rocketchat?replicaSet=rs0&directConnection=true';
const AUTH_BASE = (process.env.TG_AUTH_BASE || 'http://127.0.0.1:3001').replace(/\/$/, '');

const publicDir = path.join(__dirname, '../auth-gateway/public');
const css = fs.readFileSync(path.join(publicDir, 'app.css'), 'utf8');
const appJs = fs.readFileSync(path.join(publicDir, 'app.js'), 'utf8');
const slimJs = fs.readFileSync(path.join(publicDir, 'logged-in.js'), 'utf8');

const LOADER_OUT = `
window.__TG_AUTH_BASE__ = ${JSON.stringify(AUTH_BASE)};
${appJs}
`.trim();

const LOADER_IN = slimJs.trim();

async function setSetting(db, id, value) {
  const r = await db.collection('rocketchat_settings').updateOne(
    { _id: id },
    { $set: { value, _updatedAt: new Date() } }
  );
  console.log(id, 'matched', r.matchedCount, 'modified', r.modifiedCount, 'bytes', String(value).length);
}

async function main() {
  const client = new MongoClient(MONGO_URL);
  await client.connect();
  const db = client.db('rocketchat');

  await setSetting(db, 'theme-custom-css', css);
  await setSetting(db, 'Custom_Script_Logged_Out', LOADER_OUT);
  await setSetting(db, 'Custom_Script_Logged_In', LOADER_IN);
  await setSetting(db, 'Site_Name', 'Telegram');
  await setSetting(db, 'Layout_Login_Terms', '');
  await setSetting(db, 'Accounts_RegistrationForm', 'Disabled');
  await setSetting(db, 'Accounts_ShowFormLogin', true);

  await client.close();
  console.log('Auth UI deployed. AUTH_BASE =', AUTH_BASE);
  console.log('اگر UI نیامد یک‌بار: sudo docker restart rocketchat-app');
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
