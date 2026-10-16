<?php

namespace App\Services\Market;

use App\Models\Listing;
use App\Models\ListingReport;
use App\Models\Notice;
use App\Models\User;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * "Someone copied a seller's product: same photos, same description" (spec ch 14). Anyone signed in can report a listing; we look, and
 * remove it or turn the report down. To help the look, we show which other listings share its picture or its words.
 */
class ListingReports
{
    public function __construct(private Penalties $penalties, private Messenger $messages) {}

    /** @throws StockException */
    public function report(Listing $listing, User $by, string $reason): ListingReport
    {
        if ($listing->seller_id === $by->id) {
            throw new StockException('This is your own product.');
        }

        if (mb_strlen(trim($reason)) < 3) {
            throw new StockException('Say what is copied.');
        }

        if (ListingReport::where('listing_id', $listing->id)->where('reporter_id', $by->id)->exists()) {
            throw new StockException('You have already reported this.');
        }

        $report = ListingReport::create(['listing_id' => $listing->id, 'reporter_id' => $by->id, 'reason' => mb_substr(trim($reason), 0, 300), 'status' => 'open']);

        foreach (User::withPermission('customers') as $reviewer) {
            Notice::create(['user_id' => $reviewer->id, 'kind' => 'listing_report', 'body' => mb_substr('"'.$listing->title.'"', 0, 290)]);
        }

        return $report;
    }

    /**
     * Other listings that look like this one: the same picture (the very same file) or the same title once case and spaces are ignored.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lookAlikes(Listing $listing): Collection
    {
        $mine = $listing->photo_path !== null && Storage::exists($listing->photo_path) ? md5((string) Storage::get($listing->photo_path)) : null;
        $title = $this->plain($listing->title);

        return Listing::where('id', '!=', $listing->id)->where('seller_id', '!=', $listing->seller_id)->whereIn('status', ['published', 'pending', 'paused'])->with('seller:id,name,business_name')->get()
            ->map(function (Listing $other) use ($mine, $title) {
                $samePhoto = $mine !== null && $other->photo_path !== null && Storage::exists($other->photo_path) && md5((string) Storage::get($other->photo_path)) === $mine;
                $sameTitle = $this->plain($other->title) === $title;

                return ($samePhoto || $sameTitle)
                    ? ['id' => $other->id, 'title' => $other->title, 'seller' => $other->seller->business_name ?: $other->seller->name, 'seller_id' => $other->seller_id, 'same_photo' => $samePhoto, 'same_title' => $sameTitle, 'since' => ($other->approved_at ?? $other->created_at)?->toIso8601String()]
                    : null;
            })->filter()->values();
    }

    /**
     * @param  'remove'|'dismiss'  $decision
     *
     * @throws StockException
     */
    public function resolve(ListingReport $report, User $by, string $decision, string $note): ListingReport
    {
        if ($report->status !== 'open') {
            throw new StockException('This report was already looked at.');
        }

        if (! in_array($decision, ['remove', 'dismiss'], true)) {
            throw new StockException('Choose to remove it or turn the report down.');
        }

        if (mb_strlen(trim($note)) < 3) {
            throw new StockException('Write a note.');
        }

        $listing = $report->listing()->with('seller')->firstOrFail();

        $meta = ['our_note' => mb_substr(trim($note), 0, 300), 'decided_at' => now(), 'decided_by' => $by->id];

        if ($decision === 'remove') {
            $this->penalties->issue($listing->seller, $by, 'listing_removed', 'Copied from another seller: '.trim($note), $listing);
            // Everyone who reported this same listing gets the same answer.
            $batch = ListingReport::where('listing_id', $listing->id)->where('status', 'open')->get();
            ListingReport::whereKey($batch->pluck('id'))->update($meta + ['status' => 'removed']);
        } else {
            $report->update($meta + ['status' => 'dismissed']);
            $batch = collect([$report]);
        }

        foreach ($batch as $one) {
            if ($one->reporter_id && ($reporter = User::find($one->reporter_id))) {
                $this->messages->send($reporter, 'listing_report_done', ['name' => $listing->title, 'note' => $meta['our_note'], 'removed' => $decision === 'remove'], null);
            }
        }

        $report = $report->fresh();

        return $report;
    }

    private function plain(string $title): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($title)) ?? '';
    }
}
