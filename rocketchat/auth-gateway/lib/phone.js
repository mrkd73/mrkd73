function normalizePhone(input) {
  let p = String(input || '').trim().replace(/[\s\-()]/g, '');
  if (p.startsWith('+')) p = p.slice(1);
  if (p.startsWith('00')) p = p.slice(2);
  if (p.startsWith('0') && p.length === 11) p = '98' + p.slice(1);
  if (p.length === 10 && p.startsWith('9')) p = '98' + p;
  if (!/^\d{10,15}$/.test(p)) {
    const err = new Error('شماره موبایل معتبر نیست');
    err.code = 'invalid_phone';
    throw err;
  }
  return p;
}

function displayPhone(phone) {
  if (phone.startsWith('98') && phone.length === 12) {
    return '0' + phone.slice(2);
  }
  return '+' + phone;
}

module.exports = { normalizePhone, displayPhone };
