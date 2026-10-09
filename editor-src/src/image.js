/**
 * Reads an uploaded file well enough to warn about it before it is placed.
 *
 * The transparency check matters more than it looks: a customer who uploads a
 * JPEG logo gets a white box printed around it, and almost nobody expects that
 * until they see the parcel.
 */
export async function inspectImage(file, maxBytes) {
    if (file.size > maxBytes) {
        const megabytes = (maxBytes / (1024 * 1024)).toLocaleString('ar', {
            maximumFractionDigits: 2,
        });
        throw new Error(`حجم الملف أكبر من الحد المسموح (${megabytes} ميغابايت).`);
    }

    const url = await readAsDataUrl(file);
    const image = await load(url);

    return {
        url,
        width: image.naturalWidth,
        height: image.naturalHeight,
        opaque: isOpaque(image, file.type),
    };
}

function readAsDataUrl(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => reject(new Error('تعذّرت قراءة الملف.'));
        reader.readAsDataURL(file);
    });
}

function load(url) {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('هذا الملف ليس صورة قابلة للعرض.'));
        image.src = url;
    });
}

/** JPEG has no alpha at all; for the rest, sample the pixels. */
function isOpaque(image, mime) {
    if (mime === 'image/jpeg' || mime === 'image/jpg') {
        return true;
    }

    const size = 64;
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = size;

    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(image, 0, 0, size, size);

    const { data } = ctx.getImageData(0, 0, size, size);

    for (let i = 3; i < data.length; i += 4) {
        if (data[i] < 250) {
            return false;
        }
    }

    return true;
}

/**
 * Shrinks a bitmap to the working canvas before it is shown.
 *
 * The full-resolution original still goes to the server: the preview only needs
 * enough pixels to look right on screen, while the press file needs all of them.
 */
export async function downscale(dataUrl, maxWidth, maxHeight) {
    const image = await load(dataUrl);
    const scale = Math.min(1, maxWidth / image.naturalWidth, maxHeight / image.naturalHeight);

    if (scale >= 1) {
        return dataUrl;
    }

    const canvas = document.createElement('canvas');
    canvas.width = Math.round(image.naturalWidth * scale);
    canvas.height = Math.round(image.naturalHeight * scale);
    canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);

    return canvas.toDataURL('image/png');
}

export function dataUrlToBlob(dataUrl) {
    const [header, body] = dataUrl.split(',');
    const mime = header.match(/:(.*?);/)[1];
    const binary = atob(body);
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return new Blob([bytes], { type: mime });
}
