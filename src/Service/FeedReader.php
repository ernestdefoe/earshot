<?php

namespace ErnestDefoe\Earshot\Service;

use Carbon\Carbon;

/**
 * Reads a podcast's RSS feed into plain arrays. Nothing here touches the
 * network or the database.
 *
 * libxml is given LIBXML_NONET and never LIBXML_NOENT, so a feed cannot reach
 * out for a DTD or expand external entities.
 */
class FeedReader
{
    public const ITUNES = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
    public const PODCAST = 'https://podcastindex.org/namespace/1.0';
    public const CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    /**
     * @return array{show: array<string, mixed>, items: list<array<string, mixed>>}|null
     */
    public function read(string $xml): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $doc || ! isset($doc->channel)) {
            return null;
        }
        $channel = $doc->channel;
        $it = $channel->children(self::ITUNES);

        $show = [
            'title' => self::line((string) $channel->title, 120),
            'description' => self::text((string) ($it->summary ?: $channel->description), 4000),
            'image' => self::url((string) ($it->image?->attributes()->href ?? '')) ?? self::url((string) ($channel->image->url ?? '')),
            'language' => self::line(strtolower((string) $channel->language), 12),
            'explicit' => self::explicit((string) $it->explicit),
            'category' => self::line((string) ($it->category?->attributes()->text ?? ''), 80),
        ];

        $items = [];
        foreach ($channel->item as $item) {
            $parsed = $this->item($item);
            if ($parsed !== null) {
                $items[] = $parsed;
            }
        }

        return ['show' => $show, 'items' => $items];
    }

    /** @return array<string, mixed>|null */
    private function item(\SimpleXMLElement $item): ?array
    {
        $enclosure = $item->enclosure?->attributes();
        $audio = self::url((string) ($enclosure->url ?? ''));
        $title = self::line((string) $item->title, 200);
        if ($audio === null || $title === '') {
            return null;
        }

        $it = $item->children(self::ITUNES);
        $pod = $item->children(self::PODCAST);
        $content = $item->children(self::CONTENT);

        $guid = trim((string) $item->guid) ?: $audio;
        $date = null;
        try {
            $date = trim((string) $item->pubDate) !== '' ? Carbon::parse((string) $item->pubDate)->utc() : null;
        } catch (\Throwable) {
        }

        // The best transcript the feed offers: a timed format first.
        $transcript = null;
        $rank = ['text/vtt' => 4, 'application/x-subrip' => 3, 'application/srt' => 3, 'application/json' => 2, 'text/html' => 1, 'text/plain' => 1];
        foreach ($pod->transcript ?? [] as $tr) {
            $attrs = $tr->attributes();
            $type = strtolower((string) ($attrs->type ?? ''));
            $url = self::url((string) ($attrs->url ?? ''));
            if ($url && ($rank[$type] ?? 0) > ($transcript['rank'] ?? 0)) {
                $transcript = ['url' => $url, 'type' => $type, 'rank' => $rank[$type]];
            }
        }

        $chapters = null;
        foreach ($pod->chapters ?? [] as $c) {
            $attrs = $c->attributes();
            if (str_contains((string) ($attrs->type ?? ''), 'json')) {
                $chapters = self::url((string) $attrs?->url);
                break;
            }
        }

        $summary = (string) ($content->encoded ?: $item->description ?: $it->summary);

        return [
            'guid' => mb_substr($guid, 0, 255),
            'title' => $title,
            'summary' => self::text($summary, 5000),
            'audio' => $audio,
            'type' => self::line((string) ($enclosure->type ?? ''), 60) ?: null,
            'bytes' => ($n = (int) ($enclosure->length ?? 0)) > 0 ? $n : null,
            'duration' => self::duration((string) $it->duration),
            'published' => $date,
            'season' => ($n = (int) $it->season) > 0 && $n < 65536 ? $n : null,
            'number' => ($n = (int) $it->episode) > 0 ? $n : null,
            'image' => self::url((string) ($it->image?->attributes()->href ?? '')),
            'explicit' => self::explicit((string) $it->explicit),
            'chapters' => $chapters,
            'transcript' => $transcript ? ['url' => $transcript['url'], 'type' => $transcript['type']] : null,
        ];
    }

    /**
     * Podcasting 2.0 chapters JSON into [[seconds, title], ...]: sorted, at
     * most 200, titles trimmed. Null when there is nothing usable.
     *
     * @return list<array{0: int, 1: string}>|null
     */
    public static function chapters(string $json): ?array
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! is_array($data['chapters'] ?? null)) {
            return null;
        }
        $out = [];
        foreach ($data['chapters'] as $c) {
            if (! is_array($c) || ! is_numeric($c['startTime'] ?? null) || ($c['toc'] ?? true) === false) {
                continue;
            }
            $title = self::line((string) ($c['title'] ?? ''), 120);
            if ($title !== '') {
                $out[] = [max(0, (int) $c['startTime']), $title];
            }
        }
        usort($out, fn ($a, $b) => $a[0] <=> $b[0]);

        return $out ? array_slice($out, 0, 200) : null;
    }

    /** "1:02:03", "62:03", "3723" or "3723.5" into seconds. */
    public static function duration(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value > 0 ? (int) $value : null;
        }
        if (! preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{1,2})$/', $value, $m)) {
            return null;
        }

        $seconds = ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];

        return $seconds > 0 ? $seconds : null;
    }

    /** http(s) only: a javascript: or file: address from a feed never reaches a page. */
    public static function url(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 1000 || ! preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }

    private static function explicit(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['yes', 'true', 'explicit'], true);
    }

    public static function line(string $value, int $max): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 0, $max);
    }

    /**
     * Show notes, which feeds send as HTML, into plain text: paragraphs and
     * line breaks kept, a link's address kept beside its words (core links a
     * bare address on its own), every tag gone.
     */
    public static function text(string $html, int $max): string
    {
        $html = preg_replace_callback(
            '#<a\b[^>]*href\s*=\s*["\']?(https?://[^"\'\s>]+)["\']?[^>]*>(.*?)</a>#is',
            function ($m) {
                $words = trim(strip_tags($m[2]));

                return ($words === '' || $words === $m[1]) ? $m[1] : "$words ($m[1])";
            },
            $html
        ) ?? $html;
        $html = preg_replace(['#<br\s*/?>#i', '#</(p|div|li|h[1-6])>#i', '#<li\b[^>]*>#i'], ["\n", "\n\n", '- '], $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace(["/[ \t\x{00A0}]+/u", "/ *\n */", "/\n{3,}/"], [' ', "\n", "\n\n"], $text) ?? $text;

        return mb_substr(trim($text), 0, $max);
    }
}
