<?php

namespace ErnestDefoe\Earshot\Notification;

use ErnestDefoe\Earshot\Model\Episode;
use Flarum\Discussion\Discussion;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/** "A show you follow has a new episode." The subject is the episode's discussion. */
class NewEpisode implements AlertableInterface, BlueprintInterface, MailableInterface
{
    public function __construct(public Episode $episode, public ?User $from = null)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->episode->discussion;
    }

    public function getFromUser(): ?User
    {
        return $this->from;
    }

    /** @return array<string, string> */
    public function getData(): mixed
    {
        return ['show' => $this->episode->show->title];
    }

    public static function getType(): string
    {
        return 'earshotNewEpisode';
    }

    public static function getSubjectModel(): string
    {
        return Discussion::class;
    }

    /** @return array<string, string> */
    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-earshot::emails.plain.newEpisode', 'html' => 'ernestdefoe-earshot::emails.html.newEpisode'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-earshot.email.new_episode.subject', [
            '{show}' => $this->episode->show->title,
            '{title}' => $this->episode->title,
        ]);
    }
}
