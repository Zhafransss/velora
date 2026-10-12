import fs from 'node:fs/promises';
import path from 'node:path';
import mysql from 'mysql2/promise';
import { useMultiFileAuthState } from '@whiskeysockets/baileys';
import { openEncryptedAuthState } from './encrypted-auth-state.js';
import { MysqlAuthRepository } from './mysql-repository.js';
import { SecretBox } from './secret-box.js';

/**
 * Penyimpanan auth Baileys. Dua implementasi dengan antarmuka sama:
 *   open(channelId)   -> { state, saveCreds }
 *   remove(channelId)                      hapus SEMUA kredensial channel (logout/revoke, FR-009.6)
 *   listPaired()      -> string[]          channel yang punya kredensial (untuk dipulihkan saat boot)
 *   close()
 *
 *  - mysql : terenkripsi AES-256-GCM di MySQL (DEC-17). UNTUK PRODUKSI.
 *  - file  : direktori `auth/` TANPA enkripsi (useMultiFileAuthState). Hanya PoC/pengembangan.
 */

const CHANNEL_ID_PATTERN = /^[A-Za-z0-9_-]{1,64}$/;

class AuthPathError extends Error {}

export class FileAuthStore {
  name = 'file';

  #dir;

  constructor(dir) {
    this.#dir = dir;
  }

  #pathFor(channelId) {
    const resolved = path.resolve(this.#dir, channelId);
    if (!CHANNEL_ID_PATTERN.test(channelId) || !resolved.startsWith(this.#dir + path.sep)) {
      throw new AuthPathError('Invalid channel path.');
    }

    return resolved;
  }

  async open(channelId) {
    const folder = this.#pathFor(channelId);
    await fs.mkdir(folder, { recursive: true });

    return useMultiFileAuthState(folder);
  }

  async remove(channelId) {
    await fs.rm(this.#pathFor(channelId), { recursive: true, force: true });
  }

  async listPaired() {
    await fs.mkdir(this.#dir, { recursive: true });
    const paired = [];

    for (const entry of await fs.readdir(this.#dir, { withFileTypes: true })) {
      if (!entry.isDirectory() || !CHANNEL_ID_PATTERN.test(entry.name)) continue;
      try {
        await fs.access(path.join(this.#dir, entry.name, 'creds.json'));
        paired.push(entry.name);
      } catch {
        // belum pernah dipasangkan
      }
    }

    return paired;
  }

  async close() {}
}

export class EncryptedMysqlAuthStore {
  name = 'mysql';

  #repo;
  #box;
  #logger;

  constructor({ repo, box, logger }) {
    this.#repo = repo;
    this.#box = box;
    this.#logger = logger;
  }

  open(channelId) {
    return openEncryptedAuthState({ repo: this.#repo, box: this.#box, channelId, logger: this.#logger });
  }

  remove(channelId) {
    return this.#repo.deleteChannel(channelId);
  }

  listPaired() {
    return this.#repo.listChannels();
  }

  close() {
    return this.#repo.close();
  }
}

/** @throws {Error} konfigurasi tidak lengkap/tidak valid (gagal cepat saat boot, bukan saat pesan pertama) */
export function createAuthStore({ config, logger }) {
  if (config.authStore === 'mysql') {
    const box = new SecretBox(config.authEncryptionKeys);
    const pool = mysql.createPool({
      host: config.db.host,
      port: config.db.port,
      database: config.db.database,
      user: config.db.user,
      password: config.db.password,
      waitForConnections: true,
      connectionLimit: 5,
      charset: 'utf8mb4',
    });

    // Strict: data terenkripsi yang terlalu panjang harus GAGAL, bukan terpotong diam-diam (ciphertext terpotong = sesi rusak).
    pool.on('connection', (connection) => connection.query("SET SESSION sql_mode = CONCAT(@@sql_mode, ',STRICT_ALL_TABLES')"));

    return new EncryptedMysqlAuthStore({ repo: new MysqlAuthRepository(pool), box, logger });
  }

  logger?.warn(
    { authDir: config.authDir },
    'AUTH_STORE=file: WhatsApp credentials are stored UNENCRYPTED on disk. Use AUTH_STORE=mysql in production (DEC-17).',
  );

  return new FileAuthStore(config.authDir);
}
