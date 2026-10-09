<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use Throwable;

final class OrderRepository extends Repository
{
    /**
     * Writes the order, its lines and the stock they consume in one
     * transaction, with the variant rows locked for the whole of it.
     *
     * The lock is the whole point. Without it two customers reaching checkout
     * within the same second both read stock = 1, both pass the check, and both
     * are told they bought the last shirt. SELECT ... FOR UPDATE makes the
     * second one wait for the first to commit and then read the truth.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<int,int> $stockWanted variant id => quantity
     * @return array{id:int,item_ids:array<int,int>,short:array<int,int>}
     */
    public function create(array $order, array $items, array $stockWanted): array
    {
        $products = ProductRepository::make();

        $this->pdo()->beginTransaction();

        try {
            $short = $products->lockAndCheckStock($stockWanted);

            if ($short !== []) {
                $this->pdo()->rollBack();

                return ['id' => 0, 'item_ids' => [], 'short' => $short];
            }

            $stmt = $this->pdo()->prepare(
                'INSERT INTO orders
                    (order_number, customer_id, customer_name, email, phone, phone_digits, city, address, note,
                     subtotal_cents, discount_cents, shipping_cents, total_cents,
                     payment_driver, payment_status, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $order['order_number'],
                $order['customer_id'],
                $order['customer_name'],
                $order['email'],
                $order['phone'],
                $order['phone_digits'],
                $order['city'],
                $order['address'],
                $order['note'],
                $order['subtotal_cents'],
                $order['discount_cents'],
                $order['shipping_cents'],
                $order['total_cents'],
                $order['payment_driver'],
                $order['payment_status'],
                $order['status'],
            ]);

            $orderId = (int) $this->pdo()->lastInsertId();

            $line = $this->pdo()->prepare(
                'INSERT INTO order_items
                    (order_id, item_type, variant_id, product_id, design_id, custom_design_id, sku,
                     product_name, design_name, color_name, color_hex, size_code, placement,
                     quantity, unit_price_cents, surcharge_cents, line_total_cents)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $itemIds = [];

            foreach ($items as $item) {
                $line->execute([
                    $orderId,
                    $item['item_type'],
                    $item['variant_id'],
                    $item['product_id'],
                    $item['design_id'],
                    $item['custom_design_id'],
                    $item['sku'],
                    $item['product_name'],
                    $item['design_name'],
                    $item['color_name'],
                    $item['color_hex'],
                    $item['size_code'],
                    $item['placement'],
                    $item['quantity'],
                    $item['unit_price_cents'],
                    $item['surcharge_cents'],
                    $item['line_total_cents'],
                ]);
                $itemIds[] = (int) $this->pdo()->lastInsertId();
            }

            $products->decrementStock($stockWanted);

            $sold = [];

            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $sold[$productId] = ($sold[$productId] ?? 0) + (int) $item['quantity'];
            }

            $products->recordSales($sold);

            $this->pdo()->commit();

            return ['id' => $orderId, 'item_ids' => $itemIds, 'short' => []];
        } catch (Throwable $e) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }

