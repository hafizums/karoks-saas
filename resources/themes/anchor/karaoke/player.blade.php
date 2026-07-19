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
                @include('theme::karaoke.partials.player-stage', [
                    'project' => $project,
                    'transcript' => $transcript,
                    'theme' => $theme,
                    'themeCssVars' => $themeCssVars,
                    'audioUrl' => $audioUrl,
                    'compact' => true,
                    'showMockBadge' => false,
                ])
            @endif
        </div>
    </x-app.container>
</x-layouts.app>
