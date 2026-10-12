import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { test } from 'node:test';
import { DecryptError, generateKeySpec, SecretBox } from '../src/auth/secret-box.js';

const key = () => crypto.randomBytes(32).toString('base64');
const box = (spec = `k1:${key()}`) => new SecretBox(spec);

test('encrypts and decrypts strings and buffers', () => {
  const b = box();
  assert.equal(b.decrypt(b.encrypt('rahasia', 'aad'), 'aad').toString(), 'rahasia');
  assert.deepEqual(b.decrypt(b.encrypt(Buffer.from([0, 1, 2, 255]), 'aad'), 'aad'), Buffer.from([0, 1, 2, 255]));
  assert.equal(b.decrypt(b.encrypt('', 'aad'), 'aad').toString(), '');
});

test('the stored form never contains the plaintext and uses a fresh IV every time', () => {
  const b = box();
  const secret = 'NOISE-KEY-PRIVATE-0123456789';
  const a = b.encrypt(secret, 'aad');
  const c = b.encrypt(secret, 'aad');

  assert.ok(!a.includes(secret) && !Buffer.from(a, 'utf8').toString('base64').includes(secret));
  assert.notEqual(a, c);
  assert.match(a, /^v1\.k1\.[\w-]+\.[\w-]+\.[\w-]*$/);
});

test('a different AAD (another channel/type/id) cannot decrypt: rows cannot be swapped', () => {
  const b = box();
  const payload = b.encrypt('x', 'chanA\u0000creds');

  assert.throws(() => b.decrypt(payload, 'chanB\u0000creds'), DecryptError);
  assert.throws(() => b.decrypt(payload, 'chanA\u0000key'), DecryptError);
  assert.equal(b.decrypt(payload, 'chanA\u0000creds').toString(), 'x');
});

test('any tampering is detected', () => {
  const b = box();
  const [v, kid, iv, tag, ct] = b.encrypt('data-penting', 'aad').split('.');
  const flip = (s) => (s[0] === 'A' ? 'B' : 'A') + s.slice(1);

  for (const bad of [
    [v, kid, flip(iv), tag, ct],
    [v, kid, iv, flip(tag), ct],
    [v, kid, iv, tag, flip(ct)],
    ['v2', kid, iv, tag, ct],
    [v, 'k9', iv, tag, ct],
    [v, kid, iv, tag],
    [v, kid, iv, tag, ct, 'extra'],
    [v, kid, iv.slice(2), tag, ct],
    [v, kid, iv, tag.slice(2), ct],
  ]) {
    assert.throws(() => b.decrypt(bad.join('.'), 'aad'), DecryptError, bad.join('.').slice(0, 30));
  }
  for (const garbage of ['', 'abc', null, undefined, '....']) {
    assert.throws(() => b.decrypt(garbage, 'aad'), DecryptError);
  }
});

test('a wrong key fails with a generic error that reveals nothing', () => {
  const payload = box().encrypt('x', 'aad');
  const other = box();

  assert.throws(
    () => other.decrypt(payload, 'aad'),
    (error) => error instanceof DecryptError && error.message === 'Unable to decrypt auth record.',
  );
});

test('key rotation: the first key writes, every listed key can still read', () => {
  const k1 = key();
  const k2 = key();
  const old = new SecretBox(`k1:${k1}`);
  const rotated = new SecretBox(`k2:${k2},k1:${k1}`);
  const legacy = old.encrypt('lama', 'aad');
  const fresh = rotated.encrypt('baru', 'aad');

  assert.equal(rotated.activeKeyId, 'k2');
  assert.equal(rotated.decrypt(legacy, 'aad').toString(), 'lama');
  assert.equal(SecretBox.keyIdOf(legacy), 'k1');
  assert.equal(SecretBox.keyIdOf(fresh), 'k2');
  assert.throws(() => old.decrypt(fresh, 'aad'), DecryptError); // kunci lama saja tidak bisa membaca yang baru
  assert.equal(SecretBox.keyIdOf('sampah'), null);
});

test('rejects invalid key configuration', () => {
  assert.throws(() => new SecretBox(''), /required/);
  assert.throws(() => new SecretBox(undefined), /required/);
  assert.throws(() => new SecretBox('tanpa-titik-dua'), /<id>:<base64/);
  assert.throws(() => new SecretBox(`k1:${crypto.randomBytes(16).toString('base64')}`), /32 bytes/);
  assert.throws(() => new SecretBox(`k1:${crypto.randomBytes(33).toString('base64')}`), /32 bytes/);
  assert.throws(() => new SecretBox(`bad id!:${key()}`), /key id/);
  assert.throws(() => new SecretBox(`${'a'.repeat(17)}:${key()}`), /key id/);
  assert.throws(() => new SecretBox(`k1:${key()},k1:${key()}`), /Duplicate/);
});

test('tolerates whitespace around entries and generateKeySpec produces a usable spec', () => {
  const b = new SecretBox(` k1:${key()} , k2:${key()} `);
  assert.equal(b.activeKeyId, 'k1');

  const spec = generateKeySpec('prod1');
  assert.match(spec, /^prod1:[A-Za-z0-9+/]{43}=$/);
  assert.equal(new SecretBox(spec).decrypt(new SecretBox(spec).encrypt('ok', 'a'), 'a').toString(), 'ok');
  assert.notEqual(generateKeySpec(), generateKeySpec());
});
