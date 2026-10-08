(() => {
    'use strict';
    const config = bcstTranslationActions;
    // Native fetch bypasses Polylang's jQuery prefilter. Load its admin
    // settings context so AJAX sees the same registered strings as this page.
    function translationRequest(fields){
        return new URLSearchParams({...fields,pll_ajax_backend:'1',pll_ajax_settings:'1'});
    }
    const languageBar=document.createElement('span');languageBar.style.cssText='display:inline-flex;gap:6px;margin:0 8px;align-items:center';
    const languageSelect=document.createElement('select');languageSelect.setAttribute('aria-label','筛选列表语言');languageSelect.name='lang';
    languageSelect.add(new Option('全部语言','all'));
    Object.entries(bcstTranslationLanguages.languages).forEach(([code,language])=>languageSelect.add(new Option(language.name,code)));
    languageSelect.value=bcstTranslationLanguages.selected;
    languageSelect.addEventListener('change',()=>{const url=new URL(location.href);url.searchParams.set('lang',languageSelect.value);['paged','action','action2','_wpnonce','_wp_http_referer'].forEach(key=>url.searchParams.delete(key));location.assign(url.href);});
    languageBar.append(languageSelect);
    if(config.kind!=='settings'&&config.kind!=='string'){
        const anchor=document.querySelector('#post-query-submit, input[name="filter_action"], button[name="filter_action"]');
        if(anchor)anchor.before(languageBar);else (document.querySelector('.tablenav.top .alignleft.actions')||document.querySelector('.wrap h1'))?.after(languageBar);
    }
    if (config.media && !document.querySelector('#the-list')) {
        const note = document.createElement('div');note.className='notice notice-info';
        const p=document.createElement('p'), a=document.createElement('a');a.className='button button-primary';a.href=config.mediaList;a.textContent='切换列表模式，勾选并翻译媒体说明文字';p.append(a);note.append(p);document.querySelector('.wrap')?.prepend(note);return;
    }
    // Default terms get a translation-only checkbox, with no deletion field name.
    if(config.kind==='taxonomy'){
        document.querySelectorAll('#the-list tr[id^="tag-"]').forEach(row=>{
            if(row.querySelector('input[name="delete_tags[]"]:not(:disabled)'))return;
            const cell=row.querySelector('.check-column');if(!cell)return;
            const input=document.createElement('input');input.type='checkbox';input.className='bcst-term-select';input.value=row.id.slice(4);input.setAttribute('aria-label','选择此分类用于翻译');input.title='仅用于翻译，不用于删除';cell.append(input);
        });
    }
    if (config.kind==='string') {
        document.querySelectorAll('#the-list input[name="strings[]"]').forEach(original=>{
            if(!Object.prototype.hasOwnProperty.call(config.strings,original.value)){
                original.disabled=true;
                original.title='格式代码不参与自动翻译，可手动维护右侧格式。';
                return;
            }
            // Reuse the existing row selector. Protected strings must never be
            // submitted to Polylang's delete handler, even when selected.
            if(original.disabled){original.removeAttribute('name');original.disabled=false;}
            original.classList.add('bcst-string-select');
            original.setAttribute('aria-label','选择此公共文字');
            const sourceInput=document.getElementById(`${config.sourceLanguage}-${original.value}`);
            if(sourceInput&&!sourceInput.value.trim()&&typeof config.strings[original.value]==='string'){
                sourceInput.value=config.strings[original.value];
            }
        });
        // Keep WordPress's existing header/footer select-all controls.
    }
    const bar=document.createElement('span');bar.style.cssText='display:inline-flex;gap:8px;margin-left:8px;align-items:center;flex-wrap:wrap';
    const status=document.createElement('span');status.setAttribute('role','status');
    function button(label){const b=document.createElement('button');b.type='button';b.className='button button-primary';b.textContent=label;b.addEventListener('click',()=>select());bar.append(b);}
    button('翻译');
    bar.append(status);
    const filter=document.querySelector('#post-query-submit, input[name="filter_action"], button[name="filter_action"]');
    const actions=document.querySelector('.tablenav.top .alignleft.actions');
    if(filter)filter.after(bar);else if(languageBar.isConnected)languageBar.after(bar);else if(actions)actions.after(bar);else document.querySelector('.wrap h1')?.after(bar);
    let busy=false,dirty=false;
    {
        window.addEventListener('beforeunload',event=>{if(busy||dirty){event.preventDefault();event.returnValue='';}});
        document.querySelector('#the-list')?.closest('form')?.addEventListener('submit',event=>{if(busy){event.preventDefault();status.textContent='翻译中，请等待完成再保存或筛选。';}else dirty=false;});
    }
    async function inlineTranslate(ids){
        if(busy)return;
        const jobs=[];
        for(const id of ids){
            for(const lang of Object.keys(bcstTranslationLanguages.languages)){
                if(lang===config.sourceLanguage)continue;
                const input=document.getElementById(`${lang}-${id}`);
                if(!input){status.textContent='页面缺少目标语言输入框，请保存当前修改后刷新页面重试。';return;}
                if(!input.disabled&&!input.readOnly&&!input.value.trim())jobs.push({id,lang,input,original:config.strings[id]});
            }
        }
        if(!jobs.length){status.textContent='所选内容的其他语言已有译文，无需翻译。';return;}
        busy=true;const source=config.sourceLanguage;
        bar.querySelectorAll('button,select').forEach(el=>el.disabled=true);languageSelect.disabled=true;
        let done=0;
        try{
            for(const job of jobs){
                if(job.input.value.trim())continue;
                status.textContent=`正在翻译 ${done+1}/${jobs.length}（${bcstTranslationLanguages.languages[job.lang].name}）…`;
                const data=translationRequest({action:'bcst_tx_inline',nonce:config.nonce,id:job.id,original:job.original,source,target:job.lang});
                const response=await fetch(config.url,{method:'POST',credentials:'same-origin',body:data});
                const result=await response.json();
                if(!response.ok||!result.success)throw Error(result.data?.message||'请求失败，请重试。');
                if(!job.input.value.trim()){job.input.value=result.data.text;job.input.dispatchEvent(new Event('input',{bubbles:true}));dirty=true;}
                done++;
            }
            status.textContent=`已填入 ${done} 项译文，请点击页面底部“保存更改”。`;
        }catch(error){status.textContent=`已填入 ${done} 项，已停止：${error.message} 已填内容可先保存，再重试空白项。`;}
        finally{busy=false;bar.querySelectorAll('button,select').forEach(el=>el.disabled=false);languageSelect.disabled=false;}
    }
    let activeRun='',activeSelection='';
    async function request(fields,ids=[]){
        const data=translationRequest({...fields,nonce:config.nonce});
        ids.forEach(id=>data.append('ids[]',id));
        const response=await fetch(config.url,{method:'POST',credentials:'same-origin',body:data});
        const result=await response.json();
        if(!response.ok||!result.success)throw Error(result.data?.message||'请求失败，请稍后重试。');
        return result.data;
    }
    async function select(){
        if(busy)return;
        const selector=config.kind==='string'?'.bcst-string-select:checked':config.kind==='taxonomy'?'#the-list input[name="delete_tags[]"]:checked, #the-list .bcst-term-select:checked':'#the-list input[name="post[]"]:checked, #the-list input[name="media[]"]:checked';
        const ids=Array.from(document.querySelectorAll(selector)).filter(c=>!c.disabled).map(c=>c.value);
        if(!ids.length){status.textContent='请先勾选需要翻译的内容。';return;}
        if(config.kind==='string'){await inlineTranslate(ids);return;}
        const selection=JSON.stringify([...ids].sort());
        busy=true;bar.querySelectorAll('button').forEach(b=>b.disabled=true);languageSelect.disabled=true;
        status.textContent='正在检查配置和所选内容…';
        try{
            if(!activeRun||activeSelection!==selection){
                const started=await request({action:'bcst_tx_start',kind:config.kind,taxonomy:config.taxonomy},ids);
                activeRun=started.run;activeSelection=selection;status.textContent=started.message;
            }
            // One bounded fragment per request prevents long content timing out.
            // The next request is issued only after the previous one has completed.
            while(activeRun){
                const progress=await request({action:'bcst_tx_step',run:activeRun});
                status.textContent=progress.message;
                if(progress.halted){
                    status.textContent+='；修正问题后再次点击翻译可继续，已保存译文保留。';
                    break;
                }
                if(!progress.pending){activeRun='';activeSelection='';break;}
            }
        }catch(error){status.textContent='已停止：'+error.message+' 已保存译文保留，不会自动重试。';}
        finally{busy=false;bar.querySelectorAll('button').forEach(b=>b.disabled=false);languageSelect.disabled=false;}
    }
})();
