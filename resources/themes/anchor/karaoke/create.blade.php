<x-layouts.app>
    <x-app.container>
        <x-app.heading
            title="Create karaoke"
            description="Upload a source audio file for a new karaoke project."
            :border="false"
        />

        @include('theme::karaoke.partials.usage-summary')

        <form method="POST" action="{{ route('karaoke.projects.store') }}" enctype="multipart/form-data" class="mt-8 space-y-6 max-w-2xl">
            @csrf

            <div>
                <label for="title" class="block mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-200">Title</label>
                <input
                    id="title"
                    name="title"
                    type="text"
                    value="{{ old('title') }}"
                    required
                    maxlength="191"
                    class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-zinc-900 border-zinc-200 dark:border-zinc-700 dark:text-zinc-100 focus:ring-2 focus:ring-zinc-200 dark:focus:ring-zinc-700"
                >
                @error('title')
                    <p class="mt-2 text-sm text-red-600 dark:text-red-400" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="artist" class="block mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-200">Artist <span class="text-zinc-400">(optional)</span></label>
                <input
                    id="artist"
                    name="artist"
                    type="text"
                    value="{{ old('artist') }}"
                    maxlength="191"
                    class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-zinc-900 border-zinc-200 dark:border-zinc-700 dark:text-zinc-100 focus:ring-2 focus:ring-zinc-200 dark:focus:ring-zinc-700"
                >
                @error('artist')
                    <p class="mt-2 text-sm text-red-600 dark:text-red-400" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="audio" class="block mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-200">Audio file</label>
                <div class="p-6 border border-dashed rounded-xl border-zinc-300 dark:border-zinc-600 bg-zinc-50 dark:bg-zinc-900/40">
                    <input
                        id="audio"
                        name="audio"
                        type="file"
                        accept=".mp3,.wav,.m4a,.flac,audio/mpeg,audio/wav,audio/mp4,audio/flac"
                        required
                        class="block w-full text-sm text-zinc-600 dark:text-zinc-300 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-zinc-900 file:text-white hover:file:bg-zinc-800 dark:file:bg-zinc-100 dark:file:text-zinc-900"
                    >
                    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">MP3, WAV, M4A, or FLAC · up to 50 MB</p>
                </div>
                @error('audio')
                    <p class="mt-2 text-sm text-red-600 dark:text-red-400" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="flex items-start gap-3 text-sm text-zinc-700 dark:text-zinc-200">
                    <input
                        type="checkbox"
                        name="rights_confirmed"
                        value="1"
                        @checked(old('rights_confirmed'))
                        class="mt-1 rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-900"
                    >
                    <span>I own this audio or have permission to process it.</span>
                </label>
                @error('rights_confirmed')
                    <p class="mt-2 text-sm text-red-600 dark:text-red-400" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white rounded-lg bg-zinc-900 hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
                >
                    Upload project
                </button>
                <a href="{{ route('karaoke.projects.index') }}" wire:navigate class="text-sm text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200">
                    Cancel
                </a>
            </div>
        </form>
    </x-app.container>
</x-layouts.app>
