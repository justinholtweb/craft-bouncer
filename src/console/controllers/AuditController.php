<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\bouncer\models\Edition;
use justinholtweb\bouncer\models\VolumeExposure;
use justinholtweb\bouncer\Plugin;
use yii\console\ExitCode;

/**
 * `craft bouncer/audit` — check that everything Bouncer is asked to protect is actually protected.
 *
 * Meant for a deploy pipeline. It exits non-zero when a protected file is still fetchable or when
 * a rule cannot be evaluated by this edition, so a misconfiguration fails the build instead of
 * shipping quietly — which is the only way anybody finds out before a reader does.
 */
class AuditController extends Controller
{
    public $defaultAction = 'index';

    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();
        $problems = 0;

        $this->stdout("Rules\n", Console::BOLD);

        $rules = $plugin->rules->getAllRules();

        if ($rules === []) {
            $this->stdout("  No rules. Everything on this site is public.\n");
        }

        foreach ($rules as $rule) {
            $status = $rule->enabled ? 'enabled' : 'disabled';
            $this->stdout("  {$rule->handle} ({$rule->target->type}, $status)\n");
        }

        $unevaluable = $plugin->rules->getUnevaluableRules();

        if ($unevaluable !== []) {
            $problems += count($unevaluable);
            $this->stdout("\nRules this edition cannot evaluate — they are refusing everybody:\n", Console::FG_YELLOW);

            foreach ($unevaluable as $rule) {
                $this->stdout("  {$rule->handle}: " . implode(', ', $rule->proFeaturesUsed()) . "\n", Console::FG_YELLOW);
            }
        }

        if (!Edition::allowsAssetProtection($plugin->isPro())) {
            $this->stdout("\nFile protection needs Bouncer Pro; skipping the exposure audit.\n");

            return $problems > 0 ? ExitCode::DATAERR : ExitCode::OK;
        }

        $this->stdout("\nFiles\n", Console::BOLD);

        $reports = $plugin->exposure->audit();

        if ($reports === []) {
            $this->stdout("  No rules protect any assets.\n");
        }

        foreach ($reports as $report) {
            $colour = match ($report->severity) {
                VolumeExposure::SEVERITY_CRITICAL => Console::FG_RED,
                VolumeExposure::SEVERITY_WARNING => Console::FG_YELLOW,
                default => Console::FG_GREEN,
            };

            $this->stdout("  [{$report->severity}] {$report->volume->name}\n", $colour);

            foreach ($report->findings as $finding) {
                $this->stdout("      $finding\n");
            }

            foreach ($report->remedies as $remedy) {
                $this->stdout("      → $remedy\n", Console::FG_CYAN);
            }

            if ($report->getIsCritical()) {
                $problems++;
            }
        }

        $this->stdout("\n");

        if ($problems > 0) {
            $this->stdout("$problems problem(s) found.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $this->stdout("Everything Bouncer protects is protected.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /** Print the web-server config needed to seal off every exposed directory. */
    public function actionSnippets(string $server = 'nginx'): int
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsAssetProtection($plugin->isPro())) {
            $this->stderr("File protection needs Bouncer Pro.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $printed = false;

        foreach ($plugin->exposure->audit() as $report) {
            $snippet = $plugin->exposure->serverSnippet($report, $server);

            if ($snippet === null) {
                continue;
            }

            $this->stdout("# {$report->volume->name}\n", Console::BOLD);
            $this->stdout("$snippet\n\n");
            $printed = true;
        }

        if (!$printed) {
            $this->stdout("Nothing to seal off — no protected volume stores files inside the web root.\n", Console::FG_GREEN);
        }

        return ExitCode::OK;
    }
}
