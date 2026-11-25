import assert from 'node:assert/strict';
import test from 'node:test';
import type { Request, Response } from 'express';
import { getClientIp, setAuthSessionCookie, clearAuthSessionCookie } from './security';

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


test('session cookies ignore spoofed X-Forwarded-Proto when Express does not trust the proxy', () => {
  const headers: Record<string, string> = {};
  const request = {
    secure: false,
    headers: { 'x-forwarded-proto': 'https' },
  } as unknown as Request;
  const response = {
    setHeader: (name: string, value: string) => { headers[name] = value; },
  } as unknown as Response;

  setAuthSessionCookie(request, response, 'test-token');
  assert.match(headers['Set-Cookie'], /SameSite=Lax/);
  assert.doesNotMatch(headers['Set-Cookie'], /; Secure/);
  assert.doesNotMatch(headers['Set-Cookie'], /SameSite=None/);
});

test('session cookies use secure attributes when Express confirms HTTPS through trusted proxy config', () => {
  const headers: Record<string, string> = {};
  const request = {
    secure: true,
    headers: { 'x-forwarded-proto': 'https' },
  } as unknown as Request;
  const response = {
    setHeader: (name: string, value: string) => { headers[name] = value; },
  } as unknown as Response;

  setAuthSessionCookie(request, response, 'test-token');
  assert.match(headers['Set-Cookie'], /SameSite=None/);
  assert.match(headers['Set-Cookie'], /; Secure/);

  clearAuthSessionCookie(request, response);
  assert.match(headers['Set-Cookie'], /SameSite=None/);
  assert.match(headers['Set-Cookie'], /; Secure/);
});
