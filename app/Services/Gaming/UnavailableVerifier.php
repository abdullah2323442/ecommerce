<?php

namespace App\Services\Gaming;

use App\Models\Game;

/** No provider is connected, so no name can be shown. The buyer checks the ID and confirms it. */
class UnavailableVerifier implements PlayerVerifier
{
    public function lookup(Game $game, string $playerId, ?string $zone): array
    {
        return ['status' => 'unavailable'];
    }
}
