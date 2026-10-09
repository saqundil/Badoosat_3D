<?php

declare(strict_types=1);

namespace App\Service;

use App\Payment\PaymentDriverFactory;
use App\Payment\PaymentDriverInterface;
use App\Repository\OrderRepository;
use App\Support\Config;
use App\Support\Lang;
use App\Support\OrderNumber;
use App\Support\Queue;
use App\Support\Validator;

final class OrderService
{
    public const PRINT_JOB = 'print.produce';

    public const STATUSES = [
        'pending', 'paid', 'in_production', 'shipped', 'delivered', 'cancelled', 'refunded',
    ];

    /**
     * The dashboard's tabs, as the statuses each one shows. Cancelled and
     * refunded orders are only ever under "all": they need no action, and a
     * tab of them would be a list nobody works from.
     */
    public const TABS = [
        'all'      => [],
        'new'      => ['pending', 'paid'],
        'printing' => ['in_production'],
        'shipped'  => ['shipped', 'delivered'],
    ];

    /** What the shop may move an order to from where it is now. */
    public const TRANSITIONS = [
        'pending'       => ['paid', 'in_production', 'cancelled'],
        'paid'          => ['in_production', 'refunded', 'cancelled'],
        'in_production' => ['shipped', 'cancelled'],
        'shipped'       => ['delivered', 'refunded'],
        'delivered'     => ['refunded'],
        'cancelled'     => [],
        'refunded'      => [],
    ];

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly CartService $cart,
        private readonly Queue $queue,
    ) {
    }

    public static function make(): self
    {
        return new self(OrderRepository::make(), CartService::make(), Queue::make());
    }

    /** @return array<string,string> */
    public function validateCustomer(array $input): array
    {
        $v = new Validator($input);

        $v->required('customer_name', 'الاسم')->maxLength('customer_name', 160, 'الاسم');
        $v->required('phone', 'رقم الهاتف')->maxLength('phone', 40, 'رقم الهاتف');
        $v->required('city', 'المدينة')->maxLength('city', 80, 'المدينة');
        $v->required('address', 'العنوان')->maxLength('address', 500, 'العنوان');
        $v->maxLength('note', 500, 'الملاحظة');

        $email = $v->string('email');

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $v->fail('email', 'البريد الإلكتروني غير صالح.');
        }

        if (OrderNumber::phoneDigits($v->string('phone')) === '') {
            $v->fail('phone', 'رقم الهاتف غير صالح.');
        }

        return $v->errors();
    }

    /**
     * Turns a cart into an order.
     *
     * Two things happen here that cannot happen anywhere else. The cart is
     * priced again from the catalogue -- the totals the checkout page displayed
     * are not carried over, they are recomputed, so a tampered form changes
     * nothing -- and the stock is claimed under a row lock, so the last shirt in
     * a size cannot be sold to two people who pressed the button together.
     *
     * @return array{id:int,number:string,total_cents:int,items:int,jobs:int,
     *               short:array<int,int>,payment:array<string,mixed>}
     * @throws ValidationException
     */
    public function place(int $cartId, array $customer, ?int $customerId = null): array
    {
        $errors = $this->validateCustomer($customer);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $summary = $this->cart->summary($cartId);

        if ($summary['items'] === []) {
            throw new ValidationException(['cart' => 'السلة فارغة.']);
        }

        $items = [];
        $stock = [];

        foreach ($summary['items'] as $line) {
            $variantId         = (int) $line['variant_id'];
            $stock[$variantId] = ($stock[$variantId] ?? 0) + (int) $line['quantity'];

            $items[] = [
                'item_type'        => $line['item_type'],
                'variant_id'       => $variantId,
                'product_id'       => (int) $line['product_id'],
                'design_id'        => $line['design_id'],
                'custom_design_id' => $line['custom_design_id'],
                'sku'              => $line['sku'],
                // Copied, not joined. What the order says a customer bought
                // must not change because the shop renamed a colour in March.
                'product_name'     => $line['product_name'],
                'design_name'      => $line['design_name'],
                'color_name'       => $line['color_name'],
                'color_hex'        => $line['color_hex'],
                'size_code'        => $line['size_code'],
                'placement'        => $line['placement'],
                'quantity'         => (int) $line['quantity'],
                'unit_price_cents' => (int) $line['unit_cents'],
                'surcharge_cents'  => (int) $line['surcharge_cents'],
                'line_total_cents' => (int) $line['total_cents'],
            ];
        }

        $driver = PaymentDriverFactory::make();
        $phone  = trim((string) $customer['phone']);

        $created = $this->orders->create([
            'order_number'   => $this->number(),
            'customer_id'    => $customerId,
            'customer_name'  => trim((string) $customer['customer_name']),
            'email'          => trim((string) $customer['email']),
            'phone'          => $phone,
            'phone_digits'   => OrderNumber::phoneDigits($phone),
            'city'           => trim((string) $customer['city']),
            'address'        => trim((string) $customer['address']),
            'note'           => trim((string) ($customer['note'] ?? '')) ?: null,
            'subtotal_cents' => $summary['subtotal_cents'],
            'discount_cents' => $summary['discount_cents'],
            'shipping_cents' => $summary['shipping_cents'],
            'total_cents'    => $summary['total_cents'],
            'payment_driver' => $driver->name(),
            'payment_status' => 'unpaid',
            'status'         => 'pending',
        ], $items, $stock);

        if ($created['short'] !== []) {
            throw new ValidationException([
                'cart' => 'نفد المخزون من أحد الأصناف أثناء إتمام الطلب. راجع سلتك.',
            ]);
        }

        $order = $this->orders->find($created['id']);

        $payment = $driver->createIntent($order ?? []);

        if ($payment['status'] === 'paid') {
            $this->orders->setPayment($created['id'], 'paid', $payment['reference']);
            $this->orders->setStatus($created['id'], 'paid');
        }

        $jobs = $this->queuePrintJobs($created['id'], $items);

        $this->cart->clear($cartId);

        return [
            'id'          => $created['id'],
            'number'      => (string) $order['order_number'],
            'total_cents' => (int) $summary['total_cents'],
            'items'       => count($items),
            'jobs'        => $jobs,
            'short'       => [],
            'payment'     => $payment,
        ];
    }

    /**
     * One job per distinct artwork, not per line.
     *
     * Ten shirts in five sizes carrying the same design is one press file.
     * Queueing per line would produce five identical PDFs, five rows in
     * print_files and five chances for the operator to press the wrong one.
     *
     * @param array<int,array<string,mixed>> $items
     */
    private function queuePrintJobs(int $orderId, array $items): int
    {
        $designs = [];

        foreach ($items as $item) {
            if ($item['custom_design_id'] !== null) {
                $designs[(int) $item['custom_design_id']] = true;
            }
        }

        foreach (array_keys($designs) as $customDesignId) {
            $this->queue->push(self::PRINT_JOB, [
                'custom_design_id' => $customDesignId,
                'order_id'         => $orderId,
            ]);
        }

        return count($designs);
    }

    public function byNumber(string $number): ?array
    {
        $order = $this->orders->findByNumber(OrderNumber::normalise($number));

        return $order === null ? null : $this->hydrate($order);
    }

    public function find(int $id): ?array
    {
        $order = $this->orders->find($id);

        return $order === null ? null : $this->hydrate($order);
    }

    /**
     * The tracking lookup: order number AND phone, both or nothing.
     *
     * The caller gets null for a wrong number and null for a wrong phone and
     * null for an order that does not exist, and it cannot tell those apart --
     * which is the whole point. Anything that distinguished them would let
     * someone walk the number space and learn which orders are real.
     */
    public function track(string $number, string $phone): ?array
    {
        $normalised = OrderNumber::normalise($number);

        if (!OrderNumber::looksValid($normalised)) {
            return null;
        }

        $order = $this->orders->findForTracking($normalised, OrderNumber::phoneDigits($phone));

        return $order === null ? null : $this->hydrate($order);
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 50, string $status = ''): array
    {
        return $this->orders->recent($limit, $status);
    }

    /** @throws ValidationException */
    public function changeStatus(int $orderId, string $status): void
    {
        $order = $this->orders->find($orderId);

        if ($order === null) {
            throw new ValidationException(['order' => 'لم يُعثر على الطلب.']);
        }

        $from = (string) $order['status'];

        if (!in_array($status, self::TRANSITIONS[$from] ?? [], true)) {
            throw new ValidationException([
                'status' => 'لا يمكن نقل الطلب من «' . self::label($from) . '» إلى «' . self::label($status) . '».',
            ]);
        }

        // Cancelling puts the garments back on the shelf. Nothing else does:
        // a refund after despatch does not mean the shirts came back.
        if ($status === 'cancelled') {
            $this->orders->restock($orderId);
        }

        if ($status === 'paid') {
            $this->orders->setPayment($orderId, 'paid', null);
        }

        if ($status === 'refunded') {
            $this->orders->setPayment($orderId, 'refunded', null);
        }

        $this->orders->setStatus($orderId, $status);
    }

    /**
     * A status in words, from lang/<locale>.php (order.status.*), so the
     * dashboard's English mode and the storefront's Arabic read the same table.
     * An unknown status prints as itself rather than as a raw key.
     */
    public static function label(string $status): string
    {
        $key  = 'order.status.' . $status;
        $text = Lang::get($key);

        return $text === $key ? $status : $text;
    }

    public function driver(): PaymentDriverInterface
    {
        return PaymentDriverFactory::make();
    }

    private function hydrate(array $order): array
    {
        $order['id']             = (int) $order['id'];
        $order['subtotal_cents'] = (int) $order['subtotal_cents'];
        $order['discount_cents'] = (int) $order['discount_cents'];
        $order['shipping_cents'] = (int) $order['shipping_cents'];
        $order['total_cents']    = (int) $order['total_cents'];
        $order['status_label']   = self::label((string) $order['status']);
        $order['items']          = $this->orders->items($order['id']);

        return $order;
    }

    private function number(): string
    {
        do {
            $number = OrderNumber::generate();
        } while ($this->orders->numberExists($number));

        return $number;
    }

    /** Where a customer's own artwork is stored, for the admin download check. */
    public static function printRoot(): string
    {
        return (string) Config::get('app.paths.print');
    }
}
