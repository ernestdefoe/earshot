<?php

namespace ErnestDefoe\Earshot\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $tagline
 * @property string|null $description
 * @property int|null $user_id
 * @property int|null $tag_id
 * @property string|null $cover_path
 * @property string|null $cover_url
 * @property string|null $color
 * @property string $language
 * @property string|null $category
 * @property bool $explicit
 * @property array<string, string>|null $links
 * @property string|null $feed_url
 * @property string|null $feed_etag
 * @property string|null $feed_modified
 * @property Carbon|null $fetched_at
 * @property string|null $fetch_error
 * @property int $episode_count
 * @property int $follower_count
 * @property Carbon|null $last_episode_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $hosts
 */
class Show extends AbstractModel
{
    /** Subscribe links a show may list, in the order the hero shows them. */
    public const LINKS = ['apple', 'spotify', 'youtube', 'pocketcasts', 'overcast'];

    protected $table = 'earshot_shows';

    protected $guarded = [];

    // Flarum's AbstractModel turns timestamps off; this table has them.
    public $timestamps = true;

    protected $casts = [
        'explicit' => 'bool',
        'links' => 'array',
        'fetched_at' => 'datetime',
        'last_episode_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function hosts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'earshot_hosts', 'show_id', 'user_id')->orderBy('earshot_hosts.position');
    }

    /** @return HasMany<Episode, $this> */
    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class, 'show_id');
    }

    public function follows(): bool
    {
        return $this->feed_url !== null && $this->feed_url !== '';
    }

    /** Hosts publish; moderators with earshot.manage edit any show. Uses the loaded hosts. */
    public function editableBy(User $actor): bool
    {
        if ($actor->isGuest()) {
            return false;
        }
        if ($actor->hasPermission('earshot.manage')) {
            return true;
        }

        return $this->user_id === $actor->id || $this->hosts->contains('id', $actor->id);
    }

    /** Counts kept on the row, so the index page reads them without a query per show. */
    public function refreshCounts(): void
    {
        $this->episode_count = $this->episodes()->count();
        $this->last_episode_at = $this->episodes()->max('published_at');
        $this->follower_count = (int) $this->getConnection()->table('earshot_follows')->where('show_id', $this->id)->count();
    }
}
