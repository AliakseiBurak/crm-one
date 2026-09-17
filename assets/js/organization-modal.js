// Модальные окна (change organizations-crud): открытие по клику на кнопку
// «Изменить» в строке организации, заполнение формы данными строки,
// закрытие по крестику/оверлею/Esc/«Отмене». Сохранение — AJAX (fetch),
// ошибки валидации выводятся под полями, таблица обновляется без
// перезагрузки страницы.

const modal = document.querySelector('[data-organization-edit-modal] .modal')
    ?? document.querySelector('.modal[data-modal]');

if (modal) {
    const form = modal.querySelector('[data-organization-edit-form]');
    const fields = {
        name: modal.querySelector('[data-organization-field="name"]'),
        industry: modal.querySelector('[data-organization-field="industry"]'),
        annualPlan: modal.querySelector('[data-organization-field="annualPlan"]'),
        description: modal.querySelector('[data-organization-field="description"]'),
    };
    const hasUsedServicesCheckbox = modal.querySelector('[data-organization-field="hasUsedServices"]');
    const deleteLink = modal.querySelector('[data-organization-delete-link]');
    let activeRow = null;

    const open = (row) => {
        activeRow = row;
        form.action = `/organizations/${row.dataset.orgId}/edit`;
        if (deleteLink) {
            deleteLink.href = `/organizations/${row.dataset.orgId}/delete`;
        }
        Object.entries(fields).forEach(([key, input]) => {
            if (!input) {
                return;
            }
            const dataKey = `org${key[0].toUpperCase()}${key.slice(1)}`;
            input.value = row.dataset[dataKey] ?? '';
        });
        if (hasUsedServicesCheckbox) {
            hasUsedServicesCheckbox.checked = row.dataset.orgHasusedservices === '1';
        }
        modal.querySelectorAll('[data-organization-error]').forEach((span) => {
            span.hidden = true;
            span.textContent = '';
        });
        modal.hidden = false;
        (fields.name ?? form).focus();
    };

    const close = () => {
        modal.hidden = true;
        activeRow = null;
    };

    // Открытие: делегирование, кнопка внутри строки организации.
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-organization-edit]');
        if (trigger) {
            event.preventDefault();
            open(trigger.closest('[data-organization-row]'));
            return;
        }
        if (event.target.closest('[data-modal-close]')) {
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
            modal.querySelectorAll('[data-organization-error]').forEach((span) => {
                span.hidden = true;
                span.textContent = '';
            });
            Object.entries(payload.errors ?? {}).forEach(([field, message]) => {
                const span = modal.querySelector(`[data-organization-error="${field}"]`);
                if (span) {
                    span.textContent = message;
                    span.hidden = false;
                }
            });
            return;
        }

        // Обновление таблицы дашборда без перезагрузки страницы.
        if (payload.organization) {
            activeRow.dataset.orgName = payload.organization.name;
            activeRow.dataset.orgIndustry = payload.organization.industry;
            activeRow.dataset.orgAnnualplan = payload.organization.annualPlan ?? '';
            activeRow.dataset.orgDescription = payload.organization.description ?? '';
            activeRow.dataset.orgHasusedservices = payload.organization.hasUsedServices ? '1' : '0';
            activeRow.querySelectorAll('[data-organization-cell]').forEach((cell) => {
                cell.textContent = payload.organization[cell.dataset.organizationCell] ?? cell.textContent;
            });
        }

        close();
    });
}

// Подсветка организации после перенаправления (create/update).
{
    const highlighted = document.querySelector('.org-table__row--highlight');
    if (highlighted) {
        highlighted.scrollIntoView({ behavior: 'smooth', block: 'center' });
        // Fallback для браузеров без CSS-анимаций: убрать класс через 4 секунды.
        window.setTimeout(() => {
            highlighted.classList.remove('org-table__row--highlight');
        }, 4000);
    }
}
