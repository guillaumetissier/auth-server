// Resource types page: one dialog to create a type, create a facet, or edit a type or facet, and a
// confirmation before a removal.
// Sections of the form are shown per mode; hidden sections are disabled so they are not submitted.
(() => {
  // Close a dialog with its Cancel button or a click on the backdrop (outside its content).
  for (const modal of document.querySelectorAll('dialog.modal')) {
    modal.querySelector('[data-close-dialog]')?.addEventListener('click', () => modal.close());
    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        modal.close();
      }
    });
  }

  // Removal: the row's form waits for the confirmation, then is submitted as it is.
  const confirm = document.getElementById('rt-confirm');
  if (confirm) {
    let pending = null;
    for (const removeForm of document.querySelectorAll('form[data-confirm-remove]')) {
      removeForm.addEventListener('submit', (event) => {
        event.preventDefault();
        pending = removeForm;
        const { code, label, facet } = removeForm.dataset;
        confirm.querySelector('h2').textContent = `Remove the ${facet === '1' ? 'facet' : 'type'} ${code}?`;
        confirm.querySelector('#rt-confirm-text').textContent =
          `"${label}" (${code}) will no longer be accepted in access expressions.`;
        confirm.showModal();
        confirm.querySelector('[data-close-dialog]').focus(); // Cancel by default: Enter does not remove.
      });
    }
    confirm.querySelector('[data-confirm]').addEventListener('click', () => {
      confirm.close();
      pending?.submit(); // submit() does not fire the submit event again
    });
  }

  const dialog = document.getElementById('rt-dialog');
  if (!dialog) {
    return;
  }
  const form = dialog.querySelector('form');
  const field = (name) => form.elements.namedItem(name);
  const sections = (name) => form.querySelectorAll(`[data-for="${name}"]`);

  function show(name, visible) {
    for (const section of sections(name)) {
      section.hidden = !visible;
      for (const input of section.querySelectorAll('input, select')) {
        input.disabled = !visible;
      }
    }
  }

  function open(mode, data) {
    form.reset();
    const isFacet = mode === 'facet' || (mode === 'edit' && data.facet === '1');
    field('mode').value = mode === 'edit' ? 'edit' : 'create';

    show('type', mode !== 'facet');
    show('type-create', mode === 'type');
    show('facet-create', mode === 'facet');
    show('facet', isFacet);

    const code = field('code');
    code.readOnly = mode === 'edit';
    if (mode === 'edit') {
      code.value = data.code;
      field('label').value = data.label ?? '';
      if (isFacet) {
        field('description').value = data.description ?? '';
      }
    }
    field('label').placeholder = isFacet ? 'Prices' : 'Vehicles';

    const titles = { type: 'New type', facet: 'New facet', edit: `Edit ${isFacet ? 'facet' : 'type'} ${data.code ?? ''}` };
    dialog.querySelector('h2').textContent = titles[mode];
    dialog.showModal();
    (mode === 'edit' ? field('label') : mode === 'facet' ? field('facet') : code).focus();
  }

  for (const button of document.querySelectorAll('[data-open-dialog]')) {
    button.addEventListener('click', () => open(button.dataset.openDialog, button.dataset));
  }
})();
