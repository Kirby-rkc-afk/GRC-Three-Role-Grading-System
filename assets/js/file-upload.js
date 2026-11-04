'use strict';
document.querySelectorAll('[data-attendance-file]').forEach(panel=>{
    const input=panel.querySelector('input[type="file"]');
    const status=panel.querySelector('[data-file-status]');
    if(!input||!status)return;
    const update=()=>{
        const file=input.files?.[0];
        panel.classList.toggle('has-file',Boolean(file));
        status.textContent=file?'Selected: '+file.name:'No file selected. Choose your attendance report to continue.';
    };
    input.addEventListener('change',update);
    input.form?.addEventListener('reset',()=>setTimeout(update,0));
    update();
});
