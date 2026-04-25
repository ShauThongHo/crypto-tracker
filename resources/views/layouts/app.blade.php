<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>HST Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.0/dist/echarts.min.js"></script>
    <link rel="stylesheet" href="{{ asset('style.css') }}">
    <style>
        body { background-color: #020617; color: #f8fafc; font-family: 'Inter', sans-serif; overflow: hidden; }
        .bento-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.5rem; width: 100%; max-width: none; }
        .full-row-card { grid-column: span 2; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); }
        .bento-card { 
            background: #0f172a; border: 1px solid #1e293b; border-radius: 24px; 
            padding: 1.5rem; transition: all 0.3s ease; 
        }
        @media (max-width: 767px) {
            .bento-grid { grid-template-columns: 1fr; }
            .full-row-card { grid-column: span 1; }
        }
        .bento-card:hover { border-color: #38bdf8; transform: translateY(-2px); }
        /* 侧边栏活动状态 */
        .nav-active { background: rgba(56, 189, 248, 0.1); color: #38bdf8; border-right: 3px solid #38bdf8; }
        .nav-inactive { color: #94a3b8; border-right: 3px solid transparent; }
    </style>
</head>
<body class="flex flex-col md:flex-row h-screen w-full bg-[#020617] overflow-hidden">
    <div class="md:hidden flex items-center justify-center h-14 shrink-0 bg-[#090e17] border-b border-slate-800/60 z-40 relative">
        <h1 class="text-lg font-bold tracking-widest text-white">Crypto Tracker<span class="text-sky-400">.</span></h1>
    </div>

    @include('partials.navbar')

    <main class="flex-1 w-full h-full overflow-y-auto relative no-scrollbar pb-32 md:pb-0">
        @yield('content')
    </main>

    <script src="{{ asset('dashboard.js') }}?v={{ @filemtime(public_path('dashboard.js')) }}"></script>
</body>
</html>