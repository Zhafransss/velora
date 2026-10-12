import { BufferJSON, initAuthCreds, proto } from '@whiskeysockets/baileys';

/**
 * Auth state Baileys (creds + Signal keys) yang disimpan TERENKRIPSI lewat sebuah repository (DEC-17).
 * Antarmuka `state`/`saveCreds` sama dengan `useMultiFileAuthState`, sehingga SessionManager tidak berubah.
 *
 * Repository (diimplementasikan oleh MysqlAuthRepository dan, untuk tes, MemoryAuthRepository):
 *   getCreds(channelId)                           -> string|null  payload terenkripsi
 *   putCreds(channelId, payload, keyId)
 *   getKeys(channelId, type, ids)                 -> Map<id, payload>
 *   writeKeys(channelId, upserts, deletes)        upserts: [{type,id,payload,keyId}]  deletes: [{type,id}]  (atomik)
 *   listKeys(channelId)                           -> [{type,id,payload}]
 *   deleteChannel(channelId)
 *   listChannels()                                -> string[]  channel yang sudah dipasangkan
 */

export class AuthStoreError extends Error {
  constructor(code, message) {
    super(message);
    this.name = 'AuthStoreError';
    this.code = code;
  }
}

/** AAD mengikat ciphertext ke channel + jenis + id; baris yang ditukar/disalin gagal didekripsi. */
export const credsAad = (channelId) => [channelId, 'creds'].join('\u0000');
export const keyAad = (channelId, type, id) => [channelId, 'key', type, id].join('\u0000');

const encode = (value) => JSON.stringify(value, BufferJSON.replacer);
const decode = (buffer) => JSON.parse(buffer.toString('utf8'), BufferJSON.reviver);

/**
 * @returns {Promise<{ state: object, saveCreds: () => Promise<void> }>}
 * @throws {AuthStoreError} bila creds yang tersimpan tidak dapat didekripsi (kunci salah/data rusak). SENGAJA tidak
 *   dianggap "belum dipasangkan": membuat creds baru akan menimpa identitas perangkat yang masih berlaku.
 */
export async function openEncryptedAuthState({ repo, box, channelId, logger }) {
  const stored = await repo.getCreds(channelId);
  let creds;

  if (stored) {
    try {
      creds = decode(box.decrypt(stored, credsAad(channelId)));
    } catch {
      throw new AuthStoreError(
        'AUTH_DECRYPT_FAILED',
        'Stored credentials cannot be decrypted (wrong AUTH_ENCRYPTION_KEYS or corrupted data). Refusing to start; not overwriting.',
      );
    }
  } else {
    creds = initAuthCreds();
  }

  // Penulisan creds diserialkan: urutan simpan harus sama dengan urutan pembaruan.
  let credsQueue = Promise.resolve();

  return {
    state: {
      creds,
      keys: {
        get: async (type, ids) => {
          const rows = await repo.getKeys(channelId, type, ids);
          const data = {};

          for (const id of ids) {
            const payload = rows.get(id);
            let value = null;

            if (payload) {
              try {
                value = decode(box.decrypt(payload, keyAad(channelId, type, id)));
                if (type === 'app-state-sync-key' && value) {
                  value = proto.Message.AppStateSyncKeyData.fromObject(value);
                }
              } catch {
                // Kunci rusak diperlakukan hilang (Baileys dapat memulihkannya); jangan mencatat id/isi.
                logger?.warn({ channelId, type }, 'auth key record could not be decrypted, treating as missing');
                value = null;
              }
            }

            data[id] = value;
          }

          return data;
        },

        set: async (data) => {
          const upserts = [];
          const deletes = [];

          for (const type of Object.keys(data)) {
            for (const id of Object.keys(data[type])) {
              const value = data[type][id];

              if (value) {
                upserts.push({ type, id, payload: box.encrypt(encode(value), keyAad(channelId, type, id)), keyId: box.activeKeyId });
              } else {
                deletes.push({ type, id });
              }
            }
          }

          if (upserts.length > 0 || deletes.length > 0) {
            await repo.writeKeys(channelId, upserts, deletes);
          }
        },
      },
    },

    saveCreds: () => {
      const run = () => repo.putCreds(channelId, box.encrypt(encode(creds), credsAad(channelId)), box.activeKeyId);
      credsQueue = credsQueue.then(run, run);

      return credsQueue;
    },
  };
}

/**
 * Tulis ulang semua record sebuah channel dengan kunci AKTIF (langkah rotasi kunci). Mengembalikan jumlah record.
 * Record yang tidak dapat didekripsi dengan kunci mana pun melempar galat dan tidak diubah.
 */
export async function rotateChannel({ repo, box, channelId }) {
  let count = 0;

  const creds = await repo.getCreds(channelId);
  if (creds) {
    const plain = box.decrypt(creds, credsAad(channelId));
    await repo.putCreds(channelId, box.encrypt(plain, credsAad(channelId)), box.activeKeyId);
    count++;
  }

  const upserts = [];
  for (const row of await repo.listKeys(channelId)) {
    const plain = box.decrypt(row.payload, keyAad(channelId, row.type, row.id));
    upserts.push({ type: row.type, id: row.id, payload: box.encrypt(plain, keyAad(channelId, row.type, row.id)), keyId: box.activeKeyId });
  }

  if (upserts.length > 0) {
    await repo.writeKeys(channelId, upserts, []);
    count += upserts.length;
  }

  return count;
}
