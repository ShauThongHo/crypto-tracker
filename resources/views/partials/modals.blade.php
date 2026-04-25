<div id="addAssetModal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-[60] flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-3xl overflow-hidden shadow-2xl">
        <form id="addAssetForm" onsubmit="submitNewAsset(event)" class="p-6 space-y-4">
            <h3 class="text-xl font-bold text-white mb-4">录入新资产</h3>
            
            <div id="manual-source-row" class="relative">
                <label class="block text-[10px] text-slate-500 uppercase font-bold mb-1 ml-1">钱包 / 来源</label>
                <input type="text" id="add_source_name" oninput="searchSource(this.value)" autocomplete="off" 
                       class="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-white focus:border-sky-500 outline-none transition-all" 
                       placeholder="搜索或输入来源 (如 Binance)">
                <div id="source_suggestions" class="absolute z-50 w-full mt-1 bg-slate-900 border border-slate-700 rounded-xl hidden max-h-40 overflow-y-auto shadow-2xl"></div>
            </div>

            <div class="relative">
                <label class="block text-[10px] text-slate-500 uppercase font-bold mb-1 ml-1">代币币种</label>
                <input type="text" id="add_token_search" oninput="searchToken(this.value)" autocomplete="off" 
                       class="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-white focus:border-sky-500 outline-none transition-all" 
                       placeholder="搜索代币 (如 CRO, BTC)">
                <input type="hidden" id="add_coingecko_id"> <input type="hidden" id="add_token_name">   <div id="token_suggestions" class="absolute z-50 w-full mt-1 bg-slate-900 border border-slate-700 rounded-xl hidden max-h-40 overflow-y-auto shadow-2xl"></div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold mb-1 ml-1">持有数量</label>
                    <input type="number" id="add_token_amount" step="any" class="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-white outline-none">
                </div>
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold mb-1 ml-1">网络 (Chain)</label>
                    <input type="text" id="add_network" oninput="this.value = this.value.toUpperCase()" placeholder="如: BSC" class="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-white outline-none">
                </div>
            </div>

            <div>
                <label class="block text-[10px] text-slate-500 uppercase font-bold mb-1 ml-1">备注 (Label)</label>
                <input type="text" id="add_label" placeholder="如: Staked" class="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-white outline-none">
            </div>


            <div class="flex gap-3 mt-6">
                <button type="button" onclick="closeAddModal()" class="flex-1 px-4 py-3 rounded-xl bg-slate-800 text-white font-bold">取消</button>
                <button type="submit" class="flex-[2] px-4 py-3 rounded-xl bg-sky-500 text-white font-bold shadow-lg shadow-sky-500/20">确认添加</button>
            </div>
        </form>
    </div>
</div>

<div id="editAssetModal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-[60] flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-3xl overflow-hidden shadow-2xl">
        <form id="editAssetForm" onsubmit="submitEditAsset(event)" class="p-6 space-y-4">
            <h3 class="text-xl font-bold text-white mb-4">编辑资产 <span id="edit-token-label" class="text-[10px] bg-sky-500/10 text-sky-400 px-2 py-1 rounded-md font-mono"></span></h3>
            
            <input type="hidden" id="edit_asset_id">
            
            <div class="relative">
                <label class="block text-[10px] text-slate-500 uppercase font-bold mb-1 ml-1">钱包 / 来源</label>
                <input type="text" id="edit_source_name" 
                       class="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-white focus:border-sky-500 outline-none transition-all" 
                       placeholder="来源">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold mb-1 ml-1">持有数量</label>
                    <input type="number" id="edit_token_amount" step="any" class="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-white outline-none">
                </div>
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold mb-1 ml-1">网络 (Chain)</label>
                    <input type="text" id="edit_network" oninput="this.value = this.value.toUpperCase()" class="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-white outline-none">
                </div>
            </div>

            <div>
                <label class="block text-[10px] text-slate-500 uppercase font-bold mb-1 ml-1">备注 (Label)</label>
                <input type="text" id="edit_label" placeholder="如: Staked" class="w-full bg-slate-800 border border-slate-700 rounded-xl p-3 text-white outline-none">
            </div>

            <div class="flex gap-3 mt-6">
                <button type="button" onclick="closeEditModal()" class="flex-1 px-4 py-3 rounded-xl bg-slate-800 text-white font-bold">取消</button>
                <button type="submit" class="flex-[2] px-4 py-3 rounded-xl bg-sky-500 text-white font-bold shadow-lg shadow-sky-500/20">更新</button>
            </div>
        </form>
    </div>
</div>  