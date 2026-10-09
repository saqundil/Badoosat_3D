<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CustomerRepository;
use App\Support\Validator;

/**
 * Accounts are optional and stay that way.
 *
 * Checkout works without one, and nothing here is on the path to placing an
 * order. An account exists so a returning customer does not retype an address
 * and can see what they have ordered before -- a convenience, offered after the
 * sale rather than demanded before it.
 */
final class CustomerService
{
    public function __construct(private readonly CustomerRepository $customers)
    {
    }

    public static function make(): self
    {
        return new self(CustomerRepository::make());
    }

    /**
     * @return array<string,mixed> the new customer row
     * @throws ValidationException
     */
    public function register(array $input): array
    {
        $v = new Validator($input);
        $v->required('name', 'الاسم')->maxLength('name', 160, 'الاسم');
        $v->required('phone', 'رقم الهاتف')->maxLength('phone', 40, 'رقم الهاتف');

        $email    = mb_strtolower(trim($v->string('email')));
        $password = $v->string('password');

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $v->fail('email', 'البريد الإلكتروني غير صالح.');
        }

        // Length, and nothing else. Composition rules push people towards
        // Passw0rd! and away from the long phrases that are actually strong.
        if (mb_strlen($password) < 8) {
            $v->fail('password', 'كلمة المرور ثمانية أحرف على الأقل.');
        }

        if ($v->passes() && $this->customers->findByEmail($email) !== null) {
            $v->fail('email', 'هذا البريد مسجّل بالفعل. سجّل الدخول بدلًا من ذلك.');
        }

        if (!$v->passes()) {
            throw new ValidationException($v->errors());
        }

        $id = $this->customers->insert([
            'name'          => trim((string) $input['name']),
            'email'         => $email,
            'phone'         => trim((string) $input['phone']),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        return $this->customers->find($id) ?? [];
    }

    /**
     * Null for a wrong password, and null for an address that has no account.
     *
     * The dummy verify on the miss is deliberate: without it, a non-existent
     * email answers in a fraction of the time a real one does, and that gap is
     * enough to enumerate the customer list.
     */
    public function attempt(string $email, string $password): ?array
    {
        $customer = $this->customers->findByEmail(mb_strtolower(trim($email)));

        if ($customer === null || ($customer['password_hash'] ?? null) === null) {
            password_verify($password, '$2y$10$usesomesillystringfoxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

            return null;
        }

        return password_verify($password, (string) $customer['password_hash']) ? $customer : null;
    }

    public function find(int $id): ?array
    {
        return $this->customers->find($id);
    }

    /** @return array<int,array<string,mixed>> */
    public function orders(int $customerId): array
    {
        return $this->customers->orders($customerId);
    }

    /** @return array<int,array<string,mixed>> */
    public function addresses(int $customerId): array
    {
        return $this->customers->addresses($customerId);
    }
}
