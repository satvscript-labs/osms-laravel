import './bootstrap';

// Bootstrap 5 JS (dropdowns, modals, offcanvas, tooltips)
import * as bootstrap from 'bootstrap';
window.bootstrap = bootstrap;

// Alpine.js — powers the reactive order builder (line items, totals)
import Alpine from 'alpinejs';
window.Alpine = Alpine;

// Drag-and-drop for the orders kanban board
import Sortable from 'sortablejs';
window.Sortable = Sortable;

// Client-side Code128 barcode rendering for receipts / labels
import JsBarcode from 'jsbarcode';
window.JsBarcode = JsBarcode;

// Shared "Settle" modal controller (6.1 settle-on-deliver + 6.3 dues settlement)
import './settle-modal';

// FT-WhatsApp — the automated "undo ring" on advanced orders (Phase 6)
import './order-advance';

// SHARE-01 — shared household ("who else is on this number?") behaviour, used by
// both customer-creation surfaces.
import './household';

// Auto-enable tooltips
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
        new bootstrap.Tooltip(el);
    });
});

Alpine.start();
