<?php

declare(strict_types=1);

namespace App\Repository;

final class CustomerRepository extends Repository
{
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM customers WHERE email = ?');
        $stmt->execute([mb_strtolower($email)]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function insert(array $values): int
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO customers (name, email, phone, password_hash) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $values['name'],
            mb_strtolower((string) $values['email']),
            $values['phone'],
            $values['password_hash'] ?? null,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> */
    public function orders(int $customerId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 50'
        );
        $stmt->execute([$customerId]);

        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function addresses(int $customerId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM addresses WHERE customer_id = ? ORDER BY is_default DESC, id DESC'
        );
        $stmt->execute([$customerId]);

        return $stmt->fetchAll();
    }

    public function addAddress(int $customerId, array $values): int
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO addresses (customer_id, label, city, line1, line2, is_default)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $customerId,
            $values['label'] ?? null,
            $values['city'],
            $values['line1'],
            $values['line2'] ?? null,
            (int) ($values['is_default'] ?? 0),
        ]);

        return (int) $this->pdo()->lastInsertId();
    }
}
