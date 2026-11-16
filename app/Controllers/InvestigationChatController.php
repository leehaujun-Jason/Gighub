<?php
declare(strict_types=1);
namespace GigHub\Controllers;

use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use Throwable;

final class InvestigationChatController
{
    public function __construct(private readonly AuthGuard $guard, private readonly Closure $factory) {}

    public function handle(string $action, array $input, ?array $uploads = null): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) return $access;
        $navigationUser = $access['user'];
        $userId = (int)$navigationUser['id'];
        $options = ['options'=>['min_range'=>1]];
        $id = filter_var($input['dispute_id'] ?? null,FILTER_VALIDATE_INT,$options);
        $participant = filter_var($input['participant_id'] ?? null,FILTER_VALIDATE_INT,$options);
        if (!$id || !$participant) return self::json(404,['error'=>'Conversation not found.']);
        $backUrl = $navigationUser['role'] === 'admin' ? '?route=/admin/disputes/show&id='.$id : '?route=/account';
        $threadParams = ['dispute_id'=>$id,'participant_id'=>$participant];
        $threadRoute = '/investigation-chat';
        $threadUrl = '?route='.$threadRoute.'/thread&'.http_build_query($threadParams);
        try {
            $service = ($this->factory)();
            if ($action === 'count') {
                return self::json(200,['unread_count'=>$service->unread($id,$participant,$navigationUser)]);
            }
            if ($action === 'attachment') {
                $message = filter_var($input['id'] ?? null,FILTER_VALIDATE_INT,$options);
                $attachment = filter_var($input['attachment_id'] ?? null,FILTER_VALIDATE_INT,$options);
                if (!$message || !$attachment) return self::json(404,['error'=>'Attachment not found.']);
                return $service->attachment($id,$participant,$navigationUser,$message,$attachment);
            }
            if ($action === 'read') {
                if (!Csrf::valid($input['csrf_token'] ?? null,'investigation_chat')) return self::json(403,['error'=>'Invalid security token.']);
                $message = filter_var($input['message_id'] ?? null,FILTER_VALIDATE_INT,$options);
                if (!$message) return self::json(404,['error'=>'Message not found.']);
                $service->read($id,$participant,$navigationUser,$message);
                return self::json(200,['success'=>true,'unread_count'=>$service->unread($id,$participant,$navigationUser)]);
            }
            if ($action === 'send') {
                if (!Csrf::valid($input['csrf_token'] ?? null,'investigation_chat')) return self::json(403,['error'=>'Invalid security token.']);
                $key = $input['request_key'] ?? null;
                $body = $input['body'] ?? null;
                if (!is_string($key) || !preg_match('/\A[a-f0-9]{32}\z/D',$key) || !is_string($body)) {
                    return self::json(422,['error'=>'Invalid message request.']);
                }
                $files = [];
                if ($uploads !== null) {
                    $errors = $uploads['error'] ?? null;
                    if (!is_array($errors) || count($errors)>5) return self::json(422,['error'=>'Choose at most five attachments.']);
                    foreach ($errors as $index => $error) {
                        $files[] = ['error'=>$error,'name'=>$uploads['name'][$index] ?? null,'tmp_name'=>$uploads['tmp_name'][$index] ?? null];
                    }
                }
                $message = $service->send($id,$participant,$navigationUser,$key,$body,$files);
                if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
                    return ['status'=>303,'location'=>$threadUrl];
                }
                return self::json(201,['success'=>true,'message'=>$message,'request_key'=>bin2hex(random_bytes(16))]);
            }
            if ($action === 'poll') {
                $since = filter_var($input['since_id'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
                if ($since === false) return self::json(400,['error'=>'Invalid cursor.']);
                $messages = $service->history($id,$participant,$navigationUser,$since);
                return self::json(200,['messages'=>$messages,'last_id'=>($messages[array_key_last($messages)]['id'] ?? $since),
                    'unread_count'=>$service->unread($id,$participant,$navigationUser)]);
            }
            $context = $service->context($id,$participant,$navigationUser);
            if ($navigationUser['role'] !== 'admin') {
                $backUrl = '?route=/' . $navigationUser['role'] . '/jobs/milestones&job_id=' . (int)$context['job_id'];
            }
            $messages = $service->history($id,$participant,$navigationUser,null);
            $lastMessageId = $messages[array_key_last($messages)]['id'] ?? 0;
            $counterpartRole = $navigationUser['role'] === 'admin' ? ucfirst($context['participant_role']) : 'Admin';
            $counterpartName = $counterpartRole;
            $statusInfo = ['is_writable'=>$context['status']==='open','status_label'=>'Dispute #'.$id,
                'reason'=>'This dispute is resolved. The conversation is read-only.'];
            $isInvestigation = true;
            $requestKey = bin2hex(random_bytes(16));
            $csrfToken = Csrf::token('investigation_chat');
            $logoutToken = Csrf::token('logout');
            $escape = static fn($value) => htmlspecialchars((string)$value,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
            ob_start();
            try {
                require dirname(__DIR__).'/Views/messages/thread.php';
                return ['status'=>200,'type'=>'text/html; charset=UTF-8','body'=>ob_get_contents()];
            } finally {
                ob_end_clean();
            }
        } catch (\DomainException $e) {
            return self::json($e->getCode(),['error'=>$e->getMessage()]);
        } catch (Throwable) {
            return self::json(503,['error'=>'Investigation chat is temporarily unavailable.']);
        }
    }

    private static function json(int $status,array $data): array
    {
        return ['status'=>$status,'type'=>'application/json; charset=UTF-8','body'=>json_encode($data,JSON_INVALID_UTF8_SUBSTITUTE)];
    }
}
