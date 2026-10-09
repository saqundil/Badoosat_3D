<?php

declare(strict_types=1);

use App\Support\Config;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$options = getopt('', ['database::']);
$config  = Config::file('db');
$database = trim((string) ($options['database'] ?? $config['name']));

if ($database === '' || !preg_match('/^[A-Za-z0-9_]+$/', $database)) {
    fwrite(STDERR, "Invalid database name.\n");
    exit(1);
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['host'],
    (int) $config['port'],
    $database,
    $config['charset']
);

$pdo = new PDO($dsn, $config['user'], $config['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS migrations (
        filename VARCHAR(255) NOT NULL PRIMARY KEY,
        executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$lockName = 'migrations:' . $database;
$lock = $pdo->prepare('SELECT GET_LOCK(?, 30)');
$lock->execute([$lockName]);

if ((int) $lock->fetchColumn() !== 1) {
    fwrite(STDERR, "Could not acquire the migration lock.\n");
    exit(1);
}

register_shutdown_function(static function () use ($pdo, $lockName): void {
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
});

$executed = array_fill_keys(
    $pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN),
    true
);

$files = array_values(array_filter(
    glob(dirname(__DIR__) . '/migrations/*.php') ?: [],
    static fn (string $path): bool => (bool) preg_match('/^\d+_.+\.php$/', basename($path))
));
usort($files, static function (string $left, string $right): int {
    $number = (int) basename($left) <=> (int) basename($right);

    return $number !== 0 ? $number : strcmp(basename($left), basename($right));
});

$ran = 0;

foreach ($files as $path) {
    $filename = basename($path);

    if (isset($executed[$filename])) {
        echo "skip  {$filename}\n";
        continue;
    }

    $migration = require $path;

    if (!is_callable($migration)) {
        throw new RuntimeException("Migration {$filename} must return a callable.");
    }

    echo "run   {$filename}\n";

    try {
        $pdo->beginTransaction();
        $migration($pdo);

        // MySQL 5.7 commits DDL implicitly. Start a transaction again so the
        // migration record is committed atomically with any trailing DML.
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $stmt = $pdo->prepare('INSERT INTO migrations (filename, executed_at) VALUES (?, NOW())');
        $stmt->execute([$filename]);
        $pdo->commit();
        $ran++;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        fwrite(STDERR, "failed {$filename}: {$error->getMessage()}\n");
        exit(1);
    }
}

echo $ran === 0 ? "No pending migrations.\n" : "Applied {$ran} migration(s).\n";
