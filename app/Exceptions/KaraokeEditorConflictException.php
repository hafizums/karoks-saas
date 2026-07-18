<?php

namespace App\Exceptions;

use Exception;

class KaraokeEditorConflictException extends Exception
{
    /**
     * @param  array<string, mixed>  $latestState
     */
    public function __construct(public readonly array $latestState)
    {
        parent::__construct('The project was updated elsewhere. Reload the latest version before saving again.');
    }
}
