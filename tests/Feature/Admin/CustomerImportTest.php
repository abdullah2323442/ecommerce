<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Models\ActivityLog;
use App\Models\Address;
use App\Models\Company;
use App\Models\CustomerRate;
use App\Models\Medicine;
use App\Models\Tier;
use App\Models\User;
use App\Services\Import\CustomerImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 28, bulk import: existing shops come in from one CSV, with a preview of every line first and nothing written until the owner goes on. */
class CustomerImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tier $b;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['auth.password_confirmed_at' => time()]);
        // The reasons are written for the person reading them; these checks read the English.
        $this->withCookie(SetLocale::COOKIE, 'en');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        Tier::create(['name' => 'A', 'discount_percent' => 15]);
        $this->b = Tier::create(['name' => 'B', 'discount_percent' => 10]);
        $company = Company::create(['name' => 'Square']);
        $this->napa = Medicine::create(['company_id' => $company->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'packaging_type' => 'box', 'mrp_piece' => 100, 'is_active' => true]);
    }

    private function csv(string ...$lines): string
    {
        return implode("\n", ['name,phone,type,tier,shop,area,district,address,email,medicine,discount_percent,fixed_price', ...$lines])."\n";
    }

    private function file(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('shops.csv', $content);
    }

    private function preview(string $content)
    {
        return $this->actingAs($this->admin)->post('/admin/customers/import/preview', ['file' => $this->file($content)]);
    }

    private function token(string $content): string
    {
        $r = $this->preview($content)->assertOk();

        return $r->viewData('page')['props']['token'];
    }

    public function test_the_preview_writes_nothing_and_says_what_each_line_would_do(): void
    {
        $content = $this->csv(
            'Karim Pharmacy,01712345678,paikari,B,Karim Pharmacy,Mirpur,Dhaka,"House 4, Road 2",,,,',
            'Karim Pharmacy,01712345678,,,,,,,,Napa 500 mg,12,',
            'Habib Drug,01812345678,paikari,,,Pallabi,,,,,,',
        );

        $this->preview($content)->assertInertia(fn (Assert $p) => $p
            ->where('preview.counts.lines', 3)->where('preview.counts.new', 2)->where('preview.counts.rates', 1)->where('preview.counts.errors', 0)
            ->where('preview.lines.0.status', 'new')->where('preview.lines.1.status', 'rate')->where('preview.lines.1.rate', 'Napa 500 mg: 12% off')->where('preview.lines.2.status', 'new')
            ->has('token'));

        $this->assertSame(1, User::count());
        $this->assertSame(0, CustomerRate::count());
    }

    public function test_going_on_creates_the_shops_with_tier_address_and_rates(): void
    {
        $token = $this->token($this->csv(
            'Karim Pharmacy,01712345678,paikari,B,Karim Pharmacy,Mirpur,Dhaka,"House 4, Road 2",,,,',
            'Karim Pharmacy,01712345678,,,,,,,,Napa,,80',
        ));

        $this->post("/admin/customers/import/{$token}")->assertRedirect('/admin/customers/import');

        $shop = User::where('phone', '01712345678')->firstOrFail();
        $this->assertSame(AccountType::Paikari, $shop->account_type);
        $this->assertSame($this->b->id, $shop->tier_id);
        $this->assertSame('Karim Pharmacy', $shop->business_name);
        $this->assertSame('Mirpur', $shop->area);
        $this->assertSame('01712345678@phone.invalid', $shop->email);
        $this->assertSame('House 4, Road 2', Address::where('user_id', $shop->id)->value('address'));
        $this->assertEquals(80, CustomerRate::where('user_id', $shop->id)->value('fixed_price'));
        $this->assertTrue(ActivityLog::where('action', 'customers.imported')->exists());
        $this->assertDatabaseHas('tier_changes', ['user_id' => $shop->id, 'to_tier_id' => $this->b->id]);
    }

    public function test_a_new_shop_with_no_tier_gets_the_lowest_one(): void
    {
        $this->post('/admin/customers/import/'.$this->token($this->csv('Habib Drug,01812345678,paikari,,,Pallabi,,,,,,')));

        $this->assertSame($this->b->id, User::where('phone', '01812345678')->value('tier_id'));
    }

    public function test_the_account_cannot_be_signed_into_until_the_shop_sets_its_own_password(): void
    {
        $this->post('/admin/customers/import/'.$this->token($this->csv('Habib Drug,01812345678,paikari,,,Pallabi,,,,,,')));

        $this->post('/logout');
        $this->post('/login', ['email' => '01812345678@phone.invalid', 'password' => 'password'])->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_a_phone_already_on_the_system_updates_that_shop_and_keeps_its_name_and_type(): void
    {
        $old = User::factory()->create(['account_type' => AccountType::Wholesaler, 'phone' => '01712345678', 'name' => 'Original Name', 'area' => 'Old Area']);

        $this->post('/admin/customers/import/'.$this->token($this->csv('Different Name,+880 1712-345678,paikari,B,New Shop,Mirpur,,,,Napa 500 mg,5,')));

        $old->refresh();
        $this->assertSame('Original Name', $old->name);
        $this->assertSame(AccountType::Wholesaler, $old->account_type);
        $this->assertSame('Mirpur', $old->area);
        $this->assertSame('New Shop', $old->business_name);
        $this->assertSame($this->b->id, $old->tier_id);
        $this->assertSame(1, User::where('phone', '01712345678')->count());
        $this->assertEquals(5, CustomerRate::where('user_id', $old->id)->value('discount_percent'));
    }

    public function test_a_line_with_a_problem_is_shown_with_the_reason_and_left_out_while_the_rest_go_in(): void
    {
        $content = $this->csv(
            'Good Shop,01712345678,paikari,B,,Mirpur,,,,,,',
            'Bad Phone,12345,paikari,,,Mirpur,,,,,,',
            'No Such Tier,01812345678,paikari,Z,,Mirpur,,,,,,',
            'Bad Type,01912345678,king,,,Mirpur,,,,,,',
            'Bad District,01612345678,paikari,,,Mirpur,Atlantis,,,,,',
            'Rate Both,01512345678,paikari,,,Mirpur,,,,Napa,10,50',
            'Unknown Medicine,01412345678,paikari,,,Mirpur,,,,Zorbex,10,',
        );

        $r = $this->preview($content);
        $r->assertInertia(fn (Assert $p) => $p->where('preview.counts.new', 1)->where('preview.counts.errors', 6));
        $lines = $r->viewData('page')['props']['preview']['lines'];
        $this->assertSame('error', $lines[1]['status']);
        $this->assertStringContainsString('Bangladesh mobile number', $lines[1]['problems'][0]);
        $this->assertStringContainsString('no tier called Z', $lines[2]['problems'][0]);
        $this->assertStringContainsString('paikari, wholesaler or public', $lines[3]['problems'][0]);
        $this->assertStringContainsString('Atlantis', $lines[4]['problems'][0]);
        $this->assertStringContainsString('either a percent or a price', $lines[5]['problems'][0]);
        $this->assertStringContainsString('Zorbex', $lines[6]['problems'][0]);

        $token = $r->viewData('page')['props']['token'];
        $this->post("/admin/customers/import/{$token}");

        $this->assertSame(['01712345678'], User::where('phone', '!=', $this->admin->phone)->whereNotNull('phone')->pluck('phone')->all());
    }

    public function test_a_rate_that_loses_money_or_is_not_a_price_for_one_piece_is_a_problem(): void
    {
        $this->napa->update(['mrp_piece' => 100]);
        $content = $this->csv(
            'Box Price,01712345678,paikari,,,Mirpur,,,,Napa,,1500',
            'Zero,01812345678,paikari,,,Mirpur,,,,Napa,150,',
        );

        $this->preview($content)->assertInertia(fn (Assert $p) => $p->where('preview.counts.errors', 2)->where('preview.counts.new', 0));
    }

    public function test_two_medicines_with_one_name_are_told_apart_by_the_company_or_the_form(): void
    {
        $other = Company::create(['name' => 'Beximco']);
        $second = Medicine::create(['company_id' => $other->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'packaging_type' => 'box', 'mrp_piece' => 90, 'is_active' => true, 'form' => 'suppository']);
        $this->napa->update(['form' => 'tablet']);

        $r = $this->preview($this->csv(
            'One,01712345678,paikari,,,Mirpur,,,,Napa 500 mg,5,',
            'Two,01812345678,paikari,,,Mirpur,,,,Napa 500 mg Square,5,',
            'Three,01912345678,paikari,,,Mirpur,,,,Napa 500 mg suppository,5,',
        ));

        $lines = $r->viewData('page')['props']['preview']['lines'];
        $this->assertSame('error', $lines[0]['status']);
        $this->assertStringContainsString('Add the company', $lines[0]['problems'][0]);
        $this->assertStringContainsString('Beximco', $lines[0]['problems'][0]);
        $this->assertSame(['new', 'new'], [$lines[1]['status'], $lines[2]['status']]);

        $token = $r->viewData('page')['props']['token'];
        $this->post("/admin/customers/import/{$token}");
        $this->assertSame($this->napa->id, CustomerRate::where('user_id', User::where('phone', '01812345678')->value('id'))->value('medicine_id'));
        $this->assertSame($second->id, CustomerRate::where('user_id', User::where('phone', '01912345678')->value('id'))->value('medicine_id'));
    }

    public function test_the_same_phone_twice_without_a_rate_is_a_problem_not_a_second_shop(): void
    {
        $this->preview($this->csv('Karim,01712345678,paikari,,,Mirpur,,,,,,', 'Karim Again,01712345678,paikari,,,Mirpur,,,,,,'))
            ->assertInertia(fn (Assert $p) => $p->where('preview.counts.new', 1)->where('preview.counts.errors', 1)->where('preview.lines.1.status', 'error'));
    }

    public function test_a_staff_account_cannot_be_overwritten_by_a_file(): void
    {
        User::factory()->create(['account_type' => AccountType::Staff, 'phone' => '01712345678']);

        $this->preview($this->csv('Karim,01712345678,paikari,B,,Mirpur,,,,,,'))->assertInertia(fn (Assert $p) => $p->where('preview.counts.errors', 1));
    }

    public function test_a_file_that_is_not_a_list_of_shops_is_refused_with_a_reason(): void
    {
        $this->preview("hello,world\n1,2\n")->assertSessionHasErrors('file');
        $this->preview("name,phone\n")->assertSessionHasErrors('file');
        $this->preview('')->assertSessionHasErrors('file');
    }

    public function test_excel_files_with_a_byte_order_mark_and_lost_leading_zeros_still_work(): void
    {
        $content = "\xEF\xBB\xBF".$this->csv('Karim,1712345678,paikari,,,Mirpur,,,,,,');

        $this->preview($content)->assertInertia(fn (Assert $p) => $p->where('preview.counts.new', 1)->where('preview.lines.0.phone', '01712345678'));
    }

    public function test_the_import_cannot_be_committed_twice_or_by_someone_else(): void
    {
        $token = $this->token($this->csv('Karim,01712345678,paikari,,,Mirpur,,,,,,'));
        $other = User::factory()->create(['account_type' => AccountType::Admin]);

        $this->actingAs($other)->post("/admin/customers/import/{$token}")->assertSessionHasErrors('file');
        $this->assertSame(0, User::where('phone', '01712345678')->count());

        $this->actingAs($this->admin)->post("/admin/customers/import/{$token}")->assertSessionHasNoErrors();
        $this->post("/admin/customers/import/{$token}")->assertSessionHasErrors('file');
        $this->assertSame(1, User::where('phone', '01712345678')->count());
    }

    public function test_only_those_who_manage_customers_can_use_it(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->get('/admin/customers/import')->assertRedirect('/login');
        foreach ([$staff, $shop] as $who) {
            $this->actingAs($who)->get('/admin/customers/import')->assertRedirect('/');
            $this->actingAs($who)->post('/admin/customers/import/preview', ['file' => $this->file($this->csv('Karim,01712345678,paikari,,,Mirpur,,,,,,'))])->assertRedirect('/');
        }
        $this->assertSame(0, User::where('phone', '01712345678')->count());
    }

    public function test_the_template_has_the_columns_and_is_itself_a_valid_file(): void
    {
        $r = $this->actingAs($this->admin)->get('/admin/customers/import/template')->assertOk();
        $body = $r->streamedContent();

        foreach (CustomerImport::COLUMNS as $column) {
            $this->assertStringContainsString($column, $body);
        }

        $this->preview(ltrim($body, "\xEF\xBB\xBF"))->assertInertia(fn (Assert $p) => $p->where('preview.counts.errors', 0)->where('preview.counts.new', 2)->where('preview.counts.rates', 1));
    }

    public function test_the_page_opens_for_the_owner(): void
    {
        $this->actingAs($this->admin)->get('/admin/customers/import')->assertInertia(fn (Assert $p) => $p->component('admin/customers/import')->where('preview', null)->has('columns', 12));
    }
}
