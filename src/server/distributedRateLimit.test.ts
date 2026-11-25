import assert from 'node:assert/strict';
import test from 'node:test';
import { assertProductionRateLimitReady } from './distributedRateLimit';

test('production refuses to start when shared Redis rate limiting is not configured', async () => {
  const previousEnv = process.env.NODE_ENV;
  const previousRedisUrl = process.env.REDIS_URL;
  process.env.NODE_ENV = 'production';
  delete process.env.REDIS_URL;

  try {
    await assert.rejects(
      assertProductionRateLimitReady(),
      /REDIS_URL is required in production/
    );
  } finally {
    if (previousEnv === undefined) delete process.env.NODE_ENV;
    else process.env.NODE_ENV = previousEnv;
    if (previousRedisUrl === undefined) delete process.env.REDIS_URL;
    else process.env.REDIS_URL = previousRedisUrl;
  }
});

test('non-production environments may use the local rate-limit fallback', async () => {
  const previousEnv = process.env.NODE_ENV;
  const previousRedisUrl = process.env.REDIS_URL;
  process.env.NODE_ENV = 'test';
  delete process.env.REDIS_URL;

  try {
    await assert.doesNotReject(assertProductionRateLimitReady());
  } finally {
    if (previousEnv === undefined) delete process.env.NODE_ENV;
    else process.env.NODE_ENV = previousEnv;
    if (previousRedisUrl === undefined) delete process.env.REDIS_URL;
    else process.env.REDIS_URL = previousRedisUrl;
  }
});
