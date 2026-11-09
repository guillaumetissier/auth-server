// Asks before submitting a form marked with data-confirm-title (and data-confirm-text, data-confirm-button
// for the label of the confirm button, data-confirm-danger="0" for a non-destructive action): the dialog of
// templates/admin/_confirm.html.twig. Without this script, such forms submit directly.
(() => {
  const dialog = document.getElementById('confirm-dialog');
  if (!dialog) {
    return;
  }
  let pending = null;
  const confirmButton = dialog.querySelector('[data-confirm]');
  for (const form of document.querySelectorAll('form[data-confirm-title]')) {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      pending = form;
      dialog.querySelector('h2').textContent = form.dataset.confirmTitle;
      dialog.querySelector('[data-confirm-text]').textContent = form.dataset.confirmText ?? '';
      confirmButton.textContent = form.dataset.confirmButton ?? confirmButton.dataset.defaultLabel;
      confirmButton.classList.toggle('danger', form.dataset.confirmDanger !== '0');
      dialog.showModal();
      dialog.querySelector('[data-close-dialog]').focus(); // Cancel by default: Enter does not confirm.
    });
  }
  confirmButton.addEventListener('click', () => {
    dialog.close();
    pending?.submit(); // submit() does not fire the submit event again
  });
  if (!dialog.dataset.closeWired) {
    dialog.dataset.closeWired = '1';
    dialog.querySelector('[data-close-dialog]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) {
        dialog.close();
      }
    });
  }
})();
