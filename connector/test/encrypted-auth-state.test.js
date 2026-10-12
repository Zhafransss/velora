import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { test } from 'node:test';
import { initAuthCreds, proto } from '@whiskeysockets/baileys';
import { AuthStoreError, openEncryptedAuthState, rotateChannel } from '../src/auth/encrypted-auth-state.js';
import { MemoryAuthRepository } from '../src/auth/memory-repository.js';
import { SecretBox } from '../src/auth/secret-box.js';

const newKey = () => crypto.randomBytes(32).toString('base64');
const logs = () => {
  const entries = [];
  return { entries, warn: (...args) => entries.push(args) };
};
const open = (repo, box, channelId = 'chanA', logger = logs()) => openEncryptedAuthState({ repo, box, channelId, logger });

test('a new channel gets fresh credentials; saved credentials survive a restart with their Buffers intact', async () => {
  const repo = new MemoryAuthRepository();
  const box = new SecretBox(`k1:${newKey()}`);

  const first = await open(repo, box);
  assert.equal(await repo.getCreds('chanA'), null); // belum disimpan
  await first.saveCreds();

  const second = await open(repo, box);
  assert.ok(Buffer.isBuffer(second.state.creds.noiseKey.private));
  assert.deepEqual(second.state.creds.noiseKey.private, first.state.creds.noiseKey.private);
  assert.deepEqual(second.state.creds.signedIdentityKey.public, first.state.creds.signedIdentityKey.public);
  assert.equal(second.state.creds.registrationId, first.state.creds.registrationId);
});

test('stored credentials are ciphertext only: no field names, no key material', async () => {
  const repo = new MemoryAuthRepository();
  const box = new SecretBox(`k1:${newKey()}`);
  const { state, saveCreds } = await open(repo, box);
  await saveCreds();

  const stored = await repo.getCreds('chanA');
  const privateKeyB64 = state.creds.noiseKey.private.toString('base64');

  assert.ok(!stored.includes('noiseKey') && !stored.includes('signedIdentityKey') && !stored.includes('registrationId'));
  assert.ok(!stored.includes(privateKeyB64) && !stored.includes(state.creds.noiseKey.private.toString('hex')));
  assert.equal(repo.creds.get('chanA').keyId, 'k1');
});

test('credential updates are persisted in order even when writes overlap', async () => {
  const repo = new MemoryAuthRepository();
  const box = new SecretBox(`k1:${newKey()}`);
  const realPut = repo.putCreds.bind(repo);
  let first = true;
  repo.putCreds = async (...args) => {
    if (first) {
      first = false;
      await new Promise((r) => setTimeout(r, 40)); // simpan pertama lambat
    }
    return realPut(...args);
  };

  const { state, saveCreds } = await open(repo, box);
  state.creds.registrationId = 111;
  const p1 = saveCreds();
  state.creds.registrationId = 222;
  const p2 = saveCreds();
  await Promise.all([p1, p2]);

  assert.equal((await open(repo, box)).state.creds.registrationId, 222);
});

test('signal keys: set/get roundtrip with Buffers, falsy values delete, missing keys read as null', async () => {
  const repo = new MemoryAuthRepository();
  const box = new SecretBox(`k1:${newKey()}`);
  const { state } = await open(repo, box);
  const pre = { private: crypto.randomBytes(32), public: crypto.randomBytes(32) };

  await state.keys.set({ 'pre-key': { 1: pre, 2: { private: Buffer.from('b'), public: Buffer.from('c') } }, session: { 'x.0': Buffer.from('sesi') } });

  const got = await state.keys.get('pre-key', ['1', '2', '3']);
  assert.deepEqual(got['1'].private, pre.private);
  assert.deepEqual(got['1'].public, pre.public);
  assert.equal(got['3'], null);
  assert.deepEqual((await state.keys.get('session', ['x.0']))['x.0'], Buffer.from('sesi'));

  await state.keys.set({ 'pre-key': { 1: null, 2: undefined } });
  assert.deepEqual(await state.keys.get('pre-key', ['1', '2']), { 1: null, 2: null });
  assert.ok((await state.keys.get('session', ['x.0']))['x.0']); // jenis lain tidak ikut terhapus
});

