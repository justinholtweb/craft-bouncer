<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\services;

use Craft;
use craft\base\Component;
use craft\base\FsInterface;
use craft\base\LocalFsInterface;
use craft\models\Volume;
use justinholtweb\bouncer\models\AccessRule;
use justinholtweb\bouncer\models\RuleTarget;
use justinholtweb\bouncer\models\VolumeExposure;
use justinholtweb\bouncer\Plugin;

/**
 * The audit that makes the file protection true (Pro).
 *
 * Rewriting a protected asset's URL is only half the job. If the volume's filesystem is still
 * sitting in the web root, the original URL keeps working — the rule looks enforced in every
 * template on the site and the file is one guessed path away. That is the single most likely way
 * to deploy this plugin wrong, and it is invisible unless something goes looking.
 *
 * So Bouncer goes looking, on the settings screen and from the console, and hands over the exact
 * server config to fix it rather than a link to some documentation.
 */
class Exposure extends Component
{
    /** @return VolumeExposure[] One per volume that at least one rule protects. */
    public function audit(): array
    {
        $reports = [];
        $rulesByVolumeUid = $this->rulesByVolumeUid();

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $rules = $rulesByVolumeUid[$volume->uid] ?? [];

            if ($rules === []) {
                continue;
            }

            $reports[] = $this->auditVolume($volume, $rules);
        }

