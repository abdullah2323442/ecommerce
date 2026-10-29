<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Spec ch 28: fast on a phone. A shop tile asks for a smaller copy of the sector picture instead of the 2400 pixel original. */
class SectorHeroSizeTest extends TestCase
{
    use RefreshDatabase;

    private Sector $wood;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();

        $canvas = imagecreatetruecolor(2400, 1200);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 120, 80, 40));
        ob_start();
        imagejpeg($canvas, null, 90);
        Storage::put('sectors/hero.jpg', (string) ob_get_clean());

        $this->wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'piece', 'hero_path' => 'sectors/hero.jpg', 'is_active' => true]);
    }

    private function widthOf(string $url): int
    {
        $response = $this->get($url)->assertOk();
        ob_start();
        $response->baseResponse->sendContent();

        return getimagesizefromstring((string) ob_get_clean())[0];
    }

    public function test_a_smaller_copy_is_made_once_and_kept(): void
    {
        $this->assertSame(2400, $this->widthOf('/sector-hero/wood'));

        $this->assertSame(480, $this->widthOf('/sector-hero/wood?w=480'));
        Storage::assertExists('sectors/w480/hero.jpg');

        $this->assertSame(960, $this->widthOf('/sector-hero/wood?w=960'));
    }

    public function test_a_width_that_is_not_offered_gets_the_original(): void
    {
        $this->assertSame(2400, $this->widthOf('/sector-hero/wood?w=123'));
        Storage::assertMissing('sectors/w123/hero.jpg');
    }

    public function test_a_hidden_sector_is_still_not_served_in_any_size(): void
    {
        $this->wood->update(['is_active' => false]);

        $this->get('/sector-hero/wood?w=480')->assertRedirect('/');
    }

    public function test_the_shop_tiles_ask_for_the_smaller_copy(): void
    {
        $seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']])->fresh();
        Listing::create([
            'seller_id' => $seller->id, 'sector_id' => $this->wood->id, 'title' => 'Teak chair', 'public_price' => 1000, 'min_qty' => 1, 'stock' => 5, 'status' => 'published',
            'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now(),
        ]);

        $this->assertStringContainsString('w=960', json_encode($this->get('/')->viewData('page')['props']));
    }
}
