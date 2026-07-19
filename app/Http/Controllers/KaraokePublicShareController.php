<?php

namespace App\Http\Controllers;

use App\Support\Karaoke\KaraokeProjectShareService;
use App\Support\KaraokeAudioStreamService;
use App\Support\KaraokeThemeParser;
use App\Support\KaraokeTranscriptParser;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class KaraokePublicShareController extends Controller
{
    public function show(
        string $share,
        string $token,
        KaraokeProjectShareService $shareService,
    ): View {
        $resolved = $shareService->resolvePublicShare($share, $token);

        abort_if($resolved === null, 404);

        $project = $resolved->karaokeProject;
        $transcript = KaraokeTranscriptParser::parse($project->transcript);
        $theme = KaraokeThemeParser::parse($project->theme);

        abort_if($transcript === null, 404);

        return view('theme::karaoke.shared-player', [
            'project' => $project,
            'transcript' => $transcript,
            'theme' => $theme,
            'themeCssVars' => KaraokeThemeParser::cssVariables($theme),
            'audioUrl' => $shareService->buildPublicAudioUrl($resolved, $token),
            'simulatedProcessing' => $project->processing_driver === 'mock',
        ]);
    }

    public function audio(
        Request $request,
        string $share,
        string $token,
        KaraokeProjectShareService $shareService,
        KaraokeAudioStreamService $audioStreamService,
    ): Response {
        $resolved = $shareService->resolvePublicShare($share, $token);

        abort_if($resolved === null, 404);

        return $audioStreamService->respond(
            $resolved->karaokeProject,
            $request->header('Range'),
            $request->isMethod('HEAD'),
        );
    }
}
