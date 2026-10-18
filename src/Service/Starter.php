<?php

namespace ErnestDefoe\Earshot\Service;

use ErnestDefoe\Earshot\Model\Episode;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\DispatchEventsTrait;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The discussion behind an episode: started as the show's owner (or whoever
 * published it), in the show's tag. From then on replies, mentions,
 * subscriptions, search and who may see it are core's own.
 *
 * Its id is stored on the episode; a generated post is never found again by
 * its text, since posts hold parsed XML, not what was written.
 */
class Starter
{
    use DispatchEventsTrait;

    public function __construct(
        private ExtensionManager $extensions,
        protected Dispatcher $events,
    ) {
    }

    public function start(Episode $episode, User $author): Discussion
    {
        $show = $episode->show;

        // Plain text: a forum may run neither Markdown nor BBCode.
        $body = trim((string) $episode->summary) !== '' ? (string) $episode->summary : $episode->title;

        $discussion = Discussion::start(mb_substr($episode->title, 0, 80), $author);
        $discussion->created_at = $episode->published_at;
        $discussion->save();

        $post = new CommentPost();
        $post->discussion_id = $discussion->id;
        $post->user_id = $author->id;
        $post->type = CommentPost::$type;
        $post->created_at = $episode->published_at;
        $post->setContentAttribute(mb_substr($body, 0, 60000), $author);
        $post->save();

        // One post by one person: the counts are known. Core's listeners
        // recount on Posted anyway, so counting here would only repeat them.
        $discussion->setFirstPost($post)->setLastPost($post);
        $discussion->comment_count = 1;
        $discussion->participant_count = 1;
        $discussion->save();

        if ($show->tag_id && $this->extensions->isEnabled('flarum-tags') && \Flarum\Tags\Tag::query()->whereKey($show->tag_id)->exists()) {
            $discussion->belongsToMany(\Flarum\Tags\Tag::class, 'discussion_tag')->sync([$show->tag_id]);
            $discussion->unsetRelation('tags');
        }

        // The events a discussion started through the API would fire, so tag
        // and user counts, subscriptions and other extensions all hear of it.
        $post->raise(new Posted($post, $author));
        $this->dispatchEventsFor($discussion, $author);
        $this->dispatchEventsFor($post, $author);

        $episode->discussion_id = $discussion->id;
        $episode->saveQuietly();
        $episode->setRelation('discussion', $discussion);

        return $discussion;
    }

    /** A title edit follows to the discussion, as a rename by whoever made it. */
    public function rename(Episode $episode, User $actor): void
    {
        $discussion = $episode->discussion;
        $title = mb_substr($episode->title, 0, 80);
        if ($discussion && $discussion->title !== $title) {
            $discussion->rename($title);
            $discussion->save();
            $this->dispatchEventsFor($discussion, $actor);
        }
    }

    /** A removed episode's discussion is hidden, not deleted: its replies can be restored. */
    public function hide(Episode $episode, User $actor): void
    {
        $discussion = $episode->discussion;
        if ($discussion && ! $discussion->hidden_at) {
            $discussion->hide($actor);
            $discussion->save();
            $this->dispatchEventsFor($discussion, $actor);
        }
    }

}
