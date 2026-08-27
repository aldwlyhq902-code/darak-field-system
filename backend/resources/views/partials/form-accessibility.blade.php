<script nonce="{{ $cspNonce }}">
(()=>{
    const controls='input:not([type="hidden"]),select,textarea';
    document.querySelectorAll('label:not([for])').forEach((label,index)=>{
        const control=label.querySelector(controls)||(label.nextElementSibling?.matches(controls)?label.nextElementSibling:null);
        if(!control)return;
        if(!control.id)control.id=`accessible-field-${index}`;
        label.htmlFor=control.id;
    });
    document.querySelectorAll(controls).forEach(control=>{
        if(control.labels?.length||control.hasAttribute('aria-label')||control.hasAttribute('aria-labelledby'))return;
        const name=control.getAttribute('placeholder')||control.getAttribute('title');
        if(name)control.setAttribute('aria-label',name);
    });
    document.querySelectorAll('.table-wrap').forEach(region=>{
        if(!region.hasAttribute('tabindex'))region.tabIndex=0;
        if(!region.hasAttribute('role'))region.setAttribute('role','region');
        if(!region.hasAttribute('aria-label'))region.setAttribute('aria-label',@js(app()->isLocale('ar') ? 'جدول قابل للتمرير أفقيًا' : 'Horizontally scrollable table'));
    });
})();
</script>
