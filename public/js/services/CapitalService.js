export const CapitalService = {
    async fetchHistory() {
        return fetch('/api/capital/history').then(r => r.json());
    },
    async fetchAssets() {
        return fetch('/api/assets/thinking-map').then(r => r.json()); // 复用已有的思维导图 API
    },
    async saveRecord(data) {
        return fetch('/api/capital/record', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
    },
    async deleteRecord(id) {
        return fetch(`/api/capital/${id}`, { method: 'DELETE' });
    },
    async clearAllRecords() {
        return fetch('/api/capital/clear', { method: 'DELETE' });
    }
};