test('app-state-sync-key values are rebuilt as protobuf objects like Baileys expects', async () => {
  const repo = new MemoryAuthRepository();
  const box = new SecretBox(`k1:${newKey()}`);
  const { state } = await open(repo, box);
  const keyData = crypto.randomBytes(32);

  await state.keys.set({ 'app-state-sync-key': { AAAAAJhc: proto.Message.AppStateSyncKeyData.fromObject({ keyData, timestamp: 5 }) } });

  const value = (await state.keys.get('app-state-sync-key', ['AAAAAJhc'])).AAAAAJhc;
  assert.ok(value instanceof proto.Message.AppStateSyncKeyData);
  assert.deepEqual(Buffer.from(value.keyData), keyData);
});

test('every stored key record is ciphertext', async () => {
  const repo = new MemoryAuthRepository();
  const box = new SecretBox(`k1:${newKey()}`);
  const { state } = await open(repo, box);
  const secret = crypto.randomBytes(32);

  await state.keys.set({ 'sender-key': { 'grup::pengirim': secret } });

  for (const row of repo.keys.values()) {
    assert.ok(!row.payload.includes(secret.toString('base64')) && !row.payload.includes('"type":"Buffer"') && !row.payload.includes('buffer'));
    assert.equal(row.keyId, 'k1');
  }
});

test('channels are isolated even with identical key types and ids', async () => {
  const repo = new MemoryAuthRepository();
  const box = new SecretBox(`k1:${newKey()}`);
  const a = await open(repo, box, 'chanA');
  const b = await open(repo, box, 'chanB');

  await a.state.keys.set({ session: { same: Buffer.from('A') } });
  await b.state.keys.set({ session: { same: Buffer.from('B') } });

  assert.deepEqual((await a.state.keys.get('session', ['same'])).same, Buffer.from('A'));
  assert.deepEqual((await b.state.keys.get('session', ['same'])).same, Buffer.from('B'));
});

test('a row copied to another channel or key slot cannot be read (AAD binding)', async () => {
  const repo = new MemoryAuthRepository();
  const box = new SecretBox(`k1:${newKey()}`);
  const a = await open(repo, box, 'chanA');
  await a.saveCreds();
  await a.state.keys.set({ session: { s1: Buffer.from('rahasia') } });

  // Menyalin creds channel A ke channel B -> B menolak (bukan menganggapnya valid).
  await repo.putCreds('chanB', await repo.getCreds('chanA'), 'k1');
  await assert.rejects(open(repo, box, 'chanB'), (e) => e instanceof AuthStoreError && e.code === 'AUTH_DECRYPT_FAILED');

  // Menyalin record kunci ke jenis/id lain -> dianggap hilang.
  const row = repo.keys.get('chanA\u0000session\u0000s1');
  repo.keys.set('chanA\u0000session\u0000s2', row);
  repo.keys.set('chanA\u0000pre-key\u0000s1', row);
  const again = await open(repo, box, 'chanA');
  assert.equal((await again.state.keys.get('session', ['s2'])).s2, null);
  assert.equal((await again.state.keys.get('pre-key', ['s1']))['s1'], null);
});

test('wrong encryption key: refuses to start and does NOT overwrite the stored credentials', async () => {
  const repo = new MemoryAuthRepository();
  const good = new SecretBox(`k1:${newKey()}`);
  await (await open(repo, good)).saveCreds();
  const before = await repo.getCreds('chanA');

  await assert.rejects(open(repo, new SecretBox(`k1:${newKey()}`)), (e) => e instanceof AuthStoreError && e.code === 'AUTH_DECRYPT_FAILED');

  assert.equal(await repo.getCreds('chanA'), before);
  await open(repo, good); // kunci benar tetap bisa
});

