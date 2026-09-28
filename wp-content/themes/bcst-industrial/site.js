/* Attribution is tab-scoped; no third-party trackers are loaded by this theme. */
(() => {
  const params = new URLSearchParams(window.location.search);
  const campaign = {};
  ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(key => {
    if (params.has(key)) campaign[key] = params.get(key).slice(0, 200);
  });
  let value = '';
  try {
    if (Object.keys(campaign).length) sessionStorage.setItem('bcst_campaign', JSON.stringify(campaign));
    value = sessionStorage.getItem('bcst_campaign') || '';
  } catch (_) { value = Object.keys(campaign).length ? JSON.stringify(campaign) : ''; }
  document.querySelectorAll('.bcst-campaign').forEach(input => { input.value = value; });
  document.querySelectorAll('.whatsapp, a[href^="mailto:"]').forEach(link => {
    link.addEventListener('click', () => {
      window.dispatchEvent(new CustomEvent('bcst:contact', {detail: {channel: link.classList.contains('whatsapp') ? 'whatsapp' : 'email'}}));
    });
  });
})();
