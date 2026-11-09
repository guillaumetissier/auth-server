// Profiles pages: one dialog to create or edit a profile. Opened by buttons with data-open-profile
// ("create", or "edit" with data-code, data-name, data-rights, data-description), or at once when the
// server sends it back open (data-open: an error to correct, or rights of users to confirm).
(() => {
  const dialog = document.getElementById('profile-dialog');
  if (!dialog) {
    return;
  }
  const form = dialog.querySelector('form');
  const field = (name) => form.elements.namedItem(name);
  const submit = form.querySelector('[type="submit"]');

  function open(mode, data) {
    // A fresh dialog: drop what the server added (error, rights to confirm).
    for (const element of form.querySelectorAll('[data-dialog-message]')) {
      element.remove();
    }
    form.reset();
    const isEdit = mode === 'edit';
    form.action = isEdit ? form.dataset.editAction.replace('__code__', encodeURIComponent(data.code)) : form.dataset.createAction;
    field('code').readOnly = isEdit;
    for (const name of ['code', 'name', 'rights', 'description']) {
      field(name).value = isEdit ? (data[name] ?? '') : '';
    }
    // Translated texts: data attributes of the dialog.
    dialog.querySelector('h2').textContent = isEdit ? dialog.dataset.titleEdit.replaceAll('{name}', data.name) : dialog.dataset.titleCreate;
    submit.textContent = isEdit ? dialog.dataset.labelSave : dialog.dataset.labelCreate;
    dialog.showModal();
    field('name').focus();
  }

  for (const button of document.querySelectorAll('[data-open-profile]')) {
    button.addEventListener('click', () => open(button.dataset.openProfile, button.dataset));
  }
  dialog.querySelector('[data-close-dialog]').addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) {
      dialog.close();
    }
  });
  // Changing the rights after an impact was shown: the confirmation no longer applies.
  field('rights').addEventListener('input', () => {
    form.querySelector('input[name="confirmed"]')?.remove();
    submit.textContent = field('code').readOnly ? dialog.dataset.labelSave : dialog.dataset.labelCreate;
  });

  if (dialog.hasAttribute('data-open')) {
    dialog.showModal();
  }
})();
