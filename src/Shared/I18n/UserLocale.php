<?php

/**
 * Applies the signed-in user's interface language.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\I18n
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.8.2
 */

declare(strict_types=1);

namespace Lwt\Shared\I18n;

use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Shared\Infrastructure\Database\Settings;

/**
 * Sets the translator to the language the signed-in user chose.
 *
 * TranslatorServiceProvider::boot() runs before the middleware restores the
 * user, so on a multi-user install it can only see the global default. The
 * middleware calls this once the user is known. The user's own choice also
 * beats a leftover `lwt_lang` cookie, which only exists to serve guests.
 *
 * A user who never chose keeps whatever boot() settled on, so a language picked
 * on the sign-up page lasts until they pick one in their preferences.
 */
final class UserLocale
{
    /**
     * Apply the current user's stored interface language, if they have one.
     *
     * @return void
     */
    public static function apply(): void
    {
        $container = Container::getInstance();
        if (!$container->has(Translator::class)) {
            return;
        }

        try {
            $locale = Settings::getOwnValue('app_language');
        } catch (\RuntimeException $e) {
            return;
        }

        $translator = $container->getTyped(Translator::class);
        if ($locale !== '' && in_array($locale, $translator->getAvailableLocales(), true)) {
            $translator->setLocale($locale);
        }
    }
}
