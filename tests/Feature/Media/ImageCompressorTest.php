<?php

namespace Tests\Feature\Media;

use App\Enums\AccountType;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Media\ImageCompressor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Spec ch 28: pictures are made smaller when uploaded, and saved so a rough picture shows first and sharpens after. */
class ImageCompressorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
    }

    private function noisy(int $w, int $h, string $type = 'jpeg'): string
    {
        $img = imagecreatetruecolor($w, $h);
        for ($i = 0; $i < 4000; $i++) {
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), random_int(0, 0xFFFFFF));
        }
        imagefilledellipse($img, intdiv($w, 2), intdiv($h, 2), intdiv($w, 2), intdiv($h, 3), 0x3366CC);
        ob_start();
        $type === 'png' ? imagepng($img, null, 0) : imagejpeg($img, null, 100);

        return (string) ob_get_clean();
    }

    private function dims(string $path): array
    {
        $info = getimagesizefromstring(Storage::get($path));

        return [$info[0], $info[1], $info[2]];
    }

    public function test_a_big_picture_is_scaled_down_and_made_smaller(): void
    {
        $original = $this->noisy(3200, 2400, 'png');
        $file = UploadedFile::fake()->createWithContent('rx.png', $original);

        $path = app(ImageCompressor::class)->store($file, 'prescriptions');

        [$w, $h, $type] = $this->dims($path);
        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertSame(1600, $w);
        $this->assertSame(1200, $h);
        $this->assertStringEndsWith('.jpg', $path);
        $this->assertLessThan(strlen($original) / 4, Storage::size($path));
    }

    public function test_it_is_saved_progressive_so_a_rough_picture_comes_first(): void
    {
        $path = app(ImageCompressor::class)->store(UploadedFile::fake()->createWithContent('rx.jpg', $this->noisy(2400, 1800)), 'prescriptions');

        // A progressive JPEG carries a "start of frame 2" marker; a baseline one carries frame 0.
        $bytes = Storage::get($path);
        $this->assertStringContainsString("\xFF\xC2", $bytes);
        $this->assertStringNotContainsString("\xFF\xC0\x00\x11", $bytes);
    }

    public function test_a_small_picture_is_not_enlarged_and_a_small_jpeg_is_not_made_bigger(): void
    {
        $small = $this->noisy(400, 300);
        $path = app(ImageCompressor::class)->store(UploadedFile::fake()->createWithContent('a.jpg', $small), 'prescriptions');

        [$w, $h] = $this->dims($path);
        $this->assertSame([400, 300], [$w, $h]);
        $this->assertLessThanOrEqual(strlen($small), Storage::size($path));
    }

    public function test_the_longest_side_limit_can_be_set_for_documents(): void
    {
        $path = app(ImageCompressor::class)->store(UploadedFile::fake()->createWithContent('licence.png', $this->noisy(3000, 2000, 'png')), 'licenses', 2000);

        $this->assertSame([2000, 1333], array_slice($this->dims($path), 0, 2));
    }

    public function test_a_transparent_png_gets_a_white_page_not_a_black_one(): void
    {
        $img = imagecreatetruecolor(50, 50);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        $path = app(ImageCompressor::class)->store(UploadedFile::fake()->createWithContent('logo.png', $png), 'licenses');

        $pixel = imagecolorat(imagecreatefromstring(Storage::get($path)), 25, 25);
        $this->assertGreaterThan(0xF0F0F0, $pixel);
    }

    public function test_a_pdf_and_something_that_only_looks_like_a_picture_are_kept_as_they_came(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('licence.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n");
        $path = app(ImageCompressor::class)->store($pdf, 'licenses');
        $this->assertStringEndsWith('.pdf', $path);
        $this->assertStringContainsString('%PDF-1.4', Storage::get($path));

        $broken = UploadedFile::fake()->createWithContent('rx.png', 'this is not a picture at all');
        $path = app(ImageCompressor::class)->store($broken, 'prescriptions');
        $this->assertSame('this is not a picture at all', Storage::get($path));
    }

    public function test_a_phone_photo_stored_sideways_is_turned_the_right_way_up(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('The exif extension is not installed.');
        }

        // A 200 wide, 100 high picture whose file says "turn it 90 degrees" (orientation 6): it should come out 100 wide and 200 high.
        $tiff = "II*\x00\x08\x00\x00\x00"."\x01\x00"."\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00"."\x00\x00\x00\x00";
        $app1 = "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\x00\x00".$tiff;
        $jpeg = $this->noisy(200, 100);
        $sideways = substr($jpeg, 0, 2).$app1.substr($jpeg, 2);

        $path = app(ImageCompressor::class)->store(UploadedFile::fake()->createWithContent('rx.jpg', $sideways), 'prescriptions');

        $this->assertSame([100, 200], array_slice($this->dims($path), 0, 2));
    }

    public function test_a_prescription_uploaded_by_a_shop_is_compressed_on_the_way_in(): void
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $big = $this->noisy(2400, 1800);

        $this->actingAs($shop)->post('/paikari/prescriptions', ['file' => UploadedFile::fake()->createWithContent('rx.jpg', $big)])->assertSessionHasNoErrors();

        $rx = Prescription::firstOrFail();
        $this->assertSame(1600, $this->dims($rx->path)[0]);
        $this->assertLessThan(strlen($big) / 2, Storage::size($rx->path));
    }
}
