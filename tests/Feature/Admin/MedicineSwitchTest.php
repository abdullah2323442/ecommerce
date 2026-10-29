<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\MedicineBarcode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 11 and 06: a discontinued or renamed medicine is switched off by the owner, and one medicine can carry several barcodes. */
class MedicineSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCookie(SetLocale::COOKIE, 'en');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
    }

    public function test_the_owner_switches_a_medicine_off_and_it_disappears_from_search_then_comes_back(): void
    {
        $this->get('/search?q=Napa')->assertOk();
        $this->assertTrue(Medicine::active()->whereKey($this->napa->id)->exists());

        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}/active", ['active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($this->napa->fresh()->is_active);
        $this->assertFalse(Medicine::active()->whereKey($this->napa->id)->exists(), 'Not offered for new orders.');
        $this->assertDatabaseHas('activity_logs', ['action' => 'medicine.switched_off', 'subject_id' => $this->napa->id]);

        $this->actingAs($this->admin)->get('/admin/stock/inactive')->assertInertia(fn (Assert $p) => $p
            ->component('admin/stock/inactive')->where('rows.total', 1)->where('rows.data.0.name', 'Napa'));

        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}/active", ['active' => true])->assertSessionHasNoErrors();
        $this->assertTrue($this->napa->fresh()->is_active);
        $this->assertDatabaseHas('activity_logs', ['action' => 'medicine.switched_on']);
    }

    public function test_switching_to_the_state_it_is_already_in_changes_nothing_and_logs_nothing(): void
    {
        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}/active", ['active' => true])->assertSessionHas('status', 'Nothing changed.');
        $this->assertDatabaseMissing('activity_logs', ['action' => 'medicine.switched_on']);
    }

    public function test_switching_off_hides_the_medicine_but_never_deletes_the_record(): void
    {
        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}/active", ['active' => false]);

        // Hidden from what is offered, not deleted: old memos keep pointing at it.
        $this->assertNotNull(Medicine::find($this->napa->id));
        $this->assertSame('Napa', Medicine::find($this->napa->id)->brand_name);
    }

    public function test_the_inactive_list_can_be_searched_by_name(): void
    {
        $other = Medicine::create(['company_id' => $this->napa->company_id, 'brand_name' => 'Seclo', 'strength' => '20 mg', 'form' => 'capsule', 'packaging_type' => 'box', 'is_active' => false]);
        $this->napa->update(['is_active' => false]);

        $this->actingAs($this->admin)->get('/admin/stock/inactive?q=Seclo')->assertInertia(fn (Assert $p) => $p->where('rows.total', 1)->where('rows.data.0.id', $other->id));
        $this->actingAs($this->admin)->get('/admin/stock/inactive?q=zzz')->assertInertia(fn (Assert $p) => $p->where('rows.total', 0));
    }

    public function test_only_people_who_manage_stock_can_switch_a_medicine(): void
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($shop)->put("/admin/stock/medicines/{$this->napa->id}/active", ['active' => false])->assertRedirect('/');
        $this->put("/admin/stock/medicines/{$this->napa->id}/active", ['active' => 'maybe'])->assertRedirect('/');
        $this->assertTrue($this->napa->fresh()->is_active);

        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}/active", ['active' => 'maybe'])->assertSessionHasErrors('active');
    }

    public function test_one_medicine_can_carry_several_barcodes_and_each_can_be_removed(): void
    {
        $this->actingAs($this->admin)->post("/admin/stock/medicines/{$this->napa->id}/barcodes", ['code' => '8901234567890'])->assertSessionHasNoErrors();
        $this->post("/admin/stock/medicines/{$this->napa->id}/barcodes", ['code' => ' 8901234567906 '])->assertSessionHasNoErrors();

        $this->assertSame(2, MedicineBarcode::where('medicine_id', $this->napa->id)->count());
        $this->get("/admin/stock/medicines/{$this->napa->id}/edit")->assertInertia(fn (Assert $p) => $p
            ->has('barcodes', 2)->where('barcodes.1.code', '8901234567906')->where('active', true));

        $first = MedicineBarcode::where('code', '8901234567890')->firstOrFail();
        $this->delete("/admin/stock/medicines/{$this->napa->id}/barcodes/{$first->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('medicine_barcodes', ['code' => '8901234567890']);
        $this->assertDatabaseHas('medicine_barcodes', ['code' => '8901234567906']);
    }

    public function test_a_barcode_cannot_be_linked_twice_or_removed_through_another_medicine(): void
    {
        $other = Medicine::create(['company_id' => $this->napa->company_id, 'brand_name' => 'Seclo', 'strength' => '20 mg', 'form' => 'capsule', 'packaging_type' => 'box', 'is_active' => true]);
        $this->actingAs($this->admin)->post("/admin/stock/medicines/{$this->napa->id}/barcodes", ['code' => '8901234567890']);

        $this->post("/admin/stock/medicines/{$other->id}/barcodes", ['code' => '8901234567890'])->assertSessionHasErrors('code');
        $this->post("/admin/stock/medicines/{$other->id}/barcodes", ['code' => '   '])->assertSessionHasErrors('code');

        $barcode = MedicineBarcode::where('code', '8901234567890')->firstOrFail();
        $this->delete("/admin/stock/medicines/{$other->id}/barcodes/{$barcode->id}")->assertNotFound();
        $this->assertDatabaseHas('medicine_barcodes', ['code' => '8901234567890']);
    }
}
