<?php
declare(strict_types=1);
namespace GigHub\Controllers;

use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Services\AvatarStorage;
use InvalidArgumentException;
use Throwable;

final class AvatarController
{
    public function __construct(private readonly AuthGuard $guard, private readonly Closure $repository,
        private readonly AvatarStorage $storage) {}

    public function change(array $input, array $files): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) { return $access; }
        $user = $access['user'];
        if (!in_array($user['role'], ['client', 'freelancer'], true)) {
            return self::response(403, 'You cannot change this photo.');
        }
        if (!Csrf::valid($input['csrf_token'] ?? null, 'avatar')) {
            return self::response(403, 'Photo form expired. Return to your profile and try again.');
        }
        $error = null;
        try {
            $repository = ($this->repository)();
            $key = match ($input['action'] ?? null) {
                'upload' => $this->storage->store($files['photo'] ?? []),
                'remove' => null,
                default => throw new InvalidArgumentException('Choose a valid photo action.'),
            };
            $old = $repository->replace($user['id'], $user['role'], $key);
            if ($old !== null) {
                try { $this->storage->remove($old); } catch (Throwable) { /* Unreferenced file can be reconciled later. */ }
            }
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable) {
            $error = 'Your photo could not be saved. Please try again.';
        }
        $_SESSION['avatar_notice'] = ['id' => $user['id'], 'error' => $error];
        return self::response(303, '') + ['location' => '?route=/' . $user['role'] . '/profile/edit'];
    }

    public function show(array $input): array
    {
        $id = $input['id'] ?? null;
        if (!is_string($id) || !preg_match('/\A[1-9][0-9]{0,9}\z/', $id) || (int) $id > 4294967295) {
            return self::response(404, 'Photo not found.');
        }
        try {
            $key = ($this->repository)()->publicKey((int) $id);
            if ($key === null) {
                $access = $this->guard->check(allowOnboarding: true);
                $user = $access['user'] ?? [];
                if (($user['id'] ?? null) === (int) $id && in_array($user['role'], ['client', 'freelancer'], true)) {
                    $key = $user['avatar_key'] ?? null;
                }
            }
            $image = $key === null ? false : $this->storage->read($key);
            if ($image !== false && strlen($image) <= 2097152) {
                return ['status' => 200, 'type' => 'image/webp', 'body' => $image];
            }
        } catch (Throwable) { /* Keep absent and inaccessible photos indistinguishable. */ }
        return self::response(404, 'Photo not found.');
    }

    private static function response(int $status, string $body): array
    {
        return ['status' => $status, 'type' => 'text/plain; charset=UTF-8', 'body' => $body];
    }
}
