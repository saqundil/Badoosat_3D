<?php

declare(strict_types=1);

/**
 * English.
 *
 * Today this is the dashboard's language and nothing else: the storefront's
 * locale is config app.locale ('ar'), and its keys are not here yet. Lang falls
 * back to lang/ar.php one key at a time, so a storefront page switched to
 * English before its strings are written shows Arabic where English is
 * missing, never a blank.
 *
 * Every key below exists in lang/ar.php too. Keys in {n, plural, ...} form are
 * ICU messages for App\Support\Format::plural().
 */

return [
    'lang.native' => 'English',

    /* -------------------------------------------------------- order statuses */

    'order.status.pending'       => 'Awaiting confirmation',
    'order.status.paid'          => 'Paid',
    'order.status.in_production' => 'In production',
    'order.status.shipped'       => 'Shipped',
    'order.status.delivered'     => 'Delivered',
    'order.status.cancelled'     => 'Cancelled',
    'order.status.refunded'      => 'Refunded',

    /* -------------------------------------------------------------- dashboard */

    'admin.title'          => 'Dashboard',
    'admin.skip'           => 'Skip to content',
    'admin.list_sep'       => ', ',

    'admin.nav.label'      => 'Dashboard sections',
    'admin.nav.slides'     => 'Slides',
    'admin.nav.garments'   => 'Types',
    'admin.nav.products'   => 'Products',
    'admin.nav.catalog'    => 'Catalogue',
    'admin.nav.home'       => 'Homepage',
    'admin.nav.orders'     => 'Orders',
    'admin.nav.system'     => 'System status',
    'admin.nav.view_site'  => 'View the shop in a new window',
    'admin.nav.logout'     => 'Log out',
    'admin.lang.label'     => 'Dashboard language',

    'admin.action.back'         => 'Back',
    'admin.action.close'        => 'Close',
    'admin.action.back_to_list' => 'Back to the list',

    'admin.state.error_title' => 'Could not load',
    'admin.state.error_card'  => 'This figure could not be calculated. Reload the page.',
    'admin.state.error_list'  => 'The orders could not be loaded.',
    'admin.state.error_hint'  => 'Reload the page. If it happens again, check the error log in storage/logs.',

    'admin.filters.label'       => 'Filter orders',
    'admin.filters.active'      => 'Active filters',
    'admin.filters.apply'       => 'Apply',
    'admin.filters.clear'       => 'Clear filters',
    'admin.filters.from'        => 'From',
    'admin.filters.to'          => 'To',
    'admin.filters.search'      => 'Search',
    'admin.filters.search_hint' => 'Order number, name, email or phone',

    'admin.time.today'     => 'Today',
    'admin.time.yesterday' => 'Yesterday',
    'admin.time.days_ago'  => '{n, plural, one{# day ago} other{# days ago}}',
    'admin.count.orders'   => '{n, plural, =0{No orders} one{# order} other{# orders}}',

    'admin.trend.vs_last_month' => ':pct from last month',
    'admin.trend.no_base'       => 'No orders last month to compare with',

    'admin.stat.waiting'      => 'New orders',
    'admin.stat.waiting_none' => 'Nothing waiting for production',
    'admin.stat.oldest'       => 'Oldest :age',
    'admin.stat.printing'     => 'In printing',
    'admin.stat.print_files_pending' => '{n, plural, =0{Every print file is ready} one{# print file to prepare} other{# print files to prepare}}',
    'admin.stat.orders_month' => 'Orders this month',
    'admin.stat.value_month'  => 'Order value this month',
    'admin.stat.daily_label'  => 'Orders per day, last 14 days',

    'admin.orders.title'             => 'Orders',
    'admin.orders.subtitle'          => 'Follow orders from confirmation to despatch, and download the print files.',
    'admin.orders.summary'           => 'Orders summary',
    'admin.orders.export_production' => 'Export production orders',
    'admin.orders.updated'           => 'Order status updated.',
    'admin.orders.tabs_label'        => 'Order status',
    'admin.orders.tab.all'           => 'All orders',
    'admin.orders.tab.new'           => 'New',
    'admin.orders.tab.printing'      => 'Printing',
    'admin.orders.tab.shipped'       => 'Shipped',
    'admin.orders.empty'             => 'No orders here yet.',
    'admin.orders.empty_filtered'    => 'No orders match these filters.',
    'admin.orders.not_found'         => 'This order could not be found.',
    'admin.orders.detail'            => 'Order details',
    'admin.orders.placed'            => 'Placed',
    'admin.orders.customer'          => 'Customer',
    'admin.orders.ship_to'           => 'Ship to',
    'admin.orders.note'              => 'Customer note',
    'admin.orders.items'             => 'Items in this order',
    'admin.orders.colour'            => 'Colour',
    'admin.orders.size'              => 'Size',
    'admin.orders.quantity'          => 'Quantity',
    'admin.orders.export_sheet'      => 'Export production sheet',
    'admin.orders.subtotal'          => 'Subtotal',
    'admin.orders.discount'          => 'Discount',
    'admin.orders.shipping'          => 'Shipping',
    'admin.orders.total'             => 'Total',
    'admin.orders.art_alt.front'     => 'Front artwork on :name',
    'admin.orders.art_alt.back'      => 'Back artwork on :name',
    'admin.orders.move.paid'          => 'Mark as paid',
    'admin.orders.move.in_production' => 'Start printing',
    'admin.orders.move.shipped'       => 'Mark as shipped',
    'admin.orders.move.delivered'     => 'Mark as delivered',
    'admin.orders.move.cancelled'     => 'Cancel order',
    'admin.orders.move.refunded'      => 'Refund',
    'admin.orders.confirm.cancelled'  => 'Cancel order :number? This cannot be undone.',
    'admin.orders.confirm.refunded'   => 'Refund order :number? This cannot be undone.',

    'admin.payment.cod'             => 'Cash on delivery',
    'admin.payment.status.unpaid'   => 'Unpaid',
    'admin.payment.status.paid'     => 'Paid',
    'admin.payment.status.refunded' => 'Refunded',

    'admin.side.front' => 'Front',
    'admin.side.back'  => 'Back',

    'admin.print.ready'         => 'Ready design: :name',
    'admin.print.custom_editor' => 'Customer design (editor)',
    'admin.print.custom_upload' => 'Customer design (direct upload)',
    'admin.print.blank'         => 'No print',
    'admin.print.not_needed'    => 'No print file needed',
    'admin.print.preparing'     => 'Print file being prepared',
    'admin.print.download'      => 'Download print file',
    'admin.print.preview'       => 'Preview the print file',
];
