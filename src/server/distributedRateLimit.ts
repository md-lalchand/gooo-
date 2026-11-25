import { createClient, type RedisClientType } from 'redis';
import type { NextFunction, Request, Response } from 'express';
import { consumePublicRateLimit } from './publicRedaction';

let client: RedisClientType | null = null;
let connecting: Promise<RedisClientType> | null = null;

async function getRedisClient(): Promise<RedisClientType> {
  const url = (process.env.REDIS_URL ?? '').trim();
  if (!url) throw new Error('REDIS_URL is required for shared production API rate limiting.');
  if (client?.isOpen) return client;
  if (!connecting) {
    client = createClient({ url });
    client.on('error', (error) => {
      console.error('[SECURITY] Redis rate-limit connection error:', error.message);
    });
    connecting = client.connect().then(() => client as RedisClientType).catch((error) => {
      connecting = null;
      client = null;
      throw error;
    });
  }
  return connecting;
}

const INCREMENT_WITH_TTL = `
local count = redis.call('INCR', KEYS[1])
if count == 1 then
  redis.call('PEXPIRE', KEYS[1], ARGV[1])
end
local ttl = redis.call('PTTL', KEYS[1])
return { count, ttl }
`;

export function distributedApiRateLimit(
  bucketForRequest: (req: Request) => string,
  limitForRequest: (req: Request) => number,
  windowMs = 60_000
) {
  return async (req: Request, res: Response, next: NextFunction): Promise<void> => {
    const bucket = bucketForRequest(req);
    const limit = limitForRequest(req);

    if (process.env.NODE_ENV !== 'production' && !process.env.REDIS_URL) {
      const local = consumePublicRateLimit(bucket, limit, windowMs);
      if (!local.allowed) {
        res.setHeader('Retry-After', String(local.retryAfterSeconds));
        res.status(429).json({ error: 'API rate limit reached. Please retry later.' });
        return;
      }
      next();
      return;
    }

    try {
      const redis = await getRedisClient();
      const result = await redis.eval(INCREMENT_WITH_TTL, {
        keys: [`api-rate-limit:${bucket}`],
        arguments: [String(windowMs)],
      }) as [number | string, number | string];
      const count = Number(result[0]);
      const ttl = Number(result[1]);
      if (!Number.isFinite(count) || count > limit) {
        res.setHeader('Retry-After', String(Math.max(1, Math.ceil(Math.max(0, ttl) / 1000))));
        res.status(429).json({ error: 'API rate limit reached. Please retry later.' });
        return;
      }
      next();
    } catch (error) {
      console.error('[SECURITY] Shared API rate limiter unavailable:', error);
      // Never silently fall back to per-process limits in production: that would
      // make limits trivially bypassable by spreading traffic across instances.
      res.status(503).json({ error: 'API protection is temporarily unavailable.' });
    }
  };
}
