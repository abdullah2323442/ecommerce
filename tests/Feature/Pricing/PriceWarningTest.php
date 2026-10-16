<?php

namespace Tests\Feature\Pricing;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Listing;
use App\Models\Medicine;
use App\Models\Sector;
use App\Models\Setting;
use App\Models\User;
use App\Services\Pricing\PriceWarning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec ch 04: a price far below the one before (50 instead of 500), or zero, is not refused but must be confirmed. */
class PriceWarningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private Medicine $napa;

    private Listing $chair;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box',
            'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 500, 'mrp_strip' => 170, 'mrp_piece' => 17, 'is_active' => true,
        ]);
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']])->fresh();
        $wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'piece', 'allow_fraction' => false]);
        $this->chair = Listing::create([
            'seller_id' => $this->seller->id, 'sector_id' => $wood->id, 'title' => 'Teak chair', 'public_price' => 500, 'min_qty' => 1, 'stock' => 5, 'status' => 'published',
            'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now(),
        ]);
    }

    public function test_the_rule_by_itself(): void
    {
        $w = app(PriceWarning::class);

        $this->assertNull($w->drop(null, 50), 'a first price is never a worry');
        $this->assertNull($w->drop(500, 600), 'a rise is fine');
        $this->assertNull($w->drop(500, 400), '20% lower is fine');
        $this->assertSame(90, $w->drop(500, 50));
        $this->assertSame(70, $w->drop(500, 150), 'exactly at the line counts');
        $this->assertSame(100, $w->drop(500, 0));
        $this->assertNull($w->drop(0, 0), 'nothing before it');
    }

    public function test_the_line_is_a_setting(): void
    {
        Setting::write('prices.warn_drop_percent', 40);

        $this->assertSame(50, app(PriceWarning::class)->drop(500, 250));
    }

    public function test_the_message_names_how_far(): void
    {
        $w = app(PriceWarning::class);

        $this->assertStringContainsString('90', $w->message(90));
        $this->assertNotSame($w->message(90), $w->message(100));
    }

    // ------------------------------------------------------------------ the printed price

    public function test_a_printed_price_far_below_the_old_one_asks_first_and_then_goes_through(): void
    {
        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}", ['mrp_box' => 50])->assertSessionHasErrors('price_warning');
        $this->assertSame('500.00', (string) $this->napa->fresh()->mrp_box);

        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}", ['mrp_box' => 50, 'confirm_price' => 1])->assertSessionHasNoErrors();
        $this->assertSame('50.00', (string) $this->napa->fresh()->mrp_box);
    }

    public function test_a_small_change_or_a_rise_goes_straight_through(): void
    {
        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}", ['mrp_box' => 450])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}", ['mrp_box' => 900])->assertSessionHasNoErrors();
        $this->assertSame('900.00', (string) $this->napa->fresh()->mrp_box);
    }

    public function test_zero_asks_too(): void
    {
        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}", ['mrp_strip' => 0])->assertSessionHasErrors('price_warning');
        $this->assertSame('170.00', (string) $this->napa->fresh()->mrp_strip);
    }

    public function test_a_price_that_was_not_changed_does_not_ask(): void
    {
        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}", ['mrp_box' => 500, 'mrp_strip' => 170])->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------------ a seller's price

    public function test_a_seller_dropping_the_price_far_is_asked_and_the_price_stays_until_they_agree(): void
    {
        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}", ['public_price' => 50])->assertSessionHasErrors('price_warning');
        $this->assertSame('500.00', (string) $this->chair->fresh()->public_price);

        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}", ['public_price' => 50, 'confirm_price' => 1])->assertSessionHasNoErrors();
        $this->assertSame('50.00', (string) $this->chair->fresh()->public_price);
    }

    public function test_a_small_drop_or_a_change_of_something_else_does_not_ask(): void
    {
        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}", ['public_price' => 400])->assertSessionHasNoErrors();
        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}", ['stock' => 9])->assertSessionHasNoErrors();
        $this->assertSame('400.00', (string) $this->chair->fresh()->public_price);
    }

    public function test_a_seller_cannot_use_the_staff_page_to_skip_the_warning(): void
    {
        $this->actingAs($this->seller)->put("/admin/stock/medicines/{$this->napa->id}", ['mrp_box' => 50, 'confirm_price' => 1])->assertRedirect('/');
        $this->assertSame('500.00', (string) $this->napa->fresh()->mrp_box);
    }
}
