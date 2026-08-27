<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\events\DefineAssetUrlEvent;
use craft\helpers\ImageTransforms;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\models\ImageTransform;
use craft\web\Request as WebRequest;
use justinholtweb\bouncer\models\Edition;
use justinholtweb\bouncer\models\RuleTarget;
use justinholtweb\bouncer\Plugin;

/**
 * The file half (Pro): making a protected asset's URL point at Bouncer instead of at the file.
 *
 * This is the piece the WordPress *Protect Uploads* plugin exists for, and the reason an
 * element-only access plugin is not enough. Hiding an asset from queries does nothing at all to
 * `/uploads/2026/contract.pdf`, which is one guess and one GET away from anybody.
 *
 * Two decisions worth stating, because both look wrong at first glance:
 *
 * - **URLs are rewritten for everybody, not just for refused visitors.** A URL that changes
 *   depending on who is looking cannot be cached — not by Craft's template cache, not by a CDN,
 *   not by the browser — and the first time a logged-in editor's cached page is served to the
 *   public, the raw URL goes with it. The route re-checks access on every hit, so a stable URL
 *   costs nothing.
 * - **Transforms are generated into the runtime directory, not into Craft's transform
 *   filesystem.** The transform filesystem is public on most sites, so putting a protected
 *   image's thumbnail there would leak the thing the rule is protecting, at a URL that is
 *   derivable from the original.
 */
class Assets extends Component
{
    /** The single query-string parameter a guarded URL carries. */
    public const PARAM = 'bref';

    /** @var array<int, bool> */
    private array $_protectedCache = [];

    public function handleBeforeDefineUrl(DefineAssetUrlEvent $event): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->guardAssetUrls || !Edition::allowsAssetProtection(Plugin::getInstance()->isPro())) {
            return;
        }

        $request = Craft::$app->getRequest();

        // Site requests only. In the control panel Craft's own permissions apply, and rewriting
        // there would send every asset thumbnail through a route that is allowed to refuse it.
        if (!$request instanceof WebRequest || !$request->getIsSiteRequest()) {
            return;
        }

        if (!$this->isProtected($event->asset)) {
            return;
        }

        $event->url = $this->guardedUrl($event->asset, $event->transform);
        // Without `handled`, Craft treats a non-null URL as advisory in some paths and recomputes
        // it — and the recomputed one is the public file.
        $event->handled = true;
    }

    /** Whether any enabled rule targets this asset. Independent of who is asking. */
    public function isProtected(Asset $asset): bool
    {
        if ($asset->id !== null && isset($this->_protectedCache[$asset->id])) {
            return $this->_protectedCache[$asset->id];
        }

        $isPro = Plugin::getInstance()->isPro();
        $protected = false;

        foreach (Plugin::getInstance()->rules->getRulesForTargetType(RuleTarget::TYPE_ASSETS) as $rule) {
            if ($rule->matchesElement($asset, $isPro)) {
                $protected = true;
                break;
            }
        }

        if ($asset->id !== null) {
            $this->_protectedCache[$asset->id] = $protected;
        }

        return $protected;
    }

    /**
     * The guarded URL for an asset, with the transform folded into a signed reference.
     *
     * The transform is signed rather than passed as readable parameters so the route cannot be
     * used to make the server generate arbitrary image sizes on demand — the classic way a
     * well-meant "serve images through PHP" endpoint becomes a CPU exhaustion vector.
     */
    public function guardedUrl(Asset $asset, mixed $transform = null, int $expiry = 0, bool $bypassRules = false): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $path = $settings->getFileRouteUri() . '/' . $asset->uid;

        // The filename rides along in the path so a saved download is named sensibly even when a
        // client ignores Content-Disposition, and so the URL reads like a file.
        $filename = $asset->getFilename();

        if ($filename !== '') {
            $path .= '/' . rawurlencode($filename);
        }

        $reference = $this->encodeReference($asset, $transform, $expiry, $bypassRules);

        return UrlHelper::siteUrl($path, $reference !== null ? [self::PARAM => $reference] : null);
    }

    /**
     * A link that works without an account, for a while.
     *
     * The one deliberate hole in the wall, and the reason it is safe to have: it is signed with
     * the site's security key, it expires, and it names exactly one asset. Handing somebody a
     * signed URL is a decision the site makes on purpose, unlike the file being readable because
     * nobody moved it out of the web root.
     */
    public function signedUrl(Asset $asset, ?int $duration = null, mixed $transform = null): string
    {
        $duration ??= Plugin::getInstance()->getSettings()->signedUrlDuration;

        return $this->guardedUrl($asset, $transform, time() + max(60, $duration), true);
    }

    /**
     * @return array{transform: ImageTransform|null, expiry: int, bypass: bool}|null
     *     Null when the reference is absent, forged or expired — the caller cannot tell which,
     *     and should not: a forged reference and a stale one get the same answer.
     */
    public function decodeReference(?string $reference, Asset $asset): ?array
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        $decoded = self::base64UrlDecode($reference);

        if ($decoded === null) {
            return null;
        }

        $data = Craft::$app->getSecurity()->validateData($decoded);

        if ($data === false) {
            return null;
        }

        $payload = Json::decodeIfJson($data);

        if (!is_array($payload) || ($payload['a'] ?? null) !== $asset->uid) {
            return null;
        }

        $expiry = (int)($payload['x'] ?? 0);

        if ($expiry > 0 && $expiry < time()) {
            return null;
        }

        $transformString = $payload['t'] ?? null;

        return [
            'transform' => $transformString ? $this->transformFromString($transformString) : null,
            'expiry' => $expiry,
            'bypass' => (bool)($payload['b'] ?? false),
        ];
    }

    private function encodeReference(Asset $asset, mixed $transform, int $expiry, bool $bypassRules): ?string
    {
        $transformString = $this->transformToString($transform);

        if ($transformString === null && $expiry === 0 && !$bypassRules) {
            return null;
        }

        $payload = Json::encode(array_filter([
            'a' => $asset->uid,
            't' => $transformString,
            'x' => $expiry ?: null,
            'b' => $bypassRules ?: null,
        ], static fn($value) => $value !== null));

        // Base64url, because `hashData()` prepends a hex hash to the raw JSON and that JSON is
        // full of braces and quotes. Those survive `UrlHelper` but not every proxy, mail client
        // and chat app the link passes through on its way to being clicked.
        return self::base64UrlEncode(Craft::$app->getSecurity()->hashData($payload));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private function transformToString(mixed $transform): ?string
    {
        if ($transform === null || $transform === '') {
            return null;
        }

        $normalized = ImageTransforms::normalizeTransform($transform);

        if ($normalized === null) {
            return null;
        }

        // A named transform keeps its handle, which makes the reference short and means editing
        // the transform in the CP changes what the URL serves — the behaviour everybody expects
        // from a named transform.
        if ($normalized->handle) {
            return 'h:' . $normalized->handle;
        }

        return 's:' . ImageTransforms::getTransformString($normalized, true);
    }

    private function transformFromString(string $string): ?ImageTransform
    {
        if (str_starts_with($string, 'h:')) {
            return Craft::$app->getImageTransforms()->getTransformByHandle(substr($string, 2));
        }

        if (str_starts_with($string, 's:')) {
            return ImageTransforms::createTransformFromString(substr($string, 2));
        }

        return null;
    }
}
