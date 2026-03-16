@extends('layouts.app')

@section('content')
<section class="p-8">
    <header class="mb-8">
        <h2 class="text-slate-500 text-sm uppercase tracking-widest">History</h2>
        <div class="text-3xl text-white font-light">盈亏日历</div>
    </header>

    <div class="bg-[#0f172a] border border-slate-800 rounded-2xl p-6 mb-6 overflow-x-auto">
        <h3 class="text-slate-400 text-sm mb-4">年度热力总览 (2026)</h3>
        <div id="calendar-echarts-container" style="width: 100%; min-width: 800px; height: 180px;"></div>
    </div>

    <div class="bg-[#0f172a] border border-slate-800 rounded-2xl p-6">
        <div class="flex justify-between items-center mb-6 border-b border-slate-800 pb-4">
            <h3 class="text-white font-bold text-lg" id="month-view-title">月度明细</h3>
            <div class="flex gap-2">
                <button onclick="window.changeMonth(-1)" class="px-3 py-1 bg-slate-800 text-white rounded">上个月</button>
                <button onclick="window.changeMonth(1)" class="px-3 py-1 bg-slate-800 text-white rounded">下个月</button>
            </div>
        </div>
        <div id="month-echarts-container" style="width: 100%; height: 600px;"></div>
    </div>
</section>
@endsection