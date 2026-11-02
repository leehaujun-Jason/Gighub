<?php
declare(strict_types=1);
namespace GigHub\Services;

final class BidValidator
{
    public static function id(mixed $value): ?int
    {
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,9}\z/D',$value) && (int)$value<=4294967295 ? (int)$value : null;
    }
    public static function validate(array $input): array
    {
        $data=['amount'=>'','cover_letter'=>'','delivery_days'=>'']; $errors=[];
        $raw=$input['amount']??'';
        if (is_string($raw) && strlen($raw)<=32) $data['amount']=trim($raw);
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/D',$data['amount']) || preg_match('/\A0(?:\.0{1,2})?\z/D',$data['amount'])) {
            $errors['amount']='Enter RM0.01–RM99,999,999.99 with at most two decimal places.';
        } else {
            [$whole,$fraction]=array_pad(explode('.',$data['amount']),2,'');
            $data['amount']=$whole.'.'.str_pad($fraction,2,'0');
        }
        $raw=$input['cover_letter']??'';
        if (is_string($raw) && strlen($raw)<=65535 && mb_check_encoding($raw,'UTF-8') && !str_contains($raw,"\0")) {
            $data['cover_letter']=preg_replace('/\A\s+|\s+\z/u','',$raw);
        }
        if ($data['cover_letter']==='' || mb_strlen($data['cover_letter'],'UTF-8')>10000) $errors['cover_letter']='Write a proposal of 1–10,000 characters.';
        $raw=$input['delivery_days']??'';
        if (is_string($raw) && strlen($raw)<=5) $data['delivery_days']=$raw;
        if (!preg_match('/\A[1-9][0-9]{0,4}\z/D',$data['delivery_days']) || (int)$data['delivery_days']>65535) $errors['delivery_days']='Enter a whole number of days from 1 to 65,535.';
        return compact('data','errors');
    }
}
