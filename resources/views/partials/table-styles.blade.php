<style>
    /* ===== Unified Table Styles (from Mayor Travel Order Approvals) ===== */

    /* Tables */
    .leave-table { width:100%; border-collapse:collapse; margin-top:12px; }
    .leave-table th, .leave-table td { border:1px solid #e5e7eb; padding:10px 12px; font-size:14px; text-align:left; }
    .leave-table th { background:#f9fafb; font-weight:600; text-transform:uppercase; font-size:12px; letter-spacing:.04em; }
    .leave-table tr:hover { background:#f0f9ff; }

    /* Status badges */
    .badge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:12px; font-weight:600; text-transform:capitalize; }
    .badge-pending   { background:#fef3c7; color:#92400e; }
    .badge-requested { background:#fef3c7; color:#92400e; }
    .badge-approved  { background:#dcfce7; color:#15803d; }
    .badge-completed { background:#dcfce7; color:#15803d; }
    .badge-active    { background:#dcfce7; color:#15803d; }
    .badge-rejected  { background:#fee2e2; color:#b91c1c; }
    .badge-separated { background:#fee2e2; color:#b91c1c; }
    .badge-cancelled { background:#f3f4f6; color:#6b7280; }
    .badge-draft     { background:#f3f4f6; color:#6b7280; }
    .badge-inactive  { background:#f3f4f6; color:#6b7280; }
    .badge-default   { background:#f3f4f6; color:#6b7280; }

    /* Action buttons */
    .btn-sm { padding:5px 12px; font-size:13px; border:none; border-radius:4px; cursor:pointer; font-weight:600; }
    .btn-view    { background:#2563eb; color:#fff; }
    .btn-view:hover    { background:#1d4ed8; }
    .btn-approve { background:#16a34a; color:#fff; }
    .btn-approve:hover { background:#15803d; }
    .btn-reject  { background:#dc2626; color:#fff; }
    .btn-reject:hover  { background:#b91c1c; }
    .btn-print   { background:#ff9248; color:#fff; }
    .btn-print:hover   { background:#ff6700; }
    .action-btns { display:flex; gap:6px; flex-wrap:wrap; }

    /* Empty state */
    .empty-state { text-align:center; padding:40px 20px; color:#6b7280; font-size:15px; }

    /* Pagination */
    .pagination-wrap { margin-top:16px; display:flex; justify-content:center; }
    .pagination-wrap nav { display:flex; gap:4px; }

    /* Overlay modal (div-based) */
    .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; justify-content:center; align-items:center; }
    .modal-overlay.active { display:flex; }
    .modal-box { background:#fff; border-radius:8px; max-width:650px; width:95%; max-height:85vh; overflow-y:auto; padding:24px; box-shadow:0 20px 50px rgba(0,0,0,.25); }
    .modal-box h3 { margin:0 0 16px; font-size:18px; }
    .modal-box .detail-row { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f3f4f6; font-size:14px; }
    .modal-box .detail-row:last-child { border-bottom:none; }
    .modal-close-btn { float:right; background:none; border:none; font-size:22px; cursor:pointer; color:#6b7280; }
    .modal-close-btn:hover { color:#111; }
    .modal-section-title { font-weight:600; font-size:15px; margin:16px 0 8px; padding-bottom:4px; border-bottom:2px solid #e5e7eb; }
    .modal-actions { display:flex; gap:8px; margin-top:18px; justify-content:flex-end; }

    /* Dialog modal (native dialog) - match modal-box aesthetic */
    dialog.employee-modal,
    dialog.dept-modal {
        border:none;
        border-radius:8px;
        max-width:650px;
        width:95%;
        max-height:85vh;
        overflow-y:auto;
        padding:24px;
        box-shadow:0 20px 50px rgba(0,0,0,.25);
    }
    dialog.employee-modal::backdrop,
    dialog.dept-modal::backdrop {
        background:rgba(0,0,0,.45);
    }

    /* Employee / detail list table in modals */
    .emp-list-table { width:100%; border-collapse:collapse; margin-top:6px; font-size:13px; }
    .emp-list-table th, .emp-list-table td { border:1px solid #e5e7eb; padding:6px 10px; text-align:left; }
    .emp-list-table th { background:#f9fafb; font-weight:600; }

    /* Filter row */
    .mayor-filter-row { display:flex; gap:12px; align-items:center; margin-bottom:18px; flex-wrap:wrap; }
    .mayor-filter-row label { font-weight:600; font-size:14px; }
    .mayor-filter-row select,
    .mayor-filter-row input[type="month"] { padding:6px 10px; border:1px solid #d1d5db; border-radius:4px; font-size:14px; }
</style>
