'use strict';
// Progressive enhancement: navigation stays available when JavaScript is off.
document.querySelectorAll('.portal-nav').forEach(nav=>{
    const toggle=nav.querySelector('.workspace-toggle');
    const links=nav.querySelector('.workspace-links');
    if(!toggle||!links)return;
    const current=links.querySelector('[aria-current="page"]');
    if(current){
        const label=current.classList.contains('account-profile-link')?'Account settings':current.querySelector('span')?.textContent.trim();
        if(label)toggle.querySelector('span>span').textContent=label;
    }
    nav.classList.add('navigation-ready');
    const close=()=>{nav.classList.remove('is-open');toggle.setAttribute('aria-expanded','false');};
    toggle.addEventListener('click',()=>{
        const open=nav.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded',String(open));
    });
    nav.addEventListener('keydown',event=>{
        if(event.key==='Escape'&&nav.classList.contains('is-open')){close();toggle.focus();}
    });
    const desktop=matchMedia('(min-width:761px)');
    desktop.addEventListener('change',()=>{
        // Keep keyboard focus on a visible control when crossing the breakpoint.
        const active=document.activeElement;
        close();
        if(desktop.matches&&active===toggle)links.querySelector('a')?.focus();
        else if(!desktop.matches&&links.contains(active))toggle.focus();
    });
});
// GET filters apply immediately; POST forms always keep explicit confirmation.
document.querySelectorAll('form[data-auto-filter][method="get"]').forEach(form=>{
    let timer;
    const focusKey='grc-filter-focus:'+location.pathname;
    // Preserve typing position across the server-rendered search response.
    try{
        const saved=JSON.parse(sessionStorage.getItem(focusKey)||'null');
        sessionStorage.removeItem(focusKey);
        const field=saved&&form.elements.namedItem(saved.name);
        if(field){field.focus({preventScroll:true});if(typeof saved.start==='number'&&field.setSelectionRange)field.setSelectionRange(saved.start,saved.end);}
    }catch{/* Search remains usable if session storage is unavailable. */}
    form.addEventListener('submit',()=>{
        clearTimeout(timer);
        const field=document.activeElement;
        if(field?.form===form&&field.name){
            try{sessionStorage.setItem(focusKey,JSON.stringify({name:field.name,start:field.selectionStart,end:field.selectionEnd}));}catch{}
        }
    });
    form.querySelectorAll('select').forEach(select=>select.addEventListener('change',()=>{clearTimeout(timer);form.requestSubmit();}));
    form.querySelectorAll('input[type="search"]').forEach(input=>{
        let composing=false;
        const search=()=>{clearTimeout(timer);if(!composing)timer=setTimeout(()=>form.requestSubmit(),450);};
        input.addEventListener('input',search);
        input.addEventListener('compositionstart',()=>{composing=true;clearTimeout(timer);});
        input.addEventListener('compositionend',()=>{composing=false;search();});
    });
});
document.querySelectorAll('[data-confirm]').forEach(form=>form.addEventListener('submit',event=>{if(!confirm(form.dataset.confirm))event.preventDefault();}));
document.querySelectorAll('[data-mark-present]').forEach(button=>button.addEventListener('click',()=>{button.closest('form').querySelectorAll('[data-attendance]').forEach(field=>field.value='present');}));
document.querySelectorAll('[data-search-table]').forEach(input=>{
    const table=document.querySelector(input.dataset.searchTable);
    if(!table)return;
    const feedback=document.createElement('p');
    feedback.className='muted table-search-feedback';
    feedback.setAttribute('role','status');
    feedback.setAttribute('aria-live','polite');
    input.closest('label')?.insertAdjacentElement('afterend',feedback);
    input.addEventListener('input',()=>{
        const query=input.value.trim().toLowerCase();
        let visible=0;
        table.querySelectorAll('tbody tr').forEach(row=>{
            if(!row.querySelector('td'))return;
            row.hidden=!row.textContent.toLowerCase().includes(query);
            if(!row.hidden)visible++;
        });
        feedback.textContent=query?(visible?visible+' matching student'+(visible===1?'':'s')+'.':'No students match your search. Clear the search to see all students.'):'';
    });
});
