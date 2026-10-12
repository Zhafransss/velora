import fs from 'node:fs/promises';
import path from 'node:path';
import pino from 'pino';
import makeWASocket, {
  Browsers,
  DisconnectReason,
  fetchLatestBaileysVersion,
  makeCacheableSignalKeyStore,
  useMultiFileAuthState,
} from '@whiskeysockets/baileys';

const CHANNEL_ID_PATTERN = /^[A-Za-z0-9_-]{1,64}$/;
const PHONE_PATTERN = /^\d{8,15}$/;
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
  #baileysLogger;
  #sessions = new Map();
  #version;
  #versionAt = 0;
  #shuttingDown = false;

  constructor({ config, logger, webhook }) {
    this.#config = config;
    this.#logger = logger;
    this.#webhook = webhook;
    // Baileys is verbose and can log sensitive protocol data; keep it quiet.
    this.#baileysLogger = pino({ level: config.logLevel === 'debug' ? 'debug' : 'warn' });
  }

  static isValidChannelId(channelId) {
    return typeof channelId === 'string' && CHANNEL_ID_PATTERN.test(channelId);
  }

  /** Re-open every session that has saved credentials (survives restarts). */
  async restoreAll() {
    await fs.mkdir(this.#config.authDir, { recursive: true });
    const entries = await fs.readdir(this.#config.authDir, { withFileTypes: true });

    for (const entry of entries) {
      if (!entry.isDirectory() || !SessionManager.isValidChannelId(entry.name)) continue;
      try {
        await fs.access(path.join(this.#config.authDir, entry.name, 'creds.json'));
      } catch {
        continue; // never paired, nothing to restore
      }
      await this.start(entry.name);
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
      if (logout) await this.#removeAuthDir(channelId);
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
      await this.#removeAuthDir(channelId);
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

  #authPath(channelId) {
    const resolved = path.resolve(this.#config.authDir, channelId);
    if (!resolved.startsWith(this.#config.authDir + path.sep)) {
      throw new ChannelError('INVALID_CHANNEL_ID', 'Invalid channel path.', 422);
    }
    return resolved;
  }

  async #removeAuthDir(channelId) {
    await fs.rm(this.#authPath(channelId), { recursive: true, force: true });
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
    const authPath = this.#authPath(channelId);
    await fs.mkdir(authPath, { recursive: true });

    // Persists creds AND Signal keys; per-channel directory (PRD 17.4).
    const { state, saveCreds } = await useMultiFileAuthState(authPath);
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
      await this.#removeAuthDir(channelId);
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

    try {
      await sock.sendPresenceUpdate('composing', jid);
    } catch {
      // presence is best-effort
    }
    await sleep(randomBetween(delayMinMs, delayMaxMs));

    const sent = await sock.sendMessage(jid, { text });
    session.lastSendAt = Date.now();
    return { channelId: session.channelId, messageId: sent?.key?.id ?? null, to: jid };
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
