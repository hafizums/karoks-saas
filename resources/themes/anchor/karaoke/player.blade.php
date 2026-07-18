<x-layouts.app>
    <x-app.container>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <x-app.heading
                :title="$project->title"
                :description="$project->artist ?: 'No artist provided'"
                :border="false"
            />

            <a
                href="{{ route('karaoke.projects.show', $project) }}"
                wire:navigate
                class="text-sm font-medium text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200"
            >
                Back to project
            </a>
        </div>

        <div class="mt-6 overflow-hidden rounded-xl karoks-page">
            @if ($transcript === null)
                <div class="karoks-unavailable" role="status">
                    <h2 class="text-lg font-semibold">Lyrics are not ready</h2>
                    <p class="mt-2 text-sm opacity-80">
                        This project does not have synchronized lyrics yet. Check back after processing is complete.
                    </p>
                </div>
            @else
                <div
                    class="karaoke-stage is-compact"
                    data-bg="{{ $theme['backgroundPreset'] }}"
                    data-lyric-size="{{ $theme['lyricSize'] }}"
                    style="@foreach ($themeCssVars as $name => $value) {{ $name }}: {{ $value }}; @endforeach"
                    x-data="karoksPlayer(@js([
                        'lines' => $transcript['lines'],
                        'audioUrl' => $audioUrl,
                        'compact' => true,
                    ]))"
                    x-ref="stage"
                >
                    <header class="stage-header">
                        <div class="stage-header-row">
                            <p class="brand">Karoks</p>
                        </div>
                        <div class="track-meta">
                            <p class="track-title">{{ $project->title }}</p>
                            @if ($project->artist)
                                <p class="track-artist">{{ $project->artist }}</p>
                            @endif
                        </div>
                    </header>

                    <div class="lyric-stage" aria-label="Synchronized lyrics">
                        <p
                            class="lyric-line lyric-previous"
                            :class="{ 'is-empty': ! lyricWindow.previous }"
                            x-text="lyricWindow.previous ? lineText(lyricWindow.previous) : '\u00a0'"
                        ></p>

                        <p class="lyric-line lyric-current">
                            <template x-if="! lyricWindow.current">
                                <span>&nbsp;</span>
                            </template>
                            <template x-if="lyricWindow.current">
                                <span>
                                    <template x-for="(word, index) in lyricWindow.current.words" :key="word.id">
                                        <span>
                                            <span x-show="index > 0">&nbsp;</span>
                                            <span
                                                :class="wordClass(word)"
                                                :style="wordStyle(word)"
                                            >
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

                        <p
                            class="lyric-line lyric-next"
                            :class="{ 'is-empty': ! lyricWindow.next }"
                            x-text="lyricWindow.next ? lineText(lyricWindow.next) : '\u00a0'"
                        ></p>
                    </div>

                    <div class="karaoke-controls">
                        <p
                            x-show="audioError"
                            x-cloak
                            class="audio-status"
                            role="alert"
                        >
                            Audio is unavailable. The source file may be missing or unsupported in your browser.
                        </p>

                        <div class="seek-row">
                            <span class="time-label" x-text="formattedCurrentTime" aria-hidden="true"></span>
                            <label class="sr-only" for="karoks-seek">Seek playback position</label>
                            <input
                                id="karoks-seek"
                                type="range"
                                class="seek-slider"
                                min="0"
                                :max="seekMax"
                                step="0.01"
                                :value="currentTime"
                                :disabled="audioMissing || ! audioReady"
                                @input="onSeekInput($event)"
                                aria-valuemin="0"
                                :aria-valuemax="seekMax"
                                :aria-valuenow="currentTime"
                                aria-label="Seek playback position"
                            />
                            <span class="time-label" x-text="formattedDuration" aria-hidden="true"></span>
                        </div>

                        <div class="control-row">
                            <div class="control-group">
                                <button
                                    type="button"
                                    class="icon-btn"
                                    @click="skip(-10)"
                                    :disabled="audioMissing"
                                    aria-label="Skip backward 10 seconds"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18" aria-hidden="true">
                                        <path d="M12 5V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/>
                                        <text x="12" y="15" text-anchor="middle" font-size="7" fill="currentColor">10</text>
                                    </svg>
                                </button>

                                <button
                                    type="button"
                                    class="icon-btn play-btn"
                                    @click="togglePlay()"
                                    :disabled="audioMissing"
                                    :aria-label="isPlaying ? 'Pause' : 'Play'"
                                    :class="{ 'is-active': isPlaying }"
                                >
                                    <svg x-show="! isPlaying" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="22" height="22" aria-hidden="true">
                                        <path d="M8 5v14l11-7z"/>
                                    </svg>
                                    <svg x-show="isPlaying" x-cloak xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="22" height="22" aria-hidden="true">
                                        <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>
                                    </svg>
                                </button>

                                <button
                                    type="button"
                                    class="icon-btn"
                                    @click="skip(10)"
                                    :disabled="audioMissing"
                                    aria-label="Skip forward 10 seconds"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18" aria-hidden="true">
                                        <path d="M12 5V1l5 5-5 5V7c-3.31 0-6 2.69-6 6s2.69 6 6 6 6-2.69 6-6h2c0 4.42-3.58 8-8 8s-8-3.58-8-8 3.58-8 8-8z"/>
                                    </svg>
                                </button>
                            </div>

                            <div class="control-group volume-group">
                                <button
                                    type="button"
                                    class="icon-btn"
                                    @click="toggleMute()"
                                    :aria-label="isMuted ? 'Unmute' : 'Mute'"
                                    :class="{ 'is-active': isMuted }"
                                >
                                    <svg x-show="! isMuted" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18" aria-hidden="true">
                                        <path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z"/>
                                    </svg>
                                    <svg x-show="isMuted" x-cloak xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18" aria-hidden="true">
                                        <path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/>
                                    </svg>
                                </button>

                                <label class="sr-only" for="karoks-volume">Volume</label>
                                <input
                                    id="karoks-volume"
                                    type="range"
                                    class="volume-slider"
                                    min="0"
                                    max="1"
                                    step="0.01"
                                    :value="isMuted ? 0 : volume"
                                    @input="onVolumeInput($event)"
                                    aria-label="Volume"
                                />
                            </div>

                            <div class="control-group">
                                <button
                                    type="button"
                                    class="icon-btn"
                                    @click="toggleFullscreen()"
                                    :aria-label="isFullscreen ? 'Exit fullscreen' : 'Enter fullscreen'"
                                    :class="{ 'is-active': isFullscreen }"
                                >
                                    <svg x-show="! isFullscreen" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18" aria-hidden="true">
                                        <path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/>
                                    </svg>
                                    <svg x-show="isFullscreen" x-cloak xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18" aria-hidden="true">
                                        <path d="M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <audio
                        x-ref="audio"
                        :src="audioUrl"
                        preload="metadata"
                        class="sr-only"
                    ></audio>
                </div>
            @endif
        </div>
    </x-app.container>
</x-layouts.app>
