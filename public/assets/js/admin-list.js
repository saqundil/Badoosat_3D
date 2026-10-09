/** Drag to reorder the slide list; the new order is saved on drop. */

const list = document.querySelector('[data-reorder]');

if (list) {
    const endpoint = list.dataset.endpoint;
    const token = list.dataset.token;
    let dragged = null;

    list.addEventListener('dragstart', (event) => {
        dragged = event.target.closest('.slide');

        if (!dragged) {
            return;
        }

        dragged.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        // Firefox will not start a drag without payload.
        event.dataTransfer.setData('text/plain', dragged.dataset.id);
    });

    list.addEventListener('dragover', (event) => {
        if (!dragged) {
            return;
        }

        event.preventDefault();

        const over = event.target.closest('.slide');

        if (!over || over === dragged) {
            return;
        }

        const box = over.getBoundingClientRect();
        const after = event.clientY > box.top + box.height / 2;

        over.parentNode.insertBefore(dragged, after ? over.nextSibling : over);
    });

    list.addEventListener('dragend', async () => {
        if (!dragged) {
            return;
        }

        dragged.classList.remove('is-dragging');
        dragged = null;

        const ids = [...list.querySelectorAll('.slide')].map((item) => Number(item.dataset.id));

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token,
                    Accept: 'application/json',
                },
                body: JSON.stringify({ action: 'reorder', ids }),
            });

            if (!response.ok) {
                throw new Error('تعذّر حفظ الترتيب.');
            }

            list.classList.add('is-saved');
            setTimeout(() => list.classList.remove('is-saved'), 900);
        } catch (error) {
            alert(error.message);
            location.reload();
        }
    });
}
