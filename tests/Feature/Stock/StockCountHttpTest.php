<?php

namespace Tests\Feature\Stock;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\MedicineBarcode;
use App\Models\StockCount;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StockCountHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'mrp_box' => 100, 'mrp_piece' => 1, 'is_active' => true,
        ]);
        $source = StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]);
        app(StockService::class)->receive($source, ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 10]]]);
    }

    public function test_counts_and_barcodes_are_admin_only(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $count = StockCount::create(['scope' => 'all', 'started_by' => $this->admin->id]);

        $this->actingAs($staff)->get('/admin/stock/counts')->assertRedirect('/');
        $this->actingAs($staff)->post('/admin/stock/counts', ['scope' => 'all'])->assertRedirect('/');
        $this->actingAs($staff)->post("/admin/stock/counts/{$count->id}/approve")->assertRedirect('/');
        $this->actingAs($staff)->post('/admin/stock/barcodes', ['code' => '123', 'medicine_id' => $this->napa->id])->assertRedirect('/');

        $this->assertSame(1, StockCount::count());
        $this->assertDatabaseCount('medicine_barcodes', 0);
    }

    public function test_a_full_count_from_start_to_approval(): void
    {
        $this->actingAs($this->admin)->post('/admin/stock/counts', ['scope' => 'all'])->assertRedirect();
        $count = StockCount::first();

        $this->actingAs($this->admin)->get("/admin/stock/counts/{$count->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/stock/count')->has('lines', 1));

        $this->actingAs($this->admin)->post("/admin/stock/counts/{$count->id}/record", ['medicine_id' => $this->napa->id, 'counted' => 9])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/admin/stock/counts/{$count->id}/approve")->assertRedirect('/admin/stock/counts');

        $this->assertSame(9.0, app(StockService::class)->onHand($this->napa));
        $this->assertSame('approved', $count->fresh()->status);
    }

    public function test_an_unknown_barcode_is_refused_then_linked_once_and_scans_add_one_each_time(): void
    {
        $this->actingAs($this->admin)->post('/admin/stock/counts', ['scope' => 'all']);
        $count = StockCount::first();

        $this->actingAs($this->admin)->post("/admin/stock/counts/{$count->id}/scan", ['code' => '8901234567890'])->assertSessionHasErrors('code');

        $this->actingAs($this->admin)->post('/admin/stock/barcodes', ['code' => " 8901234567890\n", 'medicine_id' => $this->napa->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('medicine_barcodes', ['code' => '8901234567890']);

        $this->actingAs($this->admin)->post("/admin/stock/counts/{$count->id}/scan", ['code' => '8901234567890']);
        $this->actingAs($this->admin)->post("/admin/stock/counts/{$count->id}/scan", ['code' => '8901234567890']);
        $this->assertSame('2.0000', (string) $count->lines()->first()->counted_units);
    }

    public function test_a_barcode_cannot_be_linked_twice_and_lookup_finds_the_medicine(): void
    {
        MedicineBarcode::create(['medicine_id' => $this->napa->id, 'code' => '555']);

        $this->actingAs($this->admin)->post('/admin/stock/barcodes', ['code' => '555', 'medicine_id' => $this->napa->id])->assertSessionHasErrors('code');
        $this->actingAs($this->admin)->getJson('/admin/stock/barcodes/lookup?code=555')->assertOk()->assertJsonPath('medicine.brand_name', 'Napa');
        $this->actingAs($this->admin)->getJson('/admin/stock/barcodes/lookup?code=000')->assertNotFound();
    }

    public function test_the_scope_needs_what_it_scopes(): void
    {
        $this->actingAs($this->admin)->post('/admin/stock/counts', ['scope' => 'company'])->assertSessionHasErrors('company_id');
        $this->actingAs($this->admin)->post('/admin/stock/counts', ['scope' => 'shelf'])->assertSessionHasErrors('shelf');
        $this->actingAs($this->admin)->post('/admin/stock/counts', ['scope' => 'moon'])->assertSessionHasErrors('scope');
    }
}
