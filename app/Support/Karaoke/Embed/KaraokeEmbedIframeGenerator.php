<?php

namespace App\Support\Karaoke\Embed;

class KaraokeEmbedIframeGenerator
{
    public function generate(string $embedUrl, string $title): string
    {
        return '<iframe'
            .' src="'.e($embedUrl).'"'
            .' title="'.e($title).'"'
            .' width="100%"'
            .' height="480"'
            .' style="min-height:480px;border:0;"'
            .' loading="lazy"'
            .' referrerpolicy="no-referrer"'
            .' allow="autoplay; fullscreen"'
            .' allowfullscreen'
            .'></iframe>';
    }
}
