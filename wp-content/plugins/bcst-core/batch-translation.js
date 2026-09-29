(() => {
  const run = document.getElementById('bcst-run');
  if (!run) return;
  const retry = document.getElementById('bcst-retry');
  const output = document.getElementById('bcst-progress');
  let stopped = true, busy = false;
  document.getElementById('bcst-pause').onclick = () => { stopped = true; };
  async function execute(retrying = false) {
    if (busy) return;
    busy = true; stopped = false; run.disabled = retry.disabled = true;
    try {
      do {
        const body = new URLSearchParams({action:'bcst_bt_step',nonce:bcstBatch.nonce,retry:retrying?'1':'0'});
        const res = await fetch(bcstBatch.url,{method:'POST',credentials:'same-origin',body});
        const data = await res.json();
        if (!data.success) throw new Error(data.data?.message || '请求失败，请刷新页面后继续。');
        output.textContent = data.data.message;
        retrying = false;
        if (!data.data.pending) break;
        await new Promise(resolve => setTimeout(resolve, 500));
      } while (!stopped);
    } catch (error) { output.textContent += '\n已暂停：' + error.message + '。不会自动重试。'; }
    finally { busy = false; stopped = true; run.disabled = retry.disabled = false; }
  }
  run.onclick = () => execute();
  retry.onclick = () => { if (confirm('重试可能产生额外费用。继续吗？')) execute(true); };
})();
