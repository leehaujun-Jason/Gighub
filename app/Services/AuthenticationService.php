<?php
declare(strict_types=1);
namespace GigHub\Services;

use GigHub\Repositories\UserRepository;
use RuntimeException;
use Throwable;

final class AuthenticationService
{
    // Public dummy hash, not an account credential; avoids an immediate unknown-user shortcut.
    private const DUMMY_HASH='$argon2id$v=19$m=65536,t=4,p=1$SWI3emdwQTVWazZDdWYuSw$LkTgxvgBk3FOagr5jc4ym5tah/s309iF55uKNHo9Pz8';
    public function __construct(private readonly UserRepository $users) {}

    public function authenticate(string $email, #[\SensitiveParameter] string $password): ?array
    {
        try {
            $row=$this->users->findForAuthentication($email);
            $valid=password_verify($password,$row===false?self::DUMMY_HASH:$row['password_hash']);
            if(!$valid||!self::eligible($row)) { return null; }
            // Re-read current eligibility after the expensive password verification.
            $user = $this->identity((int)$row['id']);
            return $user !== null && (!isset($user['credential_stamp'])
                || hash_equals($user['credential_stamp'], hash('sha256', $row['password_hash']))) ? $user : null;
        } catch(Throwable) { throw new RuntimeException('Sign-in is temporarily unavailable.'); }
    }

    public function identity(int $id): ?array
    {
        try {
            $row=$this->users->findAuthIdentity($id);
            if (!self::eligible($row)) { return null; }
            return [
                'id' => (int) $row['id'], 'display_name' => $row['display_name'],
                'role' => $row['role'], 'status' => $row['status'],
                'avatar_key' => $row['avatar_key'] ?? null,
                'onboarding_pending' => $row['role'] !== 'admin' && (int) ($row['onboarding_pending'] ?? 0) === 1,
            ] + (isset($row['password_hash']) ? ['credential_stamp' => hash('sha256', $row['password_hash'])] : []);
        } catch(Throwable) { throw new RuntimeException('Sign-in is temporarily unavailable.'); }
    }

    private static function eligible(array|false $row): bool
    {
        return $row!==false&&$row['status']==='active'&&$row['email_verified_at']!==null
            &&in_array($row['role'],['client','freelancer','admin'],true);
    }
}
