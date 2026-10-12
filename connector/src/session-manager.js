import pino from 'pino';
import makeWASocket, {
  Browsers,
  DisconnectReason,
  fetchLatestBaileysVersion,
  makeCacheableSignalKeyStore,
} from '@whiskeysockets/baileys';
import { createAuthStore } from './auth/store.js';

const CHANNEL_ID_PATTERN = /^[A-Za-z0-9_-]{1,64}$/;
const PHONE_PATTERN = /^\d{8,15}$/;
/**
 * Indikator "mengetik" (UX): WhatsApp hanya menampilkannya ±10 detik tanpa penyegaran, padahal AI bisa butuh
 * beberapa detik. Selama aktif, `composing` diperbarui tiap TYPING_REFRESH_MS dan otomatis berhenti setelah
 * TYPING_MAX_MS (batas aman bila Laravel tidak pernah mengirim `paused`).
 */
const TYPING_REFRESH_MS = 8_000;
const TYPING_MAX_MS = 60_000;
/** Jeda minimum sebelum balasan terkirim walau indikator sudah terlihat lama. */
const MIN_AFTER_TYPING_MS = 300;

const SEEN_LIMIT = 5000;
const VERSION_TTL_MS = 60 * 60 * 1000;

// States in which a session is not running and may be started again.
const INACTIVE_STATES = new Set(['stopped', 'logged_out', 'qr_expired', 'failed']);

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const randomBetween = (min, max) => min + Math.floor(Math.random() * (max - min + 1));

export class ChannelError extends Error {
  constructor(code, message, status = 400) {
    super(message);
    this.code = code;
    this.status = status;
  }
}

/**
 * Manages one Baileys socket per channel. Every channel has its own auth
 * state directory, so a failure or reconnect in one channel never touches
 * another (PRD WA-MT-01..04).
 */
export class SessionManager {
  #config;
  #logger;
  #webhook;
  #authStore;
  #baileysLogger;
  #sessions = new Map();
  #version;
  #versionAt = 0;
  #shuttingDown = false;

  constructor({ config, logger, webhook, authStore }) {
    this.#config = config;
    this.#logger = logger;
    this.#webhook = webhook;
    // Gagal cepat saat boot bila konfigurasi penyimpanan auth tidak valid.
    this.#authStore = authStore ?? createAuthStore({ config, logger });
    // Baileys is verbose and can log sensitive protocol data; keep it quiet.
    this.#baileysLogger = pino({ level: config.logLevel === 'debug' ? 'debug' : 'warn' });
  }

  static isValidChannelId(channelId) {
    return typeof channelId === 'string' && CHANNEL_ID_PATTERN.test(channelId);
  }

  /** Re-open every session that has saved credentials (survives restarts). */
  async restoreAll() {
    for (const channelId of await this.#authStore.listPaired()) {
      if (!SessionManager.isValidChannelId(channelId)) continue;

      await this.start(channelId);
      await sleep(1000); // stagger to avoid a connection burst on boot
    }
  }

