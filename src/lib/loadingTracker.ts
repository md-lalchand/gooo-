export interface LoadingSnapshot {
  activeRequests: number;
  isNavigating: boolean;
  isLoading: boolean;
  visible: boolean;
  progress: number;
}

export interface LoadingTrackerOptions {
  requestDelayMs?: number;
  completeHoldMs?: number;
  trickleIntervalMs?: number;
}

export type LoadingListener = (snapshot: LoadingSnapshot) => void;

const SKIP_HEADER = 'x-skip-global-loader';

export function isApiRequestInput(input: RequestInfo | URL): boolean {
  let rawUrl = '';
  if (typeof input === 'string') {
    rawUrl = input;
  } else if (input instanceof URL) {
    rawUrl = input.pathname + input.search;
  } else if (typeof Request !== 'undefined' && input instanceof Request) {
    rawUrl = input.url;
  } else if (input && typeof (input as { url?: unknown }).url === 'string') {
    rawUrl = String((input as { url: string }).url);
  }
  if (!rawUrl) return false;

  try {
    if (rawUrl.startsWith('/')) {
      return rawUrl === '/api' || rawUrl.startsWith('/api/');
    }
    const parsed = new URL(rawUrl, 'http://localhost');
    return parsed.pathname === '/api' || parsed.pathname.startsWith('/api/');
  } catch {
    return rawUrl.includes('/api/');
  }
}

export function hasSkipHeader(input: RequestInfo | URL, init?: RequestInit): boolean {
  const checkHeaders = (headers?: HeadersInit): boolean => {
    if (!headers) return false;
    if (typeof Headers !== 'undefined' && headers instanceof Headers) {
      return headers.has(SKIP_HEADER);
    }
    if (Array.isArray(headers)) {
      return headers.some(([k]) => String(k).toLowerCase() === SKIP_HEADER);
    }
    return Object.keys(headers).some((k) => k.toLowerCase() === SKIP_HEADER);
  };

  if (checkHeaders(init?.headers)) return true;
  if (typeof Request !== 'undefined' && input instanceof Request) {
    return input.headers.has(SKIP_HEADER);
  }
  return false;
}

