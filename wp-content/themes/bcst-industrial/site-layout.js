(() => {
  document.querySelectorAll('.bcst-header-language').forEach(picker => {
    picker.addEventListener('mouseenter', () => { if (matchMedia('(hover:hover)').matches) picker.open = true; });
    picker.addEventListener('mouseleave', () => { if (!picker.contains(document.activeElement)) picker.open = false; });
    picker.addEventListener('focusout', event => { if (!picker.contains(event.relatedTarget)) picker.open = false; });
    picker.addEventListener('keydown', event => {
      if (event.key === 'Escape') { picker.open = false; picker.querySelector('summary').focus(); event.preventDefault(); }
    });
    document.addEventListener('click', event => { if (!picker.contains(event.target)) picker.open = false; });
  });
  document.querySelectorAll('.bcst-main-menu .bcst-category-toggle').forEach(button => {
    if (button.nextElementSibling?.id) button.setAttribute('aria-controls', button.nextElementSibling.id);
  });
})();
