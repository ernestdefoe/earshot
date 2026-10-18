<?php

namespace ErnestDefoe\Earshot\Api\Controller;

use ErnestDefoe\Earshot\Api\Concerns;
use ErnestDefoe\Earshot\Api\Presenter;
use ErnestDefoe\Earshot\Service\Media;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A show's cover, or an episode's audio file, uploaded by someone who may
 * edit the show. A cover is applied at once; audio waits for the episode
 * form to name it.
 */
class UploadController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private Media $media,
        private Presenter $presenter,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $show = $this->editableShow($actor, $this->param($request, 'id'));
        $file = $request->getUploadedFiles()['file'] ?? null;

        if (! $file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $this->fail('file', 'upload_failed');
        }

        if ($this->param($request, 'kind') === 'cover') {
            if ((int) $file->getSize() > 10 * 1024 * 1024) {
                $this->fail('file', 'too_big', ['{mb}' => 10]);
            }
            $path = $this->media->storeCover($file, $show->id);
            if ($path === null) {
                $this->fail('file', 'cover_invalid');
            }
            $old = $show->cover_path;
            $show->cover_path = $path;
            $show->cover_url = null;
            $show->save();
            $this->media->deleteCover($old);

            return new JsonResponse(['show' => $this->presenter->show($show, $actor, true)]);
        }

        $mb = max(1, (int) ($this->settings->get('ernestdefoe-earshot.max_audio_mb') ?: 250));
        if ((int) $file->getSize() > $mb * 1024 * 1024) {
            $this->fail('file', 'too_big', ['{mb}' => $mb]);
        }
        $stored = $this->media->storeAudio($file, $show->id);
        if ($stored === null) {
            $this->fail('file', 'audio_invalid');
        }
        [$path, $type, $bytes] = $stored;

        return new JsonResponse(['path' => $path, 'url' => $this->media->url($path), 'type' => $type, 'bytes' => $bytes]);
    }
}
