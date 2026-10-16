<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\SavedCart;
use App\Models\User;
use App\Services\Sales\PublicCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Spec ch 27 (Phase 2): a buyer who left things in the cart is reminded once, a day later. */
class AbandonedCartTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box',
            'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function told(): int
    {
        return Notice::where('user_id', $this->buyer->id)->where('kind', 'cart_left')->count();
    }

    public function test_a_cart_left_for_a_day_is_reminded_once(): void
    {
        $this->actingAs($this->buyer)->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 2]);
        $cart = app(PublicCart::class);

        $this->assertSame(0, $cart->remind(), 'too soon');

        Carbon::setTestNow(now()->addHours(25));
        $this->assertSame(1, $cart->remind());
        $this->assertSame(1, $this->told());
        $this->assertSame(0, $cart->remind(), 'once is enough');
    }

    public function test_touching_the_cart_again_starts_the_wait_over(): void
    {
        $this->actingAs($this->buyer)->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1]);
        Carbon::setTestNow(now()->addHours(25));
        app(PublicCart::class)->remind();

        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1]);
        $this->assertNull(SavedCart::firstOrFail()->reminded_at);

        Carbon::setTestNow(now()->addHours(25));
        $this->assertSame(1, app(PublicCart::class)->remind());
        $this->assertSame(2, $this->told());
    }

    public function test_an_emptied_or_ordered_cart_is_not_reminded_and_a_guest_has_nothing_kept(): void
    {
        $this->actingAs($this->buyer)->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1]);
        $this->assertSame(1, SavedCart::count());

        app(PublicCart::class)->clear();
        $this->assertSame(0, SavedCart::count());

        auth()->logout();
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1]);
        $this->assertSame(0, SavedCart::count());

        Carbon::setTestNow(now()->addDays(3));
        $this->assertSame(0, app(PublicCart::class)->remind());
    }
}
