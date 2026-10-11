<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden mr-2" icon="bars-2" inset="left" />

            <x-app-logo href="{{ route('home') }}" wire:navigate />

            <flux:navbar class="-mb-px max-lg:hidden">
                <flux:navbar.item icon="calendar-days" :href="route('home')" :current="request()->routeIs('home')" wire:navigate>
                    イベント一覧
                </flux:navbar.item>

                @auth
                    @if (Route::has('orders.index'))
                        <flux:navbar.item icon="ticket" :href="route('orders.index')" :current="request()->routeIs('orders.*')" wire:navigate>
                            マイチケット
                        </flux:navbar.item>
                    @endif

                    @if (auth()->user()->isOrganizer())
                        <flux:navbar.item icon="clipboard-document-list" :href="route('organizer.events.index')" :current="request()->routeIs('organizer.*')" wire:navigate>
                            主催者メニュー
                        </flux:navbar.item>
                    @endif

                    @if (auth()->user()->isAdmin())
                        @if (Route::has('admin.events.index'))
                            <flux:navbar.item icon="shield-check" :href="route('admin.events.index')" :current="request()->routeIs('admin.events.*')" wire:navigate>
                                イベント管理
                            </flux:navbar.item>
                        @endif
                        @if (Route::has('admin.users.index'))
                            <flux:navbar.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>
                                ユーザー管理
                            </flux:navbar.item>
                        @endif
                    @endif

                    <flux:navbar.item icon="cog" :href="route('profile.edit')" :current="request()->is('settings*')" wire:navigate>
                        設定
                    </flux:navbar.item>
                @endauth
            </flux:navbar>

            <flux:spacer />

            @auth
                <flux:dropdown position="bottom" align="end">
                    <flux:profile
                        :initials="auth()->user()->initials()"
                        icon-trailing="chevron-down"
                        data-test="user-menu-button"
                    />

                    <flux:menu>
                        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                            <flux:avatar
                                :name="auth()->user()->name"
                                :initials="auth()->user()->initials()"
                            />
                            <div class="grid flex-1 text-start text-sm leading-tight">
                                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                            </div>
                        </div>

                        <flux:menu.separator />

                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            設定
                        </flux:menu.item>

                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <flux:menu.item
                                as="button"
                                type="submit"
                                icon="arrow-right-start-on-rectangle"
                                class="w-full cursor-pointer"
                                data-test="logout-button"
                            >
                                ログアウト
                            </flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            @else
                <flux:navbar class="max-lg:hidden">
                    <flux:navbar.item :href="route('login')" wire:navigate>ログイン</flux:navbar.item>
                    @if (Route::has('register'))
                        <flux:navbar.item :href="route('register')" wire:navigate>会員登録</flux:navbar.item>
                    @endif
                </flux:navbar>
            @endauth
        </flux:header>

        <!-- Mobile Menu -->
        <flux:sidebar collapsible="mobile" sticky class="lg:hidden border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('home') }}" wire:navigate />
                <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.item icon="calendar-days" :href="route('home')" :current="request()->routeIs('home')" wire:navigate>
                    イベント一覧
                </flux:sidebar.item>

                @auth
                    @if (Route::has('orders.index'))
                        <flux:sidebar.item icon="ticket" :href="route('orders.index')" :current="request()->routeIs('orders.*')" wire:navigate>
                            マイチケット
                        </flux:sidebar.item>
                    @endif

                    @if (auth()->user()->isOrganizer())
                        <flux:sidebar.item icon="clipboard-document-list" :href="route('organizer.events.index')" :current="request()->routeIs('organizer.*')" wire:navigate>
                            主催者メニュー
                        </flux:sidebar.item>
                    @endif

                    @if (auth()->user()->isAdmin())
                        @if (Route::has('admin.events.index'))
                            <flux:sidebar.item icon="shield-check" :href="route('admin.events.index')" :current="request()->routeIs('admin.events.*')" wire:navigate>
                                イベント管理
                            </flux:sidebar.item>
                        @endif
                        @if (Route::has('admin.users.index'))
                            <flux:sidebar.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>
                                ユーザー管理
                            </flux:sidebar.item>
                        @endif
                    @endif

                    <flux:sidebar.item icon="cog" :href="route('profile.edit')" :current="request()->is('settings*')" wire:navigate>
                        設定
                    </flux:sidebar.item>
                @else
                    <flux:sidebar.item icon="arrow-right-end-on-rectangle" :href="route('login')" wire:navigate>
                        ログイン
                    </flux:sidebar.item>
                    @if (Route::has('register'))
                        <flux:sidebar.item icon="user-plus" :href="route('register')" wire:navigate>
                            会員登録
                        </flux:sidebar.item>
                    @endif
                @endauth
            </flux:sidebar.nav>
        </flux:sidebar>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
