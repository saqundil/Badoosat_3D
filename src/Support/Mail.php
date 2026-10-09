<?php

declare(strict_types=1);

namespace App\Support;

use App\Repository\OrderRepository;

/**
 * The two messages the shop actually sends: an order was placed, and it has
 * shipped. Both are composed here and handed to Mailer, which is the only
 * thing that knows about SMTP.
 *
 * Sending never blocks a customer. If the mail server is down or misconfigured
 * the failure is logged and the order still exists -- a confirmation email that
 * cannot be sent is not a reason to refuse an order that has already been paid
 * for and whose stock has already been claimed.
 */
final class Mail
{
    public static function orderPlaced(int $orderId): bool
    {
        $order = OrderRepository::make()->find($orderId);

        if ($order === null) {
            return false;
        }

        $items = OrderRepository::make()->items($orderId);
        $body  = self::layout(
            'تأكيد الطلب ' . $order['order_number'],
            '<p>شكرًا لك يا ' . Response::e($order['customer_name']) . '.</p>'
            . '<p>استلمنا طلبك، وهذا رقمه:</p>'
            . '<p style="font-size:22px;font-weight:700;letter-spacing:1px">'
            . Response::e($order['order_number']) . '</p>'
            . '<p>تتبّع الطلب في أي وقت برقمه ورقم هاتفك: '
            . '<a href="' . Response::e(self::url('/track.php')) . '">'
            . Response::e(self::url('/track.php')) . '</a></p>'
            . self::itemsTable($items)
            . self::totals($order)
            . '<p style="color:#6d6a63;font-size:13px">'
            . Response::e((string) Config::get('shop.returns_notice')) . '</p>'
        );

        return self::deliver($order, 'تأكيد الطلب ' . $order['order_number'], $body);
    }

    public static function orderShipped(int $orderId): bool
    {
        $order = OrderRepository::make()->find($orderId);

        if ($order === null) {
            return false;
        }

        $body = self::layout(
            'طلبك في الطريق',
            '<p>مرحبًا ' . Response::e($order['customer_name']) . '،</p>'
            . '<p>طلبك <strong>' . Response::e($order['order_number']) . '</strong> شُحن اليوم.</p>'
            . '<p>سيصلك على: ' . Response::e($order['address']) . '، ' . Response::e($order['city']) . '</p>'
            . '<p>تتبّع الطلب: <a href="' . Response::e(self::url('/track.php')) . '">'
            . Response::e(self::url('/track.php')) . '</a></p>'
        );

        return self::deliver($order, 'شُحن طلبك ' . $order['order_number'], $body);
    }

    private static function deliver(array $order, string $subject, string $html): bool
    {
        $mailer = Mailer::make();
        $result = $mailer->send(
            (string) $order['email'],
            (string) $order['customer_name'],
            $subject,
            $html
        );

        if (!$result['sent']) {
            error_log('mail failed for order ' . $order['order_number'] . ': ' . (string) $result['error']);
        }

        return $result['sent'];
    }

    /** @param array<int,array<string,mixed>> $items */
    private static function itemsTable(array $items): string
    {
        $rows = '';

        foreach ($items as $item) {
            $what = match ($item['item_type']) {
                'ready'  => 'تصميم «' . $item['design_name'] . '»',
                'custom' => 'تصميمك',
                default  => 'بلا طباعة',
            };

            $rows .= '<tr>'
                . '<td style="padding:8px;border-bottom:1px solid #e6e2da">'
                . Response::e($item['product_name']) . '<br>'
                . '<span style="color:#6d6a63;font-size:12px">'
                . Response::e($item['color_name'] . ' · ' . $item['size_code'] . ' · ' . $what)
                . '</span></td>'
                . '<td style="padding:8px;border-bottom:1px solid #e6e2da;text-align:center">'
                . (int) $item['quantity'] . '</td>'
                . '<td style="padding:8px;border-bottom:1px solid #e6e2da;text-align:end">'
                . self::money((int) $item['line_total_cents']) . '</td>'
                . '</tr>';
        }

        return '<table style="width:100%;border-collapse:collapse;margin:18px 0">'
            . '<thead><tr>'
            . '<th style="text-align:start;padding:8px;font-size:12px;color:#6d6a63">القطعة</th>'
            . '<th style="padding:8px;font-size:12px;color:#6d6a63">الكمية</th>'
            . '<th style="text-align:end;padding:8px;font-size:12px;color:#6d6a63">الإجمالي</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private static function totals(array $order): string
    {
        $line = static fn (string $label, string $value, bool $bold = false): string =>
            '<tr><td style="padding:4px 8px' . ($bold ? ';font-weight:700;font-size:17px' : '') . '">'
            . Response::e($label) . '</td>'
            . '<td style="padding:4px 8px;text-align:end' . ($bold ? ';font-weight:700;font-size:17px' : '') . '">'
            . $value . '</td></tr>';

        $rows = $line('المجموع', self::money((int) $order['subtotal_cents']));

        if ((int) $order['discount_cents'] > 0) {
            $rows .= $line('خصم الكمية', '−' . self::money((int) $order['discount_cents']));
        }

        $rows .= $line('الشحن', (int) $order['shipping_cents'] === 0
            ? 'مجاني'
            : self::money((int) $order['shipping_cents']));
        $rows .= $line('الإجمالي', self::money((int) $order['total_cents']), true);

        return '<table style="width:100%;border-collapse:collapse">' . $rows . '</table>';
    }

    private static function layout(string $heading, string $inner): string
    {
        return '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . Response::e($heading) . '</title></head>'
            . '<body style="margin:0;background:#edebe6;font-family:system-ui,\'Segoe UI\',sans-serif;color:#14140f">'
            . '<div style="max-width:600px;margin:0 auto;padding:24px">'
            . '<div style="background:#fff;border-radius:16px;padding:24px">'
            . '<h1 style="margin:0 0 16px;font-size:20px">' . Response::e($heading) . '</h1>'
            . $inner
            . '</div>'
            . '<p style="text-align:center;color:#6d6a63;font-size:12px;margin-top:16px">'
            . Response::e((string) Config::get('app.name')) . '</p>'
            . '</div></body></html>';
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2) . ' ' . Config::get('shop.currency', 'د.أ');
    }

    private static function url(string $path): string
    {
        return rtrim((string) Config::get('app.url'), '/') . $path;
    }
}
