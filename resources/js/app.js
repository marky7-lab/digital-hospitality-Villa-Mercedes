import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { enableAdminLiveFilters } from './admin-live-filters';
import { enableAdminLivePanels } from './admin-live-panels';
import {
    isSoundEnabled,
    setSoundEnabled,
    isDesktopEnabled,
    setDesktopEnabled,
    playFrontDeskChime,
} from './admin-alert-audio';
import { enableReservationAuth } from './reservation-auth';
import { pesos, pesosFromCents } from './money';
import { reservationSchedule } from './reservation-schedule';
import {
    describeInputChanges,
    inputsFrom,
    priceChange,
    priceTerms,
    stayNights,
} from './price-change';

enableReservationAuth();

document.addEventListener('input', ({ target }) => {
    if (target instanceof HTMLInputElement && target.hasAttribute('data-ph-mobile')) {
        target.value = target.value.replace(/[^0-9]/g, '').slice(0, 11);
    }
}, true);

// The money format is needed from inline Alpine expressions (x-text), which
// cannot import a module, so it is exposed on the window next to Alpine itself.
// The definition lives in one file; this is only how the templates reach it.
window.pesos = pesos;
window.pesosFromCents = pesosFromCents;

// The same, for how a booking total moved and what it is made of: the booking
// wizard needs it from inline Alpine expressions and from its own `x-for` loops,
// which cannot import a module either.
window.bookingPrice = { describeInputChanges, inputsFrom, priceChange, priceTerms, stayNights };

Alpine.plugin(collapse);
Alpine.data('reservationSchedule', reservationSchedule);
window.Alpine = Alpine;

const startAlpine = () => Alpine.start();

const enableIntentPrefetch = () => {
    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;

    if (connection?.saveData || /(^|-)2g$/.test(connection?.effectiveType || '')) {
        return;
    }

    const prefetched = new Set();
    const prefetch = (link) => {
        if (!(link instanceof HTMLAnchorElement) || !link.matches('[data-intent-prefetch]')) {
            return;
        }

        const url = new URL(link.href, window.location.href);

        if (url.origin !== window.location.origin || url.pathname === window.location.pathname || prefetched.has(url.href)) {
            return;
        }

        prefetched.add(url.href);
        const hint = document.createElement('link');
        hint.rel = 'prefetch';
        hint.href = url.href;
        document.head.append(hint);
    };

    document.addEventListener('pointerenter', (event) => {
        if (event.target instanceof Element) {
            prefetch(event.target.closest('a'));
        }
    }, true);
    document.addEventListener('focusin', (event) => {
        if (event.target instanceof Element) {
            prefetch(event.target.closest('a'));
        }
    });
};

const loadDeferredFeaturedRooms = () => {
    const section = document.querySelector('[data-home-featured]');

    if (!section) {
        return;
    }

    const content = section.querySelector('[data-home-featured-content]');
    const endpoint = section.dataset.homeFeaturedEndpoint;
    const fallbackUrl = section.dataset.homeFeaturedFallbackUrl || '/rooms';
    const cacheKey = 'public-home:featured-rooms:v1';
    const cacheLifetime = 5 * 60 * 1000;
    let loaded = false;

    if (!content || !endpoint) {
        return;
    }

    const render = (markup) => {
        content.innerHTML = markup;
        section.setAttribute('aria-busy', 'false');
        loaded = true;
    };

    const fallback = () => render(`
        <div class="rounded-3xl border border-dashed border-border bg-surface px-6 py-10 text-center">
            <p class="font-semibold text-text">Featured rooms could not load right now.</p>
            <a href="${fallbackUrl}" class="mt-3 inline-flex font-semibold text-primary hover:underline">Browse all accommodations &rarr;</a>
        </div>
    `);

    const readCachedMarkup = () => {
        try {
            const cached = JSON.parse(sessionStorage.getItem(cacheKey) || 'null');

            return cached && cached.expiresAt > Date.now() && typeof cached.markup === 'string'
                ? cached.markup
                : null;
        } catch {
            return null;
        }
    };

    const load = async () => {
        if (loaded) {
            return;
        }

        const cachedMarkup = readCachedMarkup();

        if (cachedMarkup) {
            render(cachedMarkup);
            return;
        }

        try {
            const response = await fetch(endpoint, {
                credentials: 'same-origin',
                headers: { Accept: 'text/html' },
            });

            if (!response.ok) {
                throw new Error(`Featured rooms request failed: ${response.status}`);
            }

            const markup = await response.text();
            try {
                sessionStorage.setItem(cacheKey, JSON.stringify({ markup, expiresAt: Date.now() + cacheLifetime }));
            } catch {
                // Storage can be unavailable in privacy-focused browsing modes.
            }
            render(markup);
        } catch {
            fallback();
        }
    };

    if (!('IntersectionObserver' in window)) {
        load();
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        if (!entries.some((entry) => entry.isIntersecting)) {
            return;
        }

        observer.disconnect();
        load();
    }, { rootMargin: '280px 0px' });

    observer.observe(section);
};

