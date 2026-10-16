<?php

namespace Tests\Feature\Public;

use App\Enums\AccountType;
use App\Models\ProductRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_can_say_they_were_looking_for_something(): void
    {
        $this->from('/')->post('/requests', ['query' => 'Insulin glargine'])->assertRedirect('/')->assertSessionHas('status');

        $this->assertDatabaseHas('product_requests', ['query' => 'Insulin glargine', 'normalized' => 'insulin glargine', 'user_id' => null]);
    }

    public function test_a_logged_in_visitor_is_linked_to_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/requests', ['query' => 'Vitamin D drops']);

        $this->assertDatabaseHas('product_requests', ['normalized' => 'vitamin d drops', 'user_id' => $user->id]);
    }

    public function test_the_button_can_post_with_fetch_and_gets_json_back(): void
    {
        $this->postJson('/requests', ['query' => 'Insulin glargine'])->assertOk()->assertJson(['status' => 'noted']);
        $this->postJson('/requests', ['query' => 'x'])->assertStatus(422)->assertJsonValidationErrors('query');

        $this->assertDatabaseCount('product_requests', 1);
    }

    public function test_the_query_is_validated(): void
    {
        $this->post('/requests', [])->assertSessionHasErrors('query');
        $this->post('/requests', ['query' => 'a'])->assertSessionHasErrors('query');
        $this->post('/requests', ['query' => str_repeat('x', 121)])->assertSessionHasErrors('query');
        $this->post('/requests', ['query' => 'ok query', 'note' => str_repeat('n', 301)])->assertSessionHasErrors('note');

        $this->assertDatabaseCount('product_requests', 0);
    }

    public function test_one_visitor_asking_twice_counts_once_even_with_different_spacing_and_case(): void
    {
        $this->post('/requests', ['query' => 'Napa  Extra']);
        $this->post('/requests', ['query' => 'napa extra']);

        $this->assertDatabaseCount('product_requests', 1);
    }

    public function test_different_visitors_asking_for_the_same_thing_each_count(): void
    {
        $this->post('/requests', ['query' => 'Rare medicine']);
        $this->flushSession();
        $this->post('/requests', ['query' => 'rare medicine']);

        $this->assertSame(2, ProductRequest::where('normalized', 'rare medicine')->count());
    }

    public function test_requests_are_rate_limited(): void
    {
        foreach (range(1, 6) as $i) {
            $this->post('/requests', ['query' => "thing number {$i}"])->assertStatus(302);
        }

        $this->post('/requests', ['query' => 'one too many'])->assertStatus(429);
    }

    public function test_the_admin_sees_requests_grouped_most_asked_first(): void
    {
        $admin = User::factory()->create(['account_type' => AccountType::Admin]);
        foreach ([['popular', 3], ['rare', 1]] as [$q, $n]) {
            foreach (range(1, $n) as $i) {
                ProductRequest::create(['query' => ucfirst($q), 'normalized' => $q, 'created_at' => now()]);
            }
        }

        $this->actingAs($admin)->get('/admin/requests')->assertInertia(fn (Assert $page) => $page
            ->component('admin/requests/index')
            ->where('groups.data.0.normalized', 'popular')->where('groups.data.0.total', 3)
            ->where('groups.data.1.normalized', 'rare')->where('groups.data.1.total', 1));
    }

    public function test_only_the_admin_can_read_the_requests(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $paikari = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($staff)->get('/admin/requests')->assertRedirect('/');
        $this->actingAs($paikari)->get('/admin/requests')->assertRedirect('/');
    }

    public function test_a_guest_is_sent_to_login_for_the_requests_page(): void
    {
        $this->get('/admin/requests')->assertRedirect('/login');
    }
}
