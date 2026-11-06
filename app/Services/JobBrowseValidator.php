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
        $minVal = array_key_exists('min_budget', $input) ? $input['min_budget'] : '';
        if (!is_string($minVal) || strlen($minVal) > 32) {
            $errors['min_budget'] = 'Minimum budget must be at least RM100.';
        } else {
            $minTrim = trim($minVal);
            if ($minTrim !== '') {
                if (!preg_match('/\A[1-9][0-9]{0,8}(?:\.[0-9]{1,2})?\z/D', $minTrim)) {
                    $errors['min_budget'] = 'Minimum budget must be at least RM100.';
                } else {
                    [$minWhole, $minFraction] = array_pad(explode('.', $minTrim), 2, '');
                    $paddedMin = str_pad($minWhole, 9, '0', STR_PAD_LEFT) . '.' . str_pad($minFraction, 2, '0');
                    if (strcmp($paddedMin, '000000100.00') < 0) {
                        $errors['min_budget'] = 'Minimum budget must be at least RM100.';
                    } elseif (strcmp($paddedMin, '100000000.00') > 0) {
                        $errors['min_budget'] = 'Minimum budget cannot exceed RM100,000,000.';
                    } else {
                        $data['min_budget'] = $minWhole . '.' . str_pad($minFraction, 2, '0');
                    }
                }
            }
        }

        $maxVal = array_key_exists('max_budget', $input) ? $input['max_budget'] : '';
        if (!is_string($maxVal) || strlen($maxVal) > 32) {
            $errors['max_budget'] = 'Maximum budget cannot exceed RM100,000,000.';
        } else {
            $maxTrim = trim($maxVal);
            if ($maxTrim !== '') {
                if (!preg_match('/\A[1-9][0-9]{0,8}(?:\.[0-9]{1,2})?\z/D', $maxTrim)) {
                    $errors['max_budget'] = 'Maximum budget cannot exceed RM100,000,000.';
                } else {
                    [$maxWhole, $maxFraction] = array_pad(explode('.', $maxTrim), 2, '');
                    $paddedMax = str_pad($maxWhole, 9, '0', STR_PAD_LEFT) . '.' . str_pad($maxFraction, 2, '0');
                    if (strcmp($paddedMax, '100000000.00') > 0) {
                        $errors['max_budget'] = 'Maximum budget cannot exceed RM100,000,000.';
                    } else {
                        $data['max_budget'] = $maxWhole . '.' . str_pad($maxFraction, 2, '0');
                    }
                }
            }
        }

        // Exact decimal comparison avoids binary floating-point rounding errors.
        if ($data['min_budget'] !== '' && $data['max_budget'] !== '') {
            [$minWhole, $minFraction] = explode('.', $data['min_budget']);
            [$maxWhole, $maxFraction] = explode('.', $data['max_budget']);
            $paddedMin = str_pad($minWhole, 9, '0', STR_PAD_LEFT) . '.' . $minFraction;
            $paddedMax = str_pad($maxWhole, 9, '0', STR_PAD_LEFT) . '.' . $maxFraction;
            if (strcmp($paddedMin, $paddedMax) > 0) {
                $errors['max_budget'] = 'Minimum budget cannot exceed maximum budget.';
            }
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
        if(!is_string($sort)||!in_array($sort,['newest','oldest','budget_low','budget_high'],true))$errors['sort']='Choose a listed sort order.';
        else $data['sort']=$sort;
        $page=array_key_exists('page',$input)?$input['page']:'1';
        if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,5}\z/D',$page)||(int)$page>100000)$errors['page']='Choose a page from 1 to 100000.';
        else $data['page']=(int)$page;
        return ['data'=>$data,'errors'=>$errors];
    }
}