enableIntentPrefetch();
loadDeferredFeaturedRooms();
enableAdminLiveFilters();
enableAdminLivePanels();

document.addEventListener('alpine:init', () => {
    Alpine.data('adminAlertSettings', () => ({
        sound: isSoundEnabled(),
        desktop: isDesktopEnabled(),
        supported: typeof window !== 'undefined' && 'Notification' in window,
        toggleSound() {
            this.sound = !this.sound;
            setSoundEnabled(this.sound);
            if (this.sound) {
                playFrontDeskChime();
            }
        },
        async toggleDesktop() {
            const next = !this.desktop;
            const granted = await setDesktopEnabled(next);
            this.desktop = Boolean(granted);
        },
    }));
    Alpine.directive('reveal', (el, _directive, { cleanup }) => {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            el.classList.add('is-revealed');
            return;
        }

        el.classList.add('js-reveal');

        if (! ('IntersectionObserver' in window)) {
            el.classList.add('is-revealed');
            return;
        }

        const observer = new IntersectionObserver((entries) => {
            if (! entries.some((entry) => entry.isIntersecting)) {
                return;
            }

            el.classList.add('is-revealed');
            observer.disconnect();
        }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });

        observer.observe(el);
        cleanup(() => observer.disconnect());
    });

    Alpine.data('walkInReservation', (config = {}) => ({
        facilities: Array.isArray(config.facilities) ? config.facilities : [],
        facilityId: String(config.facilityId || ''),
        checkIn: String(config.checkIn || ''),
        checkOut: String(config.checkOut || ''),
        guestCount: Number(config.guestCount || 1),
        otherGuestNames: Array.isArray(config.otherGuestNames) && config.otherGuestNames.length
            ? config.otherGuestNames.map((guest) => ({
                name: typeof guest === 'string' ? String(guest ?? '') : String(guest?.name || ''),
                valid_id: typeof guest === 'string' ? '' : String(guest?.valid_id || ''),
            }))
            : [],
        selections: {
            room: '',
            function_hall: '',
            resort_package: '',
            cottage: '',
        },

        init() {
            this.syncSelectionsFromFacilityId();
        },

        get selectedFacility() {
            if (! this.facilityId) {
                return null;
            }

            return this.facilities.find((facility) => String(facility.id) === String(this.facilityId)) || null;
        },

        get includedCapacity() {
            const capacity = this.selectedFacility?.capacity;

            return capacity === null || capacity === undefined ? null : Number(capacity);
        },

        // Cottages and other open spaces carry no capacity at all, so unset must
        // stay "unlimited" rather than fall back to 1 and clamp the guest count.
        get hasCapacityLimit() {
            return Boolean(this.selectedFacility?.has_capacity_limit);
        },

        get maxCapacity() {
            if (! this.hasCapacityLimit) {
                return Number.POSITIVE_INFINITY;
            }

            return Number(this.selectedFacility.max_capacity ?? this.selectedFacility.capacity);
        },

        get maxCompanions() {
            return Math.max(0, this.maxCapacity - 1);
        },

        get canAddOtherGuest() {
            return this.otherGuestNames.length < this.maxCompanions;
        },

        // The booking summary on the walk-in page reads from here, so the person
        // filing the sheet sees the stay they are actually about to create.
        get stayWindowLabel() {
            const start = this.parseMoment(this.checkIn);
            const end = this.parseMoment(this.checkOut);

            if (! start) {
                return 'Check-in not set yet';
            }

            if (! end) {
                return `${this.formatMoment(start)} until check-out, which is not set yet`;
            }

            if (start.toDateString() === end.toDateString()) {
                return `${this.formatMoment(start)} to ${this.formatTime(end)} (same day)`;
            }

            return `${this.formatMoment(start)} to ${this.formatMoment(end)}`;
        },

        parseMoment(value) {
            if (! value) {
                return null;
            }

            const parsed = new Date(value);

            return Number.isNaN(parsed.getTime()) ? null : parsed;
        },

        formatMoment(date) {
            return date.toLocaleString('en-PH', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
            });
        },

        formatTime(date) {
            return date.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
        },

        syncSelectionsFromFacilityId() {
            this.selections = {
                room: '',
                function_hall: '',
                resort_package: '',
                cottage: '',
            };

            const facility = this.facilities.find((item) => String(item.id) === String(this.facilityId));
            if (facility?.type && Object.prototype.hasOwnProperty.call(this.selections, facility.type)) {
                this.selections[facility.type] = String(facility.id);
            }
        },

        selectFacility(type, id) {
            const value = id ? String(id) : '';

            this.selections = {
                room: '',
                function_hall: '',
                resort_package: '',
                cottage: '',
            };

            if (value && Object.prototype.hasOwnProperty.call(this.selections, type)) {
                this.selections[type] = value;
                this.facilityId = value;
            } else {
                this.facilityId = '';
            }

            this.onFacilityChange();
        },

        onFacilityChange() {
            if (this.guestCount > this.maxCapacity) {
                this.guestCount = this.maxCapacity;
            }

            if (this.otherGuestNames.length > this.maxCompanions) {
                this.otherGuestNames = this.otherGuestNames.slice(0, this.maxCompanions);
            }
        },

        addOtherGuest() {
            if (! this.canAddOtherGuest) {
                return;
            }

            this.otherGuestNames.push({ name: '', valid_id: '' });
            this.guestCount = Math.min(this.maxCapacity, Math.max(this.guestCount, this.otherGuestNames.length + 1));
        },

        removeOtherGuest(index) {
            this.otherGuestNames.splice(index, 1);
        },
    }));
});

