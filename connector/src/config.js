import path from 'node:path';
import process from 'node:process';

const int = (name, fallback) => {
  const raw = process.env[name];
  if (raw === undefined || raw === '') return fallback;
  const value = Number.parseInt(raw, 10);
  if (Number.isNaN(value)) throw new Error(`Invalid integer for ${name}: ${raw}`);
  return value;
};

export function loadConfig() {
  const token = process.env.CONNECTOR_TOKEN ?? '';
  if (token.length < 32) {
    throw new Error('CONNECTOR_TOKEN is required and must be at least 32 characters.');
  }

  const webhookUrl = process.env.WEBHOOK_URL ?? '';
  const webhookSecret = process.env.WEBHOOK_SECRET ?? '';
  if (webhookUrl && webhookSecret.length < 32) {
    throw new Error('WEBHOOK_SECRET (min 32 chars) is required when WEBHOOK_URL is set.');
  }

  const sendDelayMin = int('SEND_DELAY_MIN_MS', 1500);
  const sendDelayMax = int('SEND_DELAY_MAX_MS', 4000);
  if (sendDelayMax < sendDelayMin) {
    throw new Error('SEND_DELAY_MAX_MS must be >= SEND_DELAY_MIN_MS.');
  }

  return {
    host: process.env.CONNECTOR_HOST ?? '127.0.0.1',
    port: int('CONNECTOR_PORT', 3100),
    token,
    authDir: path.resolve(process.env.AUTH_DIR ?? './auth'),
    webhookUrl,
    webhookSecret,
    reconnect: {
      baseMs: int('RECONNECT_BASE_MS', 2000),
      maxMs: int('RECONNECT_MAX_MS', 60000),
      maxAttempts: int('RECONNECT_MAX_ATTEMPTS', 10),
    },
    send: {
      delayMinMs: sendDelayMin,
      delayMaxMs: sendDelayMax,
      minGapMs: int('SEND_MIN_GAP_MS', 2000),
    },
    logLevel: process.env.LOG_LEVEL ?? 'info',
  };
}
