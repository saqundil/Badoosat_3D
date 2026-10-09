<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\HomeSettingsRepository;
use App\Support\Cache;
use App\Support\CacheInterface;
use App\Support\Config;
use PDOException;

/**
 * What the shop owner controls on the landing page.
 *
 * DEFAULTS is the schema. It is the list of keys that may be written -- a POST
 * naming anything else is dropped rather than stored, so a mistyped field name
 * cannot quietly create a seventh row nobody reads -- and it is what the page
 * falls back to per key, which is what makes the home page render before anyone
 * has opened the admin screen.
 *
 * The cache is the same bargain the catalogue strikes: the landing page is the
 * busiest in the shop and these six values change a few times a year, so it
 * should not cost a query. save() forgets it, because nothing else will.
 *
 * settings() never throws. A landing page that returns a 500 because the
 * database is down is worse than one whose banner shows its default text over a
 * grey gradient, and the hero above it already takes that position.
 */
final class HomeSettingsService
{
    public const CACHE_KEY = 'home.settings';

    /** Every key the page reads, with what it shows when the row is empty. */
    public const DEFAULTS = [
        'printed_card_image' => '',
        'plain_card_image'   => '',
        'promo_image'        => '',
        'promo_title'        => 'صمّم تيشيرتك بنفسك',
        'promo_text'         => 'ارفع تصميمك، ضعه على القميص وشاهده بثلاثة أبعاد قبل أن تطلبه.',
        'promo_button'       => 'ابدأ التصميم',
    ];

    /** The three keys whose value is an uploaded file path. */
    public const IMAGE_KEYS = ['printed_card_image', 'plain_card_image', 'promo_image'];

    public function __construct(
        private readonly HomeSettingsRepository $settings,
        private readonly CacheInterface $cache,
    ) {
    }

    public static function make(): self
    {
        return new self(HomeSettingsRepository::make(), Cache::store());
    }

    /**
     * Every key, always all of them, always a string.
     *
     * @return array<string,string>
     */
    public function settings(): array
    {
        $stored = $this->cache->remember(
            self::CACHE_KEY,
            fn (): array => $this->read(),
            (int) Config::get('shop.catalog_cache_ttl', 900)
        );

        $values = [];

        // DEFAULTS drives the loop, so a stale row for a key that has since
        // been removed cannot reach a template.
        foreach (self::DEFAULTS as $key => $fallback) {
            $value = trim((string) ($stored[$key] ?? ''));
            $values[$key] = $value === '' ? $fallback : $value;
        }

        return $values;
    }

    /**
     * The stored rows, or none of them.
     *
     * A missing table is the ordinary state between deploying this code and
     * running the migration, and it should read as "nothing configured yet"
     * rather than as an outage.
     */
    private function read(): array
    {
        try {
            return $this->settings->all();
        } catch (PDOException $e) {
            error_log('Home settings unavailable — ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Writes only the keys DEFAULTS declares.
     *
     * @param array<string,string> $values
     */
    public function save(array $values): void
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (array_key_exists($key, self::DEFAULTS)) {
                $clean[$key] = (string) $value;
            }
        }

        $this->settings->put($clean);
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * The stored value of one key, with no fallback applied.
     *
     * The admin screen needs this rather than settings(): it has to show an
     * empty image field as empty, and a banner title the owner has cleared as
     * cleared. settings() would hand back the default and the screen would
     * report a value the database does not hold.
     *
     * @return array<string,string>
     */
    public function stored(): array
    {
        $stored = $this->read();
        $values = [];

        foreach (self::DEFAULTS as $key => $ignored) {
            $values[$key] = (string) ($stored[$key] ?? '');
        }

        return $values;
    }
}
