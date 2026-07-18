<?php

namespace App\Support;

readonly class KaraokeProcessingResult
{
    /**
     * @param  array{version: int, lines: list<array<string, mixed>>}  $transcript
     * @param  array<string, mixed>  $theme
     */
    public function __construct(
        public string $instrumentalPath,
        public string $instrumentalMimeType,
        public array $transcript,
        public array $theme,
        public string $disclosure,
        public bool $simulated = true,
    ) {}
}
