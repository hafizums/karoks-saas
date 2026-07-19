<?php

namespace App\Http\Controllers;

use App\Models\KaraokeProject;
use App\Support\KaraokeThemeParser;
use App\Support\KaraokeTranscriptParser;
use Illuminate\Contracts\View\View;

class KaraokeProjectVideoExportController extends Controller
{
    public function show(KaraokeProject $karaokeProject): View
    {
        $this->authorize('exportVideo', $karaokeProject);

        if (! $karaokeProject->isReadyForPlayback()) {
            abort(403);
        }

        $transcript = KaraokeTranscriptParser::parse($karaokeProject->transcript);

        if ($transcript === null) {
            abort(403);
        }

        if ($karaokeProject->playbackAudioPath() === null) {
            abort(403);
        }

        $theme = KaraokeThemeParser::parse($karaokeProject->theme);

        return view('theme::karaoke.export-video', [
            'project' => $karaokeProject,
            'transcript' => $transcript,
            'theme' => $theme,
            'audioUrl' => route('karaoke.projects.audio', $karaokeProject),
        ]);
    }
}
