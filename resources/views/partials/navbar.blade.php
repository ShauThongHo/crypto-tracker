<aside
    class="fixed bottom-0 left-0 w-full bg-[#090e17]/95 backdrop-blur-md border-t border-slate-800/60 z-50 md:relative md:w-64 md:h-full md:border-t-0 md:border-r md:flex md:flex-col shrink-0"
    style="padding-bottom: var(--safe-area-bottom, 0px);">

    <div class="hidden md:flex h-24 items-center px-8 shrink-0">
        <h1 class="text-2xl font-bold tracking-widest text-white">Crypto Tracker<span
                class="text-sky-400 text-3xl">.</span></h1>
    </div>

    <nav
        class="flex flex-row md:flex-col flex-1 px-2 py-1.5 md:mt-4 md:space-y-2 md:px-3 justify-around md:justify-start">
        <a href="/"
            class="flex flex-col md:flex-row items-center justify-center md:justify-start gap-1 md:gap-4 px-2 md:px-5 py-2 md:py-3.5 rounded-xl font-medium text-[10px] md:text-sm w-full transition-all {{ request()->is('/') ? 'nav-active' : 'nav-inactive' }}">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z">
                </path>
            </svg>
            资产总览
        </a>

        <a href="/history"
            class="flex flex-col md:flex-row items-center justify-center md:justify-start gap-1 md:gap-4 px-2 md:px-5 py-2 md:py-3.5 rounded-xl font-medium text-[10px] md:text-sm w-full transition-all {{ request()->is('history') ? 'nav-active' : 'nav-inactive' }}">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            盈亏历史
        </a>

        <a href="/capital"
            class="flex flex-col md:flex-row items-center justify-center md:justify-start gap-1 md:gap-4 px-2 md:px-5 py-2 md:py-3.5 rounded-xl font-medium text-[10px] md:text-sm w-full transition-all {{ request()->is('capital') ? 'nav-active' : 'nav-inactive' }}">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
            </svg>
            出入金
        </a>

        <a href="/settings"
            class="flex flex-col md:flex-row items-center justify-center md:justify-start gap-1 md:gap-4 px-2 md:px-5 py-2 md:py-3.5 rounded-xl font-medium text-[10px] md:text-sm w-full transition-all {{ request()->is('settings') ? 'nav-active' : 'nav-inactive' }}">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z">
                </path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
            </svg>
            系统设置
        </a>
    </nav>
</aside>