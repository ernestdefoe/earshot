<?php

namespace ErnestDefoe\Earshot\Service;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Str;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Covers and audio a forum hosts itself, on one public disk.
 *
 * Covers are re-encoded, so every stored picture is one Earshot wrote. Audio is
 * kept as uploaded (re-encoding audio in PHP is not an option) but only after
 * its first bytes prove it is the audio type it claims, and under a random
 * name with an extension Earshot picks: an uploaded file can never be served
 * as HTML or script. The web server sends these files itself, so seeking
 * (HTTP range requests) works without PHP.
 */
class Media
{
    public const DISK = 'ernestdefoe-earshot';

    private const MAX_PIXELS = 40_000_000;

    /** Container sniffed from the file => [extension, MIME type sent in feeds]. */
    private const AUDIO = [
        'mp3' => ['mp3', 'audio/mpeg'],
        'm4a' => ['m4a', 'audio/mp4'],
        'ogg' => ['ogg', 'audio/ogg'],
    ];

    public function __construct(private Factory $filesystems)
    {
    }

    public function disk(): Cloud
    {
        $disk = $this->filesystems->disk(self::DISK);
        if (! $disk instanceof Cloud) {
            throw new \RuntimeException('The Earshot disk must be able to give public URLs.');
        }

        return $disk;
    }

    public function url(?string $path): ?string
    {
        return $path ? $this->disk()->url($path) : null;
    }

    public function delete(?string $path): void
    {
        if ($path) {
            $this->disk()->delete($path);
        }
    }

    /** A stored cover's small version (400 px), for cards and the player. */
    public static function small(string $path): string
    {
        return (string) preg_replace('/\.jpg$/', '-s.jpg', $path);
    }

    public function deleteCover(?string $path): void
    {
        if ($path) {
            $this->disk()->delete([$path, self::small($path)]);
        }
    }

    public function storeCover(UploadedFileInterface $file, int $showId): ?string
    {
        return $this->storeCoverBytes((string) $file->getStream(), $showId);
    }

    /**
     * A cover: square-cropped from the centre and re-encoded as JPEG, twice:
     * at most 1400 px (what Apple asks for at minimum) for the hero and
     * podcast apps, and 400 px for cards and the player. Returns the large
     * one's path, or null when the bytes are not a picture.
     */
    public function storeCoverBytes(string $data, int $showId): ?string
    {
        $info = @getimagesizefromstring($data);
        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            return null;
        }
        [$w, $h] = $info;
        if ($w < 200 || $h < 200 || $w * $h > self::MAX_PIXELS) {
            return null;
        }
        $source = @imagecreatefromstring($data);
        if (! $source) {
            return null;
        }

        $side = min($w, $h);
        $path = "covers/$showId/".Str::random(24).'.jpg';
        foreach ([$path => 1400, self::small($path) => 400] as $target => $max) {
            $size = min($max, $side);
            $out = imagecreatetruecolor($size, $size);
            imagefill($out, 0, 0, (int) imagecolorallocate($out, 255, 255, 255));
            imagecopyresampled($out, $source, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $size, $size, $side, $side);
            ob_start();
            imagejpeg($out, null, $max > 400 ? 86 : 82);
            $this->disk()->put($target, (string) ob_get_clean());
        }

        return $path;
    }

    /**
     * An episode's audio. Returns [path, MIME type, bytes], or null when the
     * file is not MP3, M4A/AAC or Ogg (Opus or Vorbis).
     *
     * @return array{0: string, 1: string, 2: int}|null
     */
    public function storeAudio(UploadedFileInterface $file, int $showId): ?array
    {
        $stream = $file->getStream();
        $stream->rewind();
        $head = $stream->read(16);
        $kind = self::sniff($head);
        if ($kind === null) {
            return null;
        }
        [$ext, $type] = self::AUDIO[$kind];

        $path = "audio/$showId/".Str::random(32).'.'.$ext;
        $stream->rewind();
        $this->disk()->writeStream($path, $stream->detach());

        return [$path, $type, (int) $this->disk()->size($path)];
    }

    /** Which audio container these first bytes start, if any. */
    public static function sniff(string $head): ?string
    {
        if (str_starts_with($head, 'ID3')) {
            return 'mp3';
        }
        // An MPEG audio frame sync: 11 set bits, layer III.
        if (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE6) === 0xE2) {
            return 'mp3';
        }
        if (substr($head, 4, 4) === 'ftyp') {
            return 'm4a';
        }
        if (str_starts_with($head, 'OggS')) {
            return 'ogg';
        }

        return null;
    }
}
