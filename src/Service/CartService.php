<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CartRepository;
use App\Repository\CustomDesignRepository;
use App\Repository\DesignRepository;
use App\Repository\ProductRepository;
use App\Support\Config;
use App\Support\Image;
use App\Support\Validator;

/**
 * The cart, as rows.
 *
 * Two rules run through everything below.
 *
 * The first is that a line stores identifiers and a quantity, and nothing else.
 * No price, no name, no colour: those are read back from the catalogue on every
 * single display and again when the order is written. A customer editing a
 * price in their developer tools is therefore editing a number that is about to
 * be thrown away, and the same is true of the design surcharge and of the
 * quantity discount -- both of which are computed here from config/shop.php and
 * the database, never accepted from the request.
 *
 * The second is that a line has exactly one of three shapes -- a blank shirt, a
 * ready-made design, a customer's own artwork -- and that the shape decides
 * which of the two design columns is filled. MariaDB 10.2 parses CHECK
 * constraints and ignores them, so that invariant is enforced here and asserted
 * by the test suite rather than trusted to the schema.
 */
final class CartService
{
    public const TYPES = ['blank', 'ready', 'custom'];

    public const PLACEMENTS = ['front', 'back', 'both'];

    public function __construct(
        private readonly CartRepository $carts,
        private readonly ProductRepository $products,
        private readonly DesignRepository $designs,
        private readonly CustomDesignRepository $customDesigns,
    ) {
    }

    public static function make(): self
    {
        return new self(
            CartRepository::make(),
            ProductRepository::make(),
            DesignRepository::make(),
            CustomDesignRepository::make()
        );
    }

    /* ------------------------------------------------------------ the cart */

    public function findByToken(string $token): ?array
    {
        return $token === '' ? null : $this->carts->findByToken($token);
    }

    /** @return array{id:int,token:string} */
    public function createFor(?int $customerId = null): array
    {
        $token = bin2hex(random_bytes(16));

        return ['id' => $this->carts->create($token, $customerId), 'token' => $token];
    }

    /**
     * Signing in brings the guest cart with it.
     *
     * Someone who filled a cart, then remembered they had an account, must not
     * find it empty on the other side of the login form -- that is a lost
     * order, and it is entirely self-inflicted.
     *
     * @return array{id:int,token:string} the cart to carry forward
     */
    public function attachToCustomer(?array $guestCart, int $customerId): array
    {
        $existing = $this->carts->findForCustomer($customerId);

        if ($guestCart === null) {
            return $existing !== null
                ? ['id' => (int) $existing['id'], 'token' => (string) $existing['token']]
                : $this->createFor($customerId);
        }

        if ($existing === null || (int) $existing['id'] === (int) $guestCart['id']) {
            $this->carts->claim((int) $guestCart['id'], $customerId);

            return ['id' => (int) $guestCart['id'], 'token' => (string) $guestCart['token']];
        }

        $this->carts->merge((int) $guestCart['id'], (int) $existing['id']);

        return ['id' => (int) $existing['id'], 'token' => (string) $existing['token']];
    }

    public function count(int $cartId): int
    {
        return $this->carts->countItems($cartId);
    }

    /* ------------------------------------------------------------- writing */

    /**
     * @throws ValidationException
     * @return int the cart item id
     */
    public function add(int $cartId, array $input): int
    {
        $line   = $this->makeLine($input);
        $limits = Config::get('shop.cart');

        $existing = $this->carts->findSame($cartId, $line);

        if ($existing !== null) {
            $quantity = min(
                (int) $limits['max_quantity_line'],
                (int) $existing['quantity'] + $line['quantity']
            );
            $this->carts->setQuantity($cartId, (int) $existing['id'], $quantity);
            $this->carts->touch($cartId);

            return (int) $existing['id'];
        }

        if ($this->carts->lineCount($cartId) >= (int) $limits['max_lines']) {
            throw new ValidationException(['cart' => 'السلة ممتلئة. أتمم طلبك أو احذف سطرًا.']);
        }

        $id = $this->carts->insert($cartId, $line);
        $this->carts->touch($cartId);

        return $id;
    }

