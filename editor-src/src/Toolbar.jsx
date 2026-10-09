import { useEditor } from './store.js';

/**
 * Every tool is a real button. The editor has to be usable from the keyboard
 * alone, from the first upload to the last click, so nothing here is a div that
 * happens to listen for clicks.
 */
export default function Toolbar({ onUpload, busy }) {
    const surface = useEditor((s) => s.surface);
    const selection = useEditor((s) => s.selection);
    const canUndo = useEditor((s) => s.canUndo);
    const canRedo = useEditor((s) => s.canRedo);
    const sync = useEditor((s) => s.sync);

    const act = (fn) => () => {
        fn();
        sync();
    };

    return (
        <div className="ed-tools" role="toolbar" aria-label="أدوات التصميم">
            <button type="button" className="ed-tool" onClick={() => onUpload()} disabled={busy}>
                {busy ? 'جارٍ الرفع…' : 'رفع صورة'}
            </button>

            <button
                type="button"
                className="ed-tool"
                onClick={act(() => surface.addText('نصّك هنا'))}
            >
                إضافة نص
            </button>

            <span className="ed-tools__sep" role="separator" />

            <button type="button" className="ed-tool" aria-label="تدوير لليسار"
                    disabled={!selection} onClick={act(() => surface.rotate(-15))}>
                ↺
            </button>
            <button type="button" className="ed-tool" aria-label="تدوير لليمين"
                    disabled={!selection} onClick={act(() => surface.rotate(15))}>
                ↻
            </button>
            <button type="button" className="ed-tool" aria-label="تصغير العنصر"
                    disabled={!selection} onClick={act(() => surface.scale(0.9))}>
                −
            </button>
            <button type="button" className="ed-tool" aria-label="تكبير العنصر"
                    disabled={!selection} onClick={act(() => surface.scale(1.1))}>
                +
            </button>

            <span className="ed-tools__sep" role="separator" />

            <button type="button" className="ed-tool" aria-label="إلى الأمام"
                    disabled={!selection} onClick={act(() => surface.order('up'))}>
                ⬆
            </button>
            <button type="button" className="ed-tool" aria-label="إلى الخلف"
                    disabled={!selection} onClick={act(() => surface.order('down'))}>
                ⬇
            </button>
            <button type="button" className="ed-tool ed-tool--danger" aria-label="حذف العنصر"
                    disabled={!selection} onClick={act(() => surface.remove())}>
                حذف
            </button>

            <span className="ed-tools__sep" role="separator" />

            <button type="button" className="ed-tool" disabled={!canUndo}
                    onClick={() => surface.undo().then(sync)} aria-keyshortcuts="Control+Z">
                تراجع
            </button>
            <button type="button" className="ed-tool" disabled={!canRedo}
                    onClick={() => surface.redo().then(sync)} aria-keyshortcuts="Control+Shift+Z">
                إعادة
            </button>
        </div>
    );
}
