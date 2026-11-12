{{-- The TA Hours tables' look, shared by every TA Hours page. --}}
<style>
    .ta-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    .ta-table th, .ta-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-grey); vertical-align: top; }
    .ta-table th { color: var(--text-grey); font-weight: 600; font-size: 12px; text-transform: uppercase; }
    .ta-table tbody tr:hover { background: var(--page-bg); }
    .ta-period { display: flex; gap: 0; margin-bottom: 14px; border: 1px solid var(--border-grey); }
    .ta-period div { flex: 1; padding: 10px 14px; font-weight: 600; color: var(--navy); }
    .ta-period div + div { border-left: 1px solid var(--border-grey); }
    .ta-add-row { margin-top: 14px; padding: 16px; border: 1px solid var(--border-grey); border-radius: 8px; }
    .ta-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
    .ta-plus { font-size: 20px; line-height: 1; padding: 8px 16px; }
    .ta-reason { color: var(--text-grey); font-size: 12px; }
</style>
