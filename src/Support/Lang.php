<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The storefront's wording, looked up by key.
 *
 * ONE FILE PER LANGUAGE
 *
 * Strings live in lang/<locale>.php -- a flat array of key => text. This replaced
 * config/lang.php, which held every language side by side in one nested array:
 * that shape works for eight strings and stops working at eighty, because a key
 * missing from one language is invisible next to the same key present in the
 * other, and two translators cannot touch the file without meeting in it.
 *
 * FALLBACK IS PER KEY, NOT PER FILE
 *
 * A lookup tries the active locale, then Arabic, then hands back the key itself.
 * So a half-written lang/en.php shows English where it has been translated and
 * Arabic where it has not, instead of a blank page -- and a key that exists in no
 * file at all shows up on screen as `products.sort.newest`, which is a bug report
 * rather than an empty <h2> nobody notices.
 *
 * Deliberately small: no plural rules, no nested files, no per-request locale
 * negotiation. Arabic has six plural forms and the two strings that would want
 * them are phrased to read correctly beside any number instead.
 */
final class Lang
{
    private const FALLBACK = 'ar';

    /** Where the dashboard remembers its language between requests. */
    private const ADMIN_SESSION_KEY = 'admin_locale';

    /** @var array<string,array<string,string>> loaded locales, by name */
    private static array $loaded = [];

    /**
     * Set for this request only, by the slide editor's preview.
     *
     * The site has one locale and reads it from config; nothing about a visit
     * chooses a language. The preview is the exception and the reason is not
     * translation but direction: an administrator has to see the card the way an
     * English visitor gets it AND the way an Arabic one does, and the two are
     * mirror images. Left null -- which it is everywhere but there -- this class
     * behaves exactly as it always has.
     */
    private static ?string $override = null;

    /**
     * @param array<string,string|int> $replace  :name placeholders to fill
     */
    public static function get(string $key, array $replace = []): string
    {
        $text = self::lookup($key);

        foreach ($replace as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
    }

    public static function locale(): string
    {
        return self::$override ?? (string) Config::get('app.locale', self::FALLBACK);
    }

    /** The document's direction for the active locale. */
    public static function isRtl(): bool
    {
        return in_array(self::locale(), ['ar', 'fa', 'he', 'ur'], true);
    }

    /** @param ?string $locale null restores the configured locale */
    public static function useLocale(?string $locale): void
    {
        self::$override = $locale;
    }

    /**
     * The dashboard's language, which is the administrator's choice and not the
     * shop's.
     *
     * The storefront still has one locale and nothing about a visit moves it.
     * The admin panel is different: the people running the shop do not all read
     * Arabic, and they need to check both directions of every screen they edit.
     * So ?lang=<code> on any admin URL switches the panel and is remembered in
     * the session; without the parameter the remembered choice applies, and
     * without either the configured locale does, exactly as before.
     *
     * Called by Auth::require() once the session is known to be an
     * administrator's, and by the login page. It goes through useLocale(), so it
     * is the same per-request override the slide preview uses -- not a second
     * mechanism. The preview opts out (it passes its own ?lang= for the frame,
     * which must not change the language of the panel around it).
     */
    public static function applyAdminChoice(): void
    {
        $allowed = self::adminLocales();
        $asked   = $_GET['lang'] ?? null;

        if (is_string($asked) && in_array($asked, $allowed, true)) {
            $_SESSION[self::ADMIN_SESSION_KEY] = $asked;
        }

        $chosen = $_SESSION[self::ADMIN_SESSION_KEY] ?? null;

        if (is_string($chosen) && in_array($chosen, $allowed, true)) {
            self::useLocale($chosen);
        }
    }

    /**
     * A language's name in that language ("English", "العربية") -- what a
     * language switch shows, because a reader looks for their own language
     * written the way they write it. From the locale's own file, not the active one.
     */
    public static function nativeName(string $locale): string
    {
        return self::strings($locale)['lang.native'] ?? $locale;
    }

    /**
     * The languages the dashboard offers: app.admin.locales, minus any code
     * without a lang file, so a typo in config cannot offer a blank language.
     *
     * @return list<string>
     */
    public static function adminLocales(): array
    {
        $codes = (array) Config::get('app.admin.locales', [self::FALLBACK]);

        return array_values(array_filter(
            $codes,
            static fn ($code): bool => is_string($code) && self::path($code) !== null,
        ));
    }

    private static function lookup(string $key): string
    {
        $locale = self::locale();

        return self::strings($locale)[$key]
            ?? self::strings(self::FALLBACK)[$key]
            ?? $key;
    }

    /**
     * One locale's strings, loaded once per request.
     *
     * A missing file is an empty array rather than an error: asking for a locale
     * nobody has translated yet should fall through to Arabic, not take the page
     * down. The name is checked against a pattern before it reaches the path,
     * because it comes from config and, in the preview, from a request.
     *
     * @return array<string,string>
     */
    private static function strings(string $locale): array
    {
        if (isset(self::$loaded[$locale])) {
            return self::$loaded[$locale];
        }

        $path = self::path($locale);

        if ($path === null) {
            return self::$loaded[$locale] = [];
        }

        $strings = require $path;

        return self::$loaded[$locale] = is_array($strings) ? $strings : [];
    }

    /** A locale's file, or null when the name is malformed or nobody wrote it. */
    private static function path(string $locale): ?string
    {
        if (preg_match('/^[a-z]{2}(-[A-Za-z]{2,8})?$/', $locale) !== 1) {
            return null;
        }

        $path = dirname(__DIR__, 2) . '/lang/' . $locale . '.php';

        return is_file($path) ? $path : null;
    }
}