  async start(channelId) {
    this.#assertValidId(channelId);
    if (this.#shuttingDown) throw new ChannelError('SHUTTING_DOWN', 'Connector is shutting down.', 503);

    let session = this.#sessions.get(channelId);
    if (session && !INACTIVE_STATES.has(session.state)) {
      return this.#summary(session);
    }

    session = this.#newSession(channelId);
    this.#sessions.set(channelId, session);
    await this.#connect(session);
    return this.#summary(session);
  }

  /**
   * Stop a channel. With `logout` the WhatsApp device is unlinked and the
   * stored credentials are deleted (channel revocation, FR-009.6).
   */
  async stop(channelId, { logout = false } = {}) {
    this.#assertValidId(channelId);
    const session = this.#sessions.get(channelId);

    if (!session) {
      if (logout) await this.#removeAuth(channelId);
      return { channelId, state: 'stopped' };
    }

    session.stopping = true;
    clearTimeout(session.reconnectTimer);

    if (logout && session.sock && session.state === 'connected') {
      try {
        await session.sock.logout();
      } catch (error) {
        this.#logger.warn({ channelId, err: error.message }, 'logout failed, continuing cleanup');
      }
    }
    this.#closeSocket(session);

    session.state = 'stopped';
    session.qr = null;

    if (logout) {
      await this.#removeAuth(channelId);
      this.#sessions.delete(channelId);
    }
    await this.#emitStatus(session);
    return { channelId, state: 'stopped' };
  }

  list() {
    return [...this.#sessions.values()].map((session) => this.#summary(session));
  }

  get(channelId) {
    this.#assertValidId(channelId);
    const session = this.#sessions.get(channelId);
    if (!session) throw new ChannelError('CHANNEL_NOT_FOUND', 'Channel not found.', 404);
    return this.#summary(session);
  }

  getQr(channelId) {
    this.#assertValidId(channelId);
    const session = this.#sessions.get(channelId);
    if (!session) throw new ChannelError('CHANNEL_NOT_FOUND', 'Channel not found.', 404);
    return { channelId, state: session.state, qr: session.qr };
  }

  /**
   * Send a text message through the given channel only. The socket is always
   * resolved from the channel id, never from the recipient (FR-009.4).
   */
  async sendText(channelId, to, text) {
    this.#assertValidId(channelId);
    const session = this.#sessions.get(channelId);
    if (!session) throw new ChannelError('CHANNEL_NOT_FOUND', 'Channel not found.', 404);
    if (session.state !== 'connected' || !session.sock) {
      throw new ChannelError('CHANNEL_NOT_CONNECTED', `Channel is ${session.state}.`, 409);
    }
    if (typeof text !== 'string' || text.length === 0 || text.length > 4096) {
      throw new ChannelError('INVALID_TEXT', 'Text must be 1..4096 characters.', 422);
    }
    const jid = this.#toJid(to);

    // Serialise sends per channel and pace them (ban mitigation, FR-009.7).
    const run = session.sendChain.then(() => this.#pacedSend(session, jid, text));
    session.sendChain = run.catch(() => {});
    return run;
  }

  metrics() {
    const states = {};
    for (const session of this.#sessions.values()) {
      states[session.state] = (states[session.state] ?? 0) + 1;
    }
    const mem = process.memoryUsage();
    return {
      uptimeSeconds: Math.round(process.uptime()),
      sessions: this.#sessions.size,
      states,
      memoryMb: {
        rss: Math.round(mem.rss / 1048576),
        heapUsed: Math.round(mem.heapUsed / 1048576),
        external: Math.round(mem.external / 1048576),
      },
    };
  }

  /** Close sockets without logging out so credentials stay valid. */
  async shutdown() {
    this.#shuttingDown = true;
    for (const session of this.#sessions.values()) {
      session.stopping = true;
      clearTimeout(session.reconnectTimer);
      this.#closeSocket(session);
    }

    await this.#authStore.close().catch(() => {});
  }

  // ---------------------------------------------------------------- internals

  #assertValidId(channelId) {
    if (!SessionManager.isValidChannelId(channelId)) {
      throw new ChannelError('INVALID_CHANNEL_ID', 'channelId must match [A-Za-z0-9_-]{1,64}.', 422);
    }
  }

  #newSession(channelId) {
    return {
      channelId,
      sock: null,
      state: 'starting',
      qr: null,
      registered: false,
      me: null,
      attempts: 0,
      reconnectTimer: null,
      stopping: false,
      lastConnectedAt: null,
      lastDisconnectCode: null,
      sendChain: Promise.resolve(),
      lastSendAt: 0,
      seen: new Set(),
      seenOrder: [],
      typing: new Map(), // jid -> { timer, startedAt, stopAt }
    };
  }

  #summary(session) {
    return {
      channelId: session.channelId,
      state: session.state,
      hasQr: Boolean(session.qr),
      registered: session.registered,
      me: session.me,
      attempts: session.attempts,
      lastConnectedAt: session.lastConnectedAt,
      lastDisconnectCode: session.lastDisconnectCode,
    };
  }

