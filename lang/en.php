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
    'admin.print.download_front' => 'Download front print file',
    'admin.print.download_back'  => 'Download back print file',
    'admin.print.preview_front'  => 'Preview the front print file',
    'admin.print.preview_back'   => 'Preview the back print file',

    /* ---------------------------------------- form system, table, studio, nav */
    'admin.form.save'          => 'Save changes',
    'admin.form.saved'         => 'Changes saved.',
    'admin.form.unsaved'       => 'Unsaved changes',
    'admin.form.discard'       => 'Discard',
    'admin.form.cancel'        => 'Cancel',
    'admin.form.required'      => 'Fill in this field.',
    'admin.form.errors_title'  => '{n, plural, one{# thing needs fixing before saving} other{# things need fixing before saving}}',
    'admin.form.errors_hint'   => 'Nothing was saved. Each problem is also shown next to its field.',
    'admin.form.matrix_colour' => 'Colour',
    'admin.form.matrix_total'  => 'Total',
    'admin.form.matrix_none'   => 'This cut is not made in this colour and size.',
    'admin.form.matrix_cell'   => 'Stock, :colour, size :size',
    'admin.form.drop_here'     => 'Drop an image here, paste one, or choose a file',
    'admin.form.drop_choose'   => 'Choose an image',
    'admin.form.drop_replace'  => 'Replace image',
    'admin.form.drop_remove'   => 'Remove the current image on save',
    'admin.form.drop_min'      => 'At least :w × :h pixels.',
    'admin.form.drop_small'    => 'This image is smaller than :w × :h pixels and may look soft.',
    'admin.form.drop_big'      => 'This file is larger than :mb MB, the upload limit.',
    'admin.form.drop_type'     => 'Use a JPEG, PNG or WebP image.',
    'admin.form.drop_chosen'   => 'Ready to upload: :name',
    'admin.toast.close'        => 'Dismiss',

    'admin.table.selected'        => 'Selected',
    'admin.table.clear_selection' => 'Clear selection',
    'admin.table.select_all'      => 'Select all rows',
    'admin.table.select_row'      => 'Select :name',
    'admin.table.actions'         => 'Actions',
    'admin.table.row_actions'     => 'Actions for :name',
    'admin.table.empty'           => 'Nothing here yet.',
    'admin.table.pages'           => 'Pages',
    'admin.table.prev'            => 'Previous page',
    'admin.table.next'            => 'Next page',

    'admin.studio.toolbar'       => 'Editor tools',
    'admin.studio.layers'        => 'Sections',
    'admin.studio.properties'    => 'Properties',
    'admin.studio.toggle_layers' => 'Show or hide the sections pane',
    'admin.studio.toggle_panel'  => 'Show or hide the properties pane',
    'admin.studio.device'        => 'Preview width',
    'admin.studio.preview_lang'  => 'Preview language',
    'admin.studio.undo'          => 'Undo',
    'admin.studio.redo'          => 'Redo',
    'admin.studio.zoom'          => 'Zoom',
    'admin.studio.zoom_fit'      => 'Fit',

    'admin.nav.menu'             => 'Menu',
    'admin.nav.tabs_label'       => 'Pages in this section',
    'admin.nav.group.overview'   => 'Overview',
    'admin.nav.group.orders'     => 'Orders',
    'admin.nav.group.catalog'    => 'Catalog',
    'admin.nav.group.storefront' => 'Storefront',
    'admin.nav.group.system'     => 'System',
    'admin.nav.tab.orders'       => 'All orders',
    'admin.nav.tab.products'     => 'Products',
    'admin.nav.tab.garments'     => 'Garment types',
    'admin.nav.tab.stock'        => 'Stock',
    'admin.nav.tab.designs'      => 'Designs',
    'admin.nav.tab.slides'       => 'Hero slides',
    'admin.nav.tab.home'         => 'Homepage',
    'admin.nav.tab.system'       => 'Status',
];
