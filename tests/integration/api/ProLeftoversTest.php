<?php

namespace ErnestDefoe\Earshot\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;

/**
 * Earshot Pro disabled but not purged: its columns stay on earshot_episodes.
 * A supporters-only episode and a scheduled one must stay hidden everywhere a
 * guest or a member could read them, exactly as with Pro on (fail closed).
 *
 * Users: 1 admin, 2 member, 3 moderator (earshot.manage), 4 host of show 1.
 */
class ProLeftoversTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-earshot');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'host', 'email' => 'host@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [['user_id' => 3, 'group_id' => Group::MODERATOR_ID]],
            'earshot_shows' => [
                ['id' => 1, 'slug' => 'late-shift', 'title' => 'Late Shift', 'user_id' => 1, 'language' => 'en', 'created_at' => '2026-01-01 00:00:00'],
            ],
            'earshot_hosts' => [['show_id' => 1, 'user_id' => 4, 'position' => 0]],
        ]);
    }

    private function call(string $method, string $path, ?int $as = null, array $json = [])
    {
        return $this->send($this->request($method, $path, array_filter(['authenticatedAs' => $as, 'json' => $json])));
    }

    /** Three episodes: public, supporters-only and scheduled, as Pro left them. */
    private function leftovers(): array
    {
        $this->app();
        // Pro's columns (earshot-pro migration 2026_10_18_100001), left behind by a disable.
        // On MySQL the ALTER commits the test's transaction, so an earlier test
        // may already have added them.
        $schema = $this->database()->getSchemaBuilder();
        if (! $schema->hasColumn('earshot_episodes', 'audience_group_id')) {
            $schema->table('earshot_episodes', function (Blueprint $table) {
                $table->unsignedInteger('audience_group_id')->nullable();
                $table->unsignedInteger('early_group_id')->nullable();
                $table->dateTime('public_at')->nullable();
                $table->dateTime('released_at')->nullable();
            });
        }

        // ...and its episodes are still there too: start from none.
        $db = $this->database();
        $old = $db->table('earshot_episodes')->pluck('discussion_id')->all();
        $db->table('earshot_episodes')->delete();
        $db->table('posts')->whereIn('discussion_id', $old)->delete();
        $db->table('discussions')->whereIn('id', $old)->delete();

        $ids = [];
        foreach (['Open Mic', 'Backstage Pass', 'Next Week'] as $title) {
            $response = $this->call('POST', '/api/earshot/shows/1/episodes', 4, ['title' => $title, 'audioUrl' => 'https://cdn.example.com/'.count($ids).'.mp3']);
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $ids[$title] = json_decode((string) $response->getBody(), true)['episode']['discussionId'];
        }
        $this->database()->table('earshot_episodes')->where('title', 'Backstage Pass')->update(['audience_group_id' => Group::MODERATOR_ID]);
        $this->database()->table('earshot_episodes')->where('title', 'Next Week')->update(['public_at' => Carbon::now()->addWeek()]);

        return $ids;
    }

    private function titles(string $path, ?int $as, string $key): array
    {
        $response = $this->call('GET', $path, $as);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);

        return $key === 'data'
            ? array_map(fn ($d) => $d['attributes']['title'], $body['data'])
            : array_column($body[$key], 'title');
    }

    #[Test]
    public function supporter_only_and_scheduled_episodes_stay_hidden_with_pro_disabled(): void
    {
        $ids = $this->leftovers();

        foreach ([null, 2] as $as) {
            $who = $as ? 'a member' : 'a guest';
            $this->assertSame(['Open Mic'], $this->titles('/api/discussions', $as, 'data'), "discussion list, $who");
            $this->assertNotContains('Backstage Pass', $this->titles('/api/discussions?filter[q]=Backstage', $as, 'data'), "search, $who");
            $this->assertNotContains('Next Week', $this->titles('/api/discussions?filter[q]=Next', $as, 'data'), "search, $who");
            $this->assertSame(['Open Mic'], $this->titles('/api/earshot/shows/late-shift', $as, 'episodes'), "show page, $who");
            $this->assertSame(['Open Mic'], $this->titles('/api/earshot/latest', $as, 'episodes'), "latest widget, $who");
            $this->assertSame(404, $this->call('GET', '/api/discussions/'.$ids['Backstage Pass'], $as)->getStatusCode(), "single episode, $who");
            $this->assertSame(404, $this->call('GET', '/api/discussions/'.$ids['Next Week'], $as)->getStatusCode(), "single episode, $who");
            $this->assertSame(200, $this->call('GET', '/api/discussions/'.$ids['Open Mic'], $as)->getStatusCode(), "single episode, $who");
        }

        $feed = (string) $this->call('GET', '/podcasts/late-shift/feed')->getBody();
        $this->assertStringContainsString('Open Mic', $feed);
        $this->assertStringNotContainsString('Backstage Pass', $feed, 'public feed');
        $this->assertStringNotContainsString('Next Week', $feed, 'public feed');

        // Podcast managers still see everything, to fix it or turn Pro back on.
        $this->assertCount(3, $this->titles('/api/earshot/shows/late-shift', 3, 'episodes'));
    }

    #[Test]
    public function a_scheduled_episode_appears_once_its_time_has_come(): void
    {
        $this->leftovers();
        $this->database()->table('earshot_episodes')->where('title', 'Next Week')->update(['public_at' => Carbon::now()->subMinute()]);

        $this->assertEqualsCanonicalizing(['Open Mic', 'Next Week'], $this->titles('/api/earshot/shows/late-shift', null, 'episodes'));
    }
}
