<?php

namespace Tests\Feature\Gaming;

use App\Enums\AccountType;
use App\Models\Game;
use App\Models\GamePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The owner chooses each game's cover picture in Admin > Game top-ups. */
class GameCoverTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->game = Game::create(['name' => 'PUBG Mobile', 'slug' => 'pubg-mobile', 'id_label' => 'Player ID', 'is_active' => true]);
        GamePackage::create(['game_id' => $this->game->id, 'name' => '60 UC', 'price' => 90, 'is_active' => true]);
    }

    public function test_the_owner_uploads_a_cover_and_the_public_sees_it(): void
    {
        $this->actingAs($this->admin)->post("/admin/gaming/games/{$this->game->id}/cover", ['cover' => UploadedFile::fake()->image('cover.jpg', 600, 800)])->assertSessionHasNoErrors();

        $this->assertNotNull($this->game->fresh()->photo_path);
        $this->get('/gaming/pubg-mobile/cover')->assertOk();
        $this->get('/gaming')->assertInertia(fn ($p) => $p->where('games.0.cover', fn ($url) => str_contains($url, '/gaming/pubg-mobile/cover')));
    }

    public function test_a_new_cover_replaces_the_old_one_and_can_be_removed(): void
    {
        $this->actingAs($this->admin)->post("/admin/gaming/games/{$this->game->id}/cover", ['cover' => UploadedFile::fake()->image('a.jpg')]);
        $first = $this->game->fresh()->photo_path;
        $this->actingAs($this->admin)->post("/admin/gaming/games/{$this->game->id}/cover", ['cover' => UploadedFile::fake()->image('b.png')]);

        Storage::assertMissing($first);
        $this->actingAs($this->admin)->delete("/admin/gaming/games/{$this->game->id}/cover");
        $this->assertNull($this->game->fresh()->photo_path);
        $this->get('/gaming/pubg-mobile/cover')->assertNotFound();
    }

    public function test_only_pictures_and_only_the_owner(): void
    {
        $this->actingAs($this->admin)->post("/admin/gaming/games/{$this->game->id}/cover", ['cover' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('cover');

        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->actingAs($buyer)->post("/admin/gaming/games/{$this->game->id}/cover", ['cover' => UploadedFile::fake()->image('c.jpg')])->assertRedirect('/');
        $this->assertNull($this->game->fresh()->photo_path);
    }
}
