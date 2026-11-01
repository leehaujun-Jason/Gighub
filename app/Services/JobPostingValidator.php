<?php
declare(strict_types=1);
namespace GigHub\Services;

final class JobPostingValidator
{
    public static function validate(array $input, bool $publishing, ?\DateTimeImmutable $now = null): array
    {
        $errors=[];$data=[];
        foreach(['title'=>[5,150],'description'=>[50,10000]] as $field=>[$min,$max]) {
            $raw=$input[$field]??'';
            $value=is_string($raw)&&strlen($raw)<=65535&&mb_check_encoding($raw,'UTF-8')&&!str_contains($raw,"\0")
                ? preg_replace('/\A\s+|\s+\z/u','',$raw) : '';
            $data[$field]=$value;
            if(mb_strlen($value,'UTF-8')<$min||mb_strlen($value,'UTF-8')>$max) {
                $errors[$field]='Use '.$min.'–'.$max.' characters for the '.($field==='title'?'job title.':'description.');
            }
        }
        $raw=$input['budget']??'';$data['budget']=is_string($raw)?substr(trim($raw),0,32):'';
        if(!preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/D',$data['budget'])
            ||preg_match('/\A0(?:\.0{1,2})?\z/D',$data['budget'])) {
            $errors['budget']='Enter RM0.01–RM99,999,999.99 using no more than two decimal places.';
        } else {
            [$whole,$fraction]=array_pad(explode('.',$data['budget']),2,'');
            $data['budget']=$whole.'.'.str_pad($fraction,2,'0');
        }
        $zone=new \DateTimeZone('Asia/Kuala_Lumpur');
        $today=($now??new \DateTimeImmutable('now',$zone))->setTimezone($zone)->format('Y-m-d');
        $raw=$input['deadline']??'';$data['deadline']=is_string($raw)?substr($raw,0,32):'';
        $formatValid=preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/D',$data['deadline'])===1;
        $date=$formatValid?\DateTimeImmutable::createFromFormat('!Y-m-d',$data['deadline'],$zone):false;
        if(!preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/D',$data['deadline'])||!$date||$date->format('Y-m-d')!==$data['deadline']) {
            $errors['deadline']='Choose a valid delivery date.';
        } elseif($publishing&&$data['deadline']<=$today) {
            $errors['deadline']='Choose a delivery deadline after today (Malaysia time).';
        }
        $raw=$input['skills']??[];$data['skills']=[];
        if(!is_array($raw)||!array_is_list($raw)||count($raw)>10) { $errors['skills']='Choose no more than 10 distinct skills from the catalogue.'; }
        else {
            foreach($raw as $id) {
                if((!is_int($id)&&!is_string($id))||!preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$id)||(int)$id>4294967295) {
                    $errors['skills']='Choose valid skills from the catalogue.';break;
                }
                $data['skills'][]=(int)$id;
            }
            if(count(array_unique($data['skills']))!==count($data['skills'])) { $errors['skills']='Choose each skill only once.'; }
            if($publishing&&$data['skills']===[]) { $errors['skills']='Choose at least one required skill before publishing.'; }
        }
        return ['data'=>$data,'errors'=>$errors];
    }

    public static function oldValues(array $input): array
    {
        $old=[];
        foreach(['title'=>600,'description'=>40000,'budget'=>32,'deadline'=>32] as $field=>$limit) {
            $v=$input[$field]??'';
            $old[$field]=is_string($v)&&mb_check_encoding($v,'UTF-8')?mb_strcut(str_replace("\0",'',$v),0,$limit,'UTF-8'):'';
        }
        $old['skills']=[];
        if(is_array($input['skills']??null))foreach(array_slice($input['skills'],0,10) as $id) {
            if((is_string($id)||is_int($id))&&preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$id)&&(int)$id<=4294967295)$old['skills'][]=(int)$id;
        }
        return $old;
    }
}
