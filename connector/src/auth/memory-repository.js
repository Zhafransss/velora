/**
 * Repository auth di memori — hanya untuk tes dan alat uji (tidak persisten). Perilakunya mengikuti
 * MysqlAuthRepository: nilai yang disimpan adalah payload terenkripsi, tiap channel terpisah, writeKeys atomik.
 */
export class MemoryAuthRepository {
  creds = new Map(); // channelId -> { payload, keyId }
  keys = new Map(); // `${channelId}\0${type}\0${id}` -> { payload, keyId }

  #k = (channelId, type, id) => `${channelId}\u0000${type}\u0000${id}`;

  async getCreds(channelId) {
    return this.creds.get(channelId)?.payload ?? null;
  }

  async putCreds(channelId, payload, keyId) {
    this.creds.set(channelId, { payload, keyId });
  }

  async getKeys(channelId, type, ids) {
    const found = new Map();
    for (const id of ids) {
      const row = this.keys.get(this.#k(channelId, type, id));
      if (row) found.set(id, row.payload);
    }

    return found;
  }

  async writeKeys(channelId, upserts, deletes) {
    for (const u of upserts) this.keys.set(this.#k(channelId, u.type, u.id), { payload: u.payload, keyId: u.keyId });
    for (const d of deletes) this.keys.delete(this.#k(channelId, d.type, d.id));
  }

  async listKeys(channelId) {
    const prefix = `${channelId}\u0000`;

    return [...this.keys.entries()]
      .filter(([k]) => k.startsWith(prefix))
      .map(([k, v]) => {
        const [, type, id] = k.split('\u0000');

        return { type, id, payload: v.payload };
      });
  }

  async deleteChannel(channelId) {
    this.creds.delete(channelId);
    for (const k of [...this.keys.keys()]) if (k.startsWith(`${channelId}\u0000`)) this.keys.delete(k);
  }

  async listChannels() {
    return [...this.creds.keys()].sort();
  }

  async close() {}
}
