<?php
/** Hook-based UI injection. This runs before SLiMS builds the bibliography form. */
// Popup URL is resolved from the registered MeSH plugin menu in SLiMS admin.
?>
<script>
(function(){
  function getPickerUrl(){
    var links=Array.from(document.querySelectorAll('a[href]'));
    var menu=links.find(function(a){
      return (a.textContent||'').trim()==='MeSH Subject Manager' && /[?&]id=/.test(a.getAttribute('href')||'');
    });
    if(!menu) return null;
    var url=new URL(menu.getAttribute('href'),window.location.href);
    url.searchParams.set('msm_picker','1');
    return url;
  }
  function openMeshModal(url){
    var old=document.getElementById('msm-modal-overlay');
    if(old){old.querySelector('iframe').src=url;return;}
    var overlay=document.createElement('div');overlay.id='msm-modal-overlay';
    overlay.setAttribute('role','dialog');overlay.setAttribute('aria-modal','true');overlay.setAttribute('aria-label','Pencarian MeSH');
    overlay.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.58);z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;';
    var panel=document.createElement('div');panel.style.cssText='width:min(960px,96vw);height:min(740px,90vh);background:white;border-radius:8px;box-shadow:0 14px 45px rgba(0,0,0,.3);display:flex;flex-direction:column;overflow:hidden;';
    var head=document.createElement('div');head.style.cssText='display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:#fff;border-bottom:1px solid #ddd;';
    var label=document.createElement('strong');label.textContent='Cari MeSH — Subjek Bibliografi';
    var close=document.createElement('button');close.type='button';close.textContent='✕ Tutup';close.className='btn btn-default notAJAX';close.onclick=function(){overlay.remove();};
    head.appendChild(label);head.appendChild(close);
    var frame=document.createElement('iframe');frame.title='Pencarian MeSH';frame.src=url;frame.style.cssText='width:100%;height:100%;border:0;flex:1;';
    panel.appendChild(head);panel.appendChild(frame);overlay.appendChild(panel);document.body.appendChild(overlay);
    overlay.addEventListener('click',function(e){if(e.target===overlay)overlay.remove();});
    var esc=function(e){if(e.key==='Escape'){overlay.remove();document.removeEventListener('keydown',esc);}};
    document.addEventListener('keydown',esc);close.focus();
  }
  if(!window.msmModalListenerAdded){
    window.msmModalListenerAdded=true;
    window.addEventListener('message',function(event){
      if(event.origin!==window.location.origin || !event.data || event.data.type!=='msm:subjects-saved')return;
      // SLiMS bibliography/index.php saves POST class into biblio.classification.
      // Its native #class change handler also populates #callNumber.
      var numbers=Array.isArray(event.data.nlmNumbers)?Array.from(new Set(event.data.nlmNumbers.filter(function(n){return typeof n==='string' && /^[A-Za-z0-9 .\/-]{1,40}$/.test(n);} ))):[];
      var old=document.getElementById('msm-nlm-apply');if(old)old.remove();
      if(numbers.length){
        var form=document.getElementById('mainForm')||document.querySelector('form[name="mainForm"]')||document;
        var classification=form.querySelector('select[name="class"]')||document.getElementById('class');
        var call=form.querySelector('input[name="callNumber"]')||document.getElementById('callNumber');
        if(classification){
          var bar=document.createElement('div');bar.id='msm-nlm-apply';bar.style.cssText='margin:8px 0;padding:10px;border-left:3px solid #2675b9;background:#eef6ff;';
          var label=document.createElement('span');label.textContent='Nomor klasifikasi NLM (pilih untuk mengisi Classification): ';bar.appendChild(label);
          numbers.forEach(function(number){
            var b=document.createElement('button');b.type='button';b.className='btn btn-sm btn-primary notAJAX';b.style.margin='2px 4px';b.textContent='Gunakan '+number;
            b.onclick=function(){
              var existing=classification.value.trim();
              if(existing && existing!=='NONE' && existing!==number && !window.confirm('Classification sudah berisi '+existing+'. Ganti dengan '+number+'?'))return;
              if(!Array.from(classification.options).some(function(o){return o.value===number;})){
                classification.add(new Option(number,number,true,true));
              }
              classification.value=number;
              // SLiMS uses jQuery/Select2; trigger its native change handler.
              if(window.jQuery){window.jQuery(classification).trigger('change');}
              else{classification.dispatchEvent(new Event('change',{bubbles:true}));}
              // Fallback when the original SLiMS change handler has not yet attached.
              if(call && !window.jQuery){call.value=number;call.dispatchEvent(new Event('input',{bubbles:true}));}
            };
            bar.appendChild(b);
          });
          classification.parentNode.appendChild(bar);
        }
      }
      var iframe=document.getElementById('topicIframe');
      if(iframe){
        try{if(typeof window.setIframeContent==='function')window.setIframeContent('topicIframe',event.data.refreshUrl);else iframe.src=event.data.refreshUrl+(event.data.refreshUrl.indexOf('?')<0?'?':'&')+'refresh='+Date.now();}
        catch(e){iframe.src=event.data.refreshUrl+(event.data.refreshUrl.indexOf('?')<0?'?':'&')+'refresh='+Date.now();}
      }
    });
  }
  function injectMeshButton(){
    var iframe=document.getElementById('topicIframe');
    if(!iframe || !iframe.parentNode) return false;
    var root=iframe.parentNode;
    if(root.querySelector('.msm-hook-button')) return true;
    var btn=document.createElement('button');
    btn.type='button';btn.className='s-btn btn btn-info notAJAX msm-hook-button';
    btn.textContent='Cari MeSH';btn.style.margin='0 0 8px 8px';
    btn.addEventListener('click',function(e){
      e.preventDefault();
      var form=iframe.closest('form')||document.getElementById('mainForm');
      var input=form && (form.querySelector('[name="updateRecordID"]') || form.querySelector('[name="itemID"]'));
      var bid=input ? parseInt(input.value,10)||0 : 0;
      var u=getPickerUrl();
      if(!u){alert('Menu MeSH Subject Manager tidak ditemukan. Pastikan plugin aktif dan menu terlihat.');return;}
      u.searchParams.set('biblioID',String(bid));
      openMeshModal(u.toString());
    });
    var add=Array.from(root.querySelectorAll('a')).find(function(a){return /Add Subject|Tambah Subjek/i.test(a.textContent||'');});
    if(add && add.parentNode){add.insertAdjacentElement('afterend',btn);}else{root.insertBefore(btn,iframe);}
    return true;
  }
  // The hook runs before form rendering, and SLiMS replaces content via AJAX.
  var attempts=0;
  function retry(){if(injectMeshButton())return;if(++attempts<80)setTimeout(retry,150);}
  retry();
})();
</script>
