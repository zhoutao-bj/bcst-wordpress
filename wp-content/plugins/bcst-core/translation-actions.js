(() => {
    'use strict';
    const config = bcstTranslationActions;
    if (config.media && !document.querySelector('#the-list')) {
        const note = document.createElement('div');note.className='notice notice-info';
        const p=document.createElement('p'), a=document.createElement('a');a.className='button button-primary';a.href=config.mediaList;a.textContent='切换列表模式，勾选并翻译媒体说明文字';p.append(a);note.append(p);document.querySelector('.wrap')?.prepend(note);return;
    }
    // Polylang's original checkboxes are deletion controls and may be disabled.
    // Add separate translation checkboxes; never enable deletion checkboxes.
    if (config.kind==='string') {
        document.querySelectorAll('#the-list input[name="strings[]"]').forEach(original=>{
            const label=document.createElement('label'),input=document.createElement('input');input.type='checkbox';input.className='bcst-string-select';input.value=original.value;input.setAttribute('aria-label','选择此公共文字用于翻译');label.title='用于翻译，不用于删除';label.append(input,document.createTextNode('译'));original.parentElement.append(label);
        });
        const head=document.querySelector('thead .check-column');
        if(head){const toggle=document.createElement('input');toggle.type='checkbox';toggle.setAttribute('aria-label','选择本页全部公共文字用于翻译');toggle.addEventListener('change',()=>document.querySelectorAll('.bcst-string-select').forEach(c=>{c.checked=toggle.checked;}));head.append(toggle);}
    }
    const bar=document.createElement('span');bar.style.cssText='display:inline-flex;gap:8px;margin-left:8px;align-items:center;flex-wrap:wrap';
    const status=document.createElement('span');status.setAttribute('role','status');
    function button(label,all){const b=document.createElement('button');b.type='button';b.className='button button-primary';b.textContent=label;b.addEventListener('click',()=>select(all));bar.append(b);}
    if(config.kind==='settings')button('翻译工业站文案（先确认）',true);
    else {button('翻译所选内容（先确认）',false);if(config.kind==='taxonomy')button('翻译全部主语言分类 / 标签',true);if(config.kind==='string')button('翻译全部公共文字（先确认）',true);}
    bar.append(status);
    const filter=document.querySelector('#post-query-submit, input[name="filter_action"], button[name="filter_action"]');
    const actions=document.querySelector('.tablenav.top .alignleft.actions');
    if(filter)filter.after(bar);else if(actions)actions.after(bar);else document.querySelector('.wrap h1')?.after(bar);
    async function select(all){
        const selector=config.kind==='string'?'.bcst-string-select:checked':config.kind==='taxonomy'?'#the-list input[name="delete_tags[]"]:checked':'#the-list input[name="post[]"]:checked, #the-list input[name="media[]"]:checked';
        const ids=Array.from(document.querySelectorAll(selector),c=>c.value);
        if(!all&&!ids.length){status.textContent='请先勾选需要翻译的内容。';return;}
        if(all&&!confirm(config.kind==='settings'?'将读取已保存的工业站文案，请先保存页面中的修改。下一页选择语言后创建任务。继续吗？':'将收集此类型的全部内容，下一页选择目标语言并确认，不会立即调用接口。继续吗？'))return;
        const data=new URLSearchParams({action:'bcst_tx_select',nonce:config.nonce,kind:config.kind,taxonomy:config.taxonomy,all:all?'1':'0'});ids.forEach(id=>data.append('ids[]',id));
        bar.querySelectorAll('button').forEach(b=>{b.disabled=true;});status.textContent='正在检查配置…';
        try{const res=await fetch(config.url,{method:'POST',credentials:'same-origin',body:data});const result=await res.json();if(!res.ok||!result.success)throw Error(result.data?.message||'请求失败，请刷新后重试。');location.assign(result.data.url);}
        catch(error){status.textContent=error.message;bar.querySelectorAll('button').forEach(b=>{b.disabled=false;});}
    }
})();
