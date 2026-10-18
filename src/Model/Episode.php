<?php

namespace ErnestDefoe\Earshot\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $show_id
 * @property int|null $discussion_id
 * @property string $guid
 * @property string $title
 * @property int|null $season
 * @property int|null $number
 * @property string|null $summary
 * @property string|null $audio_path
 * @property string|null $audio_url
 * @property string|null $audio_type
 * @property int|null $audio_bytes
 * @property int|null $duration
 * @property string|null $image_url
 * @property list<array{0: int, 1: string}>|null $chapters
 * @property bool $explicit
 * @property Carbon $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Show $show
 * @property-read Discussion|null $discussion
 */
class Episode extends AbstractModel
{
    protected $table = 'earshot_episodes';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = [
        'chapters' => 'array',
        'explicit' => 'bool',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<Show, $this> */
    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class, 'show_id');
    }

    /** @return BelongsTo<Discussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class, 'discussion_id');
    }

    /**
     * Released, and its discussion one this person may read: a show in a
     * members-only tag keeps its episodes from guests, in lists and feeds too.
     *
     * @param Builder<Episode> $query
     */
    public function scopeVisibleTo(Builder $query, User $actor): void
    {
        $query->where('published_at', '<=', Carbon::now())
            ->whereIn('discussion_id', Discussion::query()->whereVisibleTo($actor)->select('discussions.id'));
    }
}
