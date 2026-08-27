<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\services;

use Craft;
use craft\base\Component;
use craft\base\LocalFsInterface;
use craft\elements\Asset;
use craft\errors\ImageTransformException;
use craft\helpers\FileHelper;
use craft\helpers\ImageTransforms;
use craft\models\ImageTransform;
use justinholtweb\bouncer\Plugin;
use Throwable;

/**
 * Getting the bytes of a protected file, without ever putting them somewhere public.
 *
 * The interesting half is transforms. Craft generates transforms into the volume's *transform
 * filesystem*, which on most sites is the same public directory the originals live in — so
 * generating a thumbnail of a protected image the normal way publishes it at a derivable URL.
 * Bouncer generates into Craft's runtime directory instead, which is outside the web root by
 * definition, and serves from there.
 */
class Files extends Component
{
    /**
     * A readable local path for the asset, transformed if asked.
     *
     * Null means the file is on a remote filesystem and has to be streamed instead — see
     * {@see self::stream()}. Callers must handle both; assuming a path exists is what makes an
     * asset plugin work perfectly until somebody moves a volume to S3.
     */
    public function localPath(Asset $asset, ?ImageTransform $transform = null): ?string
    {
        if ($transform !== null) {
            return $this->transformPath($asset, $transform);
        }

        $volume = $asset->getVolume();
        $fs = $volume->getFs();

        if (!$fs instanceof LocalFsInterface) {
            return null;
        }

        $path = FileHelper::normalizePath($fs->getRootPath() . DIRECTORY_SEPARATOR . $volume->getSubpath() . $asset->getPath());

        return is_file($path) ? $path : null;
    }

    /**
     * A local copy of a remote asset's file.
     *
     * Only used when a range was asked for and the volume is remote — see the note in
     * `FileController::serve()`. Craft cleans these out of its temp directory during garbage
     * collection, so nothing here has to.
     */
    public function localCopy(Asset $asset): ?string
    {
        try {
            $path = $asset->getCopyOfFile();
        } catch (Throwable $e) {
            Craft::warning("Could not copy asset {$asset->id} locally: {$e->getMessage()}", Plugin::LOG_CATEGORY);

            return null;
        }

        return is_file($path) ? $path : null;
    }

    /** @return resource */
    public function stream(Asset $asset)
    {
        return $asset->getStream();
    }

    /**
     * Generate (or reuse) a protected transform under the runtime path.
     *
     * Cached on the asset's UID, its modification time and the transform string, so editing the
     * image or the transform produces a new file rather than serving the old one — and so nothing
     * has to be invalidated by hand.
     */
    public function transformPath(Asset $asset, ImageTransform $transform): ?string
    {
        $dir = $this->transformDir();
        $key = implode('|', [
            $asset->uid,
            $asset->dateModified?->getTimestamp() ?? 0,
            ImageTransforms::getTransformString($transform, true),
            $transform->format ?? '',
        ]);

        $format = $transform->format ?: ImageTransforms::detectTransformFormat($asset);
        $path = $dir . DIRECTORY_SEPARATOR . substr($asset->uid, 0, 2) . DIRECTORY_SEPARATOR . md5($key) . '.' . $format;

        if (is_file($path)) {
            return $path;
        }

        try {
            $tempPath = ImageTransforms::generateTransform($asset, $transform);
        } catch (ImageTransformException | Throwable $e) {
            Craft::warning("Could not generate a protected transform for asset {$asset->id}: {$e->getMessage()}", Plugin::LOG_CATEGORY);

            return null;
        }

        FileHelper::createDirectory(dirname($path));

        // Rename rather than copy so a concurrent request never reads a half-written file — on the
        // same filesystem this is atomic, and the runtime path and the temp path are both under
        // Craft's storage directory.
        if (!@rename($tempPath, $path)) {
            @copy($tempPath, $path);
            @unlink($tempPath);
        }

        return is_file($path) ? $path : null;
    }

    /** Remove every generated protected transform. */
    public function clearTransforms(): void
    {
        $dir = $this->transformDir();

        if (is_dir($dir)) {
            FileHelper::clearDirectory($dir);
        }
    }

    public function transformDir(): string
    {
        $dir = Craft::$app->getPath()->getRuntimePath() . DIRECTORY_SEPARATOR . 'bouncer' . DIRECTORY_SEPARATOR . 'transforms';

        FileHelper::createDirectory($dir);

        return $dir;
    }

    public function mimeType(Asset $asset, ?ImageTransform $transform = null): string
    {
        try {
            return $asset->getMimeType($transform) ?: 'application/octet-stream';
        } catch (Throwable) {
            return 'application/octet-stream';
        }
    }

    /**
     * Whether the browser may display this inline.
     *
     * Anything that can execute in the origin's context is sent as an attachment whatever the
     * settings say. An HTML or SVG file served inline from the site's own domain is a stored XSS
     * hole, and "the file was already on the site" is not a defence when Bouncer is the thing
     * that gave it a URL on the front end.
     */
    public function allowsInline(Asset $asset): bool
    {
        $extension = strtolower($asset->getExtension());

        return !in_array($extension, ['html', 'htm', 'svg', 'xml', 'xhtml', 'js', 'mjs', 'swf'], true);
    }
}
