<?php

namespace App\Services\Analytics;

use App\Enums\AccountType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What visitors and customers look for and do (spec ch 27: store behaviour from day one). It is only a record: it never
 * changes what a page shows, and a failure here must never break a page. Staff and the owner are left out so the numbers
 * are about customers. A visitor is a hash of a random token kept in their session, so nothing here names a person unless they are signed in.
 */
class Behavior
{
    public const TYPES = ['search', 'view', 'cart', 'checkout', 'preorder', 'listing_view'];

    public function record(string $type, ?int $subjectId = null, ?string $term = null, array $meta = []): void
    {
        try {
            $user = auth()->user();

            if (! in_array($type, self::TYPES, true) || in_array($user?->account_type, [AccountType::Staff, AccountType::Admin], true) || ! request()->hasSession()) {
                return;
            }

            // A random token kept in the session, not the session id: the id changes when someone signs in, and one visit would split in two.
            $session = request()->session();
            $token = $session->get('behavior_token') ?? tap(Str::random(32), fn ($t) => $session->put('behavior_token', $t));
            $visitor = substr(hash('sha256', 'visitor|'.$token), 0, 40);

            // Reloading a page is not interest: count a medicine once per visitor per half hour.
            if (in_array($type, ['view', 'listing_view'], true) && $subjectId !== null) {
                if (! Cache::add("behavior.$type.$visitor.$subjectId", 1, now()->addMinutes(30))) {
                    return;
                }
            }

            DB::table('behavior_events')->insert([
                'type' => $type, 'user_id' => $user?->id, 'visitor' => $visitor, 'subject_id' => $subjectId,
                'term' => $term === null ? null : mb_substr(trim($term), 0, 100), 'meta' => $meta ? json_encode($meta) : null, 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
