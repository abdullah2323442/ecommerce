<?php

namespace App\Services\Sales;

use App\Models\Invoice;
use App\Services\Media\ImageCompressor;
use App\Services\Stock\StockException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * What a buyer can say about an order besides what is in it (spec ch 23, "Notes and voice notes"): a written note
 * ("send before 10 am"), a voice note, a photo for what they cannot explain, and the time they need it by. All of it can be
 * added when the order is sent and, until it is done, afterwards.
 */
class OrderNotes
{
    /** Rules for the request that carries them. */
    public const RULES = [
        'note' => ['nullable', 'string', 'max:300'],
        'voice' => ['nullable', 'file', 'mimes:mp3,m4a,mp4,ogg,oga,wav,webm,weba', 'max:5120'],
        'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        'wanted_time' => ['nullable', 'date_format:H:i'],
    ];

    public function __construct(private ImageCompressor $images) {}

    /** The order can still take notes while it is not finished or cancelled. */
    public function open(Invoice $order): bool
    {
        return $order->channel === 'online' && in_array($order->status, ['placed', 'verified'], true);
    }

    /**
     * Save what came with the order. A new voice note or photo replaces the one before. Nothing here refuses an order.
     *
     * @param  array{note?: ?string, wanted_time?: ?string, voice?: ?UploadedFile, photo?: ?UploadedFile}  $extras
     */
    public function attach(Invoice $order, array $extras, bool $appendNote = false): Invoice
    {
        $changes = [];

        $note = isset($extras['note']) ? trim((string) $extras['note']) : '';
        if ($note !== '') {
            $changes['note'] = $appendNote && filled($order->note) ? mb_substr(trim($order->note.' | '.$note), 0, 600) : $note;
        }

        if (filled($extras['wanted_time'] ?? null)) {
            $changes['wanted_time'] = $extras['wanted_time'];
        }

        if (($extras['voice'] ?? null) instanceof UploadedFile) {
            $this->forget($order->voice_path);
            $changes['voice_path'] = $extras['voice']->store('order-voice');
        }

        if (($extras['photo'] ?? null) instanceof UploadedFile) {
            $this->forget($order->photo_path);
            $changes['photo_path'] = $this->images->store($extras['photo'], 'order-photos');
        }

        if ($changes !== []) {
            $order->update($changes);
        }

        return $order->fresh();
    }

    /** Add to an order that is already placed. */
    public function addLater(Invoice $order, array $extras): Invoice
    {
        if (! $this->open($order)) {
            throw new StockException('This order is finished, so a note can no longer be added.');
        }

        if (blank($extras['note'] ?? null) && blank($extras['wanted_time'] ?? null) && ! ($extras['voice'] ?? null) && ! ($extras['photo'] ?? null)) {
            throw new StockException('Write a note, record a voice note or add a photo first.');
        }

        return $this->attach($order, $extras, true);
    }

    private function forget(?string $path): void
    {
        if ($path !== null) {
            Storage::delete($path);
        }
    }
}
