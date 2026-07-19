<x-layouts.share :title="$project->title">
    <div class="overflow-hidden rounded-xl karoks-page">
        @include('theme::karaoke.partials.player-stage', [
            'project' => $project,
            'transcript' => $transcript,
            'theme' => $theme,
            'themeCssVars' => $themeCssVars,
            'audioUrl' => $audioUrl,
            'compact' => true,
            'showMockBadge' => $simulatedProcessing,
        ])
    </div>
</x-layouts.share>
