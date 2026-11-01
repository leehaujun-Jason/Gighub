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
            return $this->identity((int)$row['id']);
        } catch(Throwable) { throw new RuntimeException('Sign-in is temporarily unavailable.'); }
    }

    public function identity(int $id): ?array
    {
        try {
            $row=$this->users->findAuthIdentity($id);
            return self::eligible($row)?['id'=>(int)$row['id'],'display_name'=>$row['display_name'],'role'=>$row['role']]:null;
        } catch(Throwable) { throw new RuntimeException('Sign-in is temporarily unavailable.'); }
    }

    private static function eligible(array|false $row): bool
    {
        return $row!==false&&$row['status']==='active'&&$row['email_verified_at']!==null
            &&in_array($row['role'],['client','freelancer','admin'],true);
    }
}
