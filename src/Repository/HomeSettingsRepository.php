<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The home page's key/value rows.
 *
 * Reads are one statement for the whole table -- it is six rows and the page
 * needs all of them -- so nothing here takes a key. Writes are an upsert,
 * because the row may be missing: the seed in the migration puts every key
 * there, but a database restored from a partial dump or a key added to
 * HomeSettingsService::DEFAULTS after the migration ran would not have it, and
 * an UPDATE that matches nothing fails silently.
 */
final class HomeSettingsRepository extends Repository
{
    /** @return array<string,string> every stored key, whatever it is */
    public function all(): array
    {
        $rows = $this->pdo()->query('SELECT `key`, `value` FROM home_settings')->fetchAll();

        $values = [];

        foreach ($rows as $row) {
            $values[(string) $row['key']] = (string) ($row['value'] ?? '');
        }

        return $values;
    }

    /** @param array<string,string> $values */
    public function put(array $values): void
    {
        if ($values === []) {
            return;
        }

        $stmt = $this->pdo()->prepare(
            'INSERT INTO home_settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );

        foreach ($values as $key => $value) {
            $stmt->execute([$key, $value]);
        }
    }
}
