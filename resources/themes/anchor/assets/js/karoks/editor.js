import { parseTheme, themeToCssVars } from './theme.js';
import { applyWordText, cloneLines } from './transcript-edit.js';
import {
  createEditorSaveController,
  hasPendingChanges,
  SAVE_STATES,
} from './editor-state.js';
import { buildClientExportPayload, validateImportPayload } from './export-import.js';

function readCsrfToken() {
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  return token ?? '';
}

export function registerKaroksEditor(Alpine) {
  Alpine.data('karoksEditor', (config) => ({
    revision: config.revision,
    title: config.title,
    artist: config.artist ?? '',
    theme: parseTheme(config.theme),
    lines: cloneLines(config.lines ?? []),
    savedSnapshot: {
      revision: config.revision,
      title: config.title,
      artist: config.artist ?? '',
      theme: parseTheme(config.theme),
      lines: cloneLines(config.lines ?? []),
    },
    updateUrl: config.updateUrl,
    exportUrl: config.exportUrl,
    importUrl: config.importUrl,
    audioUrl: config.audioUrl,
    activeTab: 'edit',
    saveState: SAVE_STATES.SAVED,
    saveMessage: 'Saved',
    importError: '',
    conflictState: null,
    colorDraft: {
      baseColor: parseTheme(config.theme).baseColor,
      highlightColor: parseTheme(config.theme).highlightColor,
    },

    _saveController: null,
    _beforeUnloadHandler: null,
    _livewireNavigateHandler: null,
    _initialized: false,
    _editGeneration: 0,

    get themeStyle() {
      const vars = themeToCssVars(this.theme);
      return Object.entries(vars)
        .map(([key, value]) => `${key}: ${value}`)
        .join('; ');
    },

    get saveStatusLabel() {
      switch (this.saveState) {
        case SAVE_STATES.SAVING:
          return 'Saving…';
        case SAVE_STATES.UNSAVED:
          return 'Unsaved changes';
        case SAVE_STATES.FAILED:
          return 'Save failed';
        case SAVE_STATES.CONFLICT:
          return 'Version conflict';
        default:
          return 'Saved';
      }
    },

    init() {
      if (this._initialized) {
        return;
      }

      this._initialized = true;

      this._saveController = createEditorSaveController({
        debounceMs: 800,
        performSave: () => this.performSave(),
        onStateChange: (state) => {
          this.saveState = state;
        },
      });

      this._beforeUnloadHandler = (event) => {
        if (hasPendingChanges(this._saveController)) {
          event.preventDefault();
          event.returnValue = '';
        }
      };

      this._livewireNavigateHandler = (event) => {
        if (hasPendingChanges(this._saveController)) {
          const proceed = window.confirm('You have unsaved editor changes. Leave this page anyway?');
          if (!proceed) {
            event.preventDefault();
            return;
          }
        }

        this.destroy();
      };

      window.addEventListener('beforeunload', this._beforeUnloadHandler);
      window.addEventListener('livewire:navigating', this._livewireNavigateHandler);
    },

    destroy() {
      this._saveController?.destroy();
      if (this._beforeUnloadHandler) {
        window.removeEventListener('beforeunload', this._beforeUnloadHandler);
      }
      if (this._livewireNavigateHandler) {
        window.removeEventListener('livewire:navigating', this._livewireNavigateHandler);
      }
    },

    previewPlayerElement() {
      const stage = this.$refs.previewStage;
      if (!stage) {
        return this.$el?.querySelector('[data-karoks-preview-player]') ?? null;
      }

      return stage.querySelector('[data-karoks-preview-player]') ?? stage;
    },

    syncPreview() {
      const el = this.previewPlayerElement();
      if (!el) {
        return;
      }

      const player = window.Alpine?.$data(el);
      if (player && typeof player.replaceLines === 'function') {
        player.replaceLines(this.lines);
      }
    },

    markDirtyAndSchedule() {
      this._editGeneration += 1;
      this._saveController?.scheduleSave();
    },

    setTitle(value) {
      this.title = value;
      this.markDirtyAndSchedule();
    },

    setArtist(value) {
      this.artist = value;
      this.markDirtyAndSchedule();
    },

    setWordText(wordId, value) {
      this.lines = applyWordText(this.lines, wordId, value);
      this.syncPreview();
      this.markDirtyAndSchedule();
    },

    setBackgroundPreset(preset) {
      this.theme = { ...this.theme, backgroundPreset: preset };
      this.markDirtyAndSchedule();
    },

    setLyricSize(size) {
      this.theme = { ...this.theme, lyricSize: size };
      this.markDirtyAndSchedule();
    },

    setBaseColor(value) {
      const parsed = parseTheme({ ...this.theme, baseColor: value });
      this.theme = parsed;
      this.colorDraft.baseColor = parsed.baseColor;
      this.markDirtyAndSchedule();
    },

    setHighlightColor(value) {
      const parsed = parseTheme({ ...this.theme, highlightColor: value });
      this.theme = parsed;
      this.colorDraft.highlightColor = parsed.highlightColor;
      this.markDirtyAndSchedule();
    },

    commitColorDraft(field) {
      if (field === 'baseColor') {
        this.setBaseColor(this.colorDraft.baseColor);
      } else {
        this.setHighlightColor(this.colorDraft.highlightColor);
      }
    },

    buildPayload() {
      const payload = { revision: this.revision };
      const saved = this.savedSnapshot;

      if (this.title !== saved.title) payload.title = this.title;
      if ((this.artist ?? '') !== (saved.artist ?? '')) payload.artist = this.artist ?? '';
      if (JSON.stringify(this.theme) !== JSON.stringify(saved.theme)) payload.theme = this.theme;

      const words = {};
      for (const line of this.lines) {
        for (const word of line.words) {
          const savedWord = saved.lines.flatMap((entry) => entry.words).find((entry) => entry.id === word.id);
          if (savedWord && savedWord.text !== word.text) {
            words[word.id] = word.text;
          }
        }
      }

      if (Object.keys(words).length > 0) {
        payload.words = words;
      }

      return payload;
    },

    hasUnsavedDraftChanges() {
      const payload = this.buildPayload();
      return Object.keys(payload).length > 1;
    },

    async performSave() {
      const saveGeneration = this._editGeneration;

      const response = await fetch(this.updateUrl, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': readCsrfToken(),
        },
        body: JSON.stringify(this.buildPayload()),
      });

      const data = await response.json();

      if (response.status === 409) {
        this.conflictState = data.state ?? null;
        this.importError = '';
        return { conflict: true, state: data.state };
      }

      if (!response.ok) {
        throw new Error(data.message ?? 'Save failed');
      }

      this.applySaveResponse(data, saveGeneration);

      return {
        stillDirty: this._editGeneration !== saveGeneration || this.hasUnsavedDraftChanges(),
      };
    },

    applySaveResponse(data, saveGeneration) {
      this.revision = data.revision;
      this.savedSnapshot = {
        revision: data.revision,
        title: data.title,
        artist: data.artist ?? '',
        theme: parseTheme(data.theme),
        lines: cloneLines(data.lines ?? []),
      };

      if (this._editGeneration !== saveGeneration) {
        return;
      }

      this.title = data.title;
      this.artist = data.artist ?? '';
      this.theme = parseTheme(data.theme);
      this.lines = cloneLines(data.lines ?? []);
      this.colorDraft.baseColor = this.theme.baseColor;
      this.colorDraft.highlightColor = this.theme.highlightColor;
      this.syncPreview();
      this._saveController?.applyServerState();
      this.importError = '';
      this.conflictState = null;
    },

    applyServerState(state) {
      this.revision = state.revision;
      this.title = state.title;
      this.artist = state.artist ?? '';
      this.theme = parseTheme(state.theme);
      this.lines = cloneLines(state.lines ?? []);
      this.colorDraft.baseColor = this.theme.baseColor;
      this.colorDraft.highlightColor = this.theme.highlightColor;
      this.savedSnapshot = {
        revision: this.revision,
        title: this.title,
        artist: this.artist,
        theme: parseTheme(this.theme),
        lines: cloneLines(this.lines),
      };
      this.syncPreview();
      this._saveController?.applyServerState();
      this.importError = '';
      this.conflictState = null;
    },

    async saveNow() {
      await this._saveController?.flushSave(true);
    },

    async retrySave() {
      await this._saveController?.flushSave(true);
    },

    resetUnsaved() {
      this._editGeneration += 1;
      this.applyServerState(this.savedSnapshot);
    },

    reloadLatestConflict() {
      if (this.conflictState) {
        this.applyServerState(this.conflictState);
      } else {
        window.location.reload();
      }
    },

    async exportJson() {
      const payload = buildClientExportPayload({
        title: this.title,
        artist: this.artist,
        lines: this.lines,
        theme: this.theme,
      });

      const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = `${this.title || 'karaoke-project'}-karoks-export.json`;
      anchor.click();
      URL.revokeObjectURL(url);
    },

    async importJson(event) {
      const file = event.target.files?.[0];
      event.target.value = '';
      if (!file) return;

      this.importError = '';

      try {
        const text = await file.text();
        const payload = JSON.parse(text);
        const validationError = validateImportPayload(payload);
        if (validationError) {
          this.importError = validationError;
          return;
        }

        const formData = new FormData();
        formData.append('revision', String(this.revision));
        formData.append('import', file);

        const response = await fetch(this.importUrl, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': readCsrfToken(),
          },
          body: formData,
        });

        const data = await response.json();

        if (response.status === 409) {
          this.saveState = SAVE_STATES.CONFLICT;
          this.conflictState = data.state ?? null;
          this.importError = data.message ?? 'Version conflict.';
          return;
        }

        if (!response.ok) {
          this.importError = data.message ?? 'Import failed.';
          return;
        }

        this.applyServerState(data);
      } catch {
        this.importError = 'Import file must contain valid JSON.';
      }
    },

    formatLineTiming(line) {
      const start = Number(line.start).toFixed(1);
      const end = Number(line.end).toFixed(1);
      return `${start}s – ${end}s`;
    },
  }));
}
