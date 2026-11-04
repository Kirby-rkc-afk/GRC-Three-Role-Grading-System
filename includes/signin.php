<?php
require_once __DIR__.'/portal.php';
$db=null;$ready=false;$error='';$fieldErrors=[];$unavailable=false;
try{$db=getDbConnection();$ready=cbInstalled($db)&&(bool)cbQuery($db,'SELECT COUNT(*) FROM classbook_admins')->fetchColumn();if($ready)cbUpgrade($db);}
catch(Throwable $e){if(!($e instanceof PDOException)||($e->errorInfo[1]??0)!==1049){error_log((string)$e);$unavailable=true;}$db=null;$ready=false;}
if($ready&&getCurrentUser()){$current=getCurrentUser();$valid=portalUser($db,$current['role']);portalRedirect(portalHome($valid['role']));}
if($_SERVER['REQUEST_METHOD']==='POST')try{
    portalCsrf();$fieldErrors=cbSignInErrors($_POST);
    if(!$fieldErrors){
        if(!$ready)throw new RuntimeException('Sign-in is temporarily unavailable. Please contact the Registrar.');
        $identifier=trim($_POST['identifier']);$password=$_POST['password'];
        cbLoginAttempt($db,'all',$identifier,(string)($_SERVER['REMOTE_ADDR']??'local'));
        try{$match=cbResolveLogin($db,$identifier,$password);}
        catch(RuntimeException $e){error_log('Ambiguous grading sign-in identifiers require Registrar review.');throw new RuntimeException('Unable to sign in. Check your school ID or email and password, or contact the Registrar.');}
        if(!$match||cbStatus($db,$match['role'],(int)$match['account']['id'])!=='active'){
            cbAudit($db,['role'=>'guest','id'=>0],null,'failed_sign_in',['entry'=>'shared']);
            throw new RuntimeException('Unable to sign in. Check your school ID or email and password, or contact the Registrar.');
        }
        $role=$match['role'];$account=$match['account'];
        if($role==='professor')loginProfessor($account);elseif($role==='student')loginStudent($account);
        else{session_regenerate_id(true);$_SESSION['user']=['id'=>(int)$account['id'],'role'=>'admin','full_name'=>$account['full_name'],'username'=>$account['username']];}
        cbQuery($db,'DELETE FROM classbook_login_limits WHERE bucket=?',[hash('sha256','identity|all|'.mb_strtolower($identifier))]);
        $_SESSION['csrf_token']=bin2hex(random_bytes(32));$_SESSION['auth_signature']=hash('sha256',$account['password_hash']);$_SESSION['last_active']=time();$_SESSION['auth_started']=time();unset($_SESSION['classbook_import']);
        cbAudit($db,getCurrentUser(),null,'signed_in',['entry'=>'shared']);portalRedirect(portalHome($role));
    }
}catch(Throwable $e){$error=$e instanceof PDOException?'Sign-in is temporarily unavailable. Please try again later.':$e->getMessage();if($e instanceof PDOException)error_log((string)$e);}
portalStart('Sign in');
?><div class="auth-layout"><section class="auth-intro" aria-labelledby="portal-title"><span class="eyebrow">GRC ACADEMIC PORTAL</span><h1 id="portal-title">Your academic record.<br> A clearer view.</h1><p>One account for your assigned workspace. View released results, record classroom work, or manage academic records.</p></section>
<section class="auth-panel" aria-labelledby="signin-title"><h2 id="signin-title">Sign in to your account</h2><p class="auth-caption">Use the details registered with your school.</p>
<div id="signin-feedback" class="auth-feedback" role="alert" tabindex="-1" <?=(!$error&&!$fieldErrors)?'hidden':''?>><?=h($error?:($fieldErrors?'Check the highlighted fields and try again.':''))?></div>
<form method="post" action="index.php" id="signin-form" novalidate><?=csrfField()?>
<div class="auth-field"><label for="signin-identifier">School ID or registered email</label><input id="signin-identifier" name="identifier" type="text" required maxlength="190" autocomplete="username" autocapitalize="none" spellcheck="false" value="<?=h(is_string($_POST['identifier']??null)?$_POST['identifier']:'')?>" aria-describedby="identifier-help identifier-error" <?=isset($fieldErrors['identifier'])?'aria-invalid="true"':''?>><small id="identifier-help">Student or employee ID. Staff may use their assigned username.</small><p class="field-error" id="identifier-error" <?=isset($fieldErrors['identifier'])?'':'hidden'?>><?=h($fieldErrors['identifier']??'')?></p></div>
<div class="auth-field"><label for="signin-password">Password</label><div class="password-control"><input id="signin-password" name="password" type="password" required autocomplete="current-password" aria-describedby="password-error password-caps" <?=isset($fieldErrors['password'])?'aria-invalid="true"':''?>><button type="button" id="password-toggle" class="password-toggle" aria-controls="signin-password" aria-pressed="false" hidden>Show</button></div><p class="field-error" id="password-error" <?=isset($fieldErrors['password'])?'':'hidden'?>><?=h($fieldErrors['password']??'')?></p><small id="password-caps" hidden>Caps Lock is on.</small></div>
<button type="submit" class="auth-submit" id="signin-submit">Sign in</button><p class="auth-submit-status" id="signin-status" role="status" aria-live="polite"></p>
</form><div class="auth-links"><a href="account-activate.php">Activate account or reset password</a><a href="faculty-request.php">Request faculty access</a></div>
<details id="signin-help" class="auth-help"><summary>Need help signing in?</summary><p>Check that your school ID or email matches the account issued by the Registrar. For a new account or a forgotten password, request a one-time activation or reset code after identity verification. Never share your password or code.</p></details></section></div>
<script src="assets/js/signin.js?v=<?=filemtime(__DIR__.'/../assets/js/signin.js')?>" defer></script>
<?php portalEnd();
