<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One message between this site and PharmaTrack. */
class SyncEvent extends Model
{
    protected $fillable = ['direction', 'kind', 'ref', 'status', 'attempts', 'summary', 'error', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'attempts' => 'integer'];
    }

    /**
     * A note kept as translatable parts, so it reads in the language of whoever opens the screen and not of whoever sent the message.
     * A part is a sentence and its values; a value named why is itself a sentence and is translated too.
     *
     * @param  list<array{0: string, 1?: array<string, scalar>}>  $parts
     */
    public static function note(array $parts): string
    {
        return (string) json_encode($parts, JSON_UNESCAPED_UNICODE);
    }

    public function summaryText(): ?string
    {
        return self::read($this->summary);
    }

    public function errorText(): ?string
    {
        return self::read($this->error);
    }

    private static function read(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $parts = json_decode($stored, true);

        if (! is_array($parts) || ! array_is_list($parts)) {
            return __($stored);
        }

        return collect($parts)->map(function ($part) {
            $values = collect($part[1] ?? [])->map(fn ($v, $k) => $k === 'why' ? __((string) $v) : $v)->all();

            return __((string) $part[0], $values);
        })->implode(' ');
    }
}
