<?php

namespace ErnestDefoe\Earshot\Tests\integration\api;

use ErnestDefoe\Earshot\Api\Controller\EpisodesController;
use ErnestDefoe\Earshot\Model\Episode;
use ErnestDefoe\Earshot\Model\Show;
use ErnestDefoe\Earshot\Service\FeedReader;
use ErnestDefoe\Earshot\Service\Media;
use ErnestDefoe\Earshot\Service\SafeHttp;
use ErnestDefoe\Earshot\Tests\integration\FakeHttp;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Users: 1 admin, 2 member (normalUser), 3 moderator, 4 host of show 1.
 * No test reaches the network: the fetcher is FakeHttp.
 */
class EarshotTest extends ConsoleTestCase
{
    use RetrievesAuthorizedUsers;

    private FakeHttp $http;

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

    private function fake(): FakeHttp
    {
        $this->http = new FakeHttp();
        $this->app()->getContainer()->instance(SafeHttp::class, $this->http);

        return $this->http;
    }

    /** @return array<string, mixed> */
    private function json($response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function call(string $method, string $path, ?int $as = null, array $json = [])
    {
        $options = [];
        if ($as) {
            $options['authenticatedAs'] = $as;
        }
        if ($json) {
            $options['json'] = $json;
        }

        return $this->send($this->request($method, $path, $options));
    }

    private function publish(int $as, array $body = [])
    {
        return $this->call('POST', '/api/earshot/shows/1/episodes', $as, $body + ['title' => 'First light', 'audioUrl' => 'https://cdn.example.com/1.mp3', 'summary' => 'Notes.', 'chapters' => "0:00 Intro\n12:30 Main\nno time here"]);
    }

    #[Test]
    public function anyone_may_list_shows_but_only_moderators_start_one(): void
    {
        $response = $this->call('GET', '/api/earshot/shows');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['Late Shift'], array_column($this->json($response)['shows'], 'title'));
        $this->assertFalse($this->json($response)['canCreate']);

        $this->assertSame(403, $this->call('POST', '/api/earshot/shows', 2, ['title' => 'Nope'])->getStatusCode());

        $made = $this->call('POST', '/api/earshot/shows', 3, ['title' => 'Late Shift', 'hosts' => ['normal']]);
        $this->assertSame(200, $made->getStatusCode(), (string) $made->getBody());
        $show = $this->json($made)['show'];
        $this->assertSame('late-shift-2', $show['slug'], 'slugs never collide');
        $this->assertSame(['normal'], array_column($show['hosts'], 'username'));

        $bad = $this->call('POST', '/api/earshot/shows', 3, ['title' => 'X', 'hosts' => ['nobody-here']]);
        $this->assertSame(422, $bad->getStatusCode());
    }

    #[Test]
    public function hosts_publish_episodes_that_become_discussions(): void
    {
        $this->assertSame(403, $this->publish(2)->getStatusCode(), 'a member who is not a host cannot publish');

        $response = $this->publish(4);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $episode = $this->json($response)['episode'];

        $this->assertSame([[0, 'Intro'], [750, 'Main']], $episode['chapters']);
        $discussion = Discussion::query()->findOrFail($episode['discussionId']);
        $this->assertSame('First light', $discussion->title);
        $this->assertSame(4, $discussion->user_id);

        // The discussion carries the episode for its hero.
        $shown = $this->json($this->call('GET', '/api/discussions/'.$discussion->id));
        $this->assertSame('First light', $shown['data']['attributes']['earshot']['episode']['title']);
        $this->assertSame('Late Shift', $shown['data']['attributes']['earshot']['show']['title']);

        // ...and lists never do.
        $list = $this->json($this->call('GET', '/api/discussions'));
        $this->assertArrayNotHasKey('earshot', $list['data'][0]['attributes']);

        $page = $this->json($this->call('GET', '/api/earshot/shows/late-shift'));
        $this->assertSame(['First light'], array_column($page['episodes'], 'title'));
        $this->assertSame(1, $page['show']['episodeCount']);
    }

