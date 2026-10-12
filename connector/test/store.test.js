import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { loadConfig } from '../src/config.js';
import { MemoryAuthRepository } from '../src/auth/memory-repository.js';
import { SecretBox } from '../src/auth/secret-box.js';
import { createAuthStore, EncryptedMysqlAuthStore, FileAuthStore } from '../src/auth/store.js';

const logger = () => {
  const warnings = [];
  return { warnings, warn: (...a) => warnings.push(a) };
};
const tmp = () => fs.mkdtemp(path.join(os.tmpdir(), 'velora-auth-'));
const withEnv = (env, fn) => {
  const saved = { ...process.env };
  Object.assign(process.env, { CONNECTOR_TOKEN: 'x'.repeat(40) }, env);
  try {
    return fn();
  } finally {
    for (const k of Object.keys(process.env)) if (!(k in saved)) delete process.env[k];
    Object.assign(process.env, saved);
  }
};

// ---------------------------------------------------------------- config
test('config: file store is the default; mysql requires db and keys and fails fast', () => {
  withEnv({ AUTH_STORE: '', DB_DATABASE: '', DB_USERNAME: '', AUTH_ENCRYPTION_KEYS: '' }, () => {
    delete process.env.AUTH_STORE;
    assert.equal(loadConfig().authStore, 'file');
  });
  withEnv({ AUTH_STORE: 'mysql', DB_DATABASE: '', DB_USERNAME: '', AUTH_ENCRYPTION_KEYS: '' }, () => assert.throws(loadConfig, /DB_DATABASE and DB_USERNAME/));
  withEnv({ AUTH_STORE: 'mysql', DB_DATABASE: 'd', DB_USERNAME: 'u', AUTH_ENCRYPTION_KEYS: '' }, () => assert.throws(loadConfig, /AUTH_ENCRYPTION_KEYS/));
  withEnv({ AUTH_STORE: 'redis' }, () => assert.throws(loadConfig, /AUTH_STORE must be/));
  withEnv({ AUTH_STORE: 'mysql', DB_DATABASE: 'd', DB_USERNAME: 'u', DB_PORT: '3307', AUTH_ENCRYPTION_KEYS: 'k1:abc' }, () => {
    const c = loadConfig();
    assert.deepEqual([c.authStore, c.db.database, c.db.port], ['mysql', 'd', 3307]);
  });
});

// ---------------------------------------------------------------- factory
test('factory: file mode warns loudly that credentials are unencrypted', () => {
  const log = logger();
  const store = createAuthStore({ config: { authStore: 'file', authDir: path.resolve('./x-auth') }, logger: log });

  assert.ok(store instanceof FileAuthStore);
  assert.equal(log.warnings.length, 1);
  assert.match(log.warnings[0][1], /UNENCRYPTED/);
});

test('factory: mysql mode validates the encryption keys at boot and needs no connection yet', async () => {
  const config = { authStore: 'mysql', authEncryptionKeys: `k1:${crypto.randomBytes(32).toString('base64')}`, db: { host: '127.0.0.1', port: 1, database: 'd', user: 'u', password: '' } };

  const store = createAuthStore({ config, logger: logger() });
  assert.equal(store.name, 'mysql');
  await store.close();

  assert.throws(() => createAuthStore({ config: { ...config, authEncryptionKeys: 'k1:pendek' }, logger: logger() }), /32 bytes/);
  assert.throws(() => createAuthStore({ config: { ...config, authEncryptionKeys: '' }, logger: logger() }), /required/);
});

test('factory: mysql mode never logs the unencrypted warning', () => {
  const log = logger();
  const config = { authStore: 'mysql', authEncryptionKeys: `k1:${crypto.randomBytes(32).toString('base64')}`, db: { host: '127.0.0.1', port: 1, database: 'd', user: 'u', password: '' } };

  createAuthStore({ config, logger: log }).close();

  assert.equal(log.warnings.length, 0);
});

// ---------------------------------------------------------------- file store
test('file store: open creates the directory, listPaired reports only channels with creds.json', async () => {
  const dir = await tmp();
  const store = new FileAuthStore(dir);

  const { saveCreds } = await store.open('chanA');
  await saveCreds();
  await store.open('chanB'); // dibuka tetapi belum pernah menyimpan creds
  await fs.mkdir(path.join(dir, 'bukan valid!'));
  await fs.writeFile(path.join(dir, 'file.txt'), 'x');

  assert.deepEqual(await store.listPaired(), ['chanA']);
});

test('file store: remove deletes the whole channel directory and nothing else', async () => {
  const dir = await tmp();
  const store = new FileAuthStore(dir);
  for (const id of ['chanA', 'chanB']) await (await store.open(id)).saveCreds();

  await store.remove('chanA');

  assert.deepEqual(await store.listPaired(), ['chanB']);
  await store.remove('tidak_ada'); // tidak melempar
});

test('file store: rejects path traversal and invalid channel ids', async () => {
  const dir = await tmp();
  const store = new FileAuthStore(dir);

  for (const bad of ['../x', '..', 'a/b', 'a\\b', '', 'x'.repeat(65), '/etc']) {
    await assert.rejects(store.open(bad), /Invalid channel path/, bad);
    await assert.rejects(store.remove(bad), /Invalid channel path/, bad);
  }
});

// ---------------------------------------------------------------- mysql store (repository di memori)
test('encrypted store: open/listPaired/remove delegate correctly and keep channels apart', async () => {
  const repo = new MemoryAuthRepository();
  const box = new SecretBox(`k1:${crypto.randomBytes(32).toString('base64')}`);
  const store = new EncryptedMysqlAuthStore({ repo, box, logger: logger() });

  for (const id of ['chanA', 'chanB']) {
    const { state, saveCreds } = await store.open(id);
    await saveCreds();
    await state.keys.set({ session: { s: Buffer.from(id) } });
  }
  assert.deepEqual(await store.listPaired(), ['chanA', 'chanB']);

  await store.remove('chanA');

  assert.deepEqual(await store.listPaired(), ['chanB']);
  assert.equal((await repo.listKeys('chanA')).length, 0);
  assert.equal((await repo.listKeys('chanB')).length, 1);
});

test('encrypted store: a channel removed on logout starts fresh (new device identity) when reopened', async () => {
  const repo = new MemoryAuthRepository();
  const box = new SecretBox(`k1:${crypto.randomBytes(32).toString('base64')}`);
  const store = new EncryptedMysqlAuthStore({ repo, box, logger: logger() });
  const first = await store.open('chanA');
  await first.saveCreds();

  await store.remove('chanA');
  const second = await store.open('chanA');

  assert.notDeepEqual(second.state.creds.noiseKey.private, first.state.creds.noiseKey.private);
});
