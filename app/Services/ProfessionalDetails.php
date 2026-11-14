<?php
declare(strict_types=1);
namespace GigHub\Services;

final class ProfessionalDetails
{
    public const SECTIONS = ['education'=>'Education', 'employment'=>'Employment History', 'languages'=>'Languages', 'portfolio'=>'Portfolio'];
    public const LANGUAGES = [
        'ar'=>'Arabic','bn'=>'Bengali','zh'=>'Chinese','en'=>'English','fr'=>'French','de'=>'German',
        'hi'=>'Hindi','id'=>'Indonesian','ja'=>'Japanese','ko'=>'Korean','ms'=>'Malay','pt'=>'Portuguese',
        'ru'=>'Russian','es'=>'Spanish','ta'=>'Tamil','th'=>'Thai','vi'=>'Vietnamese',
    ];
    public const PROFICIENCY = ['Basic','Conversational','Fluent','Native'];
    public const FIELDS = [
        'education'=>['institution'=>['Institution Name','text',150,true], 'qualification'=>['Degree / Qualification','text',150,true],
            'field_of_study'=>['Field of Study','text',150,false], 'start_year'=>['Start Year','number',4,false], 'end_year'=>['End Year','number',4,false]],
        'employment'=>['job_title'=>['Job Title','text',150,true], 'company'=>['Company Name','text',150,true],
            'start_date'=>['Start Date','date',10,true], 'end_date'=>['End Date','date',10,false],
            'current_job'=>['Currently Working Here','checkbox',1,false], 'description'=>['Description','textarea',2000,false]],
        'portfolio'=>['project_title'=>['Project Title','text',150,true], 'project_url'=>['Project URL','url',2048,true],
            'description'=>['Short Description','textarea',500,true]],
        'languages'=>['language'=>['Language','select',16,true], 'proficiency'=>['Proficiency Level','select',16,true]],
    ];

    public static function safeProjectUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && !str_contains($url, chr(92))
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null;
    }

    public static function validate(string $section, array $input): array
    {
        $data = $errors = [];
        $current = ($input['current_job'] ?? null) === '1';
        foreach (self::FIELDS[$section] as $name => [$label, $type, $limit, $required]) {
            $raw = $input[$name] ?? '';
            if ($name === 'end_date' && $current) { $raw = ''; }
            if ($type === 'checkbox') {
                if (!in_array($raw, ['', '1'], true)) { $errors[$name] = 'Choose a valid current employment value.'; }
                $data[$name] = (int) $current;
                continue;
            }
            if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')
                || preg_match($type === 'textarea' ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $raw)) {
                $errors[$name] = "Enter valid text for $label.";
                $raw = '';
            }
            $value = trim($raw);
            $data[$name] = $value === '' ? null : mb_substr($value, 0, $limit);
            if (($required && $value === '') || mb_strlen($value) > $limit) {
                $errors[$name] ??= "Enter a valid $label (up to $limit characters).";
            }
            if ($value === '') { continue; }
            if ($type === 'number' && (!preg_match('/\A[0-9]{4}\z/', $value) || (int) $value < 1900 || (int) $value > (int) date('Y') + 10)) {
                $errors[$name] = 'Enter a year from 1900 through ten years from now.';
            }
            if ($type === 'date' && (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)
                || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8), (int) substr($value, 0, 4))
                || (int) substr($value, 0, 4) < 1900)) {
                $errors[$name] = 'Enter a valid calendar date from 1900 onward.';
            }
        }
        $suffix = $section === 'education' ? 'year' : 'date';
        $start = $data["start_$suffix"] ?? null;
        $end = $data["end_$suffix"] ?? null;
        if ($start !== null && $end !== null && $end < $start) {
            $errors["end_$suffix"] ??= 'The end must be on or after the start.';
        }
        if ($section === 'portfolio' && !self::safeProjectUrl($data['project_url'] ?? '')) {
            $errors['project_url'] = 'Enter a valid HTTPS project URL.';
        }
        if ($section === 'languages') {
            if (!isset(self::LANGUAGES[$data['language'] ?? ''])) { $errors['language'] = 'Choose a language from the list.'; }
            if (!in_array($data['proficiency'], self::PROFICIENCY, true)) { $errors['proficiency'] = 'Choose a proficiency level.'; }
        }
        return [$data, $errors];
    }
}