        return $reports;
    }

    /** @return VolumeExposure[] Only the ones with something to say. */
    public function exposures(): array
    {
        return array_values(array_filter($this->audit(), static fn(VolumeExposure $r) => $r->getIsExposed()));
    }

    public function hasCriticalExposure(): bool
    {
        foreach ($this->audit() as $report) {
            if ($report->getIsCritical()) {
                return true;
            }
        }

        return false;
    }

    /** @param AccessRule[] $rules */
    public function auditVolume(Volume $volume, array $rules): VolumeExposure
    {
        $report = new VolumeExposure([
            'volume' => $volume,
            'rules' => $rules,
        ]);

        $fs = $this->fsOrNull(static fn() => $volume->getFs());
        $transformFs = $this->fsOrNull(static fn() => $volume->getTransformFs());

        $report->fsHandle = $volume->getFsHandle();
        $report->transformFsHandle = $volume->getTransformFsHandle();
        $report->fsHasUrls = $fs?->hasUrls ?? false;
        $report->transformFsHasUrls = $transformFs?->hasUrls ?? false;

        if ($fs === null) {
            $report->severity = VolumeExposure::SEVERITY_WARNING;
            $report->findings[] = Craft::t('bouncer', 'This volume’s filesystem could not be loaded, so its exposure cannot be checked.');

            return $report;
        }

        $this->checkPublicUrls($report, $fs, $transformFs);
        $this->checkLocalPaths($report, $volume, $fs, $transformFs);

        if ($report->findings === []) {
            $report->findings[] = Craft::t('bouncer', 'The files are not reachable except through Bouncer.');
        }

        return $report;
    }

    private function checkPublicUrls(VolumeExposure $report, FsInterface $fs, ?FsInterface $transformFs): void
    {
        if ($fs->hasUrls) {
            $report->severity = VolumeExposure::SEVERITY_CRITICAL;
            $report->findings[] = Craft::t('bouncer', 'The filesystem has public URLs, so every protected file in it can be fetched directly at its own URL.');
            $report->remedies[] = Craft::t('bouncer', 'Turn off “Assets in this filesystem have public URLs” and move the files outside the web root. Bouncer serves them from there.');
        }

        // A public transform filesystem leaks the image itself, at a URL derived from the
        // original — which is exactly as bad as leaking the original, and much easier to miss.
        if ($transformFs !== null && $transformFs->hasUrls && $transformFs !== $fs) {
            $report->severity = VolumeExposure::SEVERITY_CRITICAL;
            $report->findings[] = Craft::t('bouncer', 'The transform filesystem has public URLs, so generated thumbnails of protected images are public.');
            $report->remedies[] = Craft::t('bouncer', 'Point the volume’s transform filesystem at a private filesystem too. Bouncer generates protected transforms into Craft’s runtime directory and never writes them to a public filesystem.');
        }
    }

    private function checkLocalPaths(VolumeExposure $report, Volume $volume, FsInterface $fs, ?FsInterface $transformFs): void
    {
        $webroot = $this->webroot();

        foreach ([$fs, $transformFs] as $candidate) {
            if (!$candidate instanceof LocalFsInterface) {
                continue;
            }

            $path = $this->realPath($candidate->getRootPath());

            if ($path === null || $webroot === null || !str_starts_with($path . '/', $webroot . '/')) {
                continue;
            }

            if (in_array($path, $report->exposedPaths, true)) {
                continue;
            }

            $report->exposedPaths[] = $path;
            $report->severity = VolumeExposure::SEVERITY_CRITICAL;
            $report->findings[] = Craft::t('bouncer', 'The files are stored inside the web root, at {path} — the web server will serve them whether Craft knows their URL or not.', [
                'path' => $path,
            ]);
        }

        if ($report->exposedPaths !== []) {
            $report->remedies[] = Craft::t('bouncer', 'Move the directory outside the web root, or deny it in the web server config using the snippet below.');
        }
    }

    /**
     * A web-server rule that seals a directory off.
     *
     * Generated rather than documented because the path is the part people get wrong, and a
     * snippet with the real path in it can be pasted without thinking about it.
     */
    public function serverSnippet(VolumeExposure $report, string $server = 'apache'): ?string
    {
        if ($report->exposedPaths === []) {
            return null;
        }

        $webroot = $this->webroot();

        if ($server === 'nginx') {
            $lines = ["# Deny direct access to files Bouncer protects. Add inside the server block."];

            foreach ($report->exposedPaths as $path) {
                $location = $webroot !== null && str_starts_with($path, $webroot)
                    ? '/' . trim(substr($path, strlen($webroot)), '/')
                    : $path;

                $lines[] = "location ^~ {$location}/ {";
                $lines[] = '    deny all;';
                $lines[] = '    return 404;';
                $lines[] = '}';
            }

            return implode("\n", $lines);
        }

        // Apache: a .htaccess file placed *in* the directory, which is harder to get wrong than a
        // path-matching block in the vhost and survives the directory being moved.
        return implode("\n", [
            '# Save as .htaccess inside each directory listed above.',
            '# Apache 2.4:',
            'Require all denied',
            '',
            '# Apache 2.2:',
            '# Order allow,deny',
            '# Deny from all',
        ]);
    }

    /** @return array<string, AccessRule[]> Volume UID => the rules protecting it. */
    private function rulesByVolumeUid(): array
    {
        $isPro = Plugin::getInstance()->isPro();
        $map = [];
        $allVolumeUids = null;

        foreach (Plugin::getInstance()->rules->getRulesForTargetType(RuleTarget::TYPE_ASSETS) as $rule) {
            if (!$rule->enabled) {
                continue;
            }

            if ($rule->target->allSources) {
                $allVolumeUids ??= array_map(
                    static fn(Volume $volume) => $volume->uid,
                    Craft::$app->getVolumes()->getAllVolumes(),
                );
                $uids = $allVolumeUids;
            } else {
                $uids = $rule->target->sourceUids;
            }

            foreach ($uids as $uid) {
                $map[$uid][] = $rule;
            }
        }

        return $map;
    }

    private function fsOrNull(callable $get): ?FsInterface
    {
        try {
            return $get();
        } catch (\Throwable) {
            // A volume pointing at a filesystem that no longer exists throws here. That is worth
            // reporting, not worth taking the whole settings screen down for.
            return null;
        }
    }

    private function webroot(): ?string
    {
        return $this->realPath(Craft::getAlias('@webroot', false) ?: null);
    }

    private function realPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $real = realpath($path);

        return $real === false ? rtrim($path, '/') : rtrim($real, '/');
    }
}
