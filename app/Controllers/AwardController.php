<?php
declare(strict_types=1);
namespace GigHub\Controllers;
use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Services\BidException;
use GigHub\Services\BidValidator;
use Throwable;

final class AwardController
{
    public function __construct(private readonly AuthGuard $guard,private readonly Closure $serviceFactory) {}
    public function award(array $input): array
    {
        $access=$this->guard->check('client'); if (!isset($access['user'])) return $access;
        if (!Csrf::valid($input['csrf_token']??null,'bid_award')) return self::text(403,'Your form expired. Reload the page and try again.');
        $jobId=BidValidator::id($input['job_id']??null); $bidId=BidValidator::id($input['bid_id']??null);
        if ($jobId===null || $bidId===null) return self::text(400,'Invalid award request.');
        try {
            ($this->serviceFactory)()->award((int)$access['user']['id'],$jobId,$bidId);
            Csrf::rotate('bid_award'); $_SESSION['award_notice']=true;
            return self::text(303,'')+['location'=>'?route=/client/jobs/bids&id='.$jobId];
        } catch (BidException $e) { return self::text($e->status,$e->errors['form']??'Award request could not be completed.'); }
        catch (Throwable) { return self::text(503,'Award selection is temporarily unavailable. Please try again later.'); }
    }
    private static function text(int $status,string $body): array { return ['status'=>$status,'type'=>'text/plain; charset=UTF-8','body'=>$body]; }
}
