<?php
declare(strict_types=1);
namespace GigHub\Services;
final class PaymentMoney
{
    public static function sen(string $value): int
    {
        if (PHP_INT_SIZE < 8 || !preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/D', $value)) {
            throw new \InvalidArgumentException('Invalid monetary amount.');
        }
        [$whole,$fraction] = array_pad(explode('.', $value), 2, '');
        return (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
    }
    public static function decimal(int $sen): string
    {
        if ($sen < 0 || $sen > 9999999999) throw new \InvalidArgumentException('Amount exceeds the supported range.');
        return intdiv($sen,100).'.'.str_pad((string)($sen%100),2,'0',STR_PAD_LEFT);
    }
    public static function target(string $principal, int $percent): int
    {
        if (!in_array($percent,[30,50],true)) throw new \InvalidArgumentException('Invalid payment schedule.');
        return intdiv(self::sen($principal)*$percent+50,100);
    }
    public static function progressive(array $row): bool { return ($row['financial_policy'] ?? 'legacy_full_v1') === 'progressive_v1'; }
}
