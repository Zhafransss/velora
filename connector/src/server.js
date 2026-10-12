import crypto from 'node:crypto';
import http from 'node:http';
import { ChannelError, SessionManager } from './session-manager.js';

const MAX_BODY_BYTES = 64 * 1024;

function safeEqual(a, b) {
  const left = Buffer.from(a);
  const right = Buffer.from(b);
  return left.length === right.length && crypto.timingSafeEqual(left, right);
}

function send(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) });
  res.end(body);
}

async function readJson(req) {
  const chunks = [];
  let size = 0;
  for await (const chunk of req) {
    size += chunk.length;
    if (size > MAX_BODY_BYTES) throw new ChannelError('PAYLOAD_TOO_LARGE', 'Body too large.', 413);
    chunks.push(chunk);
  }
  if (size === 0) return {};
  try {
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
  } catch {
    throw new ChannelError('INVALID_JSON', 'Body must be valid JSON.', 400);
  }
}

/**
 * Internal API used only by Laravel (localhost + Bearer token).
 *
 *   GET    /health                       (no auth)
 *   GET    /metrics
 *   GET    /channels
 *   POST   /channels/:id/start
 *   GET    /channels/:id
 *   GET    /channels/:id/qr
 *   POST   /channels/:id/send            { to, text }
 *   POST   /channels/:id/presence        { to, state: 'composing' | 'paused' }  (indikator "mengetik")
 *   DELETE /channels/:id                 ?logout=1 to unlink and delete credentials
 */
export function createServer({ config, manager, logger }) {
  return http.createServer(async (req, res) => {
    const requestId = crypto.randomUUID();
    try {
      const url = new URL(req.url, 'http://localhost');
      const parts = url.pathname.split('/').filter(Boolean);

      if (req.method === 'GET' && url.pathname === '/health') {
        return send(res, 200, { status: 'ok' });
      }

      const header = req.headers.authorization ?? '';
      const provided = header.startsWith('Bearer ') ? header.slice(7) : '';
      if (!provided || !safeEqual(provided, config.token)) {
        return send(res, 401, { error: { code: 'UNAUTHORIZED', message: 'Invalid token.', request_id: requestId } });
      }

      if (req.method === 'GET' && url.pathname === '/metrics') {
        return send(res, 200, manager.metrics());
      }

      if (parts[0] === 'channels') {
        const channelId = parts[1];

        if (parts.length === 1 && req.method === 'GET') {
          return send(res, 200, { channels: manager.list() });
        }
        if (parts.length === 3 && parts[2] === 'start' && req.method === 'POST') {
          return send(res, 200, await manager.start(channelId));
        }
        if (parts.length === 2 && req.method === 'GET') {
          return send(res, 200, manager.get(channelId));
        }
        if (parts.length === 3 && parts[2] === 'qr' && req.method === 'GET') {
          return send(res, 200, manager.getQr(channelId));
        }
        if (parts.length === 3 && parts[2] === 'send' && req.method === 'POST') {
          const body = await readJson(req);
          return send(res, 200, await manager.sendText(channelId, body.to, body.text));
        }
        if (parts.length === 3 && parts[2] === 'presence' && req.method === 'POST') {
          const body = await readJson(req);
          return send(res, 200, await manager.setPresence(channelId, body.to, body.state ?? 'composing'));
        }
        if (parts.length === 2 && req.method === 'DELETE') {
          return send(res, 200, await manager.stop(channelId, { logout: url.searchParams.get('logout') === '1' }));
        }
      }

      return send(res, 404, { error: { code: 'NOT_FOUND', message: 'Route not found.', request_id: requestId } });
    } catch (error) {
      if (error instanceof ChannelError) {
        return send(res, error.status, { error: { code: error.code, message: error.message, request_id: requestId } });
      }
      logger.error({ requestId, err: error.message }, 'unhandled request error');
      return send(res, 500, { error: { code: 'INTERNAL_ERROR', message: 'Internal error.', request_id: requestId } });
    }
  });
}

export { SessionManager };
