<?php
declare(strict_types=1);
namespace GigHub\Services;

final class FreelancerProfileValidator
{
    public static function validate(array $input): array
    {
        $data=['headline'=>'','bio'=>'','skills'=>[]]; $errors=[];
        foreach (['headline'=>150,'bio'=>10000] as $field=>$limit) {
            $value=$input[$field]??'';
            if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0")) {
                $errors[$field]='Enter valid text.'; continue;
            }
            $data[$field]=trim($value);
            if (mb_strlen($data[$field],'UTF-8')>$limit || strlen($data[$field])>65535) {
                $errors[$field]="Use no more than $limit characters.";
            }
        }
        $ids=$input['skills']??[];
        if (!is_array($ids) || count($ids)>1000) { $errors['skills']='Choose skills from the catalogue.'; }
        else foreach ($ids as $id) {
            if ((!is_string($id) && !is_int($id)) || !preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$id) || (int)$id>4294967295) {
                $errors['skills']='Choose skills from the catalogue.'; continue;
            }
            $data['skills'][]=(int)$id;
        }
        if ($data['skills'] === []) { $errors['skills'] ??= 'Select at least one skill.'; }
        if (count($data['skills']) !== count(array_unique($data['skills']))) { $errors['skills'] = 'Select distinct skills.'; }
        $data['skills']=array_values(array_unique($data['skills'])); sort($data['skills'],SORT_NUMERIC);
        return compact('data','errors');
    }
}
