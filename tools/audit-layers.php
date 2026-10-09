<?php

declare(strict_types=1);

/**
 * Enforces the architectural rule by parsing, not by grepping: a service may
 * not echo, may not emit inline HTML, and may not read a superglobal.
 *
 *   php tools/audit-layers.php
 */

$root = dirname(__DIR__);

$rules = [
    'src/Service'    => 'business logic',
    'src/Repository' => 'data access',
    'src/Print'      => 'print drivers',
];

$superglobals = ['$_POST', '$_GET', '$_REQUEST', '$_SESSION', '$_COOKIE', '$_FILES', '$_SERVER', '$_ENV'];

$failures = [];
$checked  = 0;

foreach ($rules as $directory => $label) {
    foreach (glob($root . '/' . $directory . '/*.php') ?: [] as $file) {
        $checked++;
        $tokens   = token_get_all((string) file_get_contents($file));
        $relative = substr($file, strlen($root) + 1);

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            $problem = match (true) {
                $id === T_ECHO                       => 'echo',
                $id === T_PRINT                      => 'print',
                $id === T_INLINE_HTML && trim($text) !== '' => 'inline HTML',
                $id === T_VARIABLE && in_array($text, $superglobals, true) => $text,
                $id === T_STRING && in_array(strtolower($text), ['header', 'setcookie', 'http_response_code', 'var_dump', 'printf', 'vprintf'], true) => $text . '()',
                default                              => null,
            };

            if ($problem !== null) {
                $failures[] = sprintf('%s:%d  %s (%s)', $relative, $line, $problem, $label);
            }
        }
    }
}

echo 'checked ' . $checked . " files\n";

if ($failures === []) {
    echo "PASS  no presentation or request state below the thin layers\n";
    exit(0);
}

echo "FAIL\n";

foreach ($failures as $failure) {
    echo '  - ' . $failure . "\n";
}

exit(1);
