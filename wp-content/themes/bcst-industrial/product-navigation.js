/* Links navigate; separate buttons expand categories on touch and keyboard. */
(() => {
  document.querySelectorAll('.bcst-product-nav').forEach(root => {
    const buttons = [...root.querySelectorAll('.bcst-category-toggle')];
    const mobile = () => window.matchMedia('(max-width:850px)').matches;
    const close = li => {
      li.classList.remove('bcst-open');
      li.classList.add('bcst-closed');
      li.querySelectorAll('.bcst-category-toggle').forEach(b => {
        b.setAttribute('aria-expanded', 'false');
        b.parentElement.classList.remove('bcst-open');
      });
    };
    const open = button => {
      const li = button.parentElement;
      [...li.parentElement.children].filter(s => s !== li).forEach(close);
      li.classList.remove('bcst-closed', 'bcst-fly-left', 'bcst-align-right');
      li.classList.add('bcst-open');
      button.setAttribute('aria-expanded', 'true');
      if (!mobile()) {
        const menu = button.nextElementSibling;
        if (menu.getBoundingClientRect().right > window.innerWidth - 12) {
          li.classList.add(li === root ? 'bcst-align-right' : 'bcst-fly-left');
        }
      }
    };
    buttons.forEach(button => {
      const li = button.parentElement;
      button.addEventListener('click', () => button.getAttribute('aria-expanded') === 'true' ? close(li) : open(button));
      li.addEventListener('mouseenter', () => { if (!mobile() && window.matchMedia('(hover:hover)').matches) open(button); });
      li.addEventListener('mouseleave', () => { if (!mobile()) close(li); });
      li.addEventListener('focusin', event => { if (!mobile() && event.target === li.querySelector('a')) open(button); });
      li.addEventListener('focusout', event => { if (!li.contains(event.relatedTarget)) close(li); });
    });
    root.addEventListener('keydown', event => {
      if (event.key !== 'Escape') return;
      const li = event.target.closest('li');
      const owner = li.querySelector(':scope > .bcst-category-toggle') || li.parentElement.previousElementSibling;
      if (owner && owner.classList.contains('bcst-category-toggle')) {
        event.preventDefault(); event.stopPropagation();
        owner.focus(); close(owner.parentElement);
      }
    });
    document.addEventListener('click', event => { if (!root.contains(event.target)) close(root); });
    window.addEventListener('resize', () => close(root));
  });
})();
