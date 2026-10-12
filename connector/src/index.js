import process from 'node:process';
import pino from 'pino';
import { loadConfig } from './config.js';
import { createServer, SessionManager } from './server.js';
import { createWebhook } from './webhook.js';

const config = loadConfig();
const logger = pino({ level: config.logLevel, redact: ['qr', '*.qr', 'text', '*.text', 'token'] });

if (config.host !== '127.0.0.1' && config.host !== 'localhost' && config.host !== '::1') {
  logger.warn({ host: config.host }, 'connector is not bound to localhost; make sure a firewall protects it');
}

const webhook = createWebhook({ url: config.webhookUrl, secret: config.webhookSecret }, logger);
const manager = new SessionManager({ config, logger, webhook });
const server = createServer({ config, manager, logger });

server.listen(config.port, config.host, () => {
  logger.info({ host: config.host, port: config.port, webhook: webhook.enabled }, 'connector listening');
  manager
    .restoreAll()
    .then(() => logger.info('saved sessions restored'))
    .catch((error) => logger.error({ err: error.message }, 'failed to restore sessions'));
});

async function shutdown(signal) {
  logger.info({ signal }, 'shutting down');
  await manager.shutdown();
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 5000).unref();
}

process.on('SIGINT', () => shutdown('SIGINT'));
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('unhandledRejection', (reason) => {
  logger.error({ err: String(reason) }, 'unhandled rejection');
});
