<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\controllers;

use Craft;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use craft\models\ImageTransform;
use craft\web\Controller;
use craft\web\Response;
use justinholtweb\bouncer\models\Edition;
use justinholtweb\bouncer\models\RuleResponse;
use justinholtweb\bouncer\Plugin;
use justinholtweb\bouncer\services\Assets;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * The guarded delivery route (Pro).
 *
 * Everything about this controller assumes the URL is public knowledge, because it is — it ends
 * up in HTML, in caches, in people's history and in referrer headers. The URL is not the
 * authority; the access check on every single request is.
 *
 * The 404-for-everything policy is deliberate: an unknown UID, an asset in an unprotected volume,
 * a forged reference and a refused visitor all look identical from outside unless a rule
 * explicitly says otherwise. Distinguishing them turns the route into an oracle for which files
 * exist.
 */
class FileController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function actionDownload(string $uid, ?string $filename = null): Response
    {
        if (!Edition::allowsAssetProtection(Plugin::getInstance()->isPro())) {
            throw new NotFoundHttpException();
        }

        // `.bouncer(false)` matters: this asset is protected by definition — that is why the URL
        // points here — so the query filter would hide it from Bouncer's own lookup and the route
        // would answer 404 for every file it exists to serve.
        $asset = Asset::find()->uid($uid)->status(null)->bouncer(false)->one();

        if (!$asset instanceof Asset) {
            throw new NotFoundHttpException();
        }

        $reference = Craft::$app->getRequest()->getQueryParam(Assets::PARAM);
        $decoded = Plugin::getInstance()->assets->decodeReference($reference, $asset);

        // A reference that was supplied but did not verify is a forgery or an expired share link.
        // Falling through to the normal access check would be friendlier and would also let a
        // tampered transform parameter be retried without one.
        if ($reference !== null && $reference !== '' && $decoded === null) {
            throw new NotFoundHttpException();
        }

        $transform = $decoded['transform'] ?? null;

        if (!($decoded['bypass'] ?? false)) {
            $this->requireAccess($asset);
        }

        return $this->serve($asset, $transform);
    }

    /**
     * Refuse anyone the rules refuse.
     *
     * A file gets a narrower set of responses than a page: redirecting an `<img>` to the login
     * screen produces a broken image and a confusing login page in the network tab, so the only
     * outcomes here are 403 and 404. Which one comes from the rule, so a site that hides the
     * existence of its content keeps hiding it.
     */
    private function requireAccess(Asset $asset): void
    {
        $verdict = Plugin::getInstance()->access->checkElement($asset);

        Plugin::getInstance()->log->recordVerdict($verdict, $asset);

        if ($verdict->allowed) {
            return;
        }

        if ($verdict->getResponse()?->type === RuleResponse::TYPE_NOT_FOUND) {
            throw new NotFoundHttpException();
        }

        throw new ForbiddenHttpException($verdict->getResponse()?->message
            ?? Craft::t('bouncer', 'You do not have access to this file.'));
    }

    private function serve(Asset $asset, ?ImageTransform $transform): Response
    {
        $files = Plugin::getInstance()->files;
        $settings = Plugin::getInstance()->getSettings();
        $response = Craft::$app->getResponse();

        $path = $files->localPath($asset, $transform);

        // A transform that could not be generated falls back to the original rather than to an
        // error: a slightly-too-large image is a better outcome than a broken one, and the
        // failure is already in the logs.
        if ($path === null && $transform !== null) {
            $transform = null;
            $path = $files->localPath($asset, null);
        }

        $wantsRange = Craft::$app->getRequest()->getHeaders()->get('Range') !== null;

        // A stream from a remote filesystem is not reliably seekable, and answering a range
        // request by seeking a stream that cannot seek sends the *wrong bytes* under a 206 —
        // silent corruption, which is worse than being slow. So a ranged request against a remote
        // volume gets a local copy first, and everything else streams straight through.
        if ($path === null && $wantsRange) {
            $path = $files->localCopy($asset);
        }

        $mimeType = $files->mimeType($asset, $transform);
        $inline = !$settings->forceDownload && $files->allowsInline($asset);
        $filename = $transform !== null && $path !== null
            ? pathinfo($asset->getFilename(), PATHINFO_FILENAME) . '.' . pathinfo($path, PATHINFO_EXTENSION)
            : $asset->getFilename();

        $this->setCommonHeaders($response);

        if ($path !== null) {
            if ($this->handOff($response, $path, $mimeType, $filename, $inline)) {
                return $response;
            }

            if ($this->notModified($response, $path)) {
                return $response;
            }

            $response->getHeaders()->set('Accept-Ranges', 'bytes');

            // Yii's own file sending implements `Range` completely — 206, `Content-Range`, and a
            // 416 for a range that cannot be met. Doing it by hand here produced a 200 with a
            // truncated body, which every media player treats as a broken file.
            return $response->sendFile($path, $filename, [
                'mimeType' => $mimeType,
                'inline' => $inline,
            ]);
        }

        // Remote filesystem, no range asked for: stream it through without buffering the lot.
        return $response->sendStreamAsFile($files->stream($asset), $filename, [
            'mimeType' => $mimeType,
            'inline' => $inline,
            'fileSize' => $asset->size,
        ]);
    }

    /**
     * Headers every response gets.
     *
     * `private` and `no-store` matter: a protected file that a shared proxy or a CDN is allowed to
     * cache is a protected file that will eventually be served to somebody who was refused.
     */
    private function setCommonHeaders(Response $response): void
    {
        $headers = $response->getHeaders();

        $headers->set('Cache-Control', 'private, max-age=0, no-store, must-revalidate');
        $headers->set('Pragma', 'no-cache');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    /**
     * Conditional requests.
     *
     * Worth having even with caching disabled: a `<video>` element re-requests constantly, and a
     * 304 costs one stat call instead of the whole file.
     */
    private function notModified(Response $response, string $path): bool
    {
        $mtime = @filemtime($path);

        if ($mtime === false) {
            return false;
        }

        $etag = '"' . md5($path . '|' . $mtime . '|' . (@filesize($path) ?: 0)) . '"';
        $headers = $response->getHeaders();

        $headers->set('ETag', $etag);
        $headers->set('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

        $request = Craft::$app->getRequest();
        $ifNoneMatch = $request->getHeaders()->get('If-None-Match');
        $ifModifiedSince = $request->getHeaders()->get('If-Modified-Since');

        // A conditional request that also carries a `Range` is asking "has it changed, and if not
        // give me this slice" — answering 304 there is correct only when nothing changed, which
        // is exactly what this returns.
        if ($ifNoneMatch !== null && trim($ifNoneMatch) === $etag) {
            $response->setStatusCode(304);
            return true;
        }

        if ($ifNoneMatch === null && $ifModifiedSince !== null && @strtotime($ifModifiedSince) >= $mtime) {
            $response->setStatusCode(304);
            return true;
        }

        return false;
    }

    /**
     * Hand the file to the web server instead of pushing it through PHP.
     *
     * Only ever an optimisation, and only when the path maps to a location the server has been
     * told about. If nothing matches, this returns false and PHP does the work — silently
     * emitting an `X-Accel-Redirect` the server does not understand would send the visitor an
     * empty 200, which is the worst possible failure for a download.
     */
    private function handOff(Response $response, string $path, string $mimeType, string $filename, bool $inline): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $method = $settings->fileDeliveryMethod;

        if ($method === null || $method === '') {
            return false;
        }

        $internal = null;

        foreach ($settings->getInternalPathMap() as $root => $location) {
            $root = FileHelper::normalizePath($root);

            if (str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                $internal = $location . '/' . ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
                break;
            }
        }

        if ($internal === null) {
            return false;
        }

        $headers = $response->getHeaders();
        $headers->set('Content-Type', $mimeType);
        $headers->set('Content-Disposition', $this->contentDisposition($filename, $inline));

        if ($method === 'x-accel-redirect') {
            $headers->set('X-Accel-Redirect', $internal);
        } else {
            $headers->set('X-Sendfile', $path);
        }

        $response->format = Response::FORMAT_RAW;
        $response->content = '';

        return true;
    }

    private function contentDisposition(string $filename, bool $inline): string
    {
        $disposition = $inline ? 'inline' : 'attachment';
        $fallback = preg_replace('/[^\x20-\x7e]/', '_', $filename) ?? 'file';

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $disposition, addslashes($fallback), rawurlencode($filename));
    }
}
