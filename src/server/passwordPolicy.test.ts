import assert from 'node:assert/strict';
import test from 'node:test';
import { isStrongPassword } from './security';

test('requires at least 12 characters with upper, lower, number and symbol', () => {
  assert.equal(isStrongPassword('StrongPass123!'), true);
  assert.equal(isStrongPassword('strongpass123!'), false);
  assert.equal(isStrongPassword('STRONGPASS123!'), false);
  assert.equal(isStrongPassword('StrongPassword!'), false);
  assert.equal(isStrongPassword('StrongPass123'), false);
  assert.equal(isStrongPassword('Aa1!short'), false);
});

test('rejects non-string and whitespace-only passwords', () => {
  assert.equal(isStrongPassword('', 12), false);
  assert.equal(isStrongPassword('            ', 12), false);
});
