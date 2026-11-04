'use strict';
(()=>{
 const form=document.getElementById('signin-form');if(!form)return;
 const identifier=document.getElementById('signin-identifier'),password=document.getElementById('signin-password'),feedback=document.getElementById('signin-feedback'),submit=document.getElementById('signin-submit'),status=document.getElementById('signin-status'),toggle=document.getElementById('password-toggle');
 const fields={identifier,password};
 function error(name,message){const input=fields[name],out=document.getElementById(name+'-error');out.textContent=message;out.hidden=!message;if(message)input.setAttribute('aria-invalid','true');else input.removeAttribute('aria-invalid');}
 toggle.hidden=false;toggle.setAttribute('aria-label','Show password');toggle.addEventListener('click',()=>{const show=password.type==='password';password.type=show?'text':'password';toggle.textContent=show?'Hide':'Show';toggle.setAttribute('aria-pressed',String(show));toggle.setAttribute('aria-label',show?'Hide password':'Show password');});
 password.addEventListener('keyup',event=>{document.getElementById('password-caps').hidden=!event.getModifierState('CapsLock');});password.addEventListener('blur',()=>document.getElementById('password-caps').hidden=true);
 Object.entries(fields).forEach(([name,input])=>input.addEventListener('input',()=>{error(name,'');feedback.hidden=true;}));
 form.addEventListener('submit',event=>{
  let invalid=false;const value=identifier.value.trim();identifier.value=value;
  error('identifier','');error('password','');feedback.hidden=true;
  if(!value){error('identifier','Enter your school ID or registered email.');invalid=true;}
  else if([...value].length>190){error('identifier','Use no more than 190 characters.');invalid=true;}
  else if(/[\x00-\x1f\x7f]/.test(value)){error('identifier','Remove unsupported characters from your school ID or email.');invalid=true;}
  else if(value.includes('@')&&!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)){error('identifier','Enter a complete email address, such as name@school.edu.');invalid=true;}
  if(!password.value){error('password','Enter your password.');invalid=true;}
  else if(new TextEncoder().encode(password.value).length>72||password.value.includes('\0')){error('password','Use the password assigned to this account (up to 72 bytes).');invalid=true;}
  if(invalid){event.preventDefault();feedback.textContent='Check the highlighted fields and try again.';feedback.hidden=false;form.querySelector('[aria-invalid="true"]').focus();return;}
  submit.disabled=true;submit.textContent='Signing in…';status.textContent='Checking your account…';form.setAttribute('aria-busy','true');
 });
 window.addEventListener('pageshow',()=>{submit.disabled=false;submit.textContent='Sign in';status.textContent='';form.removeAttribute('aria-busy');});
 const serverError=form.querySelector('[aria-invalid="true"]');if(serverError)serverError.focus();else if(!feedback.hidden)feedback.focus();
})();
