<?php
declare(strict_types=1);
namespace GigHub\Services;

use PDO;

final class MarketplaceDocuments
{
    public static function jobs(PDO $pdo, int $id): array
    {
        $q = $pdo->prepare('SELECT id,display_name FROM job_requirement_documents WHERE job_id=? ORDER BY id');
        $q->execute([$id]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function proposal(PDO $pdo, int $id): array|false
    {
        $q = $pdo->prepare('SELECT proposal_document_name FROM bids WHERE id=? AND proposal_document_key IS NOT NULL');
        $q->execute([$id]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public static function response(array $file): array
    {
        $bytes = self::storage()->read($file['storage_key']);
        if ($bytes === false) throw new \RuntimeException('Document unavailable.');
        return ['status'=>200,'type'=>'application/octet-stream','body'=>$bytes,
            'content_disposition'=>"attachment; filename*=UTF-8''" . rawurlencode($file['display_name'])];
    }

    public static function storage(): ChatAttachmentStorage
    {
        return ChatAttachmentStorage::marketplace();
    }

    public static function saveJob(PDO $pdo, int $id, array $input, array $uploads, array &$keys): void
    {
        $existing = array_column(self::jobs($pdo, $id), 'id');
        $remove = $input['remove_documents'] ?? [];
        if (!is_array($remove) || count($remove) > 5
            || array_filter($remove, static fn($value) => !is_string($value) || !ctype_digit($value))
            || array_diff($remove, $existing)) {
            throw new JobPostingException(['form'=>'Invalid document selection.']);
        }
        $files = [];
        foreach ($uploads['error'] ?? [] as $index => $error) {
            if ($error !== UPLOAD_ERR_NO_FILE) {
                $files[] = ['error'=>$error,'name'=>$uploads['name'][$index],'tmp_name'=>$uploads['tmp_name'][$index]];
            }
        }
        if (count(array_diff($existing, $remove)) + count($files) > 5) {
            throw new JobPostingException(['form'=>'A job can have at most five documents.']);
        }
        if ($remove !== []) {
            $delete = $pdo->prepare('DELETE FROM job_requirement_documents WHERE job_id=? AND id=?');
            foreach ($remove as $documentId) $delete->execute([$id, $documentId]);
        }
        foreach ($files as $file) {
            try { $stored = self::storage()->store($file); }
            catch (\InvalidArgumentException $e) { throw new JobPostingException(['form'=>$e->getMessage()]); }
            $keys[] = $stored['key'];
            $q = $pdo->prepare('INSERT INTO job_requirement_documents (job_id,storage_key,display_name,size_bytes) VALUES (?,?,?,?)');
            $q->execute([$id,$stored['key'],$stored['name'],filesize($file['tmp_name'])]);
        }
    }
}