    #[Test]
    public function an_episode_needs_audio_from_this_show_or_a_web_address(): void
    {
        $this->assertSame(422, $this->publish(4, ['audioUrl' => ''])->getStatusCode());
        $this->assertSame(422, $this->publish(4, ['audioUrl' => 'javascript:alert(1)'])->getStatusCode());
        $this->assertSame(422, $this->publish(4, ['audioUrl' => '', 'audioPath' => '../../config.php'])->getStatusCode());
        $this->assertSame(422, $this->publish(4, ['audioUrl' => '', 'audioPath' => 'audio/2/'.str_repeat('a', 32).'.mp3'])->getStatusCode(), 'another show\'s path is refused');
    }

    #[Test]
    public function hidden_episodes_leave_lists_and_the_feed(): void
    {
        $id = $this->json($this->publish(4))['episode']['discussionId'];
        $this->publish(4, ['title' => 'Second light', 'audioUrl' => 'https://cdn.example.com/2.mp3']);

        Discussion::query()->whereKey($id)->update(['hidden_at' => \Carbon\Carbon::now(), 'hidden_user_id' => 1]);

        $titles = array_column($this->json($this->call('GET', '/api/earshot/shows/late-shift'))['episodes'], 'title');
        $this->assertSame(['Second light'], $titles);

        $feed = $this->send($this->request('GET', '/podcasts/late-shift/feed'));
        $this->assertSame(200, $feed->getStatusCode());
        $this->assertStringStartsWith('application/rss+xml', $feed->getHeaderLine('Content-Type'));
        $xml = simplexml_load_string((string) $feed->getBody());
        $this->assertNotFalse($xml);
        $this->assertCount(1, $xml->channel->item);
        $this->assertSame('Second light', (string) $xml->channel->item[0]->title);
        $this->assertSame('https://cdn.example.com/2.mp3', (string) $xml->channel->item[0]->enclosure['url']);

        $again = $this->send($this->request('GET', '/podcasts/late-shift/feed')->withHeader('If-None-Match', $feed->getHeaderLine('ETag')));
        $this->assertSame(304, $again->getStatusCode());
    }

    #[Test]
    public function following_a_feed_imports_a_backlog_then_new_episodes_and_tells_followers(): void
    {
        $this->setting('ernestdefoe-earshot.backlog', '3');
        $http = $this->fake();
        $http->feed('https://feeds.example.com/show.rss', 14);

        $made = $this->call('POST', '/api/earshot/shows', 3, ['title' => 'Followed', 'feedUrl' => 'https://feeds.example.com/show.rss']);
        $this->assertSame(200, $made->getStatusCode(), (string) $made->getBody());
        $show = Show::query()->where('slug', 'followed')->firstOrFail();

        $episodes = Episode::query()->where('show_id', $show->id)->orderBy('id')->get();
        $this->assertSame(['ep-12', 'ep-13', 'ep-14'], $episodes->pluck('guid')->all(), 'only the latest few, oldest first');
        $this->assertSame('Episode 14 & friends', $episodes[2]->title);
        $this->assertSame(3723, $episodes[2]->duration);
        $this->assertSame([[0, 'Intro'], [90, 'Second']], $episodes[2]->chapters);
        $this->assertStringContainsString('a link (https://example.com/14)', (string) $episodes[2]->summary);
        $this->assertStringNotContainsString('<script', (string) $episodes[2]->summary);
        $this->assertSame('https://cdn.example.com/cover.jpg', $show->cover_url);
        $this->assertStringStartsWith("covers/$show->id/feed-", (string) $show->cover_path, 'the feed\'s artwork is kept here');
        $disk = $this->app()->getContainer()->make(Media::class)->disk();
        $this->assertSame([1400, 1400], array_slice(getimagesizefromstring($disk->get($show->cover_path)), 0, 2));
        $this->assertSame([400, 400], array_slice(getimagesizefromstring($disk->get(Media::small($show->cover_path))), 0, 2));
        $this->assertSame('Technology', $show->category);
        $this->assertSame(0, $this->database()->table('notifications')->count(), 'a first import tells nobody');

        // A member follows; two new items arrive.
        $this->assertSame(200, $this->call('POST', "/api/earshot/shows/$show->id/follow", 2, ['follow' => true])->getStatusCode());
        $http->feed('https://feeds.example.com/show.rss', 16, '"v2"');
        $this->assertSame(2, $this->app()->getContainer()->make(\ErnestDefoe\Earshot\Service\Follower::class)->refresh($show->fresh(), User::query()->find(1)));
        $this->assertSame(5, Episode::query()->where('show_id', $show->id)->count());
        $this->assertSame(2, $this->database()->table('notifications')->where('user_id', 2)->where('type', 'earshotNewEpisode')->count());

        // The next read is conditional.
        $http->responses['https://feeds.example.com/show.rss'] = ['status' => 304, 'type' => '', 'url' => '', 'body' => '', 'etag' => '', 'modified' => ''];
        $this->assertSame(0, $this->app()->getContainer()->make(\ErnestDefoe\Earshot\Service\Follower::class)->refresh($show->fresh(), User::query()->find(1)));
        $this->assertContains('If-None-Match: "v2"', end($http->asked)[1]);

        // Its feed address sends podcast apps to the real one.
        $feed = $this->send($this->request('GET', '/podcasts/followed/feed'));
        $this->assertSame(301, $feed->getStatusCode());
        $this->assertSame('https://feeds.example.com/show.rss', $feed->getHeaderLine('Location'));
    }

