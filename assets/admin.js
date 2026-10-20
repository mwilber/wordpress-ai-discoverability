(() => {
  'use strict';
  const form = document.getElementById('gz-settings-form');
  const status = document.getElementById('gz-save-status');
  let dirty = false;
  form?.addEventListener('input', () => {
    dirty = true;
    status.textContent = 'You have unsaved changes.';
    status.classList.add('is-dirty');
  });
  form?.addEventListener('submit', () => { dirty = false; });
  window.addEventListener('beforeunload', (event) => {
    if (dirty) { event.preventDefault(); event.returnValue = ''; }
  });
  document.querySelectorAll('.gz-copy').forEach((button) => {
    button.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(button.dataset.copy);
        button.textContent = 'Copied';
        document.getElementById('gz-announcement').textContent = 'URL copied to clipboard.';
        setTimeout(() => { button.textContent = 'Copy'; }, 1800);
      } catch {
        document.getElementById('gz-announcement').textContent = 'Copy unavailable. Select the URL to copy it.';
      }
    });
  });
  const tabs = [...document.querySelectorAll('.gz-tabs a')];
  tabs.forEach((tab) => tab.addEventListener('click', () => {
    tabs.forEach((item) => item.classList.toggle('is-current', item === tab));
  }));
})();
