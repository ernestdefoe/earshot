<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-earshot.email.new_episode.body', [
'{show}' => $blueprint->episode->show->title,
'{title}' => $blueprint->episode->title,
'{url}' => $url->to('forum')->route('discussion', ['id' => $blueprint->episode->discussion_id]),
]) !!}
</x-slot:body>
</x-mail::plain.notification>