    #[Test]
    public function a_broken_feed_is_recorded_not_thrown(): void
    {
        $http = $this->fake();
        $http->responses['https://feeds.example.com/bad.rss'] = ['status' => 200, 'type' => 'text/html', 'url' => '', 'etag' => '', 'modified' => '', 'body' => '<html>nope</html>'];

        $made = $this->call('POST', '/api/earshot/shows', 1, ['title' => 'Broken', 'feedUrl' => 'https://feeds.example.com/bad.rss']);
        $this->assertSame(200, $made->getStatusCode());
        $this->assertSame('not_a_feed', $this->json($made)['show']['fetchError']);
    }

    #[Test]
    public function members_keep_their_place_and_guests_cannot(): void
    {
        $episode = $this->json($this->publish(4))['episode'];

        $this->assertContains($this->call('POST', '/api/earshot/progress', null, ['episode' => $episode['id'], 'position' => 99])->getStatusCode(), [400, 401]);
        $this->assertSame(0, $this->database()->table('earshot_progress')->count());
        $this->assertSame(204, $this->call('POST', '/api/earshot/progress', 2, ['episode' => $episode['id'], 'position' => 99])->getStatusCode());
        $this->assertSame(204, $this->call('POST', '/api/earshot/progress', 2, ['episode' => $episode['id'], 'position' => 120])->getStatusCode());

        $page = $this->json($this->call('GET', '/api/earshot/shows/late-shift', 2));
        $this->assertSame(['position' => 120, 'finished' => false], $page['episodes'][0]['progress']);
        $this->assertNull($this->json($this->call('GET', '/api/earshot/shows/late-shift', 3))['episodes'][0]['progress'], 'one member never sees another\'s place');
    }

    #[Test]
    public function deleting_an_episode_hides_its_discussion(): void
    {
        $episode = $this->json($this->publish(4))['episode'];
        $this->assertSame(204, $this->call('DELETE', "/api/earshot/episodes/{$episode['id']}", 4)->getStatusCode());
        $this->assertNotNull(Discussion::query()->find($episode['discussionId'])->hidden_at);
        $this->assertSame(0, Show::query()->find(1)->episode_count);
    }

    #[Test]
    public function parsing_helpers(): void
    {
        $this->assertSame('mp3', Media::sniff("ID3\x04\x00"));
        $this->assertSame('mp3', Media::sniff("\xFF\xFB\x90\x00"));
        $this->assertSame('m4a', Media::sniff("\x00\x00\x00\x20ftypM4A "));
        $this->assertSame('ogg', Media::sniff('OggS'));
        $this->assertNull(Media::sniff('<html><body>'));
        $this->assertNull(Media::sniff("\x89PNG\r\n"));

        $this->assertSame(3723, FeedReader::duration('1:02:03'));
        $this->assertSame(3723, FeedReader::duration('62:03'));
        $this->assertSame(3723, FeedReader::duration('3723.4'));
        $this->assertNull(FeedReader::duration('soon'));
        $this->assertNull(FeedReader::url('javascript:alert(1)'));
        $this->assertSame([[5, 'A'], [3725, 'B']], EpisodesController::chapters("1:02:05 - B\n0:05 A"));
    }
}
