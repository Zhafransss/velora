import crypto from 'node:crypto';

/**
 * Posts connector events to Laravel. The body is signed so Laravel can verify
 * the sender: signature = HMAC-SHA256(secret, `${timestamp}.${body}`).
 * Laravel must also reject stale timestamps (replay protection).
 */
export function createWebhook({ url, secret }, logger) {
  if (!url) {
    return {
      enabled: false,
      async send(event) {
        // PoC mode: no Laravel yet, just log metadata (never message content).
        logger.info({ type: event.type, channelId: event.channelId }, 'webhook disabled, event dropped');
      },
    };
  }

  return {
    enabled: true,
    async send(event) {
      const body = JSON.stringify(event);
      const timestamp = Math.floor(Date.now() / 1000).toString();
      const signature = crypto
        .createHmac('sha256', secret)
        .update(`${timestamp}.${body}`)
        .digest('hex');

      try {
        const response = await fetch(url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Velora-Timestamp': timestamp,
            'X-Velora-Signature': signature,
          },
          body,
          signal: AbortSignal.timeout(10_000),
        });
        if (!response.ok) {
          logger.warn({ status: response.status, type: event.type }, 'webhook rejected');
        }
      } catch (error) {
        logger.error({ err: error.message, type: event.type }, 'webhook failed');
      }
    },
  };
}
