@extends('layouts.app')

@section('content')
    <section id="view-portfolio" class="content-view block p-4 md:p-8 lg:p-12">
        <header class="mb-8 flex items-end justify-between">
            <div>
                <h2 class="text-slate-500 text-sm font-medium tracking-widest uppercase mb-2">My Portfolio</h2>
                <div class="flex flex-col gap-2">
                    <div class="flex items-end gap-3">
                        <div id="total-value" class="text-5xl font-light text-white tracking-tight">$0.00</div>
                        <div id="roi-badge" class="hidden px-3 py-1.5 rounded-lg text-xs font-bold mb-1 transition-all">
                            ROI: <span id="roi-value">0.00%</span>
                        </div>
                    </div>

                    <div
                        class="flex items-center gap-2 px-2 py-1 bg-emerald-500/10 rounded-full border border-emerald-500/20 w-fit mt-1">
                        <span class="relative flex h-2 w-2">
                            <span
                                class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                        <span id="sync-badge" class="text-[10px] text-emerald-500 font-bold uppercase tracking-tighter">Sync
                            Aligned</span>
                    </div>
                </div>
            </div>

            <button onclick="openAddModal()"
                class="bg-sky-500 hover:bg-sky-400 text-white text-sm font-bold py-2.5 px-6 rounded-xl shadow-lg transition-all flex items-center gap-2 mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd"
                        d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"
                        clip-rule="evenodd" />
                </svg>
                添加资产
            </button>
        </header>

        <div class="w-full h-64 md:h-80 bg-[#0f172a] border border-slate-800 rounded-3xl mb-8 p-4 relative">
            <div class="absolute top-6 right-8 z-20 flex gap-2">
                <button onclick="changeRange('1D')"
                    class="range-btn bg-sky-500 text-white text-[10px] font-bold px-3 py-1 rounded-md">1D</button>
                <button onclick="changeRange('7D')"
                    class="range-btn bg-slate-800 text-slate-400 text-[10px] font-bold px-3 py-1 rounded-md">7D</button>
                <button onclick="changeRange('30D')"
                    class="range-btn bg-slate-800 text-slate-400 text-[10px] font-bold px-3 py-1 rounded-md">30D</button>
            </div>
            <div id="echarts-container" class="w-full h-full"></div>
        </div>

        <div class="bento-grid" id="grid-container"></div>
    </section>

    @include('partials.modals')
@endsection