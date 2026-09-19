// Модальное окно быстрого редактирования контакта (change contacts-crud):
// открытие по клику на кнопку «Изменить» карточки контакта, заполнение формы
// данными data-атрибутов обёртки карточки, закрытие по крестику/оверлею/
// Esc/«Отмене». Сохранение — AJAX (fetch), ошибки валидации выводятся под
// полями, карточка заменяется отрисованным с сервера HTML без перезагрузки
// страницы.

const modal = document.querySelector('[data-contact-edit-modal] .modal');

if (modal) {
    const form = modal.querySelector('[data-contact-edit-form]');
    const fields = {
        name: modal.querySelector('[data-contact-field="name"]'),
        phone: modal.querySelector('[data-contact-field="phone"]'),
        email: modal.querySelector('[data-contact-field="email"]'),
        position: modal.querySelector('[data-contact-field="position"]'),
        notes: modal.querySelector('[data-contact-field="notes"]'),
        isMain: modal.querySelector('[data-contact-field="isMain"]'),
    };
    const deleteLink = modal.querySelector('[data-contact-delete-link]');
    let activeRow = null;

    const clearErrors = () => {
        modal.querySelectorAll('[data-contact-error]').forEach((span) => {
            span.hidden = true;
            span.textContent = '';
        });
    };

    const open = (row) => {
        activeRow = row;
        form.action = `/contacts/${row.dataset.contactId}/edit`;
        if (deleteLink) {
            deleteLink.href = `/contacts/${row.dataset.contactId}/delete`;
        }
        Object.entries(fields).forEach(([key, input]) => {
            if (!input) {
                return;
            }
            const value = row.dataset[`contact${key[0].toUpperCase()}${key.slice(1)}`] ?? '';
            if (input.type === 'checkbox') {
                input.checked = value === '1';
            } else {
                input.value = value;
            }
        });
        clearErrors();
        modal.hidden = false;
        (fields.name ?? form).focus();
    };

    const close = () => {
        modal.hidden = true;
        activeRow = null;
    };

    // Открытие: делегирование, кнопка внутри обёртки карточки контакта.
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-contact-edit]');
        if (trigger) {
            event.preventDefault();
            open(trigger.closest('[data-contact-card-wrap]'));
            return;
        }
        // Закрытие по «Отмене»/крестику/оверлею только для этого окна.
        if (!modal.hidden && event.target.closest('[data-modal-close]')?.closest('[data-contact-edit-modal]')) {
            close();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            close();
        }
    });

    // AJAX-отправка формы модального окна.
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!activeRow) {
            return;
        }

        const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        let payload = { ok: false, errors: {} };
        try {
            payload = await response.json();
        } catch {
            // Ответ не JSON — оставляем значения по умолчанию.
        }

        if (!payload.ok) {
            clearErrors();
            Object.entries(payload.errors ?? {}).forEach(([field, message]) => {
                const span = modal.querySelector(`[data-contact-error="${field}"]`);
                if (span) {
                    span.textContent = message;
                    span.hidden = false;
                }
            });
            return;
        }

        // Обновление карточек организации на дашборде без перезагрузки
        // страницы: сервер возвращает отрисованную сетку контактов целиком —
        // при смене «Основной контакт» подсветка, бейдж и порядок обновляются
        // у всех карточек организации.
        if (payload.grid && activeRow.parentNode) {
            activeRow.parentNode.outerHTML = payload.grid;
        }

        close();
    });
}

// Модальное окно быстрого создания контакта (fix contacts-crud):
// открытие по клику на «Добавить контакт» организации, предвыбор организации,
// сохранение — AJAX (fetch), ошибки валидации под полями, перезагрузка
// панели после успеха (новый контакт появляется в списке организации).
const createModal = document.querySelector('[data-contact-create-modal] .modal');

if (createModal) {
    const createForm = createModal.querySelector('[data-contact-create-form]');
    const createFields = {
        organization: createModal.querySelector('[data-contact-field="organization"]'),
        name: createModal.querySelector('[data-contact-field="name"]'),
        phone: createModal.querySelector('[data-contact-field="phone"]'),
        email: createModal.querySelector('[data-contact-field="email"]'),
        position: createModal.querySelector('[data-contact-field="position"]'),
        notes: createModal.querySelector('[data-contact-field="notes"]'),
    };

    const clearCreateErrors = () => {
        createModal.querySelectorAll('[data-contact-error]').forEach((span) => {
            span.hidden = true;
            span.textContent = '';
        });
    };

    const openCreate = (orgId) => {
        createForm.reset();
        clearCreateErrors();
        if (orgId && createFields.organization) {
            createFields.organization.value = orgId;
            // Синхронизировать подпись поискового выпадающего списка (combobox).
            createFields.organization.dispatchEvent(new Event('change'));
        }
        createModal.hidden = false;
        (createFields.name ?? createForm).focus();
    };

    const closeCreate = () => {
        createModal.hidden = true;
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-contact-create]');
        if (trigger) {
            event.preventDefault();
            openCreate(trigger.dataset.orgId ?? null);
            return;
        }
        if (!createModal.hidden && event.target.closest('[data-modal-close]')?.closest('[data-contact-create-modal]')) {
            closeCreate();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !createModal.hidden) {
            closeCreate();
        }
    });

    createForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const response = await fetch(createForm.action, {
            method: 'POST',
            body: new FormData(createForm),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        let payload = { ok: false, errors: {} };
        try {
            payload = await response.json();
        } catch {
            // Ответ не JSON — оставляем значения по умолчанию.
        }

        if (!payload.ok) {
            clearCreateErrors();
            Object.entries(payload.errors ?? {}).forEach(([field, message]) => {
                const span = createModal.querySelector(`[data-contact-error="${field}"]`);
                if (span) {
                    span.textContent = message;
                    span.hidden = false;
                }
            });
            return;
        }

        // Новый контакт появляется в списке организации на панели.
        window.location.reload();
    });
}
