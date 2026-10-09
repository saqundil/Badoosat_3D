/**
 * Uploads artwork and shows the resolution verdict before the sale.
 *
 * The check itself happens on the server, in PrintFileService: the browser is
 * not the place to decide whether a file is printable.
 */

const form = document.getElementById('add-form');
const uploader = document.getElementById('uploader');
const input = document.getElementById('design-file');
const pick = document.getElementById('pick-design');
const preview = document.getElementById('design-preview');
const tokenField = document.getElementById('design-token');
const verdict = document.getElementById('design-check');
const submit = form?.querySelector('button[type="submit"]');

if (form && uploader && input) {
    pick.addEventListener('click', () => input.click());

    input.addEventListener('change', () => {
        if (input.files?.[0]) {
            send(input.files[0]);
        }
    });

    for (const type of ['dragenter', 'dragover']) {
        uploader.addEventListener(type, (event) => {
            event.preventDefault();
            uploader.classList.add('is-over');
        });
    }

    for (const type of ['dragleave', 'drop']) {
        uploader.addEventListener(type, (event) => {
            event.preventDefault();
            uploader.classList.remove('is-over');
        });
    }

    uploader.addEventListener('drop', (event) => {
        const file = event.dataTransfer?.files?.[0];

        if (file) {
            send(file);
        }
    });
}

async function send(file) {
    setVerdict('جارٍ الرفع…', '');
    setBusy(true);

    const body = new FormData();
    body.append('design', file);
    body.append('placement', form.querySelector('input[name="placement"]:checked')?.value ?? 'front');
    body.append('_token', form.dataset.token);

    try {
        const response = await fetch('/api/upload.php', {
            method: 'POST',
            body,
            headers: { 'X-CSRF-Token': form.dataset.token, Accept: 'application/json' },
        });
        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.error ?? 'تعذّر الرفع.');
        }

        const check = result.check;

        if (check.quality === 'reject') {
            tokenField.value = '';
            uploader.classList.remove('has-file');
            setVerdict(check.message, 'alert alert--error');
            setBusy(false);

            return;
        }

        tokenField.value = result.token;
        preview.src = result.path;
        uploader.classList.add('has-file');
        setVerdict(check.message, check.quality === 'warn' ? 'alert alert--warn' : 'alert alert--ok');
    } catch (error) {
        tokenField.value = '';
        setVerdict(error.message, 'alert alert--error');
    } finally {
        setBusy(false);
    }
}

function setVerdict(message, className) {
    verdict.className = className;
    verdict.textContent = message;
}

function setBusy(busy) {
    if (submit) {
        submit.disabled = busy;
    }
}
