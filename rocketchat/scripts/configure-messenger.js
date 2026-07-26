#!/usr/bin/env node
/** Apply messenger-focused Rocket.Chat settings via MongoDB */
const { MongoClient } = require('../auth-gateway/node_modules/mongodb');

const MONGO_URL =
  process.env.MONGO_URL ||
  'mongodb://127.0.0.1:27017/rocketchat?replicaSet=rs0&directConnection=true';

const SETTINGS = {
  Site_Name: 'Telegram',
  Layout_Home_Title: 'Telegram',
  Accounts_RegistrationForm: 'Disabled',
  Accounts_TwoFactorAuthentication_By_Email_Enabled: false,
  Accounts_TwoFactorAuthentication_Enabled: false,
  Accounts_TwoFactorAuthentication_Enforce_Password_Fallback: false,
  Accounts_EmailVerification: false,
  Accounts_ManuallyApproveNewUsers: false,
  Accounts_AllowUserAvatarChange: true,
  Accounts_AllowUserProfileChange: true,
  Accounts_AllowUsernameChange: true,
  Accounts_AllowPasswordChange: true,
  Livechat_enabled: false,
  Discussion_enabled: false,
  Message_AudioRecorderEnabled: true,
  FileUpload_Enabled: true,
  Accounts_CustomFields: JSON.stringify({
    phone: { type: 'text', required: false, public: true },
    firstName: { type: 'text', required: false, public: true },
    lastName: { type: 'text', required: false, public: true },
  }),
};

async function main() {
  const client = new MongoClient(MONGO_URL);
  await client.connect();
  const db = client.db('rocketchat');
  for (const [id, value] of Object.entries(SETTINGS)) {
    const r = await db.collection('rocketchat_settings').updateOne(
      { _id: id },
      { $set: { value, _updatedAt: new Date() } }
    );
    console.log(id, r.matchedCount ? 'ok' : 'MISSING');
  }
  await db.collection('users').updateMany({}, { $set: { 'services.email2fa.enabled': false } });
  await client.close();
  console.log('Messenger settings applied');
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
