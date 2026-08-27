<?php

declare(strict_types=1);

namespace justinholtweb\bouncer\models;

use Craft;
use craft\base\Model;

/**
 * What a refused visitor gets.
 *
 * The distinction that matters here is between *hiding* and *refusing*. `notFound` denies the
 * content's existence, which is the right answer for content whose very presence is confidential;
 * `forbidden` admits it exists and says no. Most sites want neither and want `login` — send them
 * somewhere they can do something about it, and bring them back afterwards.
 */
class RuleResponse extends Model
{
    /** Redirect to Craft's login path with a return URL. */
    public const TYPE_LOGIN = 'login';
    /** Redirect somewhere of the site's choosing. */
    public const TYPE_REDIRECT = 'redirect';
    /** Render a template of the site's choosing, in place, with a 403. */
    public const TYPE_TEMPLATE = 'template';
    /** Show the password form. */
    public const TYPE_PASSWORD = 'password';
    public const TYPE_FORBIDDEN = 'forbidden';
    public const TYPE_NOT_FOUND = 'notFound';

    public string $type = self::TYPE_LOGIN;

    /** Where `redirect` goes. A path, a URL, or an alias. */
    public ?string $redirectUrl = null;

    /** The template `template` renders, relative to the site template root. */
    public ?string $template = null;

    /**
     * A message shown to the refused visitor, and flashed on a login redirect.
     *
     * Deliberately per-rule rather than a single plugin-wide string: "subscribers only" and
     * "this event has finished" are both refusals and neither message works for the other.
     */
    public ?string $message = null;

    /**
     * Keep the URL and render in place, rather than redirecting.
     *
     * Only meaningful for `login` and `redirect`; `template` and the two status types always
     * render in place, because there is nowhere to go.
     */
    public bool $preserveUrl = false;

    public static function types(): array
    {
        return [
            self::TYPE_LOGIN,
            self::TYPE_REDIRECT,
            self::TYPE_TEMPLATE,
            self::TYPE_PASSWORD,
            self::TYPE_FORBIDDEN,
            self::TYPE_NOT_FOUND,
        ];
    }

    public static function typeOptions(): array
    {
        return [
            self::TYPE_LOGIN => Craft::t('bouncer', 'Send to the login page'),
            self::TYPE_REDIRECT => Craft::t('bouncer', 'Redirect somewhere else'),
            self::TYPE_TEMPLATE => Craft::t('bouncer', 'Render a template'),
            self::TYPE_PASSWORD => Craft::t('bouncer', 'Ask for the password'),
            self::TYPE_FORBIDDEN => Craft::t('bouncer', 'Refuse (403 Forbidden)'),
            self::TYPE_NOT_FOUND => Craft::t('bouncer', 'Pretend it does not exist (404)'),
        ];
    }

    public function getStatusCode(): int
    {
        return match ($this->type) {
            self::TYPE_NOT_FOUND => 404,
            default => 403,
        };
    }

    protected function defineRules(): array
    {
        return [
            [['type'], 'required'],
            [['type'], 'in', 'range' => self::types()],
            [['redirectUrl', 'template', 'message'], 'string'],
            [['preserveUrl'], 'boolean'],
            [['redirectUrl'], 'required', 'when' => fn(self $model) => $model->type === self::TYPE_REDIRECT],
            [['template'], 'required', 'when' => fn(self $model) => $model->type === self::TYPE_TEMPLATE],
        ];
    }

    public function getConfig(): array
    {
        return [
            'type' => $this->type,
            'redirectUrl' => $this->redirectUrl ?: null,
            'template' => $this->template ?: null,
            'message' => $this->message ?: null,
            'preserveUrl' => $this->preserveUrl,
        ];
    }
}
