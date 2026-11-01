<?php
declare(strict_types=1);
namespace GigHub\Services;

final class LoginValidator
{
    public static function validate(#[\SensitiveParameter] array $input): array
    {
        $email=is_string($input['email']??null)?strtolower(trim($input['email'])):'';
        $password=is_string($input['password']??null)?$input['password']:'';
        $errors=[];
        if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)) { $errors['email']='Enter a valid email address using no more than 254 characters.'; }
        if($password===''||strlen($password)>1024||!mb_check_encoding($password,'UTF-8')||str_contains($password,"\0")) { $errors['password']='Enter your password using valid text within the 1024-byte safety limit.'; }
        return ['email'=>$email,'password'=>$password,'errors'=>$errors];
    }
}
