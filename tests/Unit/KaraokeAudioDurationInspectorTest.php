<?php

use App\Support\Karaoke\Processing\KaraokeAudioDurationInspector;
use App\Support\Karaoke\Providers\KaraokeTranscriptNormalizer;

it('reads duration from vbr mp3 info headers', function () {
    $fixture = storage_path('app/private/karaoke/1/6458a32c-a2b7-4091-9133-446ed266f023/source.mp3');

    if (! is_file($fixture)) {
        test()->markTestSkipped('Local VBR MP3 fixture unavailable.');
    }

    $duration = app(KaraokeAudioDurationInspector::class)->inspectFile($fixture, 'audio/mpeg');

    expect($duration['readable'])->toBeTrue()
        ->and($duration['duration_seconds'])->toBeGreaterThanOrEqual(30)
        ->and($duration['duration_seconds'])->toBeLessThanOrEqual(31);
});

it('does not drop provider words that exceed an understated project duration', function () {
    $transcript = app(KaraokeTranscriptNormalizer::class)->normalize([
        'words' => [
            ['type' => 'word', 'text' => 'Hello', 'start' => 0.2, 'end' => 0.6],
            ['type' => 'word', 'text' => 'world', 'start' => 0.7, 'end' => 1.1],
            ['type' => 'word', 'text' => 'again', 'start' => 20.4, 'end' => 20.9],
        ],
    ], 14.0, 'test-public-id');

    expect(collect($transcript['lines'])->flatMap(fn (array $line) => $line['words'])->pluck('text')->all())
        ->toBe(['Hello', 'world', 'again']);
});
