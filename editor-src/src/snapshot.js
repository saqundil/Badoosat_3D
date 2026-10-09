const PREVIEW_WIDTH = 800;

/**
 * A copy of the WebGL canvas at a fixed width, for the order record.
 *
 * It has to be taken while the scene is still on screen: the confirmation card
 * replaces the preview, so by the time that card renders there is no canvas
 * left to read.
 */
export function snapshotScene() {
    const source = document.querySelector('#tshirt-editor .ed-stage canvas');

    if (!source || !source.width) {
        return null;
    }

    const canvas = document.createElement('canvas');
    canvas.width = PREVIEW_WIDTH;
    canvas.height = Math.round((source.height / source.width) * PREVIEW_WIDTH);
    canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);

    return canvas.toDataURL('image/png');
}
