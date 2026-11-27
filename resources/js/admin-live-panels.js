// Panels on the dashboard keep themselves current: a booking a customer sends,
// a proof verified at the desk beside you, an arrival checked in — each shows
// up on a screen nobody has touched. The work is deliberately quiet. A poll
// that found nothing new does not touch the DOM at all, so a list is never
// rebuilt under the person reading it, and a number in a panel header is only
// ever copied across together with the rows it belongs to.
//
// A panel is wired entirely by markup, because the server knows what it shows
// and the browser only knows when to look:
//
//   the panel    [data-live-panel], data-live-panel-url, data-live-panel-interval,
//                data-live-panel-noun (what a newly marked row is called)
//   inside it    [data-live-panel-status] (what the refresh is doing),
//                [data-panel-badge] and [data-panel-summary] (numbers the
//                fragment hands over), [data-live-row] with [data-live-row-new]
//   the answer   [data-live-panel-body], data-panel-signature, and optionally
//                data-panel-count and data-panel-summary
//
// Only the answer's signature decides whether anything is drawn: the resort
// renders one fingerprint of everything the panel states, so "did this panel
// change?" is answered by the server rather than by comparing markup the
// browser may have rewritten on the way in.

import { triggerShiftAlert } from './admin-alert-audio.js';

export const DEFAULT_INTERVAL_MS = 20_000;
export const MIN_INTERVAL_MS = 5_000;
export const MAX_INTERVAL_MS = 5 * 60_000;
export const COOLDOWN_MS = 5_000;

// A metered or slow connection is not worth a panel that re-reads itself. The
// screen is still correct without it: it shows what was waiting when it was
// opened, it just does not go looking for more.
export const isQuietConnection = (connection = null) =>
    Boolean(connection?.saveData) || /(^|-)2g$/.test(connection?.effectiveType || '');

// Three reasons a poll is not worth the bytes: nobody is looking, there is no
// network to look over, and the network that exists is too dear to spend on a
// panel nobody asked to see change.
export const shouldCheck = ({ hidden = false, onLine = true, connection = null } = {}) =>
    !hidden && onLine !== false && !isQuietConnection(connection);

// A failed check backs off, so a resort wifi outage does not become a loop of
// failing requests — but never past the cap, and never to the point of a panel
// that has quietly stopped listening.
export const nextInterval = ({ base, interval, failed }) =>
    failed ? Math.min(Math.max(interval, MIN_INTERVAL_MS) * 2, Math.max(MAX_INTERVAL_MS, base)) : base;

// Which rows are on the screen now that were not a moment ago. The order is the
// panel's own, so the oldest wait is still the one that gets marked first.
export const newRowIds = ({ previous = [], current = [] }) => {
    const seen = new Set(previous);

    return current.filter((id) => !seen.has(id));
};

// A row marked new stays marked for as long as the screen is open, however many
// polls redraw the panel under it — a marker that faded on the next tick would
// contradict the count the status line is still showing.
export const nextMarkedIds = ({ marked = [], added = [] }) => [...new Set([...marked, ...added])];

export const statusText = ({ newCount = 0, failed = false, noun = 'item' } = {}) => {
    if (failed) {
        return 'Could not check just now — retrying';
    }

    if (newCount === 0) {
        return 'Live';
    }

    return `${newCount} new ${noun}${newCount === 1 ? '' : 's'} since you opened`;
};

const bindLivePanel = (root) => {
    const url = root.dataset.livePanelUrl;
    const status = root.querySelector('[data-live-panel-status]');
    const badge = root.querySelector('[data-panel-badge]');
    const summary = root.querySelector('[data-panel-summary]');

    let body = root.querySelector('[data-live-panel-body]');

    if (!url || !body) {
        return;
    }

    const base = Math.max(Number(root.dataset.livePanelInterval) || DEFAULT_INTERVAL_MS, MIN_INTERVAL_MS);
    const noun = root.dataset.livePanelNoun || 'item';

    let interval = base;
    let timer = 0;
    let inFlight = false;
    let lastCheckedAt = 0;
    let newSinceOpened = 0;
    let marked = [];

    // Only ever writes when the words actually change, so a quiet poll neither
    // flickers nor interrupts a screen reader mid-sentence.
    const say = (text) => {
        if (status && status.textContent.trim() !== text) {
            status.textContent = text;
        }
    };

    const rowIds = () => [...root.querySelectorAll('[data-live-row]')].map((row) => row.dataset.liveRow);

    const adopt = (incoming) => {
        const previous = rowIds();

        body.replaceWith(incoming);
        body = incoming;

        // The header numbers arrive in the same answer as the rows, so the sum
        // beside the title is never a poll behind what it adds up.
        if (badge && incoming.dataset.panelCount !== undefined) {
            badge.textContent = incoming.dataset.panelCount;
        }

        if (summary && incoming.dataset.panelSummary !== undefined) {
            summary.textContent = incoming.dataset.panelSummary;
        }

        const added = newRowIds({ previous, current: rowIds() });

        // The fresh markup ships every marker hidden, so the ones already shown
        // are put back alongside the new arrivals.
        marked = nextMarkedIds({ marked, added });
        marked.forEach((id) => {
            root.querySelector(`[data-live-row="${id}"] [data-live-row-new]`)?.classList.remove('hidden');
        });

        return added.length;
    };

    // Every path out of a check queues the next one, so the timer survives a
    // skipped poll, a failure, and a tab that was in the background.
    const queueNext = () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(check, interval);
    };

    const check = async () => {
        const worthIt = shouldCheck({
            hidden: document.hidden,
            onLine: window.navigator.onLine,
            connection: window.navigator.connection,
        });

        if (inFlight || Date.now() - lastCheckedAt < COOLDOWN_MS || !worthIt) {
            queueNext();

            return;
        }

        inFlight = true;

        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                throw new Error(`Live panel check failed with ${response.status}`);
            }

            const incoming = new DOMParser()
                .parseFromString(await response.text(), 'text/html')
                .querySelector('[data-live-panel-body]');

            if (!incoming) {
                throw new Error('A live panel answered without its contents.');
            }

            interval = nextInterval({ base, interval, failed: false });

            if (incoming.dataset.panelSignature === body.dataset.panelSignature) {
                say(statusText({ newCount: newSinceOpened, noun }));

                return;
            }

            const addedCount = adopt(incoming);
            newSinceOpened += addedCount;
            say(statusText({ newCount: newSinceOpened, noun }));

            if (addedCount > 0) {
                const alertTitle = noun === 'request'
                    ? `${addedCount} new booking request${addedCount > 1 ? 's' : ''}`
                    : 'New items for the front desk';
                const alertBody = noun === 'request'
                    ? 'Open the dashboard to review the new booking requests.'
                    : 'Open the front desk dashboard to see what needs your attention.';

                triggerShiftAlert({ title: alertTitle, body: alertBody });
            }
        } catch {
            interval = nextInterval({ base, interval, failed: true });
            say(statusText({ failed: true }));
        } finally {
            inFlight = false;
            lastCheckedAt = Date.now();
            queueNext();
        }
    };

    // Returning to the screen is the moment a stale panel is worth fixing, so it
    // is also when the panel asks again instead of waiting out its timer.
    document.addEventListener('visibilitychange', () => {
        if (! document.hidden) {
            check();
        }
    });
    window.addEventListener('focus', check);
    window.addEventListener('online', check);

    queueNext();
};

export const enableAdminLivePanels = () => {
    document.querySelectorAll('[data-live-panel]').forEach(bindLivePanel);
};
