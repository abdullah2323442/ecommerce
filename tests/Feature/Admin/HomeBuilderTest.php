<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Models\User;
use App\Services\Site\HomeBlocks;
use App\Services\Site\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The home page builder: arrange, edit and publish the home page as blocks, safely. */
class HomeBuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->flush();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
    }

    private function save(array $blocks)
    {
        return $this->actingAs($this->admin)->put('/admin/site/blocks', ['blocks' => $blocks]);
    }

    private function block(string $type, array $props = [], ?string $id = null, bool $on = true): array
    {
        return ['id' => $id ?? $type, 'type' => $type, 'enabled' => $on, 'props' => $props];
    }

    public function test_the_standard_page_is_there_before_anything_is_saved(): void
    {
        $this->get('/')->assertInertia(fn (Assert $p) => $p->where('site.home.blocks.0.type', 'hero')->has('site.home.blocks', 7));
    }

    public function test_the_builder_opens_for_the_owner_with_the_blocks_and_their_settings(): void
    {
        $this->actingAs($this->admin)->get('/admin/site/builder')->assertInertia(fn (Assert $p) => $p->component('admin/site-builder')->has('blocks', 7)->has('types.banner.fields'));
    }

    public function test_a_saved_arrangement_is_what_the_home_page_shows(): void
    {
        $this->save([$this->block('banner', ['heading' => ['en' => 'Winter edit', 'bn' => 'শীতের সংগ্রহ'], 'tone' => 'dark']), $this->block('hero'), $this->block('arrivals', ['count' => 6])])->assertSessionHasNoErrors();

        $this->get('/')->assertInertia(fn (Assert $p) => $p->has('site.home.blocks', 3)->where('site.home.blocks.0.type', 'banner')->where('site.home.blocks.0.props.heading.en', 'Winter edit')->where('site.home.blocks.2.props.count', 6));
    }

    public function test_hidden_blocks_are_kept_and_the_order_is_exactly_as_given(): void
    {
        $this->save([$this->block('promises'), $this->block('hero', [], null, false), $this->block('pharmacy')]);

        $blocks = app(Site::class)->blocks();
        $this->assertSame(['promises', 'hero', 'pharmacy'], array_column($blocks, 'type'));
        $this->assertFalse($blocks[1]['enabled']);
    }

    public function test_unknown_blocks_and_settings_are_dropped(): void
    {
        $this->save([$this->block('script'), $this->block('banner', ['heading' => ['en' => 'Hi'], 'onclick' => 'x', 'tone' => 'neon'])]);

        $blocks = app(Site::class)->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame(['heading' => ['en' => 'Hi', 'bn' => null]], $blocks[0]['props']);
    }

    public function test_words_are_plain_text_and_cut_to_length(): void
    {
        $this->save([$this->block('banner', ['heading' => ['en' => '<script>alert(1)</script>Sale'.str_repeat('x', 300)]])]);

        $en = app(Site::class)->blocks()[0]['props']['heading']['en'];
        $this->assertStringNotContainsString('<', $en);
        $this->assertLessThanOrEqual(120, mb_strlen($en));
    }

    public function test_only_safe_links_are_accepted(): void
    {
        foreach (['javascript:alert(1)', 'http://example.com', '//evil.com', 'data:text/html,x'] as $bad) {
            $this->save([$this->block('banner', ['button_link' => $bad])])->assertSessionHasErrors('site');
        }

        $this->save([$this->block('banner', ['button_link' => '/market?sector=wood'])])->assertSessionHasNoErrors();
        $this->save([$this->block('banner', ['button_link' => 'https://example.com/offer'])])->assertSessionHasNoErrors();
    }

    public function test_a_single_block_cannot_be_on_the_page_twice_but_banners_can(): void
    {
        $this->save([$this->block('hero'), $this->block('hero', [], 'hero2')])->assertSessionHasErrors('site');
        $this->save([$this->block('banner', [], 'a'), $this->block('banner', [], 'b'), $this->block('spacer')])->assertSessionHasNoErrors();
        $this->assertCount(3, app(Site::class)->blocks());
    }

    public function test_numbers_and_choices_are_held_to_their_limits(): void
    {
        $this->save([$this->block('arrivals', ['count' => 99]), $this->block('banner', ['align' => 'sideways', 'height' => 'lg'])]);

        $blocks = app(Site::class)->blocks();
        $this->assertSame(12, $blocks[0]['props']['count']);
        $this->assertArrayNotHasKey('align', $blocks[1]['props']);
        $this->assertSame('lg', $blocks[1]['props']['height']);
    }

    public function test_a_page_can_have_up_to_thirty_blocks(): void
    {
        $many = array_map(fn ($i) => $this->block('spacer', [], "s{$i}"), range(1, 31));

        $this->save($many)->assertSessionHasErrors();
        $this->save(array_slice($many, 0, 30))->assertSessionHasNoErrors();
    }

    public function test_a_picture_is_uploaded_served_and_removed_when_no_longer_used(): void
    {
        Storage::fake();
        $name = $this->actingAs($this->admin)->post('/admin/site/block-image', ['image' => UploadedFile::fake()->image('b.jpg', 1200, 600)])->assertOk()->json('name');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{8,64}\.(jpg|png|webp|jpeg)$/', $name);
        $this->save([$this->block('banner', ['image' => $name])])->assertSessionHasNoErrors();
        $this->get("/site-image/{$name}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->save([$this->block('banner')])->assertSessionHasNoErrors();
        Storage::assertMissing('brand/'.$name);
    }

    public function test_a_made_up_picture_name_is_not_kept(): void
    {
        $this->save([$this->block('banner', ['image' => '../../.env'])]);

        $this->assertArrayNotHasKey('image', app(Site::class)->blocks()[0]['props']);
        $this->get('/site-image/..%2F..%2F.env')->assertStatus(404);
        $this->get('/site-image/nothere12.jpg')->assertStatus(404);
    }

    public function test_pictures_must_be_pictures_and_not_too_big(): void
    {
        Storage::fake();

        $this->actingAs($this->admin)->post('/admin/site/block-image', ['image' => UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml')])->assertSessionHasErrors('image');
        $this->actingAs($this->admin)->post('/admin/site/block-image', ['image' => UploadedFile::fake()->image('big.jpg')->size(5000)])->assertSessionHasErrors('image');
    }

    public function test_only_the_owner_can_use_the_builder(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);

        $this->get('/admin/site/builder')->assertRedirect();
        $this->actingAs($buyer)->get('/admin/site/builder')->assertRedirect('/');
        $this->actingAs($buyer)->put('/admin/site/blocks', ['blocks' => [$this->block('hero')]])->assertRedirect('/');
        $this->actingAs($buyer)->post('/admin/site/block-image', ['image' => UploadedFile::fake()->image('a.jpg')])->assertRedirect('/');
        $this->assertCount(7, app(Site::class)->blocks());
    }

    public function test_saving_the_older_home_form_does_not_lose_the_arranged_page(): void
    {
        $this->save([$this->block('hero'), $this->block('banner')]);

        $this->actingAs($this->admin)->put('/admin/site/home', ['hero_title' => ['en' => 'Hello'], 'hero_text' => ['en' => 'There']])->assertSessionHasNoErrors();

        $this->assertCount(2, app(Site::class)->blocks());
    }

    public function test_the_standard_blocks_list_matches_the_known_types(): void
    {
        foreach (HomeBlocks::defaults() as $b) {
            $this->assertArrayHasKey($b['type'], HomeBlocks::TYPES);
        }
    }
}
