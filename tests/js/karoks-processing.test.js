import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
  createProcessingPoller,
  isTerminalProcessingStatus,
  mergeProcessingStatus,
  shouldApplyStatusUpdate,
} from '../../resources/themes/anchor/assets/js/karoks/processing.js';

describe('isTerminalProcessingStatus', () => {
  it('treats completed, failed, and cancelled as terminal', () => {
    assert.equal(isTerminalProcessingStatus('completed'), true);
    assert.equal(isTerminalProcessingStatus('failed'), true);
    assert.equal(isTerminalProcessingStatus('cancelled'), true);
    assert.equal(isTerminalProcessingStatus('processing'), false);
  });
});

describe('shouldApplyStatusUpdate', () => {
  it('rejects stale timestamps', () => {
    const current = { updated_at: '2026-07-18T12:00:00+00:00', progress: 40, status: 'processing' };
    const incoming = { updated_at: '2026-07-18T11:00:00+00:00', progress: 80, status: 'processing' };

    assert.equal(shouldApplyStatusUpdate(current, incoming), false);
  });

  it('rejects backward progress within the same status', () => {
    const current = { progress: 60, status: 'processing' };
    const incoming = { progress: 30, status: 'processing' };

    assert.equal(shouldApplyStatusUpdate(current, incoming), false);
  });
});

describe('mergeProcessingStatus', () => {
  it('merges valid updates forward', () => {
    const merged = mergeProcessingStatus(
      { progress: 20, status: 'processing', capabilities: { can_cancel: true } },
      { progress: 40, status: 'processing', capabilities: { can_cancel: true } },
    );

    assert.equal(merged.progress, 40);
  });
});

describe('createProcessingPoller', () => {
  it('stops polling at terminal state', async () => {
    const intervals = [];
    const clears = [];
    let fetchCount = 0;

    const poller = createProcessingPoller({
      fetchStatus: async () => {
        fetchCount += 1;

        return { status: 'completed', progress: 100 };
      },
      onUpdate: () => {},
      intervalMs: 10,
      setIntervalFn: (fn, ms) => {
        const id = intervals.length + 1;
        intervals.push({ fn, ms, id });

        return id;
      },
      clearIntervalFn: (id) => {
        clears.push(id);
      },
    });

    poller.start();
    await poller.pollOnce();

    assert.equal(fetchCount, 1);
    assert.deepEqual(clears, [1]);
    assert.equal(poller.isRunning(), false);
  });

  it('allows only one active timer', () => {
    const poller = createProcessingPoller({
      fetchStatus: async () => ({ status: 'processing', progress: 10 }),
      onUpdate: () => {},
      intervalMs: 1000,
      setIntervalFn: () => 42,
      clearIntervalFn: () => {},
    });

    poller.start();
    poller.start();

    assert.equal(poller.isRunning(), true);
  });

  it('clears its timer on stop', () => {
    const clears = [];

    const poller = createProcessingPoller({
      fetchStatus: async () => ({ status: 'processing', progress: 10 }),
      onUpdate: () => {},
      intervalMs: 1000,
      setIntervalFn: () => 7,
      clearIntervalFn: (id) => clears.push(id),
    });

    poller.start();
    poller.stop();

    assert.deepEqual(clears, [7]);
    assert.equal(poller.isRunning(), false);
  });
});
