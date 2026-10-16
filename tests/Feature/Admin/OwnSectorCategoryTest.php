<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\Sector;
use App\Models\User;
use App\Support\Categories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 14, 15: a sector the owner opens can be given to a trader the same day; each trader sees only the categories they were given. */
class OwnSectorCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $trader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->trader = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['medicine'], 'pay_cod' => true, 'pay_advance' => false])->fresh();
    }

    private function trade(array $categories)
    {
        return $this->actingAs($this->admin)->put("/admin/customers/{$this->trader->id}/trade", ['account_type' => 'wholesaler', 'categories' => $categories, 'pay_cod' => 1, 'pay_advance' => 0]);
    }

    public function test_a_new_sector_is_a_category_the_owner_can_give(): void
    {
        $this->assertArrayNotHasKey('toys', Categories::all());

        Sector::create(['slug' => 'toys', 'name' => 'Toys', 'unit' => 'piece']);

        $this->assertSame('Toys', Categories::all()['toys']);
        $this->assertArrayHasKey('wood', Categories::all(), 'the starting list stays');
    }

    public function test_the_owner_gives_a_trader_the_new_category_and_it_is_kept(): void
    {
        Sector::create(['slug' => 'toys', 'name' => 'Toys', 'unit' => 'piece']);

        $this->trade(['medicine', 'toys'])->assertSessionHasNoErrors();

        $this->assertSame(['medicine', 'toys'], $this->trader->fresh()->categories);
    }

    public function test_a_category_that_does_not_exist_is_refused(): void
    {
        $this->trade(['medicine', 'nonsense'])->assertSessionHasErrors('categories.1');
        $this->assertSame(['medicine'], $this->trader->fresh()->categories);
    }

    public function test_a_switched_off_sector_cannot_be_given(): void
    {
        Sector::create(['slug' => 'toys', 'name' => 'Toys', 'unit' => 'piece', 'is_active' => false]);

        $this->trade(['toys'])->assertSessionHasErrors('categories.0');
    }

    public function test_the_customer_page_offers_the_new_category(): void
    {
        Sector::create(['slug' => 'toys', 'name' => 'Toys', 'unit' => 'piece']);

        $this->actingAs($this->admin)->get("/admin/customers/{$this->trader->id}")->assertInertia(fn (Assert $p) => $p->where('categoryOptions', fn ($o) => collect($o)->contains(fn ($c) => $c['key'] === 'toys' && $c['label'] === 'Toys')));
    }

    public function test_a_trader_can_list_only_in_the_sectors_they_were_given(): void
    {
        $toys = Sector::create(['slug' => 'toys', 'name' => 'Toys', 'unit' => 'piece']);
        Sector::create(['slug' => 'books', 'name' => 'Books', 'unit' => 'piece']);
        $this->trade(['toys'])->assertSessionHasNoErrors();

        $this->actingAs($this->trader->fresh())->get('/wholesale/sell')->assertInertia(fn (Assert $p) => $p->has('sectors', 1)->where('sectors.0.id', $toys->id));
    }

    public function test_a_trader_never_learns_of_a_category_they_do_not_have(): void
    {
        $toys = Sector::create(['slug' => 'toys', 'name' => 'Toys', 'unit' => 'piece']);
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['toys']])->fresh();
        $other->forceFill([])->save();
        Listing::create(['seller_id' => $other->id, 'sector_id' => $toys->id, 'title' => 'Bulk toys', 'public_price' => null, 'wholesale_price' => 100, 'min_qty' => 10, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 5, 'approved_at' => now(), 'submitted_at' => now()]);

        // A trade-only listing is for traders with that category, and invisible to every other kind of viewer, including a medicine-only trader.
        $this->actingAs($this->trader)->get('/market')->assertInertia(fn (Assert $p) => $p->has('items', 0));
        $this->actingAs($this->trader)->get('/market?sector=toys')->assertInertia(fn (Assert $p) => $p->has('items', 0));

        $this->trade(['medicine', 'toys']);
        $this->actingAs($this->trader->fresh())->get('/market')->assertInertia(fn (Assert $p) => $p->has('items', 1));
    }
}
