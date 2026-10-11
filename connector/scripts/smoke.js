// Usage: CONNECTOR_TOKEN=... node scripts/smoke.js [count] [--keep]
// Starts N channels in parallel and waits until each one reaches a QR (or
// connected) state, then prints metrics. Without --keep the channels are
// removed (credentials deleted) afterwards. Needs no phone for the QR stage.
import process from 'node:process';

const base = process.env.CONNECTOR_URL ?? 'http://127.0.0.1:3100';
const token = process.env.CONNECTOR_TOKEN;
const count = Number.parseInt(process.argv[2] ?? '3', 10);
const keep = process.argv.includes('--keep');
const timeoutMs = 60_000;

if (!token) {
  console.error('CONNECTOR_TOKEN is required.');
  process.exit(1);
}

async function api(method, path) {
  const response = await fetch(`${base}${path}`, { method, headers: { Authorization: `Bearer ${token}` } });
  const body = await response.json();
  if (!response.ok) throw new Error(`${method} ${path} -> ${response.status} ${body.error?.code ?? ''}`);
  return body;
}

const ids = Array.from({ length: count }, (_, i) => `smoke_${String.fromCharCode(97 + i)}`);
console.log(`Starting ${ids.length} channels: ${ids.join(', ')}`);

await Promise.all(ids.map((id) => api('POST', `/channels/${id}/start`)));

const deadline = Date.now() + timeoutMs;
const ready = new Set();
while (ready.size < ids.length && Date.now() < deadline) {
  for (const id of ids) {
    const { state, qr } = await api('GET', `/channels/${id}/qr`);
    if (qr || state === 'connected') ready.add(id);
  }
  await new Promise((resolve) => setTimeout(resolve, 1000));
}

const distinct = new Set();
for (const id of ids) {
  const { state, qr } = await api('GET', `/channels/${id}/qr`);
  console.log(`${id}: state=${state} qr=${qr ? 'yes' : 'no'}`);
  if (qr) distinct.add(qr);
}
console.log(`Channels with QR: ${ready.size}/${ids.length}; distinct QR codes: ${distinct.size}`);
console.log('Metrics:', JSON.stringify(await api('GET', '/metrics')));

if (!keep) {
  for (const id of ids) await api('DELETE', `/channels/${id}?logout=1`);
  console.log('Smoke channels removed.');
}
process.exit(ready.size === ids.length && distinct.size === ids.length ? 0 : 3);
