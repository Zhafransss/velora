import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { after, before, describe, test } from 'node:test';
import mysql from 'mysql2/promise';
import { MysqlAuthRepository } from '../src/auth/mysql-repository.js';
import { SecretBox } from '../src/auth/secret-box.js';
import { EncryptedMysqlAuthStore } from '../src/auth/store.js';

/**
 * Integrasi terhadap MySQL SUNGGUHAN (SQL asli: upsert, transaksi, kolase biner, potongan IN). Dilewati otomatis bila
 * database uji tidak dikonfigurasi. Tabel dibuat oleh migrasi Laravel (php artisan migrate).
 *
 *   TEST_DB_DATABASE=veloradigital TEST_DB_USERNAME=root [TEST_DB_PASSWORD=] [TEST_DB_HOST=127.0.0.1] [TEST_DB_PORT=3306] npm test
 *
 * Memakai channel id acak berawalan "itest_" dan membersihkannya sendiri. JANGAN diarahkan ke database produksi.
 */
const enabled = Boolean(process.env.TEST_DB_DATABASE && process.env.TEST_DB_USERNAME);

describe('MysqlAuthRepository (real MySQL)', { skip: enabled ? false : 'set TEST_DB_DATABASE and TEST_DB_USERNAME to run' }, () => {
  const ids = [`itest_${crypto.randomBytes(5).toString('hex')}`, `itest_${crypto.randomBytes(5).toString('hex')}`];
  const [A, B] = ids;
  let pool;
  let repo;
  let store;
  let box;

  before(async () => {
    pool = mysql.createPool({
      host: process.env.TEST_DB_HOST ?? '127.0.0.1',
      port: Number(process.env.TEST_DB_PORT ?? 3306),
      database: process.env.TEST_DB_DATABASE,
      user: process.env.TEST_DB_USERNAME,
      password: process.env.TEST_DB_PASSWORD ?? '',
      charset: 'utf8mb4',
    });
    repo = new MysqlAuthRepository(pool);
    box = new SecretBox(`k1:${crypto.randomBytes(32).toString('base64')}`);
    store = new EncryptedMysqlAuthStore({ repo, box, logger: { warn() {} } });
  });

  const clean = async () => {
    for (const id of ids) await repo.deleteChannel(id);
  };
  after(async () => {
    await clean();
    await pool.end();
  });

  test('credentials: upsert keeps a single row and survives a reconnect with Buffers intact', async () => {
    const first = await store.open(A);
    await first.saveCreds();
    first.state.creds.registrationId = 4242;
    await first.saveCreds();

    const [rows] = await pool.query('SELECT COUNT(*) AS n FROM wa_auth_creds WHERE channel_id = ?', [A]);
    assert.equal(rows[0].n, 1);

    const again = await store.open(A);
    assert.equal(again.state.creds.registrationId, 4242);
    assert.deepEqual(again.state.creds.noiseKey.private, first.state.creds.noiseKey.private);
  });

  test('the database only ever holds ciphertext', async () => {
    const { state } = await store.open(A);
    const secret = crypto.randomBytes(32);
    await state.keys.set({ 'sender-key': { 'grup::orang': secret } });

    const [creds] = await pool.query('SELECT payload, enc_key_id FROM wa_auth_creds WHERE channel_id = ?', [A]);
    const [keys] = await pool.query('SELECT payload FROM wa_auth_keys WHERE channel_id = ?', [A]);
    const dump = JSON.stringify([creds, keys]);

    assert.equal(creds[0].enc_key_id, 'k1');
    assert.ok(!dump.includes('noiseKey') && !dump.includes('registrationId') && !dump.includes(secret.toString('base64')) && !dump.includes(secret.toString('hex')));
    assert.match(creds[0].payload, /^v1\.k1\./);
  });

  test('keys: batch upsert and delete, spanning several 500-row chunks', async () => {
    const { state } = await store.open(A);
    const batch = {};
    for (let i = 0; i < 1250; i++) batch[`k${i}`] = Buffer.from(`v${i}`);

    await state.keys.set({ session: batch });

    const some = await state.keys.get('session', ['k0', 'k499', 'k500', 'k1249', 'tidak-ada']);
    assert.deepEqual(some.k0, Buffer.from('v0'));
    assert.deepEqual(some.k500, Buffer.from('v500'));
    assert.deepEqual(some.k1249, Buffer.from('v1249'));
    assert.equal(some['tidak-ada'], null);

    const many = await state.keys.get('session', Object.keys(batch)); // 1250 id -> IN() dipotong
    assert.equal(Object.values(many).filter(Boolean).length, 1250);

    await state.keys.set({ session: { k0: null, k1: undefined, k2: Buffer.from('baru') } });
    const after = await state.keys.get('session', ['k0', 'k1', 'k2', 'k3']);
    assert.deepEqual([after.k0, after.k1], [null, null]);
    assert.deepEqual(after.k2, Buffer.from('baru'));
    assert.deepEqual(after.k3, Buffer.from('v3'));
  });

  test('ids that differ only by letter case are DIFFERENT keys (binary collation)', async () => {
    const { state } = await store.open(A);

    await state.keys.set({ 'app-state-sync-key': {} , 'pre-key': { AAAAAJhc: Buffer.from('besar'), aaaaajhc: Buffer.from('kecil'), AaAaAjHc: Buffer.from('campur') } });

    const got = await state.keys.get('pre-key', ['AAAAAJhc', 'aaaaajhc', 'AaAaAjHc']);
    assert.deepEqual(got.AAAAAJhc, Buffer.from('besar'));
    assert.deepEqual(got.aaaaajhc, Buffer.from('kecil'));
    assert.deepEqual(got.AaAaAjHc, Buffer.from('campur'));
    const [rows] = await pool.query("SELECT COUNT(*) AS n FROM wa_auth_keys WHERE channel_id = ? AND key_type = 'pre-key'", [A]);
    assert.equal(rows[0].n, 3);
  });

  test('a failing batch is rolled back as a whole (atomic)', async () => {
    const { state } = await store.open(A);
    await state.keys.set({ session: { atomic1: Buffer.from('lama') } });

    // 501 baris = 2 potongan INSERT; potongan kedua dipaksa gagal setelah yang pertama sudah dieksekusi.
    const failing = new MysqlAuthRepository({
      query: (...a) => pool.query(...a),
      getConnection: async () => {
        const c = await pool.getConnection();
        let inserts = 0;
        const query = c.query.bind(c);
        c.query = (sql, ...rest) => (String(sql).includes('INSERT INTO wa_auth_keys') && ++inserts === 2 ? Promise.reject(new Error('boom')) : query(sql, ...rest));
        return c;
      },
    });
    const upserts = [{ type: 'session', id: 'atomic1', payload: 'v1.k1.a.b.c', keyId: 'k1' }];
    for (let i = 0; i < 500; i++) upserts.push({ type: 'session', id: `bulk${i}`, payload: 'v1.k1.a.b.c', keyId: 'k1' });

    await assert.rejects(failing.writeKeys(A, upserts, []), /boom/);

    const got = await state.keys.get('session', ['atomic1', 'bulk0']);
    assert.deepEqual(got.atomic1, Buffer.from('lama')); // potongan pertama dibatalkan juga
    assert.equal(got.bulk0, null);
  });

  test('channels are isolated; listPaired and remove only affect their own rows', async () => {
    const a = await store.open(A);
    const b = await store.open(B);
    await b.saveCreds();
    await b.state.keys.set({ session: { k3: Buffer.from('milik B') } });

    assert.ok((await store.listPaired()).includes(A) && (await store.listPaired()).includes(B));
    assert.deepEqual((await a.state.keys.get('session', ['k3'])).k3, Buffer.from('v3'));
    assert.deepEqual((await b.state.keys.get('session', ['k3'])).k3, Buffer.from('milik B'));

    await store.remove(A);

    const [[ca]] = await pool.query('SELECT COUNT(*) AS n FROM wa_auth_creds WHERE channel_id = ?', [A]);
    const [[ka]] = await pool.query('SELECT COUNT(*) AS n FROM wa_auth_keys WHERE channel_id = ?', [A]);
    const [[kb]] = await pool.query('SELECT COUNT(*) AS n FROM wa_auth_keys WHERE channel_id = ?', [B]);
    assert.deepEqual([ca.n, ka.n], [0, 0]);
    assert.ok(kb.n >= 1);
    assert.ok(!(await store.listPaired()).includes(A));
  });

  test('a wrong encryption key cannot read rows written with another key', async () => {
    const other = new EncryptedMysqlAuthStore({ repo, box: new SecretBox(`k1:${crypto.randomBytes(32).toString('base64')}`), logger: { warn() {} } });

    await assert.rejects(other.open(B), /cannot be decrypted/);
  });
});
