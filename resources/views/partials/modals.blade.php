<div id="addAssetModal"
    class="fixed inset-0 z-50 hidden flex items-center justify-center backdrop-blur-sm bg-black/60 transition-opacity duration-300 opacity-0">
    <div id="modalContent"
        class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-lg shadow-2xl transform scale-95 transition-all duration-300 overflow-hidden">
        <div class="flex border-b border-slate-800">
            <button class="flex-1 py-4 text-sm font-bold border-b-2 border-sky-500 text-sky-500">✍️ 录入资产</button>
            <div class="flex-1 py-4 text-sm font-bold text-slate-600 text-center cursor-not-allowed">API 同步 (待开发)</div>
        </div>
        <form onsubmit="submitNewAsset(event)" class="p-6 space-y-5">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-slate-400 text-[10px] font-bold mb-1 uppercase">资产来源</label>
                    <select id="asset_source_dropdown" name="source_name"
                        class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white outline-none"></select>
                </div>
                <div>
                    <label class="block text-slate-400 text-[10px] font-bold mb-1 uppercase">所在网络</label>
                    <input type="text" name="network" placeholder="如: SOL"
                        class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white uppercase outline-none focus:border-sky-500">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-slate-400 text-[10px] font-bold mb-1 uppercase">选择代币</label>
                    <select id="asset_token_dropdown" name="token_name" onchange="updateHiddenId(this)"
                        class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white outline-none focus:border-sky-500"></select>
                    <input type="hidden" id="hidden_coingecko_id" name="coingecko_id">
                </div>
                <div>
                    <label class="block text-slate-400 text-[10px] font-bold mb-1 uppercase">持有数量</label>
                    <input type="number" step="any" name="token_amount" placeholder="0.00"
                        class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white outline-none focus:border-sky-500">
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-4">
                <button type="button" onclick="closeAddModal()"
                    class="text-slate-400 text-sm font-bold px-4">取消</button>
                <button type="submit"
                    class="bg-sky-500 text-white px-8 py-2.5 rounded-xl font-bold text-sm shadow-lg shadow-sky-500/20">保存到看板</button>
            </div>
        </form>
    </div>
</div>

<div id="editAssetModal"
    class="fixed inset-0 z-50 hidden flex items-center justify-center backdrop-blur-sm bg-black/60 transition-opacity duration-300 opacity-0">
    <div id="editModalContent"
        class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-lg shadow-2xl transform scale-95 transition-all duration-300 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-800 flex justify-between items-center">
            <h3 class="text-sm font-bold text-white uppercase tracking-widest">📝 编辑资产信息</h3>
            <span id="edit-token-label"
                class="text-[10px] bg-sky-500/10 text-sky-400 px-2 py-1 rounded-md font-mono"></span>
        </div>
        <form onsubmit="submitEditAsset(event)" class="p-6 space-y-5">
            <input type="hidden" id="edit_asset_id"><input type="hidden" id="edit_source_name">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-slate-400 text-[10px] font-bold mb-1 uppercase">修改网络 (Chain)</label>
                    <input type="text" id="edit_network" name="network"
                        class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white uppercase outline-none focus:border-sky-500">
                </div>
                <div>
                    <label class="block text-slate-400 text-[10px] font-bold mb-1 uppercase">修改持有数量</label>
                    <input type="number" step="any" id="edit_token_amount" name="token_amount"
                        class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white outline-none focus:border-sky-500">
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-4">
                <button type="button" onclick="closeEditModal()"
                    class="text-slate-400 text-sm font-bold px-4">取消</button>
                <button type="submit"
                    class="bg-sky-500 text-white px-8 py-2.5 rounded-xl font-bold text-sm shadow-lg shadow-sky-500/20">更新数据</button>
            </div>
        </form>
    </div>
</div>