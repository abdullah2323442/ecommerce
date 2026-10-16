<?php

namespace Tests\Feature\Public;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Medicine;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 13, product page: "This was 450 last month" and "People who bought this also took this". */
class ProductPageExtrasTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 12:00:00');
        Cache::flush();
        $this->company = Company::create(['name' => 'Square']);
        $this->napa = $this->medicine('Napa');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function medicine(string $name, array $over = []): Medicine
    {
        return Medicine::create(array_merge([
            'company_id' => $this->company->id, 'brand_name' => $name, 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box',
            'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ], $over));
    }

    /** One finished memo with these medicines on it. */
    private function memo(array $medicines, array $over = []): Invoice
    {
        $invoice = Invoice::create(array_merge(['kind' => 'retail', 'channel' => 'counter', 'status' => 'final', 'finalised_at' => now()->subDays(5), 'opened_at' => now()->subDays(5)], $over));
        foreach ($medicines as [$m, $line]) {
            InvoiceLine::create(array_merge(['invoice_id' => $invoice->id, 'medicine_id' => $m->id, 'unit' => 'strip', 'quantity' => 1, 'stock_units' => 0.3333, 'unit_mrp' => 100, 'unit_price' => 100], $line ?? []));
        }

        return $invoice;
    }

    private function times(int $n, array $medicines, array $over = []): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->memo($medicines, $over);
        }
    }

    private function page()
    {
        Cache::flush();

        return $this->get("/medicines/{$this->napa->id}");
    }

    // ------------------------------------------------------------------ price history

    public function test_a_recent_price_change_shows_what_it_was(): void
    {
        $this->napa->forceFill(['mrp_before' => 250, 'mrp_changed_at' => now()->subDays(20)])->save();

        $this->page()->assertInertia(fn (Assert $p) => $p->where('priceWas.was', 250)->where('priceWas.now', 300)->where('priceWas.at', '2026-09-24')->where('priceWas.unit', 'box'));
    }

    public function test_an_old_change_or_an_unchanged_price_shows_nothing(): void
    {
        $this->napa->forceFill(['mrp_before' => 250, 'mrp_changed_at' => now()->subDays(120)])->save();
        $this->page()->assertInertia(fn (Assert $p) => $p->where('priceWas', null));

        $this->napa->forceFill(['mrp_before' => 300, 'mrp_changed_at' => now()->subDays(2)])->save();
        $this->page()->assertInertia(fn (Assert $p) => $p->where('priceWas', null));

        $plain = $this->medicine('Seclo');
        $this->get("/medicines/{$plain->id}")->assertInertia(fn (Assert $p) => $p->where('priceWas', null));
    }

    public function test_how_long_a_change_is_remembered_is_a_setting(): void
    {
        $this->napa->forceFill(['mrp_before' => 250, 'mrp_changed_at' => now()->subDays(120)])->save();
        Setting::write('catalog.price_history_days', 180);

        $this->page()->assertInertia(fn (Assert $p) => $p->where('priceWas.was', 250));
    }

    public function test_a_piece_sold_medicine_gives_the_piece_price(): void
    {
        $syrup = $this->medicine('Cough syrup', ['packaging_type' => 'bottle', 'mrp_box' => null, 'mrp_strip' => null, 'mrp_piece' => 120, 'mrp_before' => 100, 'mrp_changed_at' => now()->subDay()]);

        $this->get("/medicines/{$syrup->id}")->assertInertia(fn (Assert $p) => $p->where('priceWas.unit', 'piece')->where('priceWas.now', 120)->where('priceWas.was', 100));
    }

    // ------------------------------------------------------------------ bought together

    public function test_what_often_goes_on_the_same_memo_is_shown_most_common_first(): void
    {
        $seclo = $this->medicine('Seclo');
        $losec = $this->medicine('Losec');
        $this->times(7, [[$this->napa, null], [$seclo, null]]);
        $this->times(5, [[$this->napa, null], [$losec, null]]);
        $this->times(9, [[$seclo, null], [$losec, null]]);   // not with Napa: no part in it

        $this->page()->assertInertia(fn (Assert $p) => $p->has('together', 2)->where('together.0.brand_name', 'Seclo')->where('together.1.brand_name', 'Losec'));
    }

    public function test_nothing_shows_until_enough_different_memos_agree(): void
    {
        $seclo = $this->medicine('Seclo');
        $this->times(4, [[$this->napa, null], [$seclo, null]]);

        $this->page()->assertInertia(fn (Assert $p) => $p->has('together', 0));

        $this->memo([[$this->napa, null], [$seclo, null]]);
        $this->page()->assertInertia(fn (Assert $p) => $p->has('together', 1));
    }

    public function test_the_number_of_memos_needed_is_a_setting(): void
    {
        $seclo = $this->medicine('Seclo');
        $this->times(3, [[$this->napa, null], [$seclo, null]]);
        Setting::write('catalog.together_min', 3);

        $this->page()->assertInertia(fn (Assert $p) => $p->has('together', 1));
    }

    public function test_one_buyer_buying_the_same_pair_again_and_again_on_one_memo_counts_once(): void
    {
        $seclo = $this->medicine('Seclo');
        // Two lines of each on one memo is still one memo.
        for ($i = 0; $i < 5; $i++) {
            $this->memo([[$this->napa, null], [$this->napa, ['unit' => 'box']], [$seclo, null], [$seclo, ['unit' => 'box']]]);
        }
        $this->page()->assertInertia(fn (Assert $p) => $p->has('together', 1));

        $other = $this->medicine('Losec');
        $this->memo([[$this->napa, null], [$other, null], [$other, ['unit' => 'box']], [$other, ['unit' => 'piece']], [$other, ['unit' => 'piece']], [$other, ['unit' => 'strip']]]);
        $this->page()->assertInertia(fn (Assert $p) => $p->has('together', 1));   // one memo, however many lines
    }

    public function test_unfinished_cancelled_old_and_outside_lines_do_not_count(): void
    {
        $seclo = $this->medicine('Seclo');
        $this->times(2, [[$this->napa, null], [$seclo, null]]);
        $this->times(2, [[$this->napa, null], [$seclo, null]], ['status' => 'cancelled']);
        $this->times(2, [[$this->napa, null], [$seclo, null]], ['status' => 'draft']);
        $this->times(2, [[$this->napa, null], [$seclo, null]], ['finalised_at' => now()->subMonths(8)]);
        $this->times(2, [[$this->napa, null], [$seclo, ['is_outside' => true]]]);
        $this->times(2, [[$this->napa, null], [$seclo, ['removed_at' => now()]]]);

        $this->page()->assertInertia(fn (Assert $p) => $p->has('together', 0));
    }

    public function test_a_controlled_an_inactive_or_deleted_medicine_is_never_suggested(): void
    {
        $morphine = $this->medicine('Morphine', ['is_controlled' => true]);
        $retired = $this->medicine('Retired', ['is_active' => false]);
        $gone = $this->medicine('Gone');
        $this->times(6, [[$this->napa, null], [$morphine, null], [$retired, null], [$gone, null]]);
        $gone->delete();

        $this->page()->assertInertia(fn (Assert $p) => $p->has('together', 0));
    }

    public function test_at_most_three_are_shown_and_no_stock_or_buyer_is_in_the_page(): void
    {
        $others = collect(['A', 'B', 'C', 'D'])->map(fn ($n) => $this->medicine("Med {$n}"));
        $this->times(6, collect([[$this->napa, null]])->merge($others->map(fn ($m) => [$m, null]))->all());

        $response = $this->page();
        $response->assertInertia(fn (Assert $p) => $p->has('together', 3));
        foreach (['stock', 'units_remaining', 'customer', 'unit_cost'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($response->viewData('page')['props']['together']));
        }
    }
}
