import crypto from 'node:crypto';

/**
 * Enkripsi record auth Baileys sebelum disimpan di MySQL (DEC-17): AES-256-GCM dengan IV acak per record.
 *
 * Format tersimpan: `v1.<keyId>.<iv>.<tag>.<ciphertext>` (base64url).
 *
 * AAD (authenticated associated data) mengikat ciphertext ke barisnya — mis. `channel|kind|jenis|id` — sehingga baris yang
 * ditukar/disalin ke channel atau jenis lain GAGAL didekripsi, dan perubahan sekecil apa pun terdeteksi (GCM tag).
 *
 * Kunci: `AUTH_ENCRYPTION_KEYS="k1:<base64 32 byte>[,k2:<base64 32 byte>]"`. Kunci PERTAMA dipakai untuk menulis; semua
 * kunci dapat dipakai membaca, sehingga rotasi kunci = tambahkan kunci baru di depan, jalankan, lalu hapus kunci lama
 * setelah semua record ditulis ulang. Kunci hanya boleh ada di environment konektor — bukan di database, bukan di git.
 */

const VERSION = 'v1';
const KEY_ID_PATTERN = /^[A-Za-z0-9_-]{1,16}$/;
const IV_BYTES = 12;
const TAG_BYTES = 16;

export class DecryptError extends Error {
  constructor() {
    // Sengaja generik: jangan membocorkan penyebab (kunci salah vs data diubah) atau isi.
    super('Unable to decrypt auth record.');
    this.name = 'DecryptError';
  }
}

const b64u = (buf) => Buffer.from(buf).toString('base64url');

export class SecretBox {
  #keys = new Map();
  #activeId;

  /** @param {string} spec "k1:<base64>,k2:<base64>" — yang pertama aktif untuk menulis */
  constructor(spec) {
    if (typeof spec !== 'string' || spec.trim() === '') {
      throw new Error('AUTH_ENCRYPTION_KEYS is required when AUTH_STORE=mysql.');
    }

    for (const part of spec.split(',')) {
      const trimmed = part.trim();
      const separator = trimmed.indexOf(':');
      if (separator < 1) throw new Error('AUTH_ENCRYPTION_KEYS entries must look like "<id>:<base64 key>".');

      const id = trimmed.slice(0, separator);
      const key = Buffer.from(trimmed.slice(separator + 1), 'base64');

      if (!KEY_ID_PATTERN.test(id)) throw new Error('Encryption key id must match [A-Za-z0-9_-]{1,16}.');
      if (key.length !== 32) throw new Error(`Encryption key "${id}" must decode to exactly 32 bytes (AES-256).`);
      if (this.#keys.has(id)) throw new Error(`Duplicate encryption key id "${id}".`);

      this.#keys.set(id, key);
      this.#activeId ??= id;
    }
  }

  /** Id kunci yang dipakai menulis. */
  get activeKeyId() {
    return this.#activeId;
  }

  /** @returns {string} payload terenkripsi */
  encrypt(plaintext, aad) {
    const iv = crypto.randomBytes(IV_BYTES);
    const cipher = crypto.createCipheriv('aes-256-gcm', this.#keys.get(this.#activeId), iv, { authTagLength: TAG_BYTES });
    cipher.setAAD(Buffer.from(aad, 'utf8'));
    const ciphertext = Buffer.concat([cipher.update(plaintext), cipher.final()]);

    return [VERSION, this.#activeId, b64u(iv), b64u(cipher.getAuthTag()), b64u(ciphertext)].join('.');
  }

  /** @returns {Buffer} @throws {DecryptError} kunci tidak dikenal/salah, format rusak, AAD berbeda, atau data diubah */
  decrypt(payload, aad) {
    try {
      const [version, keyId, iv, tag, ciphertext, extra] = String(payload).split('.');
      const key = this.#keys.get(keyId);

      if (version !== VERSION || key === undefined || extra !== undefined || !iv || !tag || ciphertext === undefined) {
        throw new Error('bad payload');
      }

      const ivBuf = Buffer.from(iv, 'base64url');
      const tagBuf = Buffer.from(tag, 'base64url');
      if (ivBuf.length !== IV_BYTES || tagBuf.length !== TAG_BYTES) throw new Error('bad payload');

      const decipher = crypto.createDecipheriv('aes-256-gcm', key, ivBuf, { authTagLength: TAG_BYTES });
      decipher.setAAD(Buffer.from(aad, 'utf8'));
      decipher.setAuthTag(tagBuf);

      return Buffer.concat([decipher.update(Buffer.from(ciphertext, 'base64url')), decipher.final()]);
    } catch {
      throw new DecryptError();
    }
  }

  /** Id kunci yang dipakai sebuah payload (untuk menemukan record yang masih memakai kunci lama), atau null. */
  static keyIdOf(payload) {
    const parts = String(payload).split('.');
    return parts[0] === VERSION && parts.length === 5 ? parts[1] : null;
  }
}

/** Kunci acak baru dalam format AUTH_ENCRYPTION_KEYS. */
export function generateKeySpec(id = 'k1') {
  return `${id}:${crypto.randomBytes(32).toString('base64')}`;
}
