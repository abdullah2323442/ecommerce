<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Role;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TestimonialsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
    }

    public function test_the_home_page_shows_only_active_testimonials_in_order(): void
    {
        Testimonial::create(['name' => 'Second', 'quote' => 'B', 'rating' => 4, 'sort' => 2]);
        Testimonial::create(['name' => 'First', 'quote' => 'A', 'rating' => 5, 'sort' => 1]);
        Testimonial::create(['name' => 'Hidden', 'quote' => 'C', 'rating' => 5, 'sort' => 0, 'is_active' => false]);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('testimonials', 2)
            ->where('testimonials.0.name', 'First')
            ->where('testimonials.1.name', 'Second'));
    }

    public function test_the_bangla_words_are_used_when_the_reader_reads_bangla(): void
    {
        Testimonial::create(['name' => 'Reader', 'quote' => 'English words', 'quote_bn' => 'বাংলা কথা', 'rating' => 5]);

        app()->setLocale('bn');

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('testimonials.0.quote', 'বাংলা কথা'));
    }

    public function test_the_demo_command_is_safe_to_run_twice(): void
    {
        $this->artisan('demo:testimonials')->assertSuccessful();
        $count = Testimonial::count();
        $this->artisan('demo:testimonials')->assertSuccessful();

        $this->assertGreaterThan(0, $count);
        $this->assertSame($count, Testimonial::count());
    }

    public function test_only_staff_who_manage_customers_can_open_the_admin_page(): void
    {
        $this->get('/admin/testimonials')->assertRedirect();

        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->actingAs($buyer)->get('/admin/testimonials')->assertRedirect('/');
    }

    public function test_the_admin_can_add_change_and_remove_one(): void
    {
        $admin = $this->staff();
        $this->actingAs($admin);

        $this->post('/admin/testimonials', ['name' => 'Nina', 'place' => 'Dhaka', 'quote' => 'Lovely.', 'rating' => 5, 'is_active' => true])->assertSessionHasNoErrors();
        $row = Testimonial::firstOrFail();

        $this->put("/admin/testimonials/{$row->id}", ['name' => 'Nina', 'quote' => 'Lovely, truly.', 'rating' => 4, 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertSame(4, $row->fresh()->rating);
        $this->assertFalse($row->fresh()->is_active);

        $this->delete("/admin/testimonials/{$row->id}")->assertRedirect();
        $this->assertSame(0, Testimonial::count());
    }

    public function test_a_rating_outside_one_to_five_is_refused(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->post('/admin/testimonials', ['name' => 'X', 'quote' => 'Y', 'rating' => 9, 'is_active' => true])->assertSessionHasErrors('rating');
    }
}
