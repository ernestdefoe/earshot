<?php

namespace ErnestDefoe\Earshot\Http;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Earshot's page styles, built with the extension (css/earshot.css) and
 * fetched with the page chunk instead of riding in every visitor's forum.css.
 * The URL carries the content hash, so a browser keeps each version for a
 * year and fetches the next as soon as it ships. No .css in the route: nginx
 * serves *.css itself and never passes the request to PHP.
 */
class StylesheetController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = __DIR__.'/../../css/earshot.css';
        $hash = substr((string) hash_file('sha256', $path), 0, 12);
        $current = ($request->getQueryParams()['v'] ?? '') === $hash;

        return new Response(new Stream($path, 'r'), 200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
            'ETag' => '"'.$hash.'"',
        ]);
    }
}
