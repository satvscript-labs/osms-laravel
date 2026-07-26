/**
 * SHARE-01 — shared "who else is on this number?" behaviour.
 *
 * A phone number identifies a household handset, not a person, so both places
 * that create a customer — the customer form and the order builder's inline add
 * — must ask the same question and offer the same choice. This is that shared
 * behaviour; each page supplies its own markup and its own `onPick` handler, so
 * the order builder can select the person into the order while the customer form
 * simply navigates to them.
 *
 * Mixed into an Alpine component with `...household({ endpoint }), ...`.
 *
 * Spread it FIRST, then override `pickHousehold()` and the `householdNewName`
 * getter — the host component's own definitions win, and they can use `this` to
 * reach the rest of the component's state.
 */
export function household({ endpoint }) {
    return {
        householdLoading: false,
        householdMembers: [],
        householdNewPerson: false,
        householdPhone: null,
        _householdToken: 0,
        _householdTimer: null,

        /** The name currently typed, shown inside the "add them" button. Override me. */
        get householdNewName() {
            return '';
        },

        /**
         * Look up the household for a number. Debounced, because it fires while
         * staff are typing; a token guards against a slow response landing after
         * a newer one and repopulating a stale list.
         */
        checkHousehold(national, code = '+91', except = null) {
            clearTimeout(this._householdTimer);

            const digits = String(national || '').replace(/\D/g, '');

            // Only a complete number identifies a household. Anything shorter
            // would flash half-matches at someone mid-keystroke.
            if (digits.length !== 10) {
                this.resetHousehold();
                return;
            }

            this.householdLoading = true;
            this._householdTimer = setTimeout(() => {
                const token = ++this._householdToken;
                const params = new URLSearchParams({ phone: digits, country_code: code });
                if (except) params.set('except', except);

                fetch(`${endpoint}?${params}`, { headers: { Accept: 'application/json' } })
                    .then((r) => (r.ok ? r.json() : { customers: [] }))
                    .then((data) => {
                        if (token !== this._householdToken) return; // superseded
                        this.householdMembers = data.customers || [];
                        this.householdPhone = data.phone || null;
                        this.householdLoading = false;
                        // Changing the number invalidates an earlier "yes, new
                        // person" decision — it was about a different household.
                        this.householdNewPerson = false;
                    })
                    .catch(() => {
                        if (token !== this._householdToken) return;
                        // A failed lookup must never block the counter: fall back
                        // to "no household known" so the sale can still proceed.
                        this.resetHousehold();
                    });
            }, 220);
        },

        resetHousehold() {
            clearTimeout(this._householdTimer);
            this._householdToken++;
            this.householdLoading = false;
            this.householdMembers = [];
            this.householdNewPerson = false;
            this.householdPhone = null;
        },

        /** Use one of the people already on this number. Override me. */
        pickHousehold() {},

        chooseNewPerson() {
            this.householdNewPerson = true;
        },

        /**
         * Whether the form may be submitted: an unresolved household is a
         * deliberate stop, so nothing is ever silently attached to whoever
         * happened to match the number.
         */
        get householdResolved() {
            if (this.householdLoading) return false;

            return this.householdMembers.length === 0 || this.householdNewPerson;
        },
    };
}

window.household = household;
