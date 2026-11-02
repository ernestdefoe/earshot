<?php

namespace ErnestDefoe\Earshot\Api;

use Flarum\Foundation\ValidationException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A translated validation error, for the controllers that check input.
 *
 * @property TranslatorInterface $translator
 */
trait Fails
{
    /**
     * A 422 on one field. The using controller injects the
     * translator: a trait takes no constructor.
     *
     * @param array<string, mixed> $params
     */
    protected function fail(string $field, string $key, array $params = []): never
    {
        throw new ValidationException([$field => $this->translator->trans("ernestdefoe-earshot.api.$key", $params)]);
    }
}
