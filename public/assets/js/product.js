/**
 * The product page's small amount of behaviour.
 *
 * It does not price anything. Every time the customer changes a colour, a size,
 * a design or a quantity, this asks the server what that costs and prints the
 * answer -- because a price computed in the browser is a price that can drift
 * from the one the order writer will charge, and the customer will notice at
 * exactly the wrong moment. The arithmetic lives in PricingService and is
 * reached through /api/price.php, which is the same code the cart and the order
 * run through.
 *
 * The cost is one request per change. It is a query on an indexed row, and it
 * buys a number that is true.
 */

const form = document.getElementById('add-form');

if (form) {
    const priceEl = document.getElementById('price');
    const savedEl = document.getElementById('saved');
    const typeEl = document.getElementById('item-type');
    const addButton = document.getElementById('add-button');
    const productId = form.dataset.product;

    let pending = null;

    const field = (name) => form.querySelector('input[name="' + name + '"]:checked');

    /*
     * The pill on the photo is written in whole dinars, and it has to keep
     * being written in whole dinars once a live total lands in it -- a badge
     * that renders "19 د.أ" on load and "19.00 د.أ" a moment later is a badge
     * that resizes its own type for no reason the customer can see.
     *
     * total_label from the API is money(), which prints piastres: right on an
     * invoice, noise in a box this size. The same response carries
     * total_cents, so the pill is written from the number instead of from the
     * sentence. Still the server's figure -- nothing is computed here.
     */
    const badge = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });
    const badgePrice = (cents) => badge.format(cents / 100) + ' د.أ';

    /*
     * A product with no price set has no pill to write into and no purchase to
     * enable. Everything else on the page still works -- colours, sizes, the
     * gallery -- so only the price call is skipped, and the button keeps the
     * disabled state and the wording the server rendered.
     */
    const noPrice = addButton !== null && addButton.hasAttribute('data-no-price');

    async function refresh() {
        const color = field('color_id');
        const size = field('size_id');

        if (noPrice || !color || !size) {
            return;
        }

        const query = new URLSearchParams({
            product_id: productId,
            color_id: color.value,
            size_id: size.value,
            item_type: typeEl.value,
            /*
             * Always a catalogue line, always the front. The customer chose
             * neither: a print belongs to the product the shop published, at
             * the position the shop set, and nothing on this page can change
             * either. The two values are still sent because /api/price.php
             * prices every line type through the same call.
             */
            placement: 'front',
            quantity: document.getElementById('quantity').value || '1',
        });

        // Only the newest answer may reach the screen: a slow reply to an
        // earlier click must not overwrite a fast reply to a later one.
        const ticket = {};
        pending = ticket;

        try {
            const response = await fetch('/api/price.php?' + query, {
                headers: { accept: 'application/json' },
            });
            const data = await response.json();

            if (pending !== ticket) {
                return;
            }

            if (!response.ok) {
                priceEl.textContent = data.error || 'غير متاح';
                addButton.disabled = true;

                return;
            }

            priceEl.textContent = typeof data.total_cents === 'number'
                ? badgePrice(data.total_cents)
                : data.total_label;
            addButton.disabled = !data.in_stock;
            addButton.textContent = data.in_stock ? 'أضف إلى السلة' : 'نفد المخزون';

            savedEl.hidden = !data.saved_label;

            if (data.saved_label) {
                savedEl.textContent =
                    'خصم الكمية ' + data.discount_percent + '٪ — وفّرت ' + data.saved_label;
            }
        } catch {
            // The page still works without a live price: the figure printed on
            // load is a real one, and the server prices the order regardless.
            if (pending === ticket) {
                addButton.disabled = false;
            }
        }
    }

    /* A colour or a size changed: ask the server what the line costs now. */
    form.addEventListener('change', refresh);

    form.addEventListener('input', (event) => {
        if (event.target.id === 'quantity') {
            refresh();
        }
    });

    form.addEventListener('click', (event) => {
        const stepper = event.target.closest('[data-quantity-change]');

        if (!stepper) {
            return;
        }

        const quantity = document.getElementById('quantity');
        const next = Number(quantity.value || quantity.min || 1) + Number(stepper.dataset.quantityChange);
        quantity.value = String(Math.min(Number(quantity.max), Math.max(Number(quantity.min), next)));
        quantity.dispatchEvent(new Event('input', { bubbles: true }));
    });

    /* ------------------------------------------------------- size chart */

    const sizesToggle = document.querySelector('[data-sizes-toggle]');
    const sizeChart = document.getElementById('size-chart');

    if (sizesToggle && sizeChart) {
        sizesToggle.addEventListener('click', () => {
            const open = sizeChart.hidden;
            sizeChart.hidden = !open;
            sizesToggle.setAttribute('aria-expanded', String(open));
        });
    }

    /* --------------------------------------------- the folded description */

    const detailsToggle = document.querySelector('[data-details-toggle]');
    const detailsBody = document.getElementById('product-details');

    if (detailsToggle && detailsBody) {
        detailsToggle.addEventListener('click', () => {
            const open = detailsBody.hidden;
            detailsBody.hidden = !open;
            detailsToggle.setAttribute('aria-expanded', String(open));
        });
    }

    /*
     * The editor's hand-back used to be caught here.
     *
     * A saved design set the hidden token, flipped item_type to 'custom' and
     * revealed the placement chooser -- all on this page, because the editor
     * opened on top of it. The editor is not part of the product page any
     * more: a customer's own artwork comes in through its own route and
     * reaches the cart as its own line. This page sells the catalogue.
     */

    refresh();
}
