import assert from 'node:assert/strict';
import test from 'node:test';
import {
  createLoadingTracker,
  hasSkipHeader,
  installGlobalFetchTracker,
  isApiRequestInput,
} from './loadingTracker';

test('loadingTracker tracks concurrent requests idempotently and clears cleanly', () => {
  const tracker = createLoadingTracker({
    requestDelayMs: 0,
    completeHoldMs: 5000,
    trickleIntervalMs: 5000,
  });

  assert.strictEqual(tracker.getSnapshot(), tracker.getSnapshot());
  assert.deepEqual(tracker.getSnapshot(), {
    activeRequests: 0,
    isNavigating: false,
    isLoading: false,
    visible: false,
    progress: 0,
  });

  const end1 = tracker.beginRequest();
  const end2 = tracker.beginRequest();
  assert.equal(tracker.getSnapshot().activeRequests, 2);
  assert.equal(tracker.getSnapshot().isLoading, true);
  assert.equal(tracker.getSnapshot().visible, true);

  // Calling end1 twice must be idempotent and leave activeRequests at 1
  end1();
  end1();
  assert.equal(tracker.getSnapshot().activeRequests, 1);
  assert.equal(tracker.getSnapshot().isLoading, true);

  end2();
  assert.equal(tracker.getSnapshot().activeRequests, 0);
  assert.equal(tracker.getSnapshot().isLoading, false);
  assert.equal(tracker.getSnapshot().progress, 1);

  tracker.reset();
});

test('loadingTracker tracks navigation transitions and waits for in-flight fetches', async () => {
  const tracker = createLoadingTracker({
    requestDelayMs: 0,
    completeHoldMs: 25,
    trickleIntervalMs: 5000,
  });

  tracker.startNavigation(30);
  assert.equal(tracker.getSnapshot().isNavigating, true);
  assert.equal(tracker.getSnapshot().visible, true);
  assert.ok(tracker.getSnapshot().progress >= 0.35);

  const endReq = tracker.beginRequest();
  await new Promise((r) => setTimeout(r, 45));
  // Navigation timer elapsed, but fetch is still open -> remains loading
  assert.equal(tracker.getSnapshot().isNavigating, false);
  assert.equal(tracker.getSnapshot().isLoading, true);
  assert.equal(tracker.getSnapshot().visible, true);

  endReq();
  assert.equal(tracker.getSnapshot().progress, 1);
  await new Promise((r) => setTimeout(r, 40));
  assert.equal(tracker.getSnapshot().visible, false);
  assert.equal(tracker.getSnapshot().progress, 0);
});

test('trackPromise resolves normally and decrements activeRequests when throwing', async () => {
  const tracker = createLoadingTracker({
    requestDelayMs: 0,
    completeHoldMs: 20,
    trickleIntervalMs: 5000,
  });

  const val = await tracker.trackPromise(async () => 'ok');
  assert.equal(val, 'ok');
  assert.equal(tracker.getSnapshot().activeRequests, 0);

  await assert.rejects(
    tracker.trackPromise(async () => {
      throw new Error('Laravel API timeout');
    }),
    /Laravel API timeout/
  );
  assert.equal(tracker.getSnapshot().activeRequests, 0);
  assert.equal(tracker.getSnapshot().isLoading, false);

  tracker.reset();
});

test('isApiRequestInput and hasSkipHeader filter non-API and opt-out requests accurately', () => {
  assert.equal(isApiRequestInput('/api/public/bootstrap'), true);
  assert.equal(isApiRequestInput('https://example.com/api/courses?page=2'), true);
  assert.equal(isApiRequestInput(new URL('http://localhost:3000/api/portal/overview')), true);
  assert.equal(isApiRequestInput('/images/aec-logo.png'), false);
  assert.equal(isApiRequestInput('/downloads/academy-lms-laravel13.zip'), false);

  assert.equal(hasSkipHeader('/api/auth/me', { headers: { 'X-Skip-Global-Loader': '1' } }), true);
  assert.equal(hasSkipHeader('/api/auth/me', { headers: new Headers({ 'x-skip-global-loader': 'true' }) }), true);
  assert.equal(hasSkipHeader('/api/auth/me', { headers: { 'X-Requested-With': 'XMLHttpRequest' } }), false);
});

test('installGlobalFetchTracker tracks API calls without double-patching and restores cleanly', async () => {
  const tracker = createLoadingTracker({
    requestDelayMs: 0,
    completeHoldMs: 20,
    trickleIntervalMs: 5000,
  });

  let sawLoadingDuringApiCall = false;
  let sawLoadingDuringAssetCall = false;
  const mockTarget = {
    fetch: (async (input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('/api/')) {
        sawLoadingDuringApiCall = tracker.getSnapshot().activeRequests === 1;
      } else {
        sawLoadingDuringAssetCall = tracker.getSnapshot().activeRequests > 0;
      }
      return new Response('{}', { status: 200 });
    }) as typeof fetch,
  };

  const uninstall = installGlobalFetchTracker(tracker, mockTarget);
  // Installing twice is a no-op
  const noopUninstall = installGlobalFetchTracker(tracker, mockTarget);

  await mockTarget.fetch('/api/public/courses');
  assert.equal(sawLoadingDuringApiCall, true);
  assert.equal(tracker.getSnapshot().activeRequests, 0);

  await mockTarget.fetch('/images/logo.png');
  assert.equal(sawLoadingDuringAssetCall, false);

  await mockTarget.fetch('/api/logs', { headers: { 'X-Skip-Global-Loader': '1' } });
  assert.equal(tracker.getSnapshot().activeRequests, 0);

  noopUninstall();
  uninstall();
  tracker.reset();
});
