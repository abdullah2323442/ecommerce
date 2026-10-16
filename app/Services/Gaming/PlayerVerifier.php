<?php

namespace App\Services\Gaming;

use App\Models\Game;

/**
 * Looks up the player behind a game ID so the buyer sees the name before paying (spec ch 18). The real lookup comes from a top-up provider,
 * which is not chosen yet; until then nothing is checked and the buyer is asked to check the ID themselves.
 */
interface PlayerVerifier
{
    /** @return array{status: 'verified'|'invalid'|'unavailable', name?: string} */
    public function lookup(Game $game, string $playerId, ?string $zone): array;
}
