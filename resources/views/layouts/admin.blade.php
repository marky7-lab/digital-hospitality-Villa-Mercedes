@php
    $pageTitle = trim((string) ($header ?? ''));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Page first so a glance at a crowded tab strip still says which screen
         it is; the suffix names the app, never the bare env value. --}}
    <title>{{ $pageTitle !== '' ? $pageTitle.' · ' : '' }}{{ config('resort.name', 'Villa Mercedes') }} Back office</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-background font-sans text-text antialiased">
    {{-- Keyboard users should reach the work, not the navigation. --}}
    <a
        href="#admin-main"
        class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[60] focus:rounded-lg focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white"
    >
        Skip to main content
    </a>

    @php
        // The drawer is only a real toggle below md, where it starts closed;
        // at desktop widths the sidebar is part of the page and `drawer` is
        // simply kept true so nothing can inert it. Rail is desktop only.
        $shellState = <<<'JS'
        {
            drawer: false,
            rail: false,
            mobile: false,
            init() {
                const query = window.matchMedia('(max-width: 1023px)');
                const sync = () => {
                    this.mobile = query.matches;
                    this.drawer = ! query.matches;
                    document.body.style.overflow = '';
                };
                sync();
                query.addEventListener('change', sync);
                this.$watch('drawer', value => {
                    document.body.style.overflow = this.mobile && value ? 'hidden' : '';
                });
            },
            closeDrawer() {
                if (! this.mobile || ! this.drawer) return;
                this.drawer = false;
                this.$nextTick(() => this.$refs.drawerToggle?.focus());
            },
            trapDrawer(event) {
                if (! this.mobile || ! this.drawer || event.key !== 'Tab') return;
                const items = [...this.$refs.sidebar.querySelectorAll('a[href], button:not([disabled])')]
                    .filter(el => el.getClientRects().length);
                const first = items[0], last = items[items.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault(); last?.focus();
                } else if (! event.shiftKey && document.activeElement === last) {
                    event.preventDefault(); first?.focus();
                }
            },
            openDrawer() {
                this.drawer = true;
                // Land on the close control so the way back out is the first
                // thing a keyboard or screen-reader user meets. Queried on its
                // own: a selector list would return the logo link first.
                this.$nextTick(() => {
                    const sidebar = this.$refs.sidebar;
                    const target = sidebar?.querySelector('[data-drawer-close]')
                        ?? sidebar?.querySelector('a, button');
                    target?.focus();
                });
            },
        }
        JS;
    @endphp

    <div x-data="{{ $shellState }}" @keydown.escape.window="closeDrawer()" @keydown="trapDrawer($event)" class="admin-shell">
        {{-- Drawer backdrop: decorative, so it is hidden from assistive tech and
             cannot be reached by keyboard. --}}
        <div
            x-cloak
            x-show="mobile && drawer"
            x-transition.opacity.duration.150ms
            @click="closeDrawer()"
            class="fixed inset-0 z-30 bg-primary/50 lg:hidden"
            aria-hidden="true"
        ></div>

        <x-admin.sidebar />

        <div class="admin-main" :inert="mobile && drawer">
            <x-admin.header :header="$header ?? null" />

            <main id="admin-main" tabindex="-1" class="flex-1 focus:outline-none">
                <div class="mx-auto w-full max-w-[88rem] px-4 py-4 sm:px-6 sm:py-6 lg:px-8">
                    @foreach (['success' => 'success', 'status' => 'success', 'error' => 'danger', 'warning' => 'warning'] as $flashKey => $flashVariant)
                        @if (session($flashKey))
                            <div class="mb-4">
                                <x-admin.notice :variant="$flashVariant">{{ session($flashKey) }}</x-admin.notice>
                            </div>
                        @endif
                    @endforeach

                    @if ($errors->any())
                        <div class="mb-4">
                            <x-admin.notice variant="danger" title="Nothing was saved yet">
                                Fix {{ $errors->count() === 1 ? 'the highlighted field' : 'the highlighted fields' }} and submit again.
                                <ul class="mt-2 list-disc space-y-0.5 pl-5">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </x-admin.notice>
                        </div>
                    @endif

                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>
</body>
</html>
