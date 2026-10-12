// Usage: CONNECTOR_TOKEN=... node scripts/pair.js <channelId>
// Starts a channel and prints its QR in the terminal until it is connected.
import process from 'node:process';
import qrcode from 'qrcode-terminal';

const base = process.env.CONNECTOR_URL ?? 'http://127.0.0.1:3100';
const token = process.env.CONNECTOR_TOKEN;
const channelId = process.argv[2];

if (!token || !channelId) {
  console.error('Usage: CONNECTOR_TOKEN=... node scripts/pair.js <channelId>');
  process.exit(1);
}

async function api(method, path) {
  const response = await fetch(`${base}${path}`, { method, headers: { Authorization: `Bearer ${token}` } });
  const body = await response.json();
  if (!response.ok) throw new Error(`${response.status} ${body.error?.code ?? ''} ${body.error?.message ?? ''}`);
  return body;
}

await api('POST', `/channels/${channelId}/start`);
console.log(`Channel ${channelId} started. Scan the QR with WhatsApp > Linked devices.`);

let lastQr = null;
for (;;) {
  const { state, qr } = await api('GET', `/channels/${channelId}/qr`);
  if (state === 'connected') {
    const info = await api('GET', `/channels/${channelId}`);
    console.log(`Connected as ${info.me}.`);
    break;
  }
  if (['logged_out', 'qr_expired', 'failed', 'stopped'].includes(state)) {
    console.error(`Stopped with state: ${state}`);
    process.exit(2);
  }
  if (qr && qr !== lastQr) {
    lastQr = qr;
    qrcode.generate(qr, { small: true });
  }
  await new Promise((resolve) => setTimeout(resolve, 2000));
}
