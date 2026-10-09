<?php

declare(strict_types=1);

/*
 * The dashboard's two view helpers. Loaded once by src/View/admin.php, before
 * any page body runs.
 */

/**
 * Render src/View/admin/<name>.php with $vars as its only variables.
 *
 * A partial sees what it is given and nothing from the page around it, so a
 * $title or $order in the caller can never leak into a component by accident.
 * One folder level is allowed, for families of partials: 'form/field'.
 *
 * @param array<string,mixed> $vars
 */
function admin_partial(string $name, array $vars = []): void
{
    if (preg_match('~^[a-z][a-z0-9-]*(/[a-z][a-z0-9-]*)?$~', $name) !== 1) {
        throw new InvalidArgumentException('Bad partial name: ' . $name);
    }

    (static function (string $__file, array $__vars): void {
        extract($__vars, EXTR_SKIP);
        require $__file;
    })(__DIR__ . '/' . $name . '.php', $vars);
}

/**
 * One inline icon. Decorative: the control around it carries the accessible
 * name, so the svg is aria-hidden and never announced twice.
 *
 * DIRECTION
 *
 * Icons that point along the reading direction -- back, forward, an arrow out
 * of a box, a trend going "onward" -- get .icon--dir, which admin.css mirrors
 * with one :dir(rtl) rule. Everything else (search, calendar, a download arrow
 * pointing down, a clock) is the same picture in every language and is never
 * flipped. Every path is drawn for left-to-right.
 */
function admin_icon(string $name): string
{
    static $paths = [
        'back'     => '<path d="M15 6l-6 6 6 6"/>',
        'forward'  => '<path d="M9 6l6 6-6 6"/>',
        'external' => '<path d="M14 5h5v5"/><path d="M19 5l-8 8"/><path d="M18 14v4a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h4"/>',
        'logout'   => '<path d="M15 12H4"/><path d="M8 8l-4 4 4 4"/><path d="M13 4h5a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-5"/>',
        'trend'    => '<path d="M4 17l6-6 4 4 6-6"/><path d="M15 9h5v5"/>',
        'search'   => '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/>',
        'calendar' => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/>',
        'download' => '<path d="M12 4v11"/><path d="M7 10l5 5 5-5"/><path d="M5 20h14"/>',
        'file'     => '<path d="M7 3h7l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v5h5"/>',
        'export'   => '<path d="M12 15V4"/><path d="M8 8l4-4 4 4"/><path d="M5 14v5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-5"/>',
        'clock'    => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>',
        'printer'  => '<path d="M7 9V4h10v5"/><rect x="4" y="9" width="16" height="7" rx="2"/><path d="M7 14h10v6H7z"/>',
        'stack'    => '<path d="M12 4l8 4-8 4-8-4z"/><path d="M4 12l8 4 8-4"/><path d="M4 16l8 4 8-4"/>',
        'alert'    => '<circle cx="12" cy="12" r="8"/><path d="M12 8v5M12 16h.01"/>',
        'close'    => '<path d="M6 6l12 12M18 6L6 18"/>',
        'globe'    => '<circle cx="12" cy="12" r="8"/><path d="M4 12h16M12 4c2.5 2.5 2.5 13.5 0 16M12 4c-2.5 2.5-2.5 13.5 0 16"/>',
        'eye'      => '<path d="M3 12s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6z"/><circle cx="12" cy="12" r="2.5"/>',
        'inbox'    => '<path d="M4 13l2-8h12l2 8v6H4z"/><path d="M4 13h5l1 2h4l1-2h5"/>',
        'dot'      => '<circle cx="12" cy="12" r="3"/>',
        'more'     => '<circle cx="6" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="18" cy="12" r="1.2"/>',
        'undo'     => '<path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
        'redo'     => '<path d="M15 14l5-5-5-5"/><path d="M20 9H9a5 5 0 0 0 0 10h3"/>',
        'panel'    => '<rect x="4" y="5" width="16" height="14" rx="2"/><path d="M10 5v14"/>',
        'menu'     => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'grid'     => '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
        'shirt'    => '<path d="M9 4l-5 3 2 4 2-1v10h8V10l2 1 2-4-5-3a3 3 0 0 1-6 0z"/>',
        'store'    => '<path d="M4 9l1.5-4h13L20 9"/><path d="M4 9a2.7 2.7 0 0 0 5.3 0 2.7 2.7 0 0 0 5.4 0A2.7 2.7 0 0 0 20 9"/><path d="M5.5 11.5V20h13v-8.5"/><path d="M10 20v-5h4v5"/>',
        'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1"/>',
    ];
    // undo/redo point back and forward in time, which reads along the line.
    static $directional = ['back', 'forward', 'external', 'logout', 'trend', 'undo', 'redo'];

    $class = 'icon' . (in_array($name, $directional, true) ? ' icon--dir' : '');

    return '<svg class="' . $class . '" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"'
        . ' focusable="false" fill="none" stroke="currentColor" stroke-width="1.8"'
        . ' stroke-linecap="round" stroke-linejoin="round">'
        . ($paths[$name] ?? $paths['dot'])
        . '</svg>';
}

/** Up to two initials for an avatar circle, from any script. */
function admin_initials(string $name): string
{
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $first = $words[0] ?? '';
    $last  = count($words) > 1 ? $words[count($words) - 1] : '';

    return mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
}

/**
 * The current admin URL with some query parameters replaced (null removes
 * one). Tabs, filters and the language switch all keep the rest of the state.
 *
 * @param array<string,scalar|null> $changes
 */
function admin_url(array $changes, ?string $path = null): string
{
    $path  ??= (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH) ?: '/admin/');
    $query = array_merge($_GET, $changes);
    $query = array_filter($query, static fn ($value): bool => $value !== null && $value !== '');

    return $path . ($query === [] ? '' : '?' . http_build_query($query));
}
