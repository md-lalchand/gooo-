import assert from 'node:assert/strict';
import test from 'node:test';
import type { Request } from 'express';
import { getClientIp } from './security';

test('getClientIp ignores a client-supplied X-Forwarded-For header', () => {
  const request = {
    headers: { 'x-forwarded-for': '203.0.113.99' },
    ip: '198.51.100.10',
    socket: { remoteAddress: '127.0.0.1' },
  } as unknown as Request;

  assert.equal(getClientIp(request), '198.51.100.10');
});

test('getClientIp falls back to the socket address when Express has no resolved IP', () => {
  const request = {
    headers: { 'x-forwarded-for': '203.0.113.99' },
    ip: undefined,
    socket: { remoteAddress: '127.0.0.1' },
  } as unknown as Request;

  assert.equal(getClientIp(request), '127.0.0.1');
});
