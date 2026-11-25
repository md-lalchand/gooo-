import assert from 'node:assert/strict';
import test from 'node:test';
import { resolvePaymentWebhookSecret } from './paymentWebhookSecurity';

test('prefers the gateway-specific integration secret', () => {
  assert.equal(
    resolvePaymentWebhookSecret(' gateway-secret ', 'environment-secret', 'production', null),
    'gateway-secret'
  );
});

test('uses the dedicated environment secret when no gateway secret exists', () => {
  assert.equal(
    resolvePaymentWebhookSecret('', ' environment-secret ', 'production', null),
    'environment-secret'
  );
});

test('fails closed in production when no dedicated webhook secret exists', () => {
  assert.throws(
    () => resolvePaymentWebhookSecret('', '', 'production', 'session-derived-secret'),
    /dedicated PAYMENT_WEBHOOK_SECRET/
  );
});

test('allows an explicit development-only signing secret outside production', () => {
  assert.equal(
    resolvePaymentWebhookSecret('', '', 'development', 'local-sandbox-secret'),
    'local-sandbox-secret'
  );
});

test('does not silently fall back when no secret is available', () => {
  assert.throws(
    () => resolvePaymentWebhookSecret('', '', 'test', ''),
    /No payment webhook signing secret/
  );
});