    /**
     * Adds one artwork across a set of sizes in a single call.
     *
     * A company ordering ten shirts in five sizes is one design and one print
     * file; making them add it five times, or forcing five orders, is how that
     * customer is lost. Every line points at the same custom_design_id, which is
     * what makes the queue produce one press file for the lot.
     *
     * @param array<int,array{color_id:int,size_id:int,quantity:int}> $rows
     * @throws ValidationException
     * @return int the number of pieces added
     */
    public function addSpread(int $cartId, array $input, array $rows): int
    {
        $added = 0;

        foreach ($rows as $row) {
            $quantity = (int) ($row['quantity'] ?? 0);

            if ($quantity < 1) {
                continue;
            }

            $this->add($cartId, [
                ...$input,
                'color_id' => (int) ($row['color_id'] ?? 0),
                'size_id'  => (int) ($row['size_id'] ?? 0),
                'quantity' => $quantity,
            ]);

            $added += $quantity;
        }

        if ($added === 0) {
            throw new ValidationException(['quantities' => 'أدخل كمية واحدة على الأقل.']);
        }

        return $added;
    }

    public function setQuantity(int $cartId, int $itemId, int $quantity): void
    {
        $max = (int) Config::get('shop.cart.max_quantity_line');

        if ($quantity < 1) {
            $this->carts->removeItem($cartId, $itemId);

            return;
        }

        $this->carts->setQuantity($cartId, $itemId, min($max, $quantity));
        $this->carts->touch($cartId);
    }

    public function remove(int $cartId, int $itemId): void
    {
        $this->carts->removeItem($cartId, $itemId);
        $this->carts->touch($cartId);
    }

    public function clear(int $cartId): void
    {
        $this->carts->clear($cartId);
    }

    /* ------------------------------------------------------------ pricing */

    /**
     * Reads every line back from the catalogue and prices it from scratch.
     *
     * This runs on every cart view, every checkout render and again inside the
     * order writer. That repetition is the point: there is no path by which a
     * figure the browser sent can reach a total, because no figure the browser
     * sent is ever read.
     *
     * @return array{items:array<int,array<string,mixed>>,subtotal_cents:int,
     *               discount_cents:int,shipping_cents:int,total_cents:int,
     *               pieces:int,shipping_gap_cents:int,dropped:array<int,string>}
     */
    public function summary(int $cartId): array
    {
        $rows    = $this->carts->items($cartId);
        $items   = [];
        $dropped = [];

        foreach ($rows as $row) {
            $problem = $this->unavailable($row);

            if ($problem !== null) {
                $dropped[] = $problem;
                $this->carts->removeItem($cartId, (int) $row['id']);

                continue;
            }

            $items[] = $this->priceRow($row);
        }

        return ['items' => $items, 'dropped' => $dropped, ...PricingService::totals($items)];
    }

