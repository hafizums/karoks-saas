<?php

namespace App\Http\Controllers;

use App\Exceptions\KaraokeEditorConflictException;
use App\Http\Requests\ImportKaraokeProjectRequest;
use App\Http\Requests\UpdateKaraokeProjectEditorRequest;
use App\Models\KaraokeProject;
use App\Support\KaraokeProjectEditorService;
use App\Support\KaraokeProjectExporter;
use App\Support\KaraokeProjectImporter;
use App\Support\KaraokeThemeParser;
use App\Support\KaraokeTranscriptParser;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KaraokeProjectEditorController extends Controller
{
    public function edit(KaraokeProject $karaokeProject, KaraokeProjectEditorService $editorService): View
    {
        $this->authorize('edit', $karaokeProject);

        $transcript = KaraokeTranscriptParser::parse($karaokeProject->transcript);
        $theme = KaraokeThemeParser::parse($karaokeProject->theme);

        return view('theme::karaoke.editor', [
            'project' => $karaokeProject,
            'transcript' => $transcript,
            'theme' => $theme,
            'editorState' => $editorService->normalizedState($karaokeProject),
            'audioUrl' => route('karaoke.projects.audio', $karaokeProject),
            'updateUrl' => route('karaoke.projects.update', $karaokeProject),
            'exportUrl' => route('karaoke.projects.export', $karaokeProject),
            'importUrl' => route('karaoke.projects.import', $karaokeProject),
        ]);
    }

    public function update(
        UpdateKaraokeProjectEditorRequest $request,
        KaraokeProject $karaokeProject,
        KaraokeProjectEditorService $editorService,
    ): JsonResponse {
        $this->authorize('update', $karaokeProject);

        if (! $karaokeProject->isReadyForEditing()) {
            return response()->json([
                'message' => 'Lyrics are not ready for editing.',
            ], 422);
        }

        try {
            $state = $editorService->update($karaokeProject, $request->validated());

            return response()->json($state);
        } catch (KaraokeEditorConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'conflict' => true,
                'state' => $exception->latestState,
            ], 409);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function export(KaraokeProject $karaokeProject): StreamedResponse|JsonResponse
    {
        $this->authorize('export', $karaokeProject);

        if (! $karaokeProject->isReadyForEditing()) {
            return response()->json([
                'message' => 'Lyrics are not ready for export.',
            ], 422);
        }

        try {
            $payload = KaraokeProjectExporter::buildPayload($karaokeProject);
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $filename = KaraokeProjectExporter::downloadFilename($karaokeProject);

            return response()->streamDownload(
                static function () use ($json): void {
                    echo $json;
                },
                $filename,
                [
                    'Content-Type' => 'application/json; charset=UTF-8',
                ],
            );
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Unable to export this project.',
            ], 422);
        }
    }

    public function import(
        ImportKaraokeProjectRequest $request,
        KaraokeProject $karaokeProject,
        KaraokeProjectEditorService $editorService,
    ): JsonResponse {
        $this->authorize('import', $karaokeProject);

        if (! $karaokeProject->isReadyForEditing()) {
            return response()->json([
                'message' => 'Lyrics are not ready for import.',
            ], 422);
        }

        try {
            $contents = (string) file_get_contents($request->file('import')->getRealPath());
            $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            $parsed = KaraokeProjectImporter::parseImportPayload($payload, $karaokeProject);
            $state = $editorService->import(
                $karaokeProject,
                $parsed,
                (int) $request->integer('revision'),
            );

            return response()->json($state);
        } catch (KaraokeEditorConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'conflict' => true,
                'state' => $exception->latestState,
            ], 409);
        } catch (InvalidArgumentException|\JsonException $exception) {
            return response()->json([
                'message' => $exception instanceof InvalidArgumentException
                    ? $exception->getMessage()
                    : 'Import file must contain valid JSON.',
            ], 422);
        }
    }
}
