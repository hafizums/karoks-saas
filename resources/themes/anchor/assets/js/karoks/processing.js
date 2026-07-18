export const TERMINAL_PROCESSING_STATUSES = new Set(['completed', 'failed', 'cancelled']);

export function isTerminalProcessingStatus(status) {
  return TERMINAL_PROCESSING_STATUSES.has(status);
}

export function shouldApplyStatusUpdate(current, incoming) {
  if (!incoming || typeof incoming !== 'object') {
    return false;
  }

  if (!current || typeof current !== 'object') {
    return true;
  }

  if (incoming.updated_at && current.updated_at && incoming.updated_at < current.updated_at) {
    return false;
  }

  if (typeof incoming.progress === 'number' && typeof current.progress === 'number') {
    if (incoming.progress < current.progress && incoming.status === current.status) {
      return false;
    }
  }

  return true;
}

export function mergeProcessingStatus(current, incoming) {
  if (!shouldApplyStatusUpdate(current, incoming)) {
    return current;
  }

  return {
    ...current,
    ...incoming,
    capabilities: {
      ...(current?.capabilities ?? {}),
      ...(incoming?.capabilities ?? {}),
    },
    usage: {
      ...(current?.usage ?? {}),
      ...(incoming?.usage ?? {}),
    },
    routes: {
      ...(current?.routes ?? {}),
      ...(incoming?.routes ?? {}),
    },
  };
}

export function createProcessingPoller({
  fetchStatus,
  onUpdate,
  intervalMs = 2000,
  setIntervalFn = setInterval,
  clearIntervalFn = clearInterval,
}) {
  let timerId = null;
  let inFlight = false;
  let stopped = false;

  async function pollOnce() {
    if (stopped || inFlight) {
      return;
    }

    inFlight = true;

    try {
      const payload = await fetchStatus();

      if (payload && typeof onUpdate === 'function') {
        onUpdate(payload);
      }

      if (payload && isTerminalProcessingStatus(payload.status)) {
        stop();
      }
    } finally {
      inFlight = false;
    }
  }

  function start() {
    if (timerId !== null || stopped) {
      return;
    }

    timerId = setIntervalFn(() => {
      void pollOnce();
    }, intervalMs);

    void pollOnce();
  }

  function stop() {
    stopped = true;

    if (timerId !== null) {
      clearIntervalFn(timerId);
      timerId = null;
    }
  }

  return {
    start,
    stop,
    pollOnce,
    isRunning: () => timerId !== null,
  };
}

export function registerKaroksProcessing(Alpine) {
  Alpine.data('karoksProcessing', (initialStatus) => ({
    status: initialStatus,
    polling: false,
    poller: null,
    _initialized: false,
    _destroyed: false,
    livewireCleanup: null,

    init() {
      if (this._initialized) {
        return;
      }

      this._initialized = true;

      if (isTerminalProcessingStatus(this.status.status)) {
        return;
      }

      if (!['queued', 'processing'].includes(this.status.status)) {
        return;
      }

      this.poller = createProcessingPoller({
        fetchStatus: async () => {
          const response = await fetch(this.status.routes.status, {
            headers: {
              Accept: 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
          });

          if (!response.ok) {
            return null;
          }

          return response.json();
        },
        onUpdate: (payload) => {
          this.status = mergeProcessingStatus(this.status, payload);

          if (payload.status === 'completed') {
            window.location.reload();
            return;
          }

          if (isTerminalProcessingStatus(this.status.status)) {
            this.stopPolling();
          }
        },
      });

      this.poller.start();
      this.polling = true;

      this._livewireNavigateHandler = () => this.destroy();
      window.addEventListener('livewire:navigating', this._livewireNavigateHandler);
    },

    destroy() {
      if (this._destroyed) {
        return;
      }

      this._destroyed = true;
      this.stopPolling();

      if (this._livewireNavigateHandler) {
        window.removeEventListener('livewire:navigating', this._livewireNavigateHandler);
        this._livewireNavigateHandler = null;
      }
    },

    stopPolling() {
      if (this.poller) {
        this.poller.stop();
        this.poller = null;
      }

      this.polling = false;
    },

    get stageLabel() {
      return this.status.stage_label || 'Waiting to start';
    },

    get progressPercent() {
      return Math.max(0, Math.min(100, Number(this.status.progress) || 0));
    },

    get isActive() {
      return ['queued', 'processing'].includes(this.status.status);
    },

    get isCompleted() {
      return this.status.status === 'completed';
    },

    get isFailed() {
      return this.status.status === 'failed';
    },

    get isCancelled() {
      return this.status.status === 'cancelled';
    },

    get isUploaded() {
      return this.status.status === 'uploaded';
    },

    get canProcess() {
      return Boolean(this.status.capabilities?.can_process);
    },

    get canCancel() {
      return Boolean(this.status.capabilities?.can_cancel);
    },

    get canRetry() {
      return Boolean(this.status.capabilities?.can_retry);
    },

    get canPlay() {
      return Boolean(this.status.capabilities?.can_play);
    },

    get usage() {
      return this.status.usage ?? {};
    },

    get processingEnabled() {
      return Boolean(this.status.processing_enabled ?? true);
    },
  }));
}
