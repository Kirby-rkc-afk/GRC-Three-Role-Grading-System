<?php
declare(strict_types=1);
function cbPassword(string $password,string $confirmation): void {
    if(strlen($password)<12||strlen($password)>72||str_contains($password,"\0")||$password!==$confirmation)throw new InvalidArgumentException('Use matching passwords between 12 and 72 bytes.');
}
/** Shared browser/server rules avoid inventing a school-specific ID pattern. */
function cbIdentifierError(mixed $identifier): ?string {
    if(!is_string($identifier)||trim($identifier)==='')return 'Enter your school ID or registered email.';
    if(!mb_check_encoding($identifier,'UTF-8'))return 'Enter your assigned school ID or registered email.';
    if(mb_strlen(trim($identifier))>190)return 'Use no more than 190 characters.';
    if(preg_match('/[\x00-\x1f\x7f]/u',trim($identifier)))return 'Remove unsupported characters from your school ID or email.';
    if(str_contains($identifier,'@')&&!filter_var(trim($identifier),FILTER_VALIDATE_EMAIL))return 'Enter a complete email address, such as name@school.edu.';
    return null;
}
function cbSignInErrors(array $input): array {
    $errors=[];$identifier=$input['identifier']??'';$password=$input['password']??'';
    if(($identifierError=cbIdentifierError($identifier))!==null)$errors['identifier']=$identifierError;
    if(!is_string($password)||$password==='')$errors['password']='Enter your password.';
    elseif(strlen($password)>72||str_contains($password,"\0"))$errors['password']='Use the password assigned to this account (up to 72 bytes).';
    return $errors;
}
function cbLoginAttempt(PDO $db,string $role,string $identifier,string $ip): void {
    // No plaintext identifiers or IP addresses are stored in the throttle table.
    $buckets=[hash('sha256','identity|'.$role.'|'.mb_strtolower(trim($identifier)))=>10,hash('sha256','ip|'.$ip)=>100];
    foreach($buckets as $bucket=>$limit){
        cbQuery($db,'INSERT INTO classbook_login_limits(bucket,attempts,window_started) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE attempts=IF(window_started<DATE_SUB(NOW(),INTERVAL 15 MINUTE),1,attempts+1),window_started=IF(window_started<DATE_SUB(NOW(),INTERVAL 15 MINUTE),NOW(),window_started)',[$bucket]);
        if((int)cbQuery($db,'SELECT attempts FROM classbook_login_limits WHERE bucket=?',[$bucket])->fetchColumn()>$limit)throw new RuntimeException('Too many sign-in attempts. Wait 15 minutes before trying again.');
    }
    cbQuery($db,'DELETE FROM classbook_login_limits WHERE window_started<DATE_SUB(NOW(),INTERVAL 1 DAY)');
}
