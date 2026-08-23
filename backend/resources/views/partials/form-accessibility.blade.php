<script nonce="{{ $cspNonce }}">
document.querySelectorAll('label:not([for])').forEach((label,index)=>{const control=label.querySelector('input,select,textarea')||(label.nextElementSibling?.matches('input,select,textarea')?label.nextElementSibling:null);if(!control)return;if(!control.id)control.id=`accessible-field-${index}`;label.htmlFor=control.id});
</script>