test('a corrupted key record reads as missing and is logged without leaking ids or content', async () => {
  const repo = new MemoryAuthRepository();
  const box = new SecretBox(`k1:${newKey()}`);
  const logger = logs();
  const { state } = await open(repo, box, 'chanA', logger);
  await state.keys.set({ session: { 'id-rahasia-1': Buffer.from('isi') } });
  const slot = 'chanA\u0000session\u0000id-rahasia-1';
  repo.keys.set(slot, { ...repo.keys.get(slot), payload: repo.keys.get(slot).payload.slice(0, -4) + 'AAAA' });

  assert.equal((await state.keys.get('session', ['id-rahasia-1']))['id-rahasia-1'], null);
  assert.equal(logger.entries.length, 1);
  assert.ok(!JSON.stringify(logger.entries).includes('id-rahasia-1') && !JSON.stringify(logger.entries).includes('isi'));
});

test('empty set() calls do not touch the repository', async () => {
  const repo = new MemoryAuthRepository();
  let writes = 0;
  repo.writeKeys = async () => { writes++; };
  const { state } = await open(repo, new SecretBox(`k1:${newKey()}`));

  await state.keys.set({});
  await state.keys.set({ session: {} });

  assert.equal(writes, 0);
});

test('key rotation re-encrypts every record with the active key and keeps the state readable', async () => {
  const k1 = newKey();
  const k2 = newKey();
  const repo = new MemoryAuthRepository();
  const oldBox = new SecretBox(`k1:${k1}`);
  const a = await open(repo, oldBox);
  await a.saveCreds();
  await a.state.keys.set({ session: { s1: Buffer.from('1'), s2: Buffer.from('2') }, 'pre-key': { 9: { private: Buffer.from('p'), public: Buffer.from('q') } } });

  const rotatedBox = new SecretBox(`k2:${k2},k1:${k1}`);
  const count = await rotateChannel({ repo, box: rotatedBox, channelId: 'chanA' });

  assert.equal(count, 4); // 1 creds + 3 kunci
  assert.equal(SecretBox.keyIdOf(await repo.getCreds('chanA')), 'k2');
  for (const row of repo.keys.values()) assert.equal(SecretBox.keyIdOf(row.payload), 'k2');

  // Setelah rotasi, kunci lama boleh dibuang.
  const onlyNew = await open(repo, new SecretBox(`k2:${k2}`));
  assert.deepEqual((await onlyNew.state.keys.get('session', ['s1', 's2'])).s2, Buffer.from('2'));
  assert.deepEqual(onlyNew.state.creds.noiseKey.private, a.state.creds.noiseKey.private);
});

test('rotation aborts on an unreadable record instead of destroying data', async () => {
  const repo = new MemoryAuthRepository();
  const a = await open(repo, new SecretBox(`k1:${newKey()}`));
  await a.saveCreds();
  const before = await repo.getCreds('chanA');

  await assert.rejects(rotateChannel({ repo, box: new SecretBox(`k9:${newKey()}`), channelId: 'chanA' }));

  assert.equal(await repo.getCreds('chanA'), before);
});

test('deleteChannel removes everything of one channel only; listChannels reports paired channels', async () => {
  const repo = new MemoryAuthRepository();
  const box = new SecretBox(`k1:${newKey()}`);
  const a = await open(repo, box, 'chanA');
  const b = await open(repo, box, 'chanB');
  await a.saveCreds();
  await b.saveCreds();
  await a.state.keys.set({ session: { x: Buffer.from('a') } });
  await b.state.keys.set({ session: { x: Buffer.from('b') } });
  assert.deepEqual(await repo.listChannels(), ['chanA', 'chanB']);

  await repo.deleteChannel('chanA');

  assert.deepEqual(await repo.listChannels(), ['chanB']);
  assert.equal(await repo.getCreds('chanA'), null);
  assert.equal((await repo.getKeys('chanA', 'session', ['x'])).size, 0);
  assert.equal((await repo.getKeys('chanB', 'session', ['x'])).size, 1);
});

test('initAuthCreds sanity: creds are generated locally and never sent as plaintext anywhere', () => {
  const creds = initAuthCreds();
  assert.ok(Buffer.isBuffer(creds.noiseKey.private) && creds.noiseKey.private.length === 32);
});
