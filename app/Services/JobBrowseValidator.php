<?php
declare(strict_types=1);
namespace GigHub\Services;

final class JobBrowseValidator
{
    public static function validate(array $input): array
    {
        $data=['q'=>'','min_budget'=>'','max_budget'=>'','skills'=>[],'sort'=>'newest','page'=>1];$errors=[];
        $q=array_key_exists('q',$input)?$input['q']:'';
        if(!is_string($q)||strlen($q)>400||!mb_check_encoding($q,'UTF-8')||str_contains($q,"\0")) {
            $errors['q']='Use no more than 100 characters for your search.';
        } else {
            $data['q']=preg_replace('/\A\s+|\s+\z/u','',$q);
            if(mb_strlen($data['q'],'UTF-8')>100)$errors['q']='Use no more than 100 characters for your search.';
        }
        foreach(['min_budget','max_budget'] as $field) {
            $v=array_key_exists($field,$input)?$input[$field]:'';
            if(!is_string($v)||strlen($v)>32) {$errors[$field]='Enter a valid RM amount.';continue;}
            $v=trim($v);if($v==='')continue;
            if(!preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/D',$v)||preg_match('/\A0(?:\.0{1,2})?\z/D',$v)) {
                $errors[$field]='Use RM0.01–RM99,999,999.99 with up to two decimal places.';continue;
            }
            [$whole,$fraction]=array_pad(explode('.',$v),2,'');$data[$field]=$whole.'.'.str_pad($fraction,2,'0');
        }
        // Fixed-width decimal comparison avoids floating point and platform integer limits.
        if($data['min_budget']!==''&&$data['max_budget']!==''&&strcmp(str_pad($data['min_budget'],11,'0',STR_PAD_LEFT),str_pad($data['max_budget'],11,'0',STR_PAD_LEFT))>0) {
            $errors['max_budget']='Maximum budget must be at least the minimum budget.';
        }
        $ids=array_key_exists('skills',$input)?$input['skills']:[];
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>10)$errors['skills']='Choose up to 10 distinct catalogue skills.';
        else {
            foreach($ids as $id) {
                if((!is_string($id)&&!is_int($id))||!preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$id)||(int)$id>4294967295) {
                    $errors['skills']='Choose valid catalogue skills.';break;
                }
                $data['skills'][]=(int)$id;
            }
            if(count(array_unique($data['skills']))!==count($data['skills']))$errors['skills']='Choose each skill only once.';
        }
        $sort=array_key_exists('sort',$input)?$input['sort']:'newest';
        if(!is_string($sort)||!in_array($sort,['newest','budget_low','budget_high'],true))$errors['sort']='Choose a listed sort order.';
        else $data['sort']=$sort;
        $page=array_key_exists('page',$input)?$input['page']:'1';
        if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,5}\z/D',$page)||(int)$page>100000)$errors['page']='Choose a page from 1 to 100000.';
        else $data['page']=(int)$page;
        return ['data'=>$data,'errors'=>$errors];
    }
}
