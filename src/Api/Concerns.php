<?php

namespace ErnestDefoe\Earshot\Api;

use ErnestDefoe\Earshot\Model\Show;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/** What every Earshot controller needs: who is asking, and what they mean. */
trait Concerns
{
    protected function actor(ServerRequestInterface $request): User
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertPermission($actor->hasPermission('earshot.view'));

        return $actor;
    }

    /** @return array<string, mixed> */
    protected function input(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    protected function param(ServerRequestInterface $request, string $key): string
    {
        $value = Arr::get($request->getQueryParams(), $key, '');

        return is_scalar($value) ? (string) $value : '';
    }

    /** A show this person may change, or a 403. */
    protected function editableShow(User $actor, int|string $id): Show
    {
        $show = Show::query()->with('hosts')->findOrFail((int) $id);
        if (! $show->editableBy($actor)) {
            throw new PermissionDeniedException();
        }

        return $show;
    }

    /** @param array<string, mixed> $params */
    protected function fail(string $field, string $key, array $params = []): never
    {
        throw new ValidationException([$field => resolve('translator')->trans("ernestdefoe-earshot.api.$key", $params)]);
    }
}
