import assert from 'node:assert/strict';
import test from 'node:test';
import { getClientIp } from './security';

test('uses Express resolved client IP instead of an untrusted forwarded header', () => {
  const req = {
    headers: { 'x-forwarded-for': '198.51.100.99' },
    ip: '203.0.113.12',
    socket: { remoteAddress: '10.0.0.2' },
  } as never;
  assert.equal(getClientIp(req), '203.0.113.12');
});

test('falls back to the socket address when Express has no resolved IP', () => {
  const req = {
    headers: { 'x-forwarded-for': '198.51.100.99' },
    ip: undefined,
    socket: { remoteAddress: '10.0.0.2' },
  } as never;
  assert.equal(getClientIp(req), '10.0.0.2');
});
