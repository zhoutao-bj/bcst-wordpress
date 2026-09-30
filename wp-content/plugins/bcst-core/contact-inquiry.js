(function () {
    'use strict';
    const dialog = document.getElementById('bcst-inquiry-dialog');
    if (!dialog) return;
    const form = dialog.querySelector('form');
    const submit = form.querySelector('[type="submit"]');
    const status = form.querySelector('.bcst-inquiry-status');
    let trigger, busy = false;
    document.addEventListener('click', async function (event) {
        const button = event.target.closest('.bcst-inquiry-open');
        if (!button) return;
        event.preventDefault();
        trigger = button;
        if (!dialog.open) dialog.showModal();
        document.documentElement.classList.add('bcst-modal-open');
        if (busy) return;
        status.textContent = '';
        submit.disabled = true;
        form.elements.source.value = location.href;
        const campaign = new URLSearchParams();
        new URLSearchParams(location.search).forEach((value, key) => {
            if (/^utm_/.test(key)) campaign.append(key, value);
        });
        form.elements.campaign.value = campaign.toString();
        try {
            const response = await fetch(bcstInquiry.url, {method: 'POST', credentials: 'same-origin', body: new URLSearchParams({action: 'bcst_inquiry_token'})});
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error();
            form.elements.bcst_nonce.value = result.data.nonce;
            submit.disabled = false;
        } catch (_) { status.textContent = bcstInquiry.error; }
    });
    dialog.querySelector('.bcst-inquiry-close').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', function (event) {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
    });
    dialog.addEventListener('close', function () {
        document.documentElement.classList.remove('bcst-modal-open');
        if (trigger) trigger.focus();
    });
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        busy = true; submit.disabled = true; status.textContent = bcstInquiry.sending;
        try {
            const response = await fetch(bcstInquiry.url, {method: 'POST', credentials: 'same-origin', body: new FormData(form)});
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.data && result.data.message || bcstInquiry.error);
            status.textContent = result.data.message;
            ['name', 'email', 'message'].forEach(key => { form.elements[key].value = ''; });
            form.elements.consent.checked = false;
        } catch (error) { status.textContent = error.message || bcstInquiry.error; }
        finally { busy = false; submit.disabled = false; }
    });
}());
