<?php

namespace App\Http\Controllers;

use App\Enums\KaraokeProjectStatus;
use App\Http\Requests\StoreKaraokeProjectRequest;
use App\Models\KaraokeProject;
use App\Rules\ValidKaraokeAudio;
use App\Support\Karaoke\Processing\KaraokeAudioDurationInspector;
use App\Support\KaraokeAudioStreamService;
use App\Support\Karaoke\KaraokeProjectShareService;
use App\Support\KaraokeProcessingStateService;
use App\Support\KaraokeThemeParser;
use App\Support\KaraokeTranscriptParser;
use App\Support\KaraokeUsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KaraokeProjectController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', KaraokeProject::class);

        $projects = auth()->user()
            ->karaokeProjects()
            ->latest()
            ->get();

        return view('theme::karaoke.index', [
            'projects' => $projects,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', KaraokeProject::class);

        return view('theme::karaoke.create', [
            'usageSummary' => app(KaraokeUsageService::class)->summary(auth()->user()),
            'processingEnabled' => app(KaraokeUsageService::class)->processingEnabled(),
        ]);
    }

    public function store(StoreKaraokeProjectRequest $request): RedirectResponse
    {
        $this->authorize('create', KaraokeProject::class);

        /** @var UploadedFile $audio */
        $audio = $request->file('audio');
        $publicId = (string) Str::uuid();
        $userId = (int) $request->user()->id;
        $detectedMime = strtolower((string) $audio->getMimeType());
        $safeExtension = ValidKaraokeAudio::safeExtensionFromMime($detectedMime);

        if ($safeExtension === null) {
            return back()
                ->withInput()
                ->withErrors(['audio' => 'This file type does not look like supported audio.']);
        }

        $directory = 'karaoke/'.$userId.'/'.$publicId;
        $sourcePath = $directory.'/source.'.$safeExtension;
        $storedPath = null;

        try {
            $project = DB::transaction(function () use ($request, $audio, $publicId, $userId, $detectedMime, $sourcePath, &$storedPath) {
                $storedPath = Storage::disk('local')->putFileAs(
                    dirname($sourcePath),
                    $audio,
                    basename($sourcePath)
                );

                if ($storedPath === false) {
                    throw new \RuntimeException('Unable to store uploaded audio.');
                }

                $durationInspection = app(KaraokeAudioDurationInspector::class)->inspectFile(
                    Storage::disk('local')->path($storedPath),
                    $detectedMime,
                );

                $durationSeconds = $durationInspection['readable'] === true
                    ? (int) $durationInspection['duration_seconds']
                    : null;

                return KaraokeProject::create([
                    'public_id' => $publicId,
                    'user_id' => $userId,
                    'title' => $request->string('title')->toString(),
                    'artist' => $request->filled('artist') ? $request->string('artist')->toString() : null,
                    'original_filename' => basename($audio->getClientOriginalName()),
                    'source_path' => $storedPath,
                    'mime_type' => $detectedMime,
                    'size_bytes' => $audio->getSize(),
                    'duration_seconds' => $durationSeconds,
                    'status' => KaraokeProjectStatus::Uploaded,
                    'progress' => 0,
                    'rights_confirmed_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
                Storage::disk('local')->deleteDirectory('karaoke/'.$userId.'/'.$publicId);
            }

            throw $exception;
        }

        return redirect()
            ->route('karaoke.projects.show', $project)
            ->with('success', 'Your karaoke project was uploaded successfully.');
    }

    public function show(KaraokeProject $karaokeProject): View
    {
        $this->authorize('view', $karaokeProject);

        $shareService = app(KaraokeProjectShareService::class);
        $activeShare = $shareService->activeShareForProject($karaokeProject);

        return view('theme::karaoke.show', [
            'project' => $karaokeProject,
            'isReadyForPlayback' => $karaokeProject->isReadyForPlayback(),
            'processingStatus' => app(KaraokeProcessingStateService::class)->statusPayload($karaokeProject, auth()->user()),
            'usageSummary' => app(KaraokeUsageService::class)->summary(auth()->user()),
            'processingEnabled' => app(KaraokeUsageService::class)->processingEnabled(),
            'activeShare' => $activeShare,
            'shareUrl' => session('share_url') ?? ($activeShare ? $shareService->ownerShareUrl($activeShare) : null),
        ]);
    }

    public function player(KaraokeProject $karaokeProject): View
    {
        $this->authorize('play', $karaokeProject);

        $transcript = KaraokeTranscriptParser::parse($karaokeProject->transcript);
        $theme = KaraokeThemeParser::parse($karaokeProject->theme);

        return view('theme::karaoke.player', [
            'project' => $karaokeProject,
            'transcript' => $transcript,
            'theme' => $theme,
            'themeCssVars' => KaraokeThemeParser::cssVariables($theme),
            'audioUrl' => route('karaoke.projects.audio', $karaokeProject),
        ]);
    }

    public function audio(
        Request $request,
        KaraokeProject $karaokeProject,
        KaraokeAudioStreamService $audioStreamService,
    ): Response {
        $this->authorize('streamAudio', $karaokeProject);

        return $audioStreamService->respond(
            $karaokeProject,
            $request->header('Range'),
            $request->isMethod('HEAD'),
        );
    }

    public function source(KaraokeProject $karaokeProject): StreamedResponse
    {
        $this->authorize('downloadSource', $karaokeProject);

        abort_unless(
            Storage::disk('local')->exists($karaokeProject->source_path),
            404
        );

        return Storage::disk('local')->download(
            $karaokeProject->source_path,
            $karaokeProject->original_filename,
            ['Content-Type' => $karaokeProject->mime_type]
        );
    }

    public function destroy(KaraokeProject $karaokeProject): RedirectResponse
    {
        $this->authorize('delete', $karaokeProject);

        $karaokeProject->delete();

        return redirect()
            ->route('karaoke.projects.index')
            ->with('success', 'Karaoke project deleted.');
    }
}
