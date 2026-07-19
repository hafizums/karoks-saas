<?php

namespace App\Http\Controllers;

use App\Support\Karaoke\Embed\KaraokeEmbedSecurityHeaders;
use App\Support\Karaoke\KaraokeProjectShareService;
use App\Support\KaraokeThemeParser;
use App\Support\KaraokeTranscriptParser;
use Illuminate\Http\Response;

class KaraokeEmbedController extends Controller
{
    public function show(
        string $share,
        string $token,
        KaraokeProjectShareService $shareService,
        KaraokeEmbedSecurityHeaders $securityHeaders,
    ): Response {
        $resolved = $shareService->resolveEmbeddableShare($share, $token);

        abort_if($resolved === null, 404);

        $project = $resolved->karaokeProject;
        $transcript = KaraokeTranscriptParser::parse($project->transcript);
        $theme = KaraokeThemeParser::parse($project->theme);

        abort_if($transcript === null, 404);

        $response = response()->view('theme::karaoke.embed-player', [
            'project' => $project,
            'transcript' => $transcript,
            'theme' => $theme,
            'themeCssVars' => KaraokeThemeParser::cssVariables($theme),
            'audioUrl' => $shareService->buildPublicAudioUrl($resolved, $token),
            'simulatedProcessing' => $project->processing_driver === 'mock',
        ]);

        return $securityHeaders->apply(
            $response,
            $resolved->embed_allowed_origins ?? [],
        );
    }
}
