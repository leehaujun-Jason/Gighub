<?php
declare(strict_types=1);

namespace GigHub\Core;

use Closure;
use RuntimeException;

/** Optional presentation identity; never a replacement for protected-route authorization. */
final class PublicIdentity
{
    public function __construct(private readonly AuthSession $session, private readonly Closure $lookup) {}

    public function current(): ?array
    {
        $id = $this->session->userId();
        if ($id === null) { return null; }
        try {
            // The injected AuthenticationService rechecks status, verification and role in SQL-backed identity().
            $user = ($this->lookup)($id);
        } catch (RuntimeException) {
            // Keep public pages available. Do not trust stale identity or destroy a session on transient failure.
            return null;
        }
        if ($user === null || !$this->session->matchesCredential($user)) { $this->session->forget(); return null; }
        unset($user['credential_stamp']);
        $this->session->touch();
        return $user;
    }
}