// Load the Messenger greeting widget only when a visitor opens it.
const supportChatRoot = document.querySelector('[data-support-chat]');
let supportChatLoader = null;

if (supportChatRoot) {
    const openSupportChat = (toggle = false) => {
        supportChatLoader ??= import('./support-chat').then(({ mountSupportChat }) => mountSupportChat(supportChatRoot));
        supportChatLoader.then((chat) => toggle ? chat.toggle() : chat.open());
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target instanceof Element
            ? event.target.closest('[data-support-chat-open]')
            : null;

        if (!trigger) {
            return;
        }

        event.preventDefault();
        openSupportChat(trigger.hasAttribute('data-chat-launcher'));
    });

    // A hash link provides a useful direct route to support while preserving
    // the same click-to-load behavior for ordinary page visits.
    if (window.location.hash === '#support-chat') {
        openSupportChat();
    }
}

// The booking calendar is the only page that needs these libraries. Loading
// them here keeps the public, customer, and admin pages from downloading their
// JavaScript and CSS on every navigation. Alpine starts after they are ready so
// the booking component can safely use the globals during initialization.
if (document.querySelector('[data-booking-wizard]')) {
    Promise.all([
        import('flatpickr'),
        import('flatpickr/dist/flatpickr.min.css'),
    ]).then(([flatpickrModule]) => {
        window.flatpickr = flatpickrModule.default;
    }).then(startAlpine, startAlpine);
} else {
    startAlpine();
}
