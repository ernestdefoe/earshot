<?php

namespace ErnestDefoe\Earshot\Tests\integration;

use ErnestDefoe\Earshot\Service\SafeHttp;

/**
 * The fetcher with the network taken out: canned answers by URL, and a log of
 * what was asked (with its headers). The guard (check) is the real one.
 */
class FakeHttp extends SafeHttp
{
    /** @var array<string, array<string, mixed>> */
    public array $responses = [];

    /** @var list<array{0: string, 1: list<string>}> */
    public array $asked = [];

    public function get(string $url, int $maxBytes, int $timeout = 6, array $headers = []): array
    {
        $this->asked[] = [$url, $headers];

        return $this->responses[$url] ?? ['error' => 'unreachable'];
    }

    public function check(string $url): array|string
    {
        return ['host' => (string) parse_url($url, PHP_URL_HOST), 'port' => 443, 'ip' => '203.0.113.10'];
    }

    /** A feed of $count items, numbered 1..$count, newest first. */
    public function feed(string $url, int $count, string $etag = '"v1"'): void
    {
        $items = '';
        for ($i = $count; $i >= 1; $i--) {
            $date = gmdate('D, d M Y H:i:s', strtotime("2026-01-01 +$i days")).' +0000';
            $chapters = $i === $count ? '<podcast:chapters url="https://cdn.example.com/ch.json" type="application/json+chapters"/>' : '';
            $items .= <<<XML
                <item>
                  <title>Episode $i &amp; friends</title>
                  <guid isPermaLink="false">ep-$i</guid>
                  <pubDate>$date</pubDate>
                  <description><![CDATA[<p>Notes for $i with <a href="https://example.com/$i">a link</a>.</p><script>alert(1)</script>]]></description>
                  <enclosure url="https://cdn.example.com/$i.mp3" length="1234" type="audio/mpeg"/>
                  <itunes:duration>1:02:03</itunes:duration>
                  <itunes:episode>$i</itunes:episode>
                  $chapters
                </item>
                XML;
        }
        $this->responses[$url] = ['status' => 200, 'type' => 'application/rss+xml', 'url' => $url, 'etag' => $etag, 'modified' => '', 'body' => <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:podcast="https://podcastindex.org/namespace/1.0">
              <channel>
                <title>Followed Show</title>
                <description>About the followed show.</description>
                <itunes:image href="https://cdn.example.com/cover.jpg"/>
                <itunes:category text="Technology"/>
                $items
              </channel>
            </rss>
            XML];
        $this->responses['https://cdn.example.com/ch.json'] = ['status' => 200, 'type' => 'application/json', 'url' => '', 'etag' => '', 'modified' => '',
            'body' => json_encode(['version' => '1.2.0', 'chapters' => [['startTime' => 90, 'title' => 'Second'], ['startTime' => 0, 'title' => 'Intro'], ['startTime' => 5, 'title' => 'Hidden', 'toc' => false]]])];
    }
}
