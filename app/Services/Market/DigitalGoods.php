<?php

namespace App\Services\Market;

use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\User;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Digital products (spec ch 13). The seller attaches one file; the buyer pays online; the moment the payment is confirmed the order is complete and the
 * file can be downloaded, but only a few times and only for a few days, because one purchase posted in a Facebook group would otherwise be taken free by
 * hundreds. The file lives on a private disk (never in a public folder), is sent only through the buyer's private link, and is always sent as a download.
 */
class DigitalGoods
{
    public const MAX_KB = 51200;

    public const TYPES = ['pdf', 'epub', 'zip', 'mp3', 'mp4', 'jpg', 'jpeg', 'png', 'txt', 'docx', 'xlsx'];

    public function __construct(private Messenger $messages) {}

    private function disk()
    {
        return Storage::disk('local');
    }

    /**
     * Attach (or replace) the file of a listing. A digital product is always paid online, has no delivery and no shelf to count.
     *
     * @throws StockException
     */
    public function attach(Listing $listing, UploadedFile $file, int $max, int $days): Listing
    {
        if (! $file->isValid() || ! in_array(strtolower($file->getClientOriginalExtension()), self::TYPES, true) || $file->getSize() > self::MAX_KB * 1024) {
            throw new StockException('This file cannot be used. Send a PDF, e-book, music, video, picture, text, Word, Excel or ZIP file, up to 50 MB.');
        }

        $old = $listing->digital_path;
        $path = $file->storeAs('digital/'.$listing->id, bin2hex(random_bytes(16)).'.'.strtolower($file->getClientOriginalExtension()), 'local');

        $listing->update([
            'digital_path' => $path, 'digital_name' => $this->safeName($file->getClientOriginalName()), 'digital_max' => max(1, min(20, $max)), 'digital_days' => max(1, min(365, $days)),
            'cod_allowed' => false, 'stock' => null, 'delivery_charge' => 0, 'pickup' => false,
        ]);

        if ($old && $old !== $path) {
            $this->disk()->delete($old);
        }

        return $listing->fresh();
    }

    /** The file is taken off; the product goes back to being an ordinary one. Buyers who already paid can no longer download. */
    public function remove(Listing $listing): Listing
    {
        if ($listing->digital_path) {
            $this->disk()->delete($listing->digital_path);
        }

        $listing->update(['digital_path' => null, 'digital_name' => null]);

        return $listing->fresh();
    }

    /** Paid: the sale is complete and the download opens. Called once the payment has been confirmed with the gateway. */
    public function deliver(MarketOrder $order): void
    {
        $listing = $order->listing;

        if (! $listing || $listing->digital_path === null || $order->status !== 'placed') {
            return;
        }

        $order->update(['status' => 'delivered', 'delivered_at' => now(), 'download_max' => $listing->digital_max, 'downloads' => 0, 'download_until' => now()->addDays($listing->digital_days)]);

        if ($order->buyer instanceof User) {
            $this->messages->send($order->buyer, 'market_download_ready', ['name' => $order->title], '/market/orders/'.$order->public_token);
        }
    }

    /** What the buyer's page shows about the download. @return array<string, mixed>|null */
    public function panel(MarketOrder $order): ?array
    {
        $listing = $order->listing;

        if (! $listing || ($listing->digital_path === null && $order->download_max === 0)) {
            return null;
        }

        $ready = $order->paid_at !== null && $order->download_until !== null;

        return [
            'name' => $listing->digital_name, 'ready' => $ready && $listing->digital_path !== null && $order->download_until->isFuture() && $order->downloads < $order->download_max,
            'left' => $ready ? max(0, $order->download_max - $order->downloads) : 0, 'until' => $order->download_until?->toIso8601String(), 'paid' => $order->paid_at !== null,
            'over' => $ready && $order->download_until->isPast(), 'max' => $ready ? $order->download_max : (int) $listing->digital_max, 'days' => (int) $listing->digital_days,
        ];
    }

    /**
     * Hand over the file, counting this download. The count is taken in one statement so two taps at once cannot both use the last download.
     *
     * @throws StockException
     */
    public function download(MarketOrder $order): StreamedResponse
    {
        $listing = $order->listing;

        if (! $listing || $listing->digital_path === null || $order->paid_at === null || $order->status !== 'delivered' || $order->download_max < 1) {
            throw new StockException('There is nothing to download for this order.');
        }

        if ($order->download_until === null || $order->download_until->isPast()) {
            throw new StockException('The download time for this order is over.');
        }

        if (! $this->disk()->exists($listing->digital_path)) {
            throw new StockException('The file is not available now. Please tell us.');
        }

        $taken = MarketOrder::whereKey($order->id)->whereColumn('downloads', '<', 'download_max')->increment('downloads');

        if (! $taken) {
            throw new StockException('You have used all your downloads.');
        }

        return $this->disk()->download($listing->digital_path, $listing->digital_name ?: 'download', ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /** A name that is safe to put in a header and on a buyer's disk. */
    private function safeName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename($name)) ?? 'download';

        return mb_substr(trim($name, '. '), 0, 100) ?: 'download';
    }
}
