<?php
declare(strict_types=1);
namespace GigHub\Services;

use GigHub\Repositories\UserRepository;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class RegistrationService
{
    public function __construct(private readonly PDO $pdo, private readonly UserRepository $users) {}

    /** True only after this call commits its own user/profile transaction. */
    public function register(array $input): bool
    {
        $validated = RegistrationValidator::validate($input);
        if ($validated['errors'] !== []) { throw new RegistrationValidationException($validated['errors']); }
        $data = $validated['data'];
        if ($this->users->emailExists($data['email'])) { throw self::duplicateEmail(); }
        // Expensive hashing precedes the write transaction; no plaintext is persisted.
        $hash = password_hash($data['password'], PASSWORD_ARGON2ID);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        else { $this->pdo->exec('SAVEPOINT gighub_registration'); }
        try {
            $id = $this->users->insertUser($data, $hash);
            $this->users->insertProfile($id, $data);
            if ($ownsTransaction) { $this->pdo->commit(); }
            else { $this->pdo->exec('RELEASE SAVEPOINT gighub_registration'); }
            return $ownsTransaction;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            elseif (!$ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT gighub_registration');
                $this->pdo->exec('RELEASE SAVEPOINT gighub_registration');
            }
            if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062
                && str_contains($error->getMessage(), 'uq_users_email')) {
                throw self::duplicateEmail();
            }
            error_log('[GigHub] Registration persistence failed.');
            throw new RuntimeException('Registration is temporarily unavailable. Please try again.');
        }
    }

    private static function duplicateEmail(): RegistrationValidationException
    {
        return new RegistrationValidationException(['email' => 'This email is already registered.']);
    }
}
