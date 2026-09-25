// Модалка предпросмотра письма (change wysiwyg-email-body): на карточке
// кампании iframe уже указывает на готовый email-документ; в форме JS
// отправляет несохранённое тело на live-эндпоинт и подставляет полученный
// HTML через srcdoc. Редактор синхронизирует скрытую textarea по событию
// campaign-preview:before-open.

const previewRoot = document.querySelector('[data-campaign-preview-modal]');

if (previewRoot) {
    const modal = previewRoot.querySelector('.modal');
    const frame = previewRoot.querySelector('[data-campaign-preview-frame]');
    const liveUrl = frame?.dataset.previewUrl ?? null;

    const open = () => {
        modal.hidden = false;
    };

    const close = () => {
        modal.hidden = true;
    };

    const loadLivePreview = async () => {
        const form = document.querySelector('form.campaign-form');
        if (!form || !frame) {
            return;
        }

        document.dispatchEvent(new CustomEvent('campaign-preview:before-open'));

        const data = new FormData(form);
        const csrf = form.querySelector('input[name="_csrf_preview"]')?.value ?? '';

        try {
            const response = await fetch(liveUrl, {
                method: 'POST',
                body: new URLSearchParams({
                    body: data.get('body') ?? '',
                    subject: data.get('subject') ?? '',
                    preview_text: data.get('preview_text') ?? '',
                }),
                headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            });
            const payload = await response.json();
            if (payload.html) {
                frame.srcdoc = payload.html;
            }
        } catch {
            // Сеть недоступна — оставляем предыдущий предпросмотр.
        }
    };

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-campaign-preview-open]')) {
            event.preventDefault();
            open();
            if (liveUrl) {
                loadLivePreview();
            }

            return;
        }
        if (event.target.closest('[data-modal-close]') && !modal.hidden) {
            close();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            close();
        }
    });
}
