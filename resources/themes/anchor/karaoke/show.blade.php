<x-layouts.app>
    <x-app.container>
        <div
            class="space-y-8"
            x-data="karoksProcessing(@js($processingStatus))"
            x-init="init()"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <x-app.heading
                        :title="$project->title"
                        :description="$project->artist ?: 'No artist provided'"
                        :border="false"
                    />
                </div>

                <span class="inline-flex px-3 py-1 text-xs font-medium rounded-full bg-zinc-100 text-zinc-700 dark:bg-zinc-700/60 dark:text-zinc-200">
                    {{ $project->status->label() }}
                </span>
            </div>

            @if (session('success'))
                <div class="p-4 text-sm text-green-800 bg-green-50 border border-green-200 rounded-lg dark:bg-green-950/40 dark:text-green-200 dark:border-green-900" role="status">
                    {{ session('success') }}
                </div>
            @endif

            @error('processing')
                <div class="p-4 text-sm text-red-800 bg-red-50 border border-red-200 rounded-lg dark:bg-red-950/30 dark:text-red-100 dark:border-red-900/60" role="alert">
                    {{ $message }}
                </div>
            @enderror

            @error('provider_consent')
                <div class="p-4 text-sm text-red-800 bg-red-50 border border-red-200 rounded-lg dark:bg-red-950/30 dark:text-red-100 dark:border-red-900/60" role="alert">
                    {{ $message }}
                </div>
            @enderror

            @include('theme::karaoke.partials.usage-summary')

            @include('theme::karaoke.partials.public-sharing-panel', [
                'project' => $project,
                'isReadyForPlayback' => $isReadyForPlayback,
                'activeShare' => $activeShare,
                'shareUrl' => $shareUrl,
            ])

            <div class="p-6 space-y-4 border rounded-xl border-zinc-200 dark:border-zinc-700">
                <h4 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Upload information</h4>
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Original filename</dt>
                        <dd class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">{{ $project->original_filename }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">File size</dt>
                        <dd class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">{{ number_format($project->size_bytes / 1024, 1) }} KB</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">MIME type</dt>
                        <dd class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">{{ $project->mime_type }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Uploaded</dt>
                        <dd class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">{{ $project->created_at->format('M j, Y g:i A') }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Rights confirmed</dt>
                        <dd class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">{{ $project->rights_confirmed_at?->format('M j, Y g:i A') }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Progress</dt>
                        <dd class="mt-1 font-medium text-zinc-900 dark:text-zinc-100" x-text="`${progressPercent}%`">{{ $project->progress }}%</dd>
                    </div>
                </dl>
            </div>

            <template x-if="(isUploaded || isCancelled) && status.simulated_processing">
                <div class="p-4 text-sm border rounded-lg border-sky-200 bg-sky-50 text-sky-950 dark:border-sky-900/60 dark:bg-sky-950/30 dark:text-sky-100">
                    <p class="font-medium">Simulated processing</p>
                    <p class="mt-2">Processing runs locally on this server using a development mock. Your upload is copied for playback and generic placeholder lyrics are generated. No external provider is contacted.</p>
                </div>
            </template>

            <template x-if="(isUploaded || isCancelled) && status.requires_provider_consent">
                <div class="p-4 text-sm border rounded-lg border-violet-200 bg-violet-50 text-violet-950 dark:border-violet-900/60 dark:bg-violet-950/30 dark:text-violet-100">
                    <p class="font-medium">Real processing disclosure</p>
                    <p class="mt-2">Starting processing sends your source audio to WaveSpeed for vocal separation, then sends the isolated vocal track to ElevenLabs Scribe for transcription. Instrumental and transcript results are stored privately in your account.</p>
                </div>
            </template>

            <template x-if="(isUploaded || isCancelled) && status.processing_mode === 'unavailable'">
                <div class="p-4 text-sm border rounded-lg border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100" role="alert">
                    <p class="font-medium">Real processing unavailable</p>
                    <p class="mt-2">Real provider processing is not configured on this server. Processing cannot be started until configuration is complete.</p>
                </div>
            </template>

            <template x-if="isActive">
                <div class="p-4 space-y-4 border rounded-lg border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Processing in progress</p>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400" x-text="stageLabel"></p>
                        </div>
                        <form method="POST" :action="status.routes.cancel" x-show="canCancel">
                            @csrf
                            <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                Cancel
                            </button>
                        </form>
                    </div>

                    <div>
                        <div class="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
                            <span>Progress</span>
                            <span x-text="`${progressPercent}%`" aria-live="polite"></span>
                        </div>
                        <div
                            class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800"
                            role="progressbar"
                            :aria-valuenow="progressPercent"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            :aria-label="`Processing progress ${progressPercent} percent`"
                        >
                            <div class="h-full rounded-full bg-zinc-900 transition-all duration-300 dark:bg-zinc-100" :style="`width: ${progressPercent}%`"></div>
                        </div>
                    </div>
                </div>
            </template>

            <template x-if="isFailed">
                <div class="p-4 text-sm border rounded-lg border-red-200 bg-red-50 text-red-900 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-100">
                    <p class="font-medium">Processing failed</p>
                    <p class="mt-2" x-text="status.error_message || 'Processing could not be completed.'"></p>
                </div>
            </template>

            <template x-if="isCancelled">
                <div class="p-4 text-sm border rounded-lg border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100">
                    <p class="font-medium">Processing cancelled</p>
                    <p class="mt-2">This run was cancelled before completion. You can start a fresh processing attempt when ready.</p>
                </div>
            </template>

            <template x-if="isCompleted && status.simulated_processing">
                <div class="p-4 text-sm border rounded-lg border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100" role="status">
                    <p class="font-medium">Simulated result</p>
                    <p class="mt-2">{{ \App\Support\Karaoke\Processors\MockKaraokeSyntheticTranscript::DISCLOSURE }}</p>
                </div>
            </template>

            <template x-if="isCompleted && !status.simulated_processing">
                <div class="p-4 text-sm border rounded-lg border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-100" role="status">
                    <p class="font-medium">Real processing result</p>
                    <p class="mt-2">{{ \App\Support\Karaoke\Processors\RealKaraokeProcessor::DISCLOSURE }}</p>
                </div>
            </template>

            <div class="flex flex-wrap items-center gap-3">
                <template x-if="canProcess && status.requires_provider_consent && !status.provider_consent_confirmed">
                    <form method="POST" :action="status.routes.process" class="w-full max-w-xl space-y-3">
                        @csrf
                        <label class="flex items-start gap-3 text-sm text-zinc-700 dark:text-zinc-200">
                            <input
                                type="checkbox"
                                name="provider_consent"
                                value="1"
                                class="mt-1 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500 dark:border-zinc-600 dark:bg-zinc-900"
                                required
                            />
                            <span>I consent to sending this audio to WaveSpeed and ElevenLabs for vocal separation and transcription.</span>
                        </label>
                        @error('provider_consent')
                            <p class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
                        @enderror
                        <button type="submit" class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white rounded-lg bg-zinc-900 hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white">
                            Start processing
                        </button>
                    </form>
                </template>

                <template x-if="canProcess && (!status.requires_provider_consent || status.provider_consent_confirmed)">
                    <form method="POST" :action="status.routes.process">
                        @csrf
                        <button type="submit" class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white rounded-lg bg-zinc-900 hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white">
                            Start processing
                        </button>
                    </form>
                </template>

                <template x-if="canRetry">
                    <form method="POST" :action="status.routes.retry">
                        @csrf
                        <button type="submit" class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white rounded-lg bg-zinc-900 hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white">
                            Retry processing
                        </button>
                    </form>
                </template>

                <template x-if="isFailed && canRetry">
                    <p class="w-full text-xs text-zinc-500 dark:text-zinc-400">
                        Retrying a processing failure does not consume another monthly allowance.
                    </p>
                </template>

                <template x-if="(isUploaded || isCancelled) && !canProcess && processingEnabled">
                    <p class="w-full text-sm text-red-700 dark:text-red-300">
                        Processing cannot be started because your monthly allowance is exhausted or unavailable.
                        @if (\Illuminate\Support\Facades\Route::has('pricing'))
                            <a href="{{ route('pricing') }}" wire:navigate class="font-medium underline underline-offset-2">View plans</a>
                        @endif
                    </p>
                </template>

                @if ($isReadyForPlayback)
                    <a
                        href="{{ route('karaoke.projects.player', $project) }}"
                        wire:navigate
                        class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white rounded-lg bg-zinc-900 hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
                    >
                        Open karaoke player
                    </a>

                    <a
                        href="{{ route('karaoke.projects.edit', $project) }}"
                        wire:navigate
                        class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium rounded-lg border border-zinc-300 text-zinc-900 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-100 dark:hover:bg-zinc-800"
                    >
                        Edit lyrics & theme
                    </a>
                @else
                    <span class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium rounded-lg cursor-not-allowed bg-zinc-200 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400" title="Processing must complete before playback">
                        Karaoke player unavailable
                    </span>
                @endif

                <a
                    href="{{ route('karaoke.projects.source', $project) }}"
                    class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium rounded-lg border border-zinc-300 text-zinc-900 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-100 dark:hover:bg-zinc-800"
                >
                    Download source audio
                </a>

                <a href="{{ route('karaoke.projects.index') }}" wire:navigate class="text-sm text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200">
                    Back to projects
                </a>

                <form
                    method="POST"
                    action="{{ route('karaoke.projects.destroy', $project) }}"
                    class="ml-auto"
                    onsubmit="return confirm('Delete this karaoke project and its uploaded audio?');"
                >
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                        Delete project
                    </button>
                </form>
            </div>
        </div>
    </x-app.container>
</x-layouts.app>
