<x-layouts.app>
    <x-app.container>
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
            <div class="p-4 mt-6 text-sm text-green-800 bg-green-50 border border-green-200 rounded-lg dark:bg-green-950/40 dark:text-green-200 dark:border-green-900" role="status">
                {{ session('success') }}
            </div>
        @endif

        <div class="p-6 mt-8 space-y-4 border rounded-xl border-zinc-200 dark:border-zinc-700">
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
                    <dd class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">{{ $project->progress }}%</dd>
                </div>
            </dl>
        </div>

        <div class="p-4 mt-6 text-sm border rounded-lg border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100">
            Processing, synchronized lyrics, and playback will arrive in later phases. Your source audio is stored privately on the server.
        </div>

        <div class="flex flex-wrap items-center gap-3 mt-8">
            <a
                href="{{ route('karaoke.projects.source', $project) }}"
                class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white rounded-lg bg-zinc-900 hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
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
    </x-app.container>
</x-layouts.app>