            throw $e;
        }
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByNumber(string $number): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM orders WHERE order_number = ?');
        $stmt->execute([$number]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The tracking page's only lookup. Both halves are in the WHERE clause and
     * the comparison happens in the database, so a wrong number and a wrong
     * phone are indistinguishable from here up: there is no branch that could
     * leak which of the two was right.
     */
    public function findForTracking(string $number, string $phoneDigits): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM orders WHERE order_number = ? AND phone_digits = ? LIMIT 1'
        );
        $stmt->execute([$number, $phoneDigits]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function items(int $orderId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT oi.*,
                    cd.token AS custom_token, cd.preview_path AS custom_preview,
                    cd.image_path AS custom_image, cd.source AS custom_source,
                    cd.note AS custom_note, cd.dpi AS custom_dpi,
                    (cd.back_original_path IS NOT NULL) AS custom_back,
                    d.thumb_path AS design_thumb, d.print_path AS design_print,
                    g.name_ar AS garment_name_ar, g.name_en AS garment_name_en
               FROM order_items oi
          LEFT JOIN custom_designs cd ON cd.id = oi.custom_design_id
          LEFT JOIN designs        d  ON d.id  = oi.design_id
          LEFT JOIN products       p  ON p.id  = oi.product_id
          LEFT JOIN garments       g  ON g.id  = p.garment_id
              WHERE oi.order_id = ?
              ORDER BY oi.id ASC'
        );
        $stmt->execute([$orderId]);

        return $stmt->fetchAll();
    }

    public function item(int $itemId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM order_items WHERE id = ?');
        $stmt->execute([$itemId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 50, string $status = ''): array
    {
        $sql = 'SELECT o.*, COALESCE(SUM(oi.quantity), 0) AS pieces,
                       SUM(oi.item_type = \'custom\') AS custom_lines
                  FROM orders o
             LEFT JOIN order_items oi ON oi.order_id = o.id';

        $params = [];

        if ($status !== '') {
            $sql     .= ' WHERE o.status = ?';
            $params[] = $status;
        }

        $sql .= ' GROUP BY o.id ORDER BY o.id DESC LIMIT :limit';

        $stmt = $this->pdo()->prepare($sql);

        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value);
        }

        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /*
     * ------------------------------------------------------------------
     * The dashboard's reads. Every one is a SELECT; nothing below writes.
     * ------------------------------------------------------------------
     */

    /**
     * The orders list with the dashboard's filters: a set of statuses (a tab),
     * an inclusive date range, and one search box over the number, the name,
     * the email and the phone. Same columns as recent(), newest first, one page
     * at a time.
     *
     * @param list<string> $statuses empty means every status
     * @return array<int,array<string,mixed>>
     */
    public function search(array $statuses, ?string $from, ?string $to, string $query, int $limit = 80, int $offset = 0): array
    {
        [$where, $params] = $this->filters($statuses, $from, $to, $query);

        $sql = 'SELECT o.*, COALESCE(SUM(oi.quantity), 0) AS pieces,
                       SUM(oi.item_type = \'custom\') AS custom_lines
                  FROM orders o
             LEFT JOIN order_items oi ON oi.order_id = o.id'
            . $where
            . ' GROUP BY o.id ORDER BY o.id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo()->prepare($sql);

        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value);
        }

        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * How many orders in each status match the date range and the search --
     * the same filters as search(), without the tab. The tabs, the list's
     * count and its pages all read from this one query, so they cannot
     * disagree with each other or with the rows shown.
     *
     * @return array<string,int> status => number of matching orders
     */
    public function countMatching(?string $from, ?string $to, string $query): array
    {
        [$where, $params] = $this->filters([], $from, $to, $query);

        $stmt = $this->pdo()->prepare('SELECT o.status, COUNT(*) AS n FROM orders o' . $where . ' GROUP BY o.status');

        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value);
        }

        $stmt->execute();
        $counts = [];

        foreach ($stmt as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * The WHERE clause shared by search() and countMatching().
     *
     * @param list<string> $statuses
     * @return array{0:string,1:array<string,string>}
     */
    private function filters(array $statuses, ?string $from, ?string $to, string $query): array
    {
        $where  = [];
        $params = [];

        if ($statuses !== []) {
            $names = [];

            foreach (array_values($statuses) as $i => $status) {
                $names[]             = ':status' . $i;
                $params['status' . $i] = $status;
            }

            $where[] = 'o.status IN (' . implode(', ', $names) . ')';
        }

        if ($from !== null) {
            $where[]        = 'o.created_at >= :from';
            $params['from'] = $from . ' 00:00:00';
        }

        if ($to !== null) {
            $where[]      = 'o.created_at < :to + INTERVAL 1 DAY';
            $params['to'] = $to;
        }

        $query = trim($query);

        if ($query !== '') {
            // The search box is a substring match, so its own % and _ are
            // literal characters, not wildcards.
            $like   = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
            $digits = preg_replace('/\D+/', '', $query) ?? '';

            $match = ['o.order_number LIKE :q1', 'o.customer_name LIKE :q2', 'o.email LIKE :q3'];
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];

            if ($digits !== '') {
                $match[]      = 'o.phone_digits LIKE :q4';
                $params['q4'] = '%' . $digits . '%';
            }

            $where[] = '(' . implode(' OR ', $match) . ')';
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    /** @return array<string,int> status => number of orders in it */
    public function countByStatus(): array
    {
        $counts = [];

        foreach ($this->pdo()->query('SELECT status, COUNT(*) AS n FROM orders GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Orders and their value placed in [from, to), cancelled and refunded
     * left out: they are orders the shop will not fulfil.
     *
     * @return array{orders:int,value_cents:int}
     */
    public function totalsBetween(string $from, string $to): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) AS orders, COALESCE(SUM(total_cents), 0) AS value_cents
               FROM orders
              WHERE created_at >= :from AND created_at < :to
                AND status NOT IN (\'cancelled\', \'refunded\')'
        );
        $stmt->execute(['from' => $from, 'to' => $to]);
        $row = $stmt->fetch();

        return ['orders' => (int) $row['orders'], 'value_cents' => (int) $row['value_cents']];
    }

    /**
     * Orders per calendar day from $from (inclusive), same exclusions as
     * totalsBetween(). Days with no orders are absent; the caller fills them.
     *
     * @return array<string,int> 'Y-m-d' => orders
     */
    public function dailyCounts(string $from): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT DATE(created_at) AS day, COUNT(*) AS n
               FROM orders
              WHERE created_at >= :from
                AND status NOT IN (\'cancelled\', \'refunded\')
           GROUP BY DATE(created_at)'
        );
        $stmt->execute(['from' => $from]);

        $days = [];

        foreach ($stmt->fetchAll() as $row) {
            $days[(string) $row['day']] = (int) $row['n'];
        }

        return $days;
    }

    /** The oldest order still waiting for production (pending or paid), or null. */
    public function oldestWaiting(): ?string
    {
        $value = $this->pdo()
            ->query('SELECT MIN(created_at) FROM orders WHERE status IN (\'pending\', \'paid\')')
            ->fetchColumn();

        return $value === null || $value === false ? null : (string) $value;
    }

    /**
     * Customer artwork on live orders that is still missing a press file.
     * Counted per artwork, not per line: ten lines sharing a design wait for
     * one file. A design printed differently on each side waits until both of
     * its sheets exist -- its front alone is not ready.
     */
    public function printFilesPending(): int
    {
        return (int) $this->pdo()->query(
            'SELECT COUNT(DISTINCT oi.custom_design_id)
               FROM order_items oi
               JOIN orders o           ON o.id = oi.order_id
               JOIN custom_designs cd  ON cd.id = oi.custom_design_id
              WHERE oi.item_type = \'custom\'
                AND o.status NOT IN (\'cancelled\', \'refunded\')
                AND (
                      NOT EXISTS (SELECT 1 FROM print_files pf
                                   WHERE pf.custom_design_id = cd.id AND pf.side IN (\'single\', \'front\'))
                   OR (cd.back_original_path IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM print_files pf
                                        WHERE pf.custom_design_id = cd.id AND pf.side = \'back\'))
                )'
        )->fetchColumn();
    }

    /** @param array<int,int> $ids @return array<int,array<string,mixed>> */
    public function findManyWithItems(array $ids): array
    {
        $ids = self::ints($ids);

        if ($ids === []) {
            return [];
        }

        $stmt = $this->pdo()->prepare(
            'SELECT * FROM orders WHERE id IN (' . self::placeholders(count($ids)) . ') ORDER BY id ASC'
        );
        $stmt->execute($ids);

        $orders = $stmt->fetchAll();

        foreach ($orders as $i => $order) {
            $orders[$i]['items'] = $this->items((int) $order['id']);
        }

        return $orders;
    }

    /**
     * The first line that ordered a given artwork. Used only to decide which
     * garment colour the agreement picture is drawn on; the press file itself
     * does not depend on it.
     */
    public function firstLineForCustomDesign(int $customDesignId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM order_items WHERE custom_design_id = ? ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([$customDesignId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function setStatus(int $id, string $status): void
    {
        $this->pdo()->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    public function setPayment(int $id, string $status, ?string $reference = null): void
    {
        $this->pdo()
            ->prepare('UPDATE orders SET payment_status = ?, payment_ref = ? WHERE id = ?')
            ->execute([$status, $reference, $id]);
    }

    public function numberExists(string $number): bool
    {
        $stmt = $this->pdo()->prepare('SELECT 1 FROM orders WHERE order_number = ? LIMIT 1');
        $stmt->execute([$number]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Returns stock to the shelf when an order is cancelled. Only lines whose
     * variant still exists can be credited, which is why it is a join and not
     * a blind update.
     */
    public function restock(int $orderId): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE product_variants v
               JOIN order_items oi ON oi.variant_id = v.id
                SET v.stock = v.stock + oi.quantity
              WHERE oi.order_id = ?'
        );
        $stmt->execute([$orderId]);
    }
}
