// Asks before submitting a form marked with data-confirm-title (and data-confirm-text): the dialog of
// templates/admin/_confirm.html.twig. Without this script, such forms submit directly.
(() => {
  const dialog = document.getElementById('confirm-dialog');
  if (!dialog) {
    return;
  }
  let pending = null;
  for (const form of document.querySelectorAll('form[data-confirm-title]')) {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      pending = form;
      dialog.querySelector('h2').textContent = form.dataset.confirmTitle;
      dialog.querySelector('[data-confirm-text]').textContent = form.dataset.confirmText ?? '';
      dialog.showModal();
      dialog.querySelector('[data-close-dialog]').focus(); // Cancel by default: Enter does not confirm.
    });
  }
  dialog.querySelector('[data-confirm]').addEventListener('click', () => {
    dialog.close();
    pending?.submit(); // submit() does not fire the submit event again
  });
  dialog.querySelector('[data-close-dialog]').addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) {
      dialog.close();
    }
  });
})();