  /** Hapus SEMUA kredensial channel dari penyimpanan auth (logout/revoke). */
  async #removeAuth(channelId) {
    await this.#authStore.remove(channelId);
  }

  async #getVersion() {
    if (this.#version && Date.now() - this.#versionAt < VERSION_TTL_MS) return this.#version;
    try {
      const { version } = await fetchLatestBaileysVersion();
      this.#version = version;
      this.#versionAt = Date.now();
    } catch (error) {
      this.#logger.warn({ err: error.message }, 'could not fetch latest WA version, using default');
    }
    return this.#version;
  }

  #closeSocket(session) {
    for (const entry of session.typing.values()) clearInterval(entry.timer);
    session.typing.clear();

    const sock = session.sock;
    session.sock = null;
    if (!sock) return;
    try {
      sock.ev.removeAllListeners('connection.update');
      sock.ev.removeAllListeners('creds.update');
      sock.ev.removeAllListeners('messages.upsert');
      sock.end(undefined);
    } catch (error) {
      this.#logger.debug({ err: error.message }, 'error while closing socket');
    }
  }

  async #connect(session) {
    const { channelId } = session;
    // Creds DAN Signal keys per channel (PRD 17.4), terenkripsi di MySQL pada produksi (DEC-17).
    const { state, saveCreds } = await this.#authStore.open(channelId);
    const version = await this.#getVersion();

    const sock = makeWASocket({
      ...(version ? { version } : {}),
      auth: {
        creds: state.creds,
        keys: makeCacheableSignalKeyStore(state.keys, this.#baileysLogger),
      },
      browser: Browsers.ubuntu('Velora'),
      logger: this.#baileysLogger,
      markOnlineOnConnect: false,
      syncFullHistory: false,
      generateHighQualityLinkPreview: false,
    });

    session.sock = sock;
    session.registered = Boolean(state.creds.registered);
    session.state = 'starting';

    sock.ev.on('creds.update', () => {
      saveCreds().catch((error) =>
        this.#logger.error({ channelId, err: error.message }, 'failed to persist credentials'),
      );
    });

    sock.ev.on('connection.update', (update) => {
      if (session.sock !== sock) return; // stale socket
      this.#onConnectionUpdate(session, sock, update).catch((error) =>
        this.#logger.error({ channelId, err: error.message }, 'connection.update handler failed'),
      );
    });

    sock.ev.on('messages.upsert', ({ messages, type }) => {
      if (session.sock !== sock || type !== 'notify') return;
      for (const message of messages) {
        this.#onInboundMessage(session, message).catch((error) =>
          this.#logger.error({ channelId, err: error.message }, 'inbound handler failed'),
        );
      }
    });
  }

  async #onConnectionUpdate(session, sock, update) {
    const { channelId } = session;
    const { connection, lastDisconnect, qr } = update;

    if (qr) {
      // Held in memory only. Never logged, never sent through the webhook.
      session.qr = qr;
      if (session.state !== 'qr') {
        session.state = 'qr';
        await this.#emitStatus(session);
      }
    }

    if (connection === 'open') {
      session.state = 'connected';
      session.qr = null;
      session.registered = true;
      session.attempts = 0;
      session.lastConnectedAt = new Date().toISOString();
      session.me = sock.user?.id ? sock.user.id.split(':')[0].split('@')[0] : null;
      this.#logger.info({ channelId }, 'channel connected');
      await this.#emitStatus(session);
      return;
    }

    if (connection !== 'close') return;

    const code = lastDisconnect?.error?.output?.statusCode ?? null;
    session.lastDisconnectCode = code;
    session.qr = null;
    this.#closeSocket(session);

    if (session.stopping) return;

    if (code === DisconnectReason.loggedOut) {
      // The user unlinked the device: credentials are now useless.
      session.state = 'logged_out';
      session.registered = false;
      await this.#removeAuth(channelId);
      this.#logger.warn({ channelId }, 'channel logged out, credentials removed');
      await this.#emitStatus(session);
      return;
    }

    if (code === DisconnectReason.timedOut && !session.registered) {
      // QR was never scanned; stop instead of looping forever.
      session.state = 'qr_expired';
      await this.#emitStatus(session);
      return;
    }

    this.#scheduleReconnect(session, code);
  }

  #scheduleReconnect(session, code) {
    const { channelId } = session;
    const { baseMs, maxMs, maxAttempts } = this.#config.reconnect;

    session.attempts += 1;
    if (session.attempts > maxAttempts) {
      session.state = 'failed';
      this.#logger.error({ channelId, code }, 'reconnect attempts exhausted');
      this.#emitStatus(session).catch(() => {});
      return;
    }

    // restartRequired happens right after pairing and is expected.
    const delay =
      code === DisconnectReason.restartRequired
        ? 500
        : Math.round(Math.min(maxMs, baseMs * 2 ** (session.attempts - 1)) * (0.5 + Math.random() / 2));

    session.state = 'reconnecting';
    this.#logger.warn({ channelId, code, attempt: session.attempts, delayMs: delay }, 'reconnect scheduled');
    this.#emitStatus(session).catch(() => {});

    session.reconnectTimer = setTimeout(() => {
      if (session.stopping || this.#shuttingDown) return;
      this.#connect(session).catch((error) => {
        this.#logger.error({ channelId, err: error.message }, 'reconnect failed');
        this.#scheduleReconnect(session, null);
      });
    }, delay);
  }

  async #onInboundMessage(session, message) {
    const key = message.key;
    if (!key?.id || !key.remoteJid || key.fromMe || !message.message) return;

    const jid = key.remoteJid;
    // MVP handles 1:1 chats only.
    if (jid.endsWith('@g.us') || jid.endsWith('@broadcast') || jid.endsWith('@newsletter')) return;

    // Idempotency: WhatsApp may redeliver; never forward the same id twice.
    if (session.seen.has(key.id)) return;
    session.seen.add(key.id);
    session.seenOrder.push(key.id);
    if (session.seenOrder.length > SEEN_LIMIT) {
      session.seen.delete(session.seenOrder.shift());
    }

    const text = message.message.conversation ?? message.message.extendedTextMessage?.text ?? null;
    if (!text) {
      this.#logger.debug({ channelId: session.channelId }, 'non-text inbound message ignored');
      return;
    }

    await this.#webhook.send({
      type: 'message.inbound',
      channelId: session.channelId,
      externalMessageId: key.id,
      from: jid,
      // Baileys v7 may use LID jids; the phone-number jid is provided here when known.
      fromAlt: key.remoteJidAlt ?? null,
      pushName: message.pushName ?? null,
      text,
      timestamp: Number(message.messageTimestamp) || Math.floor(Date.now() / 1000),
    });
  }

  async #pacedSend(session, jid, text) {
    const { delayMinMs, delayMaxMs, minGapMs } = this.#config.send;

    const sinceLast = Date.now() - session.lastSendAt;
    if (sinceLast < minGapMs) await sleep(minGapMs - sinceLast);

    const sock = session.sock;
    if (!sock || session.state !== 'connected') {
      throw new ChannelError('CHANNEL_NOT_CONNECTED', `Channel is ${session.state}.`, 409);
    }

    // Bila indikator mengetik sudah tampil (mis. selama AI bekerja), jeda "mengetik" dikurangi sebesar waktu itu
    // supaya pelanggan tidak menunggu dobel; tetap ada jeda minimum agar balasan tidak terasa seketika.
    const alreadyTypingMs = session.typing.has(jid) ? Date.now() - session.typing.get(jid).startedAt : 0;
    await this.#startTyping(session, jid);
    await sleep(Math.max(MIN_AFTER_TYPING_MS, randomBetween(delayMinMs, delayMaxMs) - alreadyTypingMs));

    let sent;
    try {
      sent = await sock.sendMessage(jid, { text });
    } finally {
      await this.#stopTyping(session, jid, true);
    }
    session.lastSendAt = Date.now();
    return { channelId: session.channelId, messageId: sent?.key?.id ?? null, to: jid };
  }

  /**
   * Menampilkan/menghentikan indikator "mengetik" kepada pelanggan. Tidak dipaced dan tidak mengantre di belakang
   * pengiriman, supaya indikator muncul segera. Best effort: kegagalan presence tidak boleh mengganggu alur utama.
   */
  async setPresence(channelId, to, state = 'composing') {
    this.#assertValidId(channelId);
    const session = this.#sessions.get(channelId);
    if (!session) throw new ChannelError('CHANNEL_NOT_FOUND', 'Channel not found.', 404);
    if (session.state !== 'connected' || !session.sock) {
      throw new ChannelError('CHANNEL_NOT_CONNECTED', `Channel is ${session.state}.`, 409);
    }
    if (state !== 'composing' && state !== 'paused') {
      throw new ChannelError('INVALID_STATE', 'state must be composing or paused.', 422);
    }
    const jid = this.#toJid(to);

    if (state === 'composing') await this.#startTyping(session, jid);
    else await this.#stopTyping(session, jid, true);

    return { channelId, state, to: jid };
  }

  async #presence(session, jid, state) {
    const sock = session.sock;
    if (!sock) return;
    try {
      // presenceSubscribe dibutuhkan agar update presence ke kontak tersebut tampil di sisi mereka.
      if (state === 'composing') await sock.presenceSubscribe(jid);
      await sock.sendPresenceUpdate(state, jid);
    } catch (error) {
      this.#logger.debug({ channelId: session.channelId, err: error.message }, 'presence update failed (best effort)');
    }
  }

  async #startTyping(session, jid) {
    const stopAt = Date.now() + TYPING_MAX_MS;
    const existing = session.typing.get(jid);
    if (existing) {
      existing.stopAt = stopAt; // sudah berjalan: cukup perpanjang
      return;
    }

    const entry = { timer: null, startedAt: Date.now(), stopAt };
    session.typing.set(jid, entry);
    await this.#presence(session, jid, 'composing');

    // Dihentikan saat menunggu presence pertama: jangan buat timer yatim.
    if (session.typing.get(jid) !== entry) return;

    entry.timer = setInterval(() => {
      if (Date.now() >= entry.stopAt || !session.sock || session.state !== 'connected') {
        this.#stopTyping(session, jid, false).catch(() => {});
        return;
      }
      this.#presence(session, jid, 'composing').catch(() => {});
    }, TYPING_REFRESH_MS);
    entry.timer.unref?.();
  }

  async #stopTyping(session, jid, sendPaused) {
    const entry = session.typing.get(jid);
    if (entry) {
      clearInterval(entry.timer);
      session.typing.delete(jid);
    }
    if (sendPaused) await this.#presence(session, jid, 'paused');
  }

  #toJid(to) {
    if (typeof to !== 'string') throw new ChannelError('INVALID_RECIPIENT', 'Recipient is required.', 422);
    if (to.includes('@')) {
      if (!/^[\w.:-]+@(s\.whatsapp\.net|lid)$/.test(to)) {
        throw new ChannelError('INVALID_RECIPIENT', 'Unsupported recipient jid.', 422);
      }
      return to;
    }
    const digits = to.replace(/\D+/g, '');
    if (!PHONE_PATTERN.test(digits)) {
      throw new ChannelError('INVALID_RECIPIENT', 'Phone number must be 8..15 digits (E.164 without +).', 422);
    }
    return `${digits}@s.whatsapp.net`;
  }

  async #emitStatus(session) {
    await this.#webhook.send({
      type: 'channel.status',
      channelId: session.channelId,
      state: session.state,
      me: session.me,
      lastDisconnectCode: session.lastDisconnectCode,
      at: new Date().toISOString(),
    });
  }
}
