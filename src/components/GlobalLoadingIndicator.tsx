import { Loader2 } from 'lucide-react';
import { useEffect, useState, useSyncExternalStore } from 'react';
import {
  globalLoadingTracker,
  type LoadingSnapshot,
  type LoadingTracker,
} from '../lib/loadingTracker';

interface GlobalLoadingIndicatorProps {
  tracker?: LoadingTracker;
  isBootstrapLoading?: boolean;
  statusDelayMs?: number;
}

export function GlobalLoadingIndicator({
  tracker = globalLoadingTracker,
  isBootstrapLoading = false,
  statusDelayMs = 280,
}: GlobalLoadingIndicatorProps) {
  const snapshot: LoadingSnapshot = useSyncExternalStore(
    tracker.subscribe,
    tracker.getSnapshot,
    tracker.getSnapshot
  );

  const isLoading = snapshot.isLoading || isBootstrapLoading;
  const showBar = snapshot.visible || isBootstrapLoading;
  const rawProgress = snapshot.visible
    ? snapshot.progress
    : isBootstrapLoading
      ? 0.35
      : 0;
  const clampedProgress = Math.max(0, Math.min(1, rawProgress));
  const percent = Math.round(clampedProgress * 100);

  const [showStatus, setShowStatus] = useState(false);

  useEffect(() => {
    if (!isLoading) {
      setShowStatus(false);
      return;
    }
    const timer = window.setTimeout(() => {
      setShowStatus(true);
    }, statusDelayMs);
    return () => {
      window.clearTimeout(timer);
    };
  }, [isLoading, statusDelayMs]);

  return (
    <>
      <div
        role="progressbar"
        aria-label="Page and API loading progress"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={percent}
        aria-hidden={!showBar}
        data-testid="global-loading-bar"
        data-state={showBar ? 'loading' : 'idle'}
        className={`pointer-events-none fixed inset-x-0 top-0 z-[100] h-[3px] overflow-hidden transition-opacity duration-200 motion-reduce:transition-none ${
          showBar ? 'opacity-100' : 'opacity-0'
        }`}
      >
        <div
          className="h-full w-full origin-left bg-gradient-to-r from-indigo-600 via-sky-500 to-indigo-600 transition-transform duration-200 ease-[cubic-bezier(0.16,1,0.3,1)] motion-reduce:transition-none"
          style={{
            transform: `scaleX(${showBar ? clampedProgress : 0})`,
          }}
        />
      </div>

      {showStatus && (
        <div
          role="status"
          aria-live="polite"
          data-testid="global-loading-status"
          className="pointer-events-none fixed right-4 top-20 z-[100] flex items-center gap-2 rounded-lg border border-slate-200 bg-white/95 px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm backdrop-blur-xs dark:border-slate-700 dark:bg-slate-900/95 dark:text-slate-200"
        >
          <Loader2 className="h-3.5 w-3.5 animate-spin text-indigo-600 dark:text-indigo-400" aria-hidden="true" />
          <span className="whitespace-nowrap">Loading...</span>
        </div>
      )}
    </>
  );
}
