<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The Obsidian vault reader.
 *
 * Every test points the service at a throwaway vault in the system temp
 * directory rather than at the developer's real one: the assertions are about
 * parsing, filtering and containment, and a real vault would make them depend on
 * whatever Alexander happened to write last.
 */
class ObsidianVaultTest extends TestCase
{
    use RefreshDatabase;

    private string $vault;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vault = sys_get_temp_dir().'/omnitrack-vault-'.uniqid();
        $this->outside = sys_get_temp_dir().'/omnitrack-outside-'.uniqid().'.md';

        File::makeDirectory($this->vault.'/Projects', 0755, true);
        File::makeDirectory($this->vault.'/.obsidian/plugins', 0755, true);

        File::put($this->vault.'/Welcome.md', "# Welcome\n\nCatatan pertama, ada #second-brain dan [[Projects/Omnitrack]].\n");
        File::put($this->vault.'/Projects/Omnitrack.md', "---\ntitle: Omnitrack Notes\ntags: [proyek, laravel]\n---\n\n# Omnitrack\n\nVault dibaca oleh Omnitrack.\n");
        File::put($this->vault.'/Projects/Roadmap.md', "# Roadmap\n\n- [ ] Sinkronisasi Obsidian\n");
        // Obsidian's own state directory: never a note, not even through the API.
        File::put($this->vault.'/.obsidian/plugins/hidden.md', "# Bukan catatan\n");
        File::put($this->outside, "# Rahasia\n\nTidak boleh terbaca.\n");

        config([
            'services.obsidian.vault_path' => $this->vault,
            'services.obsidian.vault_name' => 'Second Brain',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vault);
        @unlink($this->outside);

        parent::tearDown();
    }

    public function test_vault_page_renders_for_the_signed_in_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/vault')
            ->assertOk()
            ->assertSee('Second Brain');
    }

    public function test_notes_endpoint_lists_notes_with_folders_tags_and_stats(): void
    {
        $response = $this->actingAs(User::factory()->create())->getJson('/vault/notes');

        $response->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('name', 'Second Brain')
            ->assertJsonPath('stats.notes', 3)
            ->assertJsonPath('stats.folders', 2);

        $paths = $this->paths($response->json('notes'));

        $this->assertSame(['Projects/Omnitrack.md', 'Projects/Roadmap.md', 'Welcome.md'], $paths);

        $omnitrack = collect($response->json('notes'))->firstWhere('path', 'Projects/Omnitrack.md');

        $this->assertSame('Omnitrack Notes', $omnitrack['title']);
        $this->assertSame(['laravel', 'proyek'], $omnitrack['tags']);
        $this->assertSame('Projects', $omnitrack['folder']);
    }

    public function test_notes_endpoint_filters_by_folder_and_by_name(): void
    {
        $user = User::factory()->create();

        $folder = $this->actingAs($user)->getJson('/vault/notes?folder=Projects');
        $this->assertSame(['Projects/Omnitrack.md', 'Projects/Roadmap.md'], $this->paths($folder->json('notes')));

        $name = $this->actingAs($user)->getJson('/vault/notes?q=roadmap');
        $this->assertSame(['Projects/Roadmap.md'], $this->paths($name->json('notes')));
    }

    public function test_search_matches_body_text_and_returns_a_snippet(): void
    {
        $response = $this->actingAs(User::factory()->create())->getJson('/vault/search?q=Obsidian');

        $response->assertOk()->assertJsonPath('matched', 1);

        $hit = $response->json('results.0');

        $this->assertSame('Projects/Roadmap.md', $hit['path']);
        $this->assertStringContainsString('Obsidian', $hit['snippet']);
    }

    public function test_search_rejects_a_one_character_query(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/vault/search?q=a')
            ->assertStatus(422);
    }

    public function test_note_endpoint_returns_body_frontmatter_links_and_backlinks(): void
    {
        $user = User::factory()->create();

        $welcome = $this->actingAs($user)->getJson('/vault/note?path=Welcome.md');

        $welcome->assertOk()
            ->assertJsonPath('note.title', 'Welcome')
            ->assertJsonPath('note.links.0', 'Projects/Omnitrack');

        $note = $this->actingAs($user)->getJson('/vault/note?path='.urlencode('Projects/Omnitrack.md'));

        $note->assertOk()
            ->assertJsonPath('note.title', 'Omnitrack Notes')
            ->assertJsonPath('note.frontmatter.title', 'Omnitrack Notes')
            // A path-qualified link ([[Projects/Omnitrack]]) still counts.
            ->assertJsonPath('note.backlinks.0', 'Welcome.md');

        $this->assertStringContainsString('Vault dibaca oleh Omnitrack.', $note->json('note.content'));
    }

    public function test_note_endpoint_refuses_to_leave_the_vault(): void
    {
        $user = User::factory()->create();

        $attacks = [
            '../'.basename($this->outside),
            $this->outside,
            'C:/Windows/win.ini',
            '.obsidian/plugins/hidden.md',
            'Projects/../../'.$this->outside,
        ];

        foreach ($attacks as $path) {
            $this->actingAs($user)
                ->getJson('/vault/note?path='.urlencode($path))
                ->assertStatus(404);
        }
    }

    public function test_vault_endpoints_degrade_when_the_mount_is_absent(): void
    {
        config(['services.obsidian.vault_path' => $this->vault.'-missing']);

        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/vault/notes')
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('stats.notes', 0)
            ->assertJsonPath('notes', []);

        $this->actingAs($user)->getJson('/vault/search?q=Obsidian')
            ->assertOk()
            ->assertJsonPath('matched', 0);

        $this->actingAs($user)->getJson('/vault/note?path=Welcome.md')
            ->assertStatus(404);
    }

    public function test_harness_vault_routes_require_the_token(): void
    {
        $this->getJson('/api/harness/vault')->assertStatus(401);
        $this->withToken('wrong-token')->getJson('/api/harness/vault')->assertStatus(401);
    }

    public function test_harness_vault_routes_expose_the_vault_to_agents(): void
    {
        $token = ['Authorization' => 'Bearer test-harness-token'];

        $this->withHeaders($token)->getJson('/api/harness/vault')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('stats.notes', 3);

        // Files written in the same second have no defined mtime order, so this
        // compares the set rather than an index.
        $folder = $this->withHeaders($token)->getJson('/api/harness/vault/notes?folder=Projects')->assertOk();

        $this->assertSame(['Projects/Omnitrack.md', 'Projects/Roadmap.md'], $this->paths($folder->json('notes')));

        $this->withHeaders($token)->getJson('/api/harness/vault/search?q=Obsidian')
            ->assertOk()
            ->assertJsonPath('matched', 1);

        $this->withHeaders($token)->getJson('/api/harness/vault/note?path='.urlencode('Projects/Roadmap.md'))
            ->assertOk()
            ->assertJsonPath('note.title', 'Roadmap');

        $this->withHeaders($token)->getJson('/api/harness/vault/note?path='.urlencode('../'.basename($this->outside)))
            ->assertStatus(404);
    }

    /**
     * Sorted note paths, so an assertion never depends on filesystem mtime order.
     *
     * @param  array<int, array<string, mixed>>  $notes
     * @return list<string>
     */
    private function paths(array $notes): array
    {
        $paths = array_column($notes, 'path');
        sort($paths);

        return $paths;
    }
}
