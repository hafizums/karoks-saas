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

  it('serializes follow-up save after in-flight edit without losing newest draft', async () => {
    let resolveSaveA;
    const saveAGate = new Promise((resolve) => {
      resolveSaveA = resolve;
    });

    let revision = 1;
    let title = 'Original';
    let editGeneration = 0;
    let savedTitle = 'Original';
    const requests = [];

    const performSave = async () => {
      const saveGeneration = editGeneration;
      requests.push({ revision, title, saveGeneration });

      if (requests.length === 1) {
        await saveAGate;
      }

      revision += 1;
      savedTitle = requests.at(-1).title;

      const stillDirty = editGeneration !== saveGeneration || title !== savedTitle;
      if (!stillDirty) {
        title = savedTitle;
      }

      return { stillDirty };
    };

    let state = SAVE_STATES.SAVED;
    const controller = createEditorSaveController({
      debounceMs: 5,
      performSave,
      onStateChange: (next) => {
        state = next;
      },
    });

    editGeneration += 1;
    controller.scheduleSave();
    const saveA = controller.flushSave(true);

    editGeneration += 1;
    title = 'Newest';
    controller.scheduleSave();

    resolveSaveA();
    await saveA;
    await new Promise((resolve) => setTimeout(resolve, 50));

    assert.equal(requests.length, 2);
    assert.equal(requests[0].title, 'Original');
    assert.equal(requests[0].revision, 1);
    assert.equal(requests[1].title, 'Newest');
    assert.equal(requests[1].revision, 2);
    assert.equal(title, 'Newest');
    assert.equal(state, SAVE_STATES.SAVED);
    assert.equal(controller.dirty, false);

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
