<x-layouts.app>
    <x-app.container>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <x-app.heading
                title="My karaoke projects"
                description="Upload and manage your karaoke source tracks."
                :border="false"
            />

            <a
                href="{{ route('karaoke.projects.create') }}"
                wire:navigate
                class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white rounded-lg bg-zinc-900 hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
            >
                Create karaoke
            </a>
        </div>

        @if (session('success'))
            <div class="p-4 mt-6 text-sm text-green-800 bg-green-50 border border-green-200 rounded-lg dark:bg-green-950/40 dark:text-green-200 dark:border-green-900" role="status">
                {{ session('success') }}
            </div>
        @endif

        @if ($projects->isEmpty())
            <div class="p-8 mt-8 text-center border border-dashed rounded-xl border-zinc-200 dark:border-zinc-700">
                <h4 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">No karaoke projects yet</h4>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Upload your first track to get started. Processing and playback arrive in later phases.</p>
                <a
                    href="{{ route('karaoke.projects.create') }}"
                    wire:navigate
                    class="inline-flex items-center justify-center px-4 py-2 mt-6 text-sm font-medium text-white rounded-lg bg-zinc-900 hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
                >
                    Create karaoke
                </a>
            </div>
        @else
            <div class="mt-8 overflow-hidden border rounded-xl border-zinc-200 dark:border-zinc-700">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-900/60">
                            <tr class="text-left text-zinc-500 dark:text-zinc-400">
                                <th class="px-4 py-3 font-medium">Title</th>
                                <th class="px-4 py-3 font-medium">Artist</th>
                                <th class="px-4 py-3 font-medium">File</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 font-medium">Size</th>
                                <th class="px-4 py-3 font-medium">Created</th>
                                <th class="px-4 py-3 font-medium"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($projects as $project)
                                <tr class="text-zinc-700 dark:text-zinc-200">
                                    <td class="px-4 py-3 font-medium">
                                        <a href="{{ route('karaoke.projects.show', $project) }}" wire:navigate class="hover:underline">
                                            {{ $project->title }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3">{{ $project->artist ?: '—' }}</td>
                                    <td class="px-4 py-3">{{ $project->original_filename }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-zinc-100 text-zinc-700 dark:bg-zinc-700/60 dark:text-zinc-200">
                                            {{ $project->status->label() }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">{{ number_format($project->size_bytes / 1024, 1) }} KB</td>
                                    <td class="px-4 py-3">{{ $project->created_at->format('M j, Y') }}</td>
                                    <td class="px-4 py-3">
                                        <form
                                            method="POST"
                                            action="{{ route('karaoke.projects.destroy', $project) }}"
                                            onsubmit="return confirm('Delete this karaoke project and its uploaded audio?');"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                                Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </x-app.container>
</x-layouts.app>
