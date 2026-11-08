// Opens a dialog from a button: data-dialog="<dialog id>", and optionally data-fields='{"name": value}' to
// fill the dialog's form (checkboxes take true/false). Without data-fields, the form is reset.
// Cancel buttons (data-close-dialog) and a click on the backdrop close the dialog.
(() => {
  for (const button of document.querySelectorAll('[data-dialog]')) {
    const dialog = document.getElementById(button.dataset.dialog);
    if (!dialog) {
      continue;
    }
    button.addEventListener('click', () => {
      const form = dialog.querySelector('form');
      if (form) {
        form.reset();
        const fields = button.dataset.fields ? JSON.parse(button.dataset.fields) : {};
        for (const [name, value] of Object.entries(fields)) {
          const input = form.elements.namedItem(name);
          if (input instanceof HTMLInputElement && input.type === 'checkbox') {
            input.checked = Boolean(value);
          } else if (input) {
            input.value = value ?? '';
          }
        }
      }
      dialog.showModal();
      dialog.querySelector('input:not([type=hidden]):not([readonly]), select')?.focus();
    });
  }

  for (const dialog of document.querySelectorAll('dialog.modal')) {
    if (dialog.dataset.closeWired) {
      continue;
    }
    dialog.dataset.closeWired = '1';
    for (const cancel of dialog.querySelectorAll('[data-close-dialog]')) {
      cancel.addEventListener('click', () => dialog.close());
    }
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) {
        dialog.close();
      }
    });
  }
})();
