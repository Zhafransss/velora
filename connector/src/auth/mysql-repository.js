/**
 * Repository auth Baileys di MySQL. Tabel dibuat oleh migrasi Laravel (wa_auth_creds, wa_auth_keys); konektor hanya
 * membaca/menulis. Nilai yang disimpan SUDAH terenkripsi (lihat secret-box.js) — repository ini tidak pernah melihat
 * plaintext. Disarankan memakai pengguna MySQL khusus dengan hak SELECT/INSERT/UPDATE/DELETE hanya pada dua tabel ini.
 */

const CHUNK = 500;

const chunk = (items, size) => {
  const out = [];
  for (let i = 0; i < items.length; i += size) out.push(items.slice(i, i + size));
  return out;
};

export class MysqlAuthRepository {
  #pool;

  /** @param pool pool mysql2/promise */
  constructor(pool) {
    this.#pool = pool;
  }

  async getCreds(channelId) {
    const [rows] = await this.#pool.query('SELECT payload FROM wa_auth_creds WHERE channel_id = ?', [channelId]);

    return rows[0]?.payload ?? null;
  }

  async putCreds(channelId, payload, keyId) {
    await this.#pool.query(
      `INSERT INTO wa_auth_creds (channel_id, payload, enc_key_id, updated_at) VALUES (?, ?, ?, NOW(3))
       ON DUPLICATE KEY UPDATE payload = VALUES(payload), enc_key_id = VALUES(enc_key_id), updated_at = NOW(3)`,
      [channelId, payload, keyId],
    );
  }

  async getKeys(channelId, type, ids) {
    const found = new Map();

    for (const part of chunk(ids, CHUNK)) {
      const [rows] = await this.#pool.query(
        'SELECT key_id, payload FROM wa_auth_keys WHERE channel_id = ? AND key_type = ? AND key_id IN (?)',
        [channelId, type, part],
      );
      for (const row of rows) found.set(row.key_id, row.payload);
    }

    return found;
  }

  /** Upsert dan hapus dalam SATU transaksi: set kunci Signal harus atomik. */
  async writeKeys(channelId, upserts, deletes) {
    const connection = await this.#pool.getConnection();

    try {
      await connection.beginTransaction();

      for (const part of chunk(upserts, CHUNK)) {
        await connection.query(
          `INSERT INTO wa_auth_keys (channel_id, key_type, key_id, payload, enc_key_id, updated_at) VALUES ?
           ON DUPLICATE KEY UPDATE payload = VALUES(payload), enc_key_id = VALUES(enc_key_id), updated_at = VALUES(updated_at)`,
          [part.map((u) => [channelId, u.type, u.id, u.payload, u.keyId, new Date()])],
        );
      }

      const byType = new Map();
      for (const d of deletes) byType.set(d.type, [...(byType.get(d.type) ?? []), d.id]);

      for (const [type, ids] of byType) {
        for (const part of chunk(ids, CHUNK)) {
          await connection.query('DELETE FROM wa_auth_keys WHERE channel_id = ? AND key_type = ? AND key_id IN (?)', [channelId, type, part]);
        }
      }

      await connection.commit();
    } catch (error) {
      await connection.rollback().catch(() => {});
      throw error;
    } finally {
      connection.release();
    }
  }

  async listKeys(channelId) {
    const [rows] = await this.#pool.query('SELECT key_type, key_id, payload FROM wa_auth_keys WHERE channel_id = ?', [channelId]);

    return rows.map((r) => ({ type: r.key_type, id: r.key_id, payload: r.payload }));
  }

  async deleteChannel(channelId) {
    const connection = await this.#pool.getConnection();

    try {
      await connection.beginTransaction();
      await connection.query('DELETE FROM wa_auth_keys WHERE channel_id = ?', [channelId]);
      await connection.query('DELETE FROM wa_auth_creds WHERE channel_id = ?', [channelId]);
      await connection.commit();
    } catch (error) {
      await connection.rollback().catch(() => {});
      throw error;
    } finally {
      connection.release();
    }
  }

  async listChannels() {
    const [rows] = await this.#pool.query('SELECT channel_id FROM wa_auth_creds ORDER BY channel_id');

    return rows.map((r) => r.channel_id);
  }

  async close() {
    await this.#pool.end();
  }
}
