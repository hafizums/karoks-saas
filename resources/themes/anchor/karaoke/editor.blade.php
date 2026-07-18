<x-layouts.app>
    <x-app.container>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <x-app.heading
                title="Edit karaoke project"
                :description="$project->title"
                :border="false"
            />

            <div class="flex flex-wrap gap-3 text-sm">
                <a href="{{ route('karaoke.projects.player', $project) }}" wire:navigate class="font-medium text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200">
                    Open player
                </a>
                <a href="{{ route('karaoke.projects.show', $project) }}" wire:navigate class="font-medium text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200">
                    Project details
                </a>
            </div>
        </div>

        <div class="mt-6">
            @if ($transcript === null)
                <div class="karoks-unavailable-editor" role="status">
                    <h2 class="text-lg font-semibold">Lyrics are not ready for editing</h2>
                    <p class="mt-2 text-sm opacity-80">
                        This project does not have a valid synchronized transcript yet.
                    </p>
                </div>
            @else
                <div
                    class="karoks-editor-shell"
                    x-data="karoksEditor(@js([
                        'revision' => $editorState['revision'],
                        'title' => $editorState['title'],
                        'artist' => $editorState['artist'],
                        'theme' => $editorState['theme'],
                        'lines' => $editorState['lines'],
                        'updateUrl' => $updateUrl,
                        'exportUrl' => $exportUrl,
                        'importUrl' => $importUrl,
                        'audioUrl' => $audioUrl,
                    ]))"
                >
                    <div class="karoks-editor-toolbar">
                        <div
                            class="karoks-editor-status"
                            :class="{
                                'is-unsaved': saveState === 'unsaved' || saveState === 'saving',
                                'is-failed': saveState === 'failed',
                                'is-conflict': saveState === 'conflict',
                            }"
                            role="status"
                            x-text="saveStatusLabel"
                        ></div>

                        <div class="karoks-editor-tabs" role="tablist" aria-label="Editor panels">
                            <button type="button" class="karoks-editor-tab" :class="{ 'is-active': activeTab === 'edit' }" @click="activeTab = 'edit'" role="tab" :aria-selected="activeTab === 'edit'">
                                Edit
                            </button>
                            <button type="button" class="karoks-editor-tab" :class="{ 'is-active': activeTab === 'preview' }" @click="activeTab = 'preview'" role="tab" :aria-selected="activeTab === 'preview'">
                                Preview
                            </button>
                        </div>

                        <div class="karoks-editor-actions">
                            <button type="button" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900" @click="saveNow()">
                                Save now
                            </button>
                            <button type="button" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-zinc-300 dark:border-zinc-600" @click="resetUnsaved()">
                                Reset unsaved changes
                            </button>
                            <button type="button" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-zinc-300 dark:border-zinc-600" @click="exportJson()">
                                Export JSON
                            </button>
                            <label class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-zinc-300 cursor-pointer dark:border-zinc-600">
                                Import JSON
                                <input type="file" accept="application/json,.json" class="sr-only" @change="importJson($event)">
                            </label>
                        </div>
                    </div>

                    <div x-show="saveState === 'failed'" x-cloak class="karoks-editor-alert is-error" role="alert">
                        Save failed. Check your connection and try again.
                        <button type="button" class="ml-2 underline" @click="retrySave()">Retry</button>
                    </div>

                    <div x-show="saveState === 'conflict'" x-cloak class="karoks-editor-alert is-conflict" role="alert">
                        This project was updated elsewhere. Reload the latest version before saving again.
                        <button type="button" class="ml-2 underline" @click="reloadLatestConflict()">Reload latest version</button>
                    </div>

                    <p x-show="importError" x-cloak class="karoks-editor-alert is-error" role="alert" x-text="importError"></p>

                    <div class="karoks-editor-layout">
                        <section
                            class="karoks-editor-panel is-active"
                            data-panel="edit"
                            :class="{ 'is-active': activeTab === 'edit' }"
                            aria-label="Editor controls"
                        >
                            <fieldset class="karoks-editor-fieldset">
                                <legend>Song details</legend>
                                <label class="karoks-editor-label">
                                    Title
                                    <input type="text" class="karoks-editor-input" maxlength="191" :value="title" @input="setTitle($event.target.value)">
                                </label>
                                <label class="karoks-editor-label">
                                    Artist
                                    <input type="text" class="karoks-editor-input" maxlength="191" :value="artist" @input="setArtist($event.target.value)">
                                </label>
                            </fieldset>

                            <fieldset class="karoks-editor-fieldset">
                                <legend>Appearance</legend>
                                <div class="karoks-editor-chip-row" role="group" aria-label="Background preset">
                                    <template x-for="preset in ['noir-gold', 'midnight-blue', 'neon-berry']" :key="preset">
                                        <button type="button" class="karoks-editor-chip" :class="{ 'is-active': theme.backgroundPreset === preset }" @click="setBackgroundPreset(preset)" x-text="preset"></button>
                                    </template>
                                </div>
                                <div class="karoks-editor-chip-row" role="group" aria-label="Lyric size">
                                    <template x-for="size in ['small', 'medium', 'large']" :key="size">
                                        <button type="button" class="karoks-editor-chip" :class="{ 'is-active': theme.lyricSize === size }" @click="setLyricSize(size)" x-text="size"></button>
                                    </template>
                                </div>
                                <div class="karoks-editor-color-row">
                                    <label class="karoks-editor-label">
                                        Base color
                                        <input type="color" class="karoks-editor-input" :value="theme.baseColor" @input="setBaseColor($event.target.value)">
                                        <input type="text" class="karoks-editor-input" maxlength="7" x-model="colorDraft.baseColor" @change="commitColorDraft('baseColor')">
                                    </label>
                                    <label class="karoks-editor-label">
                                        Highlight color
                                        <input type="color" class="karoks-editor-input" :value="theme.highlightColor" @input="setHighlightColor($event.target.value)">
                                        <input type="text" class="karoks-editor-input" maxlength="7" x-model="colorDraft.highlightColor" @change="commitColorDraft('highlightColor')">
                                    </label>
                                </div>
                            </fieldset>

                            <fieldset class="karoks-editor-fieldset">
                                <legend>Word text</legend>
                                <template x-for="line in lines" :key="line.id">
                                    <div class="karoks-editor-line-group">
                                        <p class="karoks-editor-line-meta" x-text="formatLineTiming(line)"></p>
                                        <div class="karoks-editor-word-grid">
                                            <template x-for="word in line.words" :key="word.id">
                                                <label class="karoks-editor-label">
                                                    <span x-text="word.id"></span>
                                                    <input
                                                        type="text"
                                                        class="karoks-editor-input"
                                                        maxlength="200"
                                                        :value="word.text"
                                                        :id="'karoks-word-' + word.id"
                                                        @input="setWordText(word.id, $event.target.value)"
                                                    >
                                                </label>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </fieldset>
                        </section>

                        <section
                            class="karoks-editor-panel"
                            data-panel="preview"
                            :class="{ 'is-active': activeTab === 'preview' }"
                            aria-label="Live preview"
                        >
                            <div class="karoks-editor-preview-wrap karoks-page" x-ref="previewStage">
                                <div
                                    class="karaoke-stage is-compact"
                                    data-karoks-preview-player
                                    :data-bg="$parent.theme.backgroundPreset"
                                    :data-lyric-size="$parent.theme.lyricSize"
                                    :style="$parent.themeStyle"
                                    x-data="karoksPlayer(@js([
                                        'lines' => $editorState['lines'],
                                        'audioUrl' => $audioUrl,
                                        'compact' => true,
                                    ]))"
                                >
                                    <header class="stage-header">
                                        <p class="brand">Karoks</p>
                                        <div class="track-meta">
                                            <p class="track-title" x-text="$parent.title"></p>
                                            <p class="track-artist" x-show="$parent.artist" x-text="$parent.artist"></p>
                                        </div>
                                    </header>

                                    <div class="lyric-stage" aria-label="Synchronized lyrics preview">
                                        <p class="lyric-line lyric-previous" :class="{ 'is-empty': ! lyricWindow.previous }" x-text="lyricWindow.previous ? lineText(lyricWindow.previous) : '\u00a0'"></p>
                                        <p class="lyric-line lyric-current">
                                            <template x-if="lyricWindow.current">
                                                <span>
                                                    <template x-for="(word, index) in lyricWindow.current.words" :key="word.id">
                                                        <span>
                                                            <span x-show="index > 0">&nbsp;</span>
                                                            <span :class="wordClass(word)" :style="wordStyle(word)">
                                                                <template x-if="wordIsActive(word)">
                                                                    <span>
                                                                        <span class="karaoke-word-base" x-text="word.text" aria-hidden="true"></span>
                                                                        <span class="karaoke-word-fill" x-text="word.text" aria-hidden="true"></span>
                                                                        <span class="sr-only" x-text="word.text"></span>
                                                                    </span>
                                                                </template>
                                                                <template x-if="! wordIsActive(word)">
                                                                    <span x-text="word.text"></span>
                                                                </template>
                                                            </span>
                                                        </span>
                                                    </template>
                                                </span>
                                            </template>
                                        </p>
                                        <p class="lyric-line lyric-next" :class="{ 'is-empty': ! lyricWindow.next }" x-text="lyricWindow.next ? lineText(lyricWindow.next) : '\u00a0'"></p>
                                    </div>

                                    <div class="karaoke-controls">
                                        <div class="seek-row">
                                            <span class="time-label" x-text="formattedCurrentTime"></span>
                                            <label class="sr-only" for="karoks-editor-seek">Seek playback position</label>
                                            <input id="karoks-editor-seek" type="range" class="seek-slider" min="0" :max="seekMax" step="0.01" :value="currentTime" @input="onSeekInput($event)">
                                            <span class="time-label" x-text="formattedDuration"></span>
                                        </div>
                                        <div class="control-row">
                                            <button type="button" class="icon-btn play-btn" @click="togglePlay()" :aria-label="isPlaying ? 'Pause' : 'Play'">
                                                <span x-text="isPlaying ? '❚❚' : '▶'"></span>
                                            </button>
                                        </div>
                                    </div>

                                    <audio x-ref="audio" :src="audioUrl" preload="metadata" class="sr-only"></audio>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
            @endif
        </div>
    </x-app.container>
</x-layouts.app>
