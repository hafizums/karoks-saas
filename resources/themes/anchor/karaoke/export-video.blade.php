<x-layouts.app>
    <x-app.container>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <x-app.heading
                :title="$project->title"
                :description="$project->artist ?: 'Export a local WebM karaoke video'"
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

        <div
            class="mt-6 space-y-6"
            x-data="karoksVideoExport(@js([
                'lines' => $transcript['lines'],
                'theme' => $theme,
                'title' => $project->title,
                'artist' => $project->artist,
                'audioUrl' => $audioUrl,
            ]))"
            x-init="init()"
        >
            <section class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Local WebM export</h2>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                    Export runs entirely in your browser. A four-minute song may take about four minutes to record.
                    Keep this tab active while export is running.
                </p>

                <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_220px]">
                    <div class="overflow-hidden rounded-lg border border-zinc-200 bg-zinc-950 dark:border-zinc-700">
                        <canvas x-ref="previewCanvas" class="block h-auto w-full" aria-label="Theme preview"></canvas>
                    </div>

                    <div class="space-y-3 text-sm text-zinc-600 dark:text-zinc-300">
                        <p><span class="font-medium text-zinc-900 dark:text-zinc-100">Estimated duration:</span> <span x-text="totalLabel"></span></p>
                        <p><span class="font-medium text-zinc-900 dark:text-zinc-100">Container:</span> WebM only</p>
                        <p x-show="selectedMimeType" x-cloak>
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">Codec:</span>
                            <span x-text="selectedMimeType"></span>
                        </p>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="export-resolution" class="block text-sm font-medium text-zinc-900 dark:text-zinc-100">Resolution</label>
                        <select
                            id="export-resolution"
                            x-model="resolutionId"
                            @change="onResolutionChange()"
                            :disabled="isBusy"
                            class="mt-2 block w-full rounded-lg border-zinc-300 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        >
                            <template x-for="preset in resolutions" :key="preset.id">
                                <option :value="preset.id" x-text="`${preset.label} (${preset.width}×${preset.height})`"></option>
                            </template>
                        </select>
                    </div>

                    <div class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                        <p class="font-medium">Browser support</p>
                        <p class="mt-1" x-show="browserSupported">Use a current desktop Chrome or Edge browser for reliable WebM export.</p>
                        <p class="mt-1" x-show="!browserSupported" x-cloak x-text="browserMessage"></p>
                        <p class="mt-2 text-xs opacity-90">Large exports use significant memory. Close other heavy tabs if your device slows down.</p>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap gap-3">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
                        @click="startExport()"
                        :disabled="!canStart"
                    >
                        Start export
                    </button>

                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-900 hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-600 dark:text-zinc-100 dark:hover:bg-zinc-800"
                        @click="cancelExport()"
                        x-show="isBusy"
                        x-cloak
                    >
                        Cancel export
                    </button>

                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-900 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-100 dark:hover:bg-zinc-800"
                        @click="retryExport()"
                        x-show="state === 'failed' || state === 'cancelled'"
                        x-cloak
                    >
                        Retry export
                    </button>

                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-900 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-100 dark:hover:bg-zinc-800"
                        @click="newExport()"
                        x-show="state === 'completed'"
                        x-cloak
                    >
                        Export again
                    </button>

                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-600"
                        @click="downloadResult()"
                        x-show="state === 'completed' && downloadUrl"
                        x-cloak
                    >
                        Download WebM
                    </button>
                </div>

                <div class="mt-5 space-y-2">
                    <div class="flex items-center justify-between text-sm">
                        <p class="font-medium text-zinc-900 dark:text-zinc-100" x-text="statusLabel"></p>
                        <p class="text-zinc-500 dark:text-zinc-400">
                            <span x-text="elapsedLabel"></span>
                            <span aria-hidden="true"> / </span>
                            <span x-text="totalLabel"></span>
                        </p>
                    </div>

                    <div class="h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" :aria-valuenow="Math.round(progress)" aria-valuemin="0" aria-valuemax="100">
                        <div class="h-full rounded-full bg-zinc-900 transition-all duration-300 dark:bg-zinc-100" :style="`width: ${progress}%`"></div>
                    </div>

                    <p class="text-xs text-zinc-500 dark:text-zinc-400" x-show="isBusy" x-cloak>
                        Real-time export in progress at <span x-text="resolutionId"></span>. Do not close or navigate away from this tab.
                    </p>
                </div>

                <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200" x-show="state === 'failed' && errorMessage" x-cloak role="alert">
                    <p x-text="errorMessage"></p>
                </div>

                <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100" x-show="state === 'completed'" x-cloak>
                    <p class="font-medium">Export ready</p>
                    <p class="mt-1">
                        <span x-text="filename"></span>
                        <span x-show="fileSizeLabel"> (<span x-text="fileSizeLabel"></span>)</span>
                    </p>
                    <video
                        x-show="previewUrl"
                        class="mt-3 max-h-56 w-full rounded-lg bg-black"
                        controls
                        playsinline
                        :src="previewUrl"
                    ></video>
                </div>
            </section>
        </div>
    </x-app.container>
</x-layouts.app>
