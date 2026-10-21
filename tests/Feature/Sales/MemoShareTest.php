<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A finished counter memo can be sent to the buyer by WhatsApp or email, through a link that needs no sign-in. */
class MemoShareTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $shop;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'phone' => '01711223344', 'email' => 'karim@example.test']);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'packaging_type' => 'box',
            'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 10]]]);
        $stock->setMasterSync(true);
    }

    private function finished(): \App\Models\Invoice
    {
        $svc = app(InvoiceService::class);
        $draft = $svc->open('paikari', $this->admin, $this->shop);
        $svc->addLine($draft, $this->napa, 'box', 1, $this->admin);

        return $svc->finalise($draft->fresh(), $this->admin);
    }

    public function test_the_link_opens_the_memo_without_signing_in_and_is_the_same_each_time(): void
    {
        $memo = $this->finished();

        $first = $this->actingAs($this->admin)->postJson("/admin/counter/{$memo->id}/share")->assertOk()->json();
        $again = $this->postJson("/admin/counter/{$memo->id}/share")->json();

        $this->assertSame($first['url'], $again['url']);
        $this->assertSame('8801711223344', $first['phone'], 'a Bangladesh number goes to WhatsApp with 88 in front');
        $this->assertSame('karim@example.test', $first['email']);
        $this->assertStringContainsString($memo->number, $first['text']);
        $this->assertStringContainsString($first['url'], $first['text']);

        auth()->logout();
        $this->get($first['url'])->assertOk();
    }

    public function test_a_memo_that_is_not_finished_cannot_be_sent(): void
    {
        $svc = app(InvoiceService::class);
        $draft = $svc->open('retail', $this->admin);
        $svc->addLine($draft, $this->napa, 'box', 1, $this->admin);

        $this->actingAs($this->admin)->postJson("/admin/counter/{$draft->id}/share")->assertStatus(422);
        $this->assertNull($draft->fresh()->public_token);
    }

    public function test_a_shop_cannot_ask_for_a_link(): void
    {
        $memo = $this->finished();

        $this->actingAs($this->shop)->postJson("/admin/counter/{$memo->id}/share")->assertStatus(302)->assertRedirect('/');
    }
}
