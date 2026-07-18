export const SAVE_STATES = {
  SAVED: 'saved',
  UNSAVED: 'unsaved',
  SAVING: 'saving',
  FAILED: 'failed',
  CONFLICT: 'conflict',
};

export function createEditorSaveController(options) {
  const {
    debounceMs = 800,
    performSave,
    onStateChange,
  } = options;

  let saveState = SAVE_STATES.SAVED;
  let dirty = false;
  let debounceTimer = null;
  let activeSave = null;
  let queuedAfterSave = false;
  let destroyed = false;

  function setState(next) {
    saveState = next;
    onStateChange?.(next, dirty);
  }

  function markDirty() {
    dirty = true;
    if (saveState !== SAVE_STATES.SAVING && saveState !== SAVE_STATES.CONFLICT) {
      setState(SAVE_STATES.UNSAVED);
    }
  }

  function markSaved() {
    dirty = false;
    setState(SAVE_STATES.SAVED);
  }

  function scheduleSave() {
    if (destroyed) {
      return;
    }

    markDirty();
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      void flushSave();
    }, debounceMs);
  }

  async function flushSave(force = false) {
    if (destroyed) {
      return null;
    }

    clearTimeout(debounceTimer);

    if (activeSave) {
      queuedAfterSave = true;
      return activeSave;
    }

    if (!dirty && !force) {
      return null;
    }

    setState(SAVE_STATES.SAVING);

    activeSave = performSave()
      .then((result) => {
        if (result?.conflict) {
          dirty = true;
          setState(SAVE_STATES.CONFLICT);
          return result;
        }

        markSaved();
        return result;
      })
      .catch(() => {
        dirty = true;
        setState(SAVE_STATES.FAILED);
        return null;
      })
      .finally(() => {
        activeSave = null;
        if (queuedAfterSave) {
          queuedAfterSave = false;
          if (dirty) {
            void flushSave();
          }
        }
      });

    return activeSave;
  }

  function applyServerState() {
    dirty = false;
    setState(SAVE_STATES.SAVED);
  }

  function destroy() {
    destroyed = true;
    clearTimeout(debounceTimer);
    queuedAfterSave = false;
  }

  return {
    get saveState() {
      return saveState;
    },
    get dirty() {
      return dirty;
    },
    markDirty,
    markSaved,
    scheduleSave,
    flushSave,
    applyServerState,
    destroy,
  };
}

export function buildUpdatePayload(state, savedSnapshot) {
  const payload = {
    revision: state.revision,
  };

  if (state.title !== savedSnapshot.title) {
    payload.title = state.title;
  }

  if ((state.artist ?? '') !== (savedSnapshot.artist ?? '')) {
    payload.artist = state.artist ?? '';
  }

  if (JSON.stringify(state.theme) !== JSON.stringify(savedSnapshot.theme)) {
    payload.theme = state.theme;
  }

  const words = collectChangedWords(state.lines, savedSnapshot.lines);
  if (Object.keys(words).length > 0) {
    payload.words = words;
  }

  return payload;
}

function collectChangedWords(currentLines, savedLines) {
  const savedIndex = {};
  for (const line of savedLines) {
    for (const word of line.words) {
      savedIndex[word.id] = word.text;
    }
  }

  const changes = {};
  for (const line of currentLines) {
    for (const word of line.words) {
      if (savedIndex[word.id] !== word.text) {
        changes[word.id] = word.text;
      }
    }
  }

  return changes;
}

export function hasPendingChanges(saveController) {
  return saveController.dirty || saveController.saveState === SAVE_STATES.UNSAVED
    || saveController.saveState === SAVE_STATES.FAILED
    || saveController.saveState === SAVE_STATES.CONFLICT
    || saveController.saveState === SAVE_STATES.SAVING;
}
