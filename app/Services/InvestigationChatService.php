<?php
declare(strict_types=1);
namespace GigHub\Services;

use GigHub\Repositories\InvestigationChatRepository;
use Throwable;

final class InvestigationChatService
{
    public function __construct(private readonly InvestigationChatRepository $repo) {}

    public function context(int $id, int $participant, array $user): array
    {
        $context = $this->authorize($this->repo->context($id),$participant,$user);
        if ($user['role'] !== 'admin' && !$this->repo->initiated($id,$participant)) {
            throw new \DomainException('Conversation not found.',404);
        }
        return $context;
    }

    private function authorize(array|false $context, int $participant, array $user): array
    {
        if ($context !== false) {
            $role = $participant === (int)$context['client_id'] ? 'client'
                : ($participant === (int)$context['freelancer_id'] ? 'freelancer' : null);
            if ($role !== null && ($user['role'] === 'admin' || ($user['role'] === $role && (int)$user['id'] === $participant))) {
                return $context + ['participant_role' => $role];
            }
        }
        throw new \DomainException('Conversation not found.',404);
    }

    public function history(int $id, int $participant, array $user, ?int $since): array
    {
        $this->context($id,$participant,$user);
        return array_map(fn(array $row) => $this->format($row,$user), $this->repo->messages($id,$participant,$since));
    }

    public function attachment(int $id, int $participant, array $user, int $message, int $attachment): array
    {
        $this->context($id,$participant,$user);
        $file = $this->repo->attachment($id,$participant,$message,$attachment);
        if ($file === false || ($bytes = (new ChatAttachmentStorage())->read($file['attachment_key'])) === false) {
            throw new \DomainException('Attachment not found.',404);
        }
        $image = (bool)$file['attachment_image'];
        return ['status'=>200,'body'=>$bytes,'type'=>$image ? 'image/webp' : 'application/octet-stream',
            'content_disposition'=>$image ? 'inline' : "attachment; filename*=UTF-8''".rawurlencode($file['attachment_name'])];
    }

    public function read(int $id, int $participant, array $user, int $message): void
    {
        $this->context($id,$participant,$user);
        if (!$this->repo->read($id,$participant,(int)$user['id'],$message)) {
            throw new \DomainException('Message not found.',404);
        }
    }

    public function unread(int $id, int $participant, array $user): int
    {
        $this->context($id,$participant,$user);
        return $this->repo->unread($id,$participant,(int)$user['id']);
    }

    public function send(int $id, int $participant, array $user, string $key, string $body, array $files = []): array
    {
        $validation = MessageService::validateBody($body,$files !== []);
        if (!$validation['valid']) throw new \DomainException($validation['error'],422);
        $candidate = $this->context($id,$participant,$user);
        $pdo = $this->repo->pdo;
        $storage = new ChatAttachmentStorage();
        $stored = [];
        $committing = false;
        $pdo->beginTransaction();
        try {
            $locked = $this->authorize($this->repo->lockContext($id,(int)$candidate['job_id']),$participant,$user);
            if ($locked['status'] !== 'open') {
                throw new \DomainException('This dispute is resolved. The conversation is read-only.',409);
            }
            $row = $this->repo->save($id,$participant,$user,$key,$validation['body'],static function () use ($files,$storage,&$stored): array {
                foreach ($files as $file) $stored[] = $storage->store($file);
                return $stored;
            });
            $committing = true;
            $pdo->commit();
            return $this->format($row,$user);
        } catch (Throwable $e) {
            try {
                if ($pdo->inTransaction()) $pdo->rollBack();
            } finally {
                if (!$committing) foreach ($stored as $file) $storage->remove($file['key']);
            }
            if ($e instanceof \InvalidArgumentException) throw new \DomainException($e->getMessage(),422,$e);
            throw $e;
        }
    }

    private function format(array $row, array $user): array
    {
        $row['id'] = (int)$row['id'];
        return $row + ['attachments'=>$this->repo->attachments($row['id']), 'is_me'=>(int)$row['sender_id']===(int)$user['id'],
            'formatted_time'=>MessageService::formatMalaysiaTime($row['created_at'])];
    }
}