    /** Why this line can no longer be sold, or null when it can. */
    private function unavailable(array $row): ?string
    {
        if (!(int) $row['variant_active'] || !(int) $row['product_active']) {
            return $row['product_name'] . ' لم يعد متاحًا.';
        }

        if ($row['item_type'] === 'ready' && !(int) ($row['design_active'] ?? 0)) {
            return 'التصميم «' . ($row['design_name'] ?? '') . '» لم يعد متاحًا.';
        }

        if ($row['item_type'] === 'custom' && ($row['custom_quality'] ?? '') === 'reject') {
            return 'أحد التصاميم المرفوعة دقّته منخفضة جدًا للطباعة.';
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function priceRow(array $row): array
    {
        $type      = (string) $row['item_type'];
        $surcharge = (int) ($row['design_surcharge_cents'] ?? 0);

        $priced = PricingService::line(
            (int) $row['variant_price_cents'],
            $type,
            $surcharge,
            (string) $row['placement'],
            (int) $row['quantity']
        );

        return [
            'id'               => (int) $row['id'],
            'item_type'        => $type,
            'variant_id'       => (int) $row['variant_id'],
            'product_id'       => (int) $row['product_id'],
            'product_name'     => $row['product_name'],
            'product_slug'     => $row['product_slug'],
            'sku'              => $row['sku'],
            'color_name'       => $row['color_name'],
            'color_hex'        => $row['color_hex'],
            'size_code'        => $row['size_code'],
            'size_label'       => $row['size_label'],
            'placement'        => $row['placement'],
            'quantity'         => (int) $row['quantity'],
            'stock'            => (int) $row['stock'],
            'design_id'        => $row['design_id'] === null ? null : (int) $row['design_id'],
            'design_name'      => $row['design_name'],
            'custom_design_id' => $row['custom_design_id'] === null ? null : (int) $row['custom_design_id'],
            'custom_token'     => $row['custom_token'],
            'custom_source'    => $row['custom_source'],
            // The agreement picture. It follows the line from here to the
            // confirmation screen, the order and the production floor.
            'preview_path'     => $type === 'custom'
                ? $row['custom_preview']
                : ($type === 'ready' ? $row['design_thumb'] : $row['image_path']),
            'image'            => Image::responsive((string) $row['image_path'], '100px'),
            ...$priced,
        ];
    }

    /* ---------------------------------------------------------- validation */

    /** @throws ValidationException */
    private function makeLine(array $input): array
    {
        $v = new Validator($input);
        $v->in('item_type', self::TYPES, 'نوع السطر');
        $v->in('placement', self::PLACEMENTS, 'موضع الطباعة');
        $v->integer('quantity', 1, (int) Config::get('shop.cart.max_quantity_line'), 'الكمية');

        $type = (string) ($input['item_type'] ?? 'blank');

        $variant = $this->resolveVariant($input, $v);

        $designId = null;
        $customId = null;

        if ($type === 'ready') {
            $designId = $this->resolveDesign($input, $variant, $v);
        }

        if ($type === 'custom') {
            $customId = $this->resolveCustomDesign($input, $v);
        }

        // A blank shirt with a design attached, or a printed one without, is a
        // line whose price cannot be computed. Refuse it rather than guess.
        if ($type === 'blank' && ($designId !== null || $customId !== null)) {
            $v->fail('item_type', 'سطر بلا طباعة لا يحمل تصميمًا.');
        }

        if ($type === 'blank' && ($input['placement'] ?? 'front') !== 'front') {
            $v->fail('placement', 'سطر بلا طباعة لا موضع له.');
        }

        if (!$v->passes()) {
            throw new ValidationException($v->errors());
        }

        return [
            'item_type'        => $type,
            'variant_id'       => (int) $variant['id'],
            'design_id'        => $designId,
            'custom_design_id' => $customId,
            'placement'        => $type === 'blank' ? 'front' : (string) $input['placement'],
            'quantity'         => (int) $input['quantity'],
        ];
    }

    /**
     * A line names a product, a colour and a size; the variant is looked up,
     * never sent. A variant id in the request would let a customer attach a
     * price from one product to a design allowed only on another.
     */
    private function resolveVariant(array $input, Validator $v): array
    {
        $variant = $this->products->findVariantBy(
            (int) ($input['product_id'] ?? 0),
            (int) ($input['color_id'] ?? 0),
            (int) ($input['size_id'] ?? 0)
        );

        if ($variant === null || !(int) $variant['is_active'] || !(int) $variant['product_active']) {
            $v->fail('variant', 'هذا اللون والمقاس غير متاحين لهذا المنتج.');

            throw new ValidationException($v->errors());
        }

        if ((int) $variant['stock'] < 1) {
            $v->fail('variant', 'نفد المخزون من هذا اللون والمقاس.');

            throw new ValidationException($v->errors());
        }

        return $variant;
    }

    private function resolveDesign(array $input, array $variant, Validator $v): ?int
    {
        $design = $this->designs->find((int) ($input['design_id'] ?? 0));

        if ($design === null || !(int) $design['is_active']) {
            $v->fail('design_id', 'هذا التصميم غير متاح.');

            return null;
        }

        if (!$this->designs->isAllowedOn((int) $design['id'], (int) $variant['product_id'])) {
            $v->fail('design_id', 'هذا التصميم غير متاح على هذا المنتج.');

            return null;
        }

        return (int) $design['id'];
    }

    private function resolveCustomDesign(array $input, Validator $v): ?int
    {
        $token  = trim((string) ($input['custom_token'] ?? ''));
        $design = $token === '' ? null : $this->customDesigns->findByToken($token);

        if ($design === null) {
            $v->fail('custom_token', 'لم يُعثر على التصميم.');

            return null;
        }

        if ($design['quality'] === 'reject') {
            $v->fail('custom_token', 'دقّة التصميم منخفضة جدًا للطباعة.');

            return null;
        }

        return (int) $design['id'];
    }
}
