import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { applyWordText, cloneLines } from '../../resources/themes/anchor/assets/js/karoks/transcript-edit.js';
import {
  createEditorSaveController,
  SAVE_STATES,
  hasPendingChanges,
} from '../../resources/themes/anchor/assets/js/karoks/editor-state.js';
import {
  validateImportPayload,
  timingSkeletonsMatch,
} from '../../resources/themes/anchor/assets/js/karoks/export-import.js';
import { parseTheme } from '../../resources/themes/anchor/assets/js/karoks/theme.js';

const sampleLines = [
  {
    id: 'line-1',
    start: 0.5,
    end: 4,
    words: [
      { id: 'word-1', text: 'Hello', start: 0.5, end: 1.2 },
      { id: 'word-2', text: 'world', start: 1.2, end: 4 },
    ],
  },
];

describe('applyWordText', () => {
  it('updates text without changing ids or timing', () => {
    const next = applyWordText(sampleLines, 'word-1', 'Hi');
    assert.equal(next[0].words[0].text, 'Hi');
    assert.equal(next[0].words[0].start, 0.5);
    assert.equal(next[0].words[0].id, 'word-1');
  });

  it('cloneLines handles non-cloneable reactive arrays', () => {
    const reactive = new Proxy(sampleLines, {
      get(target, prop) {
        return target[prop];
      },
    });

    const cloned = cloneLines(reactive);
    assert.deepEqual(cloned, sampleLines);
    assert.notEqual(cloned, reactive);
  });
});

describe('createEditorSaveController', () => {
  it('marks dirty and saves successfully', async () => {
    let state = SAVE_STATES.SAVED;
    const controller = createEditorSaveController({
      debounceMs: 10,
      performSave: async () => ({ revision: 2 }),
      onStateChange: (next) => {
        state = next;
      },
    });

    controller.scheduleSave();
    assert.equal(state, SAVE_STATES.UNSAVED);
    await controller.flushSave(true);
    assert.equal(state, SAVE_STATES.SAVED);
    controller.destroy();
  });

  it('queues another save while one is active', async () => {
    let saves = 0;
    const controller = createEditorSaveController({
      debounceMs: 5,
      performSave: async () => {
        saves += 1;
        await new Promise((resolve) => setTimeout(resolve, 20));
        return { revision: saves + 1 };
      },
      onStateChange: () => {},
    });

    controller.scheduleSave();
    const first = controller.flushSave(true);
    controller.scheduleSave();
    await first;
    await new Promise((resolve) => setTimeout(resolve, 40));
    assert.ok(saves >= 1);
    controller.destroy();
  });

  it('enters conflict state without marking saved', async () => {
    let state = SAVE_STATES.SAVED;
    const controller = createEditorSaveController({
      debounceMs: 5,
      performSave: async () => ({ conflict: true }),
      onStateChange: (next) => {
        state = next;
      },
    });

    controller.scheduleSave();
    await controller.flushSave(true);
    assert.equal(state, SAVE_STATES.CONFLICT);
    assert.equal(hasPendingChanges(controller), true);
    controller.destroy();
  });

  it('cleans up timers on destroy', async () => {
    const controller = createEditorSaveController({
      debounceMs: 1000,
      performSave: async () => ({}),
      onStateChange: () => {},
    });

    controller.scheduleSave();
    controller.destroy();
    assert.equal(controller.dirty, true);
  });
});

describe('import helpers', () => {
  it('validates export schema', () => {
    assert.equal(validateImportPayload(null), 'Import file must contain a JSON object.');
    assert.equal(
      validateImportPayload({ schema: 'karoks-project', version: 1, project: { title: 'x' } }),
      'Imported transcript is invalid.',
    );
  });

  it('matches timing skeletons', () => {
    const imported = cloneLines(sampleLines);
    imported[0].words[0].text = 'Changed';
    assert.equal(timingSkeletonsMatch(sampleLines, imported), true);
    imported[0].start = 9;
    assert.equal(timingSkeletonsMatch(sampleLines, imported), false);
  });
});

describe('parseTheme', () => {
  it('falls back safely for invalid colors', () => {
    const theme = parseTheme({ baseColor: 'red', highlightColor: '#112233' });
    assert.equal(theme.baseColor, '#f4f0e6');
    assert.equal(theme.highlightColor, '#112233');
  });
});
