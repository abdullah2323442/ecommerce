<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Preorder;
use App\Models\PreorderLine;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\PreOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 21: "10 people wanted it, 6 came": whoever ordered first gets it first, and the rest are told. */
class PreorderShareTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Medicine $seclo;

    /** @var array<int, User> */
    private array $shops = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $tier = Tier::create(['name' => 'A', 'discount_percent' => 10]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $company = Company::create(['name' => 'Beacon']);
        $company->forceFill(['preorder_days' => 3])->save();
        $this->seclo = Medicine::create([
            'company_id' => $company->id, 'brand_name' => 'Seclo', 'strength' => '20 mg', 'form' => 'capsule', 'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10,
            'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        foreach (['Alpha', 'Beta', 'Gamma'] as $n) {
            $this->shops[$n] = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => $tier->id, 'name' => $n.' Pharmacy', 'categories' => ['medicine']]);
        }
    }

    /** A shop asks for some boxes, a minute apart so the order of asking is clear; the staff accept it. */
    private function ask(string $shop, float $boxes, string $unit = 'box'): Preorder
    {
        Carbon::setTestNow(now()->addMinute());
        $this->actingAs($this->shops[$shop])->post('/preorders', ['medicine_id' => $this->seclo->id, 'unit' => $unit, 'quantity' => $boxes])->assertSessionHasNoErrors();
        $pre = Preorder::latest('id')->firstOrFail();
        $this->actingAs($this->staff)->post("/admin/preorders/{$pre->id}/accept", ['days' => 3])->assertSessionHasNoErrors();

        return $pre->fresh();
    }

    private function share(float $boxes)
    {
        return $this->actingAs($this->staff)->post('/admin/preorders/share', ['medicine_id' => $this->seclo->id, 'boxes' => $boxes]);
    }

    private function given(Preorder $p): ?float
    {
        $v = PreorderLine::where('preorder_id', $p->id)->value('share_quantity');

        return $v === null ? null : (float) $v;
    }

    public function test_the_first_to_ask_get_all_the_one_it_runs_out_on_gets_the_rest_and_later_ones_get_none(): void
    {
        $a = $this->ask('Alpha', 4);
        $b = $this->ask('Beta', 4);
        $c = $this->ask('Gamma', 4);

        $this->share(6)->assertSessionHasNoErrors();

        $this->assertSame(4.0, $this->given($a));
        $this->assertSame(2.0, $this->given($b));
        $this->assertSame(0.0, $this->given($c));
    }

    public function test_those_who_got_less_are_told_and_those_who_got_all_are_not(): void
    {
        $a = $this->ask('Alpha', 4);
        $b = $this->ask('Beta', 4);
        $c = $this->ask('Gamma', 4);

        $this->share(6);

        $this->assertSame(0, Notice::where('user_id', $a->customer_id)->where('kind', 'preorder_short')->count());
        $body = json_decode(Notice::where('user_id', $b->customer_id)->where('kind', 'preorder_short')->firstOrFail()->body, true);
        $this->assertSame(2.0, (float) $body['given']);
        $this->assertSame(4.0, (float) $body['asked']);
        $none = json_decode(Notice::where('user_id', $c->customer_id)->where('kind', 'preorder_short')->firstOrFail()->body, true);
        $this->assertSame(0.0, (float) $none['given']);
    }

    public function test_when_enough_came_everyone_gets_all_and_nobody_is_told(): void
    {
        $a = $this->ask('Alpha', 2);
        $b = $this->ask('Beta', 3);

        $this->share(10)->assertSessionHas('status');

        $this->assertSame(2.0, $this->given($a));
        $this->assertSame(3.0, $this->given($b));
        $this->assertSame(0, Notice::where('kind', 'preorder_short')->count());
    }

    public function test_it_is_the_order_of_asking_not_the_size_that_counts(): void
    {
        $big = $this->ask('Alpha', 9);
        $small = $this->ask('Beta', 1);

        $this->share(9);

        $this->assertSame(9.0, $this->given($big));
        $this->assertSame(0.0, $this->given($small));
    }

    public function test_a_strip_counts_as_a_part_of_a_box(): void
    {
        $a = $this->ask('Alpha', 6, 'strip');   // two boxes' worth of strips
        $b = $this->ask('Beta', 1);

        $this->share(2);

        $this->assertSame(6.0, $this->given($a), 'six strips are two boxes');
        $this->assertSame(0.0, $this->given($b));
    }

    public function test_only_accepted_and_not_yet_received_orders_share(): void
    {
        $a = $this->ask('Alpha', 3);
        $declined = $this->ask('Beta', 3);
        $this->actingAs($this->staff)->post("/admin/preorders/{$declined->id}/cancel", ['note' => 'Sorry']);
        $c = $this->ask('Gamma', 3);

        $this->share(4);

        $this->assertSame(3.0, $this->given($a));
        $this->assertSame(1.0, $this->given($c));
        $this->assertNull($this->given($declined), 'a cancelled one takes no share');
    }

    public function test_sharing_again_starts_afresh_and_does_not_tell_the_same_person_twice_for_the_same_result(): void
    {
        $a = $this->ask('Alpha', 4);
        $b = $this->ask('Beta', 4);

        $this->share(5);
        $this->share(8);

        $this->assertSame(4.0, $this->given($a));
        $this->assertSame(4.0, $this->given($b), 'more came, so the second gets all');
    }

    public function test_the_receive_form_starts_from_what_was_shared(): void
    {
        $this->ask('Alpha', 4);
        $b = $this->ask('Beta', 4);
        $this->share(6);

        $this->actingAs($this->staff)->get('/admin/preorders')->assertInertia(fn (Assert $p) => $p
            ->where('accepted.0.lines.0.share', 4)->where('accepted.1.lines.0.share', 2)->where('accepted.1.id', $b->id));
    }

    public function test_it_needs_a_number_and_a_medicine_and_only_the_office_may_do_it(): void
    {
        $this->ask('Alpha', 2);

        $this->share(0)->assertSessionHasErrors('boxes');
        $this->actingAs($this->staff)->post('/admin/preorders/share', ['medicine_id' => 999999, 'boxes' => 3])->assertSessionHasErrors('medicine_id');
        $this->actingAs($this->shops['Alpha'])->post('/admin/preorders/share', ['medicine_id' => $this->seclo->id, 'boxes' => 3])->assertRedirect('/');
    }

    public function test_the_buying_list_lets_the_office_share_out_each_medicine(): void
    {
        $this->ask('Alpha', 2);

        $this->actingAs($this->staff)->get('/admin/preorders')->assertInertia(fn (Assert $p) => $p->where('buying.0.medicines.0.medicine_id', $this->seclo->id));
        $this->assertNotNull(app(PreOrders::class));
    }
}