export function createLoadingTracker(options: LoadingTrackerOptions = {}) {
  const requestDelayMs = options.requestDelayMs ?? 45;
  const completeHoldMs = options.completeHoldMs ?? 180;
  const trickleIntervalMs = options.trickleIntervalMs ?? 220;

  let activeRequests = 0;
  let isNavigating = false;
  let visible = false;
  let progress = 0;

  let delayTimer: ReturnType<typeof setTimeout> | null = null;
  let navTimer: ReturnType<typeof setTimeout> | null = null;
  let completeTimer: ReturnType<typeof setTimeout> | null = null;
  let trickleTimer: ReturnType<typeof setInterval> | null = null;

  const listeners = new Set<LoadingListener>();

  let cachedSnapshot: LoadingSnapshot = {
    activeRequests,
    isNavigating,
    isLoading: false,
    visible,
    progress,
  };

  const getSnapshot = (): LoadingSnapshot => cachedSnapshot;

  const emit = () => {
    const nextIsLoading = activeRequests > 0 || isNavigating;
    if (
      cachedSnapshot.activeRequests === activeRequests &&
      cachedSnapshot.isNavigating === isNavigating &&
      cachedSnapshot.isLoading === nextIsLoading &&
      cachedSnapshot.visible === visible &&
      cachedSnapshot.progress === progress
    ) {
      return;
    }
    cachedSnapshot = {
      activeRequests,
      isNavigating,
      isLoading: nextIsLoading,
      visible,
      progress,
    };
    for (const listener of listeners) {
      listener(cachedSnapshot);
    }
  };

  const stopTrickle = () => {
    if (trickleTimer) {
      clearInterval(trickleTimer);
      trickleTimer = null;
    }
  };

  const clearDelay = () => {
    if (delayTimer) {
      clearTimeout(delayTimer);
      delayTimer = null;
    }
  };

  const clearComplete = () => {
    if (completeTimer) {
      clearTimeout(completeTimer);
      completeTimer = null;
    }
  };

  const startTrickle = () => {
    if (trickleTimer) return;
    trickleTimer = setInterval(() => {
      if (activeRequests === 0 && !isNavigating) {
        stopTrickle();
        return;
      }
      if (progress < 0.92) {
        const remaining = 0.94 - progress;
        const step = Math.max(0.02, remaining * 0.22);
        progress = Math.min(0.94, Number((progress + step).toFixed(3)));
        emit();
      }
    }, trickleIntervalMs);
  };

  const showNow = (initialProgress = 0.28) => {
    clearComplete();
    clearDelay();
    visible = true;
    progress = Math.max(progress, initialProgress);
    startTrickle();
    emit();
  };

  const maybeComplete = () => {
    if (activeRequests > 0 || isNavigating) return;
    clearDelay();
    stopTrickle();

    if (!visible) {
      progress = 0;
      emit();
      return;
    }

    progress = 1;
    emit();

    clearComplete();
    completeTimer = setTimeout(() => {
      completeTimer = null;
      if (activeRequests === 0 && !isNavigating) {
        visible = false;
        progress = 0;
        emit();
      }
    }, completeHoldMs);
  };

  const beginRequest = (): (() => void) => {
    activeRequests += 1;
    clearComplete();

    if (visible) {
      progress = Math.max(progress, 0.32);
      emit();
    } else if (requestDelayMs <= 0) {
      showNow(0.28);
    } else if (!delayTimer) {
      delayTimer = setTimeout(() => {
        delayTimer = null;
        if (activeRequests > 0 || isNavigating) {
          showNow(0.28);
        }
      }, requestDelayMs);
    } else {
      emit();
    }

    let ended = false;
    return () => {
      if (ended) return;
      ended = true;
      activeRequests = Math.max(0, activeRequests - 1);
      if (activeRequests === 0 && !isNavigating) {
        maybeComplete();
      } else {
        emit();
      }
    };
  };

  const startNavigation = (minDurationMs = 240) => {
    isNavigating = true;
    clearComplete();
    clearDelay();
    if (navTimer) {
      clearTimeout(navTimer);
      navTimer = null;
    }
    showNow(0.35);

    navTimer = setTimeout(() => {
      navTimer = null;
      isNavigating = false;
      if (activeRequests === 0) {
        maybeComplete();
      } else {
        emit();
      }
    }, Math.max(0, minDurationMs));
  };

  const trackPromise = async <T,>(fn: () => Promise<T>): Promise<T> => {
    const done = beginRequest();
    try {
      return await fn();
    } finally {
      done();
    }
  };

  const subscribe = (listener: LoadingListener): (() => void) => {
    listeners.add(listener);
    return () => {
      listeners.delete(listener);
    };
  };

  const reset = () => {
    clearDelay();
    clearComplete();
    stopTrickle();
    if (navTimer) {
      clearTimeout(navTimer);
      navTimer = null;
    }
    activeRequests = 0;
    isNavigating = false;
    visible = false;
    progress = 0;
    emit();
  };

  return {
    beginRequest,
    startNavigation,
    trackPromise,
    subscribe,
    getSnapshot,
    reset,
  };
}

export type LoadingTracker = ReturnType<typeof createLoadingTracker>;

export const globalLoadingTracker = createLoadingTracker();

const PATCHED_FETCH_SYMBOL = Symbol.for('edupro.globalLoadingPatchedFetch');

type FetchTarget = {
  fetch: typeof fetch & { [PATCHED_FETCH_SYMBOL]?: boolean };
};

export function installGlobalFetchTracker(
  tracker: LoadingTracker = globalLoadingTracker,
  target: FetchTarget | undefined = typeof window !== 'undefined' ? window : undefined
): () => void {
  if (!target || typeof target.fetch !== 'function') {
    return () => {};
  }
  if (target.fetch[PATCHED_FETCH_SYMBOL]) {
    return () => {};
  }

  const originalFetch = target.fetch.bind(target);
  const wrappedFetch = (async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    if (!isApiRequestInput(input) || hasSkipHeader(input, init)) {
      return originalFetch(input, init);
    }
    const done = tracker.beginRequest();
    try {
      return await originalFetch(input, init);
    } finally {
      done();
    }
  }) as typeof fetch & { [PATCHED_FETCH_SYMBOL]?: boolean };

  wrappedFetch[PATCHED_FETCH_SYMBOL] = true;
  target.fetch = wrappedFetch;

  return () => {
    if (target.fetch === wrappedFetch) {
      target.fetch = originalFetch as typeof fetch & { [PATCHED_FETCH_SYMBOL]?: boolean };
    }
  };
}
