<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pictures people upload are made smaller on the way in (spec ch 28: "images auto-compressed on upload"), and saved as
 * progressive JPEGs, which show a rough picture first and sharpen as the rest arrives: the "low-quality image first, the good one
 * after" that helps on slow internet. A phone photo of 5 MB becomes a few hundred KB and is still readable.
 *
 * Anything that is not a picture (a PDF) is stored as it came. If a picture cannot be read it is stored as it came too, never lost.
 */
class ImageCompressor
{
    /** Longest side in pixels. A prescription or a licence must stay readable, so this is generous. */
    public const MAX_SIDE = 1600;

    public const QUALITY = 82;

    /** More pixels than this are not decoded: the memory they need is too much for one request. */
    private const MAX_PIXELS = 40_000_000;

    /** Store the upload on the default disk in $directory and return its path, compressed when it is a picture. */
    public function store(UploadedFile $file, string $directory, int $maxSide = self::MAX_SIDE): string
    {
        $bytes = $this->compress((string) $file->get(), $maxSide);

        if ($bytes === null) {
            return $file->store($directory);
        }

        $path = trim($directory, '/').'/'.Str::random(40).'.jpg';
        Storage::put($path, $bytes);

        return $path;
    }

    /** The compressed JPEG bytes of a picture, or null when this is not a picture we can read. */
    public function compress(string $contents, int $maxSide = self::MAX_SIDE): ?string
    {
        if (! function_exists('imagecreatefromstring') || $contents === '') {
            return null;
        }

        $info = @getimagesizefromstring($contents);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true) || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }

        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            return null;
        }

        $source = $this->upright($source, $contents, $info[2]);
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, $maxSide / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        // A flat white page behind it, so a transparent PNG does not turn black as a JPEG.
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        imageinterlace($canvas, true);   // progressive: rough first, sharp after
        ob_start();
        imagejpeg($canvas, null, self::QUALITY);
        $out = (string) ob_get_clean();

        if ($out === '') {
            return null;
        }

        // Never make a small picture bigger: a JPEG that is already smaller than our copy is kept as it is.
        return $info[2] === IMAGETYPE_JPEG && strlen($out) >= strlen($contents) ? $contents : $out;
    }

    /** Phones store a photo sideways and note it in the file; turn it the right way up so a prescription is not read on its side. */
    private function upright($image, string $contents, int $type)
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($contents));
        $turn = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180, 6 => -90, 8 => 90, default => 0,
        };

        if ($turn === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $turn, 0);

        return $rotated === false ? $image : $rotated;
    }
}
