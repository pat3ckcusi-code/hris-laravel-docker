/**
 * Uniform Inspection Management JS
 *
 * Responsibilities:
 *  1. Dynamic employee violation rows (create + edit forms)
 *  2. Employee autocomplete (reuses /api/employee-search)
 *  3. Violation history display per employee
 *  4. AJAX status update on the show page
 */

(function () {
    'use strict';

    // ─── Row counter ──────────────────────────────────────────────────────────
    // On the edit page, existing rows already occupy indices 0..N-1.
    // The counter starts after the highest existing index so new rows don't collide.
    let rowCounter = 0;

    function nextIndex() {
        return rowCounter++;
    }

    // ─── Template cloning ─────────────────────────────────────────────────────
    function cloneTemplate(index) {
        const tpl = document.getElementById('violationRowTemplate');
        if (!tpl) return null;

        const html = tpl.innerHTML
            .replace(/__INDEX__/g, index)
            .replace(/__NUM__/g, document.querySelectorAll('#violationRowsContainer .violation-row').length + 1);

        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        return wrapper.firstElementChild;
    }

    // ─── Add row button ───────────────────────────────────────────────────────
    function addRow() {
        const index = nextIndex();
        const row = cloneTemplate(index);
        if (!row) return;

        const container = document.getElementById('violationRowsContainer');
        container.appendChild(row);
        renumberRows();
        initRowEvents(row);
        updateEmptyState();

        // Focus the search input in the new row
        const input = row.querySelector('.emp-search-input');
        if (input) { setTimeout(() => input.focus(), 50); }
    }

    function initAddRowButton() {
        const btn    = document.getElementById('addRowBtn');
        const btnAlt = document.getElementById('addRowBtnAlt');
        if (btn)    btn.addEventListener('click', addRow);
        if (btnAlt) btnAlt.addEventListener('click', addRow);
    }

    // ─── Remove row (delegated) ───────────────────────────────────────────────
    function initRemoveRows() {
        const container = document.getElementById('violationRowsContainer');
        if (!container) return;

        container.addEventListener('click', function (e) {
            const btn = e.target.closest('.remove-row-btn');
            if (!btn) return;

            const row = btn.closest('.vrow-card, .violation-row');
            if (!row) return;

            if (document.querySelectorAll('#violationRowsContainer .vrow-card, #violationRowsContainer .violation-row').length <= 1) {
                alert('At least one employee violation is required.');
                return;
            }

            row.remove();
            renumberRows();
            updateEmptyState();
        });
    }

    function renumberRows() {
        document.querySelectorAll('#violationRowsContainer .vrow-card, #violationRowsContainer .violation-row').forEach((row, i) => {
            const num = row.querySelector('.row-number');
            if (num) num.textContent = i + 1;
        });
        updateRowCountBadge();
    }

    function updateEmptyState() {
        const hint      = document.getElementById('emptyRowsHint');
        const container = document.getElementById('violationRowsContainer');
        if (!hint || !container) return;
        const hasRows = container.querySelectorAll('.vrow-card, .violation-row').length > 0;
        hint.style.display = hasRows ? 'none' : 'block';
        updateRowCountBadge();
    }

    function updateRowCountBadge() {
        const badge = document.getElementById('rowCountBadge');
        if (!badge) return;
        const count = document.querySelectorAll('#violationRowsContainer .vrow-card, #violationRowsContainer .violation-row').length;
        if (count > 0) {
            badge.textContent = count + (count === 1 ? ' employee' : ' employees');
            badge.style.display = 'inline-flex';
        } else {
            badge.style.display = 'none';
        }
    }

    // ─── Employee autocomplete ────────────────────────────────────────────────
    function initRowEvents(row) {
        const searchInput = row.querySelector('.emp-search-input');
        const hiddenId    = row.querySelector('.emp-id-input');
        const suggestions = row.querySelector('.emp-suggestions');
        const priorSpan   = row.querySelector('.prior-violations');

        if (!searchInput || !hiddenId || !suggestions) return;

        let debounceTimer = null;

        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            const q = this.value.trim();

            if (q.length < 2) {
                suggestions.style.display = 'none';
                hiddenId.value = '';
                if (priorSpan) { priorSpan.classList.add('hidden'); }
                row.classList.remove('has-employee');
                return;
            }

            debounceTimer = setTimeout(() => {
                fetch('/api/employee-search?q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => {
                        const items = Array.isArray(data) ? data : (data.data ?? []);
                        if (!items.length) {
                            suggestions.innerHTML = '<div class="list-group-item" style="color:#94a3b8;font-size:0.85rem;cursor:default;">No employees found.</div>';
                            suggestions.style.display = 'block';
                            return;
                        }

                        suggestions.innerHTML = items.map(emp =>
                            `<button type="button" class="list-group-item list-group-item-action"
                                     data-id="${emp.id}"
                                     data-dept-id="${emp.department?.Dept_id ?? ''}"
                                     data-name="${escHtml((emp.last_name ?? '') + ', ' + (emp.first_name ?? ''))} (${escHtml(emp.EmpNo ?? '')})">
                                <span style="font-weight:600;">${escHtml((emp.last_name ?? '') + ', ' + (emp.first_name ?? ''))}</span>
                                <small>${escHtml(emp.EmpNo ?? '')}${emp.department?.Dept_name ? ' &mdash; ' + escHtml(emp.department.Dept_name) : ''}</small>
                             </button>`
                        ).join('');

                        suggestions.style.display = 'block';
                    })
                    .catch(() => { suggestions.style.display = 'none'; });
            }, 250);
        });

        suggestions.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-id]');
            if (!btn) return;

            hiddenId.value    = btn.dataset.id;
            searchInput.value = btn.dataset.name;
            suggestions.style.display = 'none';
            row.classList.add('has-employee');


            // Load violation history count
            if (priorSpan) {
                priorSpan.textContent = '';
                priorSpan.classList.remove('hidden', 'clean');
                fetch('/api/uniform-inspection/employee-history?employee_id=' + btn.dataset.id)
                    .then(r => r.json())
                    .then(data => {
                        const count = (data.data ?? []).length;
                        if (count > 0) {
                            priorSpan.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${count} prior violation${count > 1 ? 's' : ''} - this will be offense #${count + 1}`;
                        } else {
                            priorSpan.innerHTML = `<i class="fas fa-check-circle"></i> No prior violations`;
                            priorSpan.classList.add('clean');
                        }
                        priorSpan.classList.remove('hidden');
                    })
                    .catch(() => { priorSpan.classList.add('hidden'); });
            }
        });

        // Close suggestions when clicking outside
        document.addEventListener('click', function (e) {
            if (!row.contains(e.target)) {
                suggestions.style.display = 'none';
            }
        });
    }

    // ─── Filter page: employee autocomplete ───────────────────────────────────
    function initIndexFilterAutocomplete() {
        const searchInput = document.getElementById('idxEmpSearch');
        const hiddenId    = document.getElementById('idxEmpId');
        const suggestions = document.getElementById('idxEmpSuggestions');
        if (!searchInput || !hiddenId || !suggestions) return;

        let debounceTimer = null;

        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            const q = this.value.trim();

            if (q.length < 2) {
                suggestions.style.display = 'none';
                hiddenId.value = '';
                return;
            }

            debounceTimer = setTimeout(() => {
                fetch('/api/employee-search?q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => {
                        const items = Array.isArray(data) ? data : (data.data ?? []);
                        if (!items.length) { suggestions.style.display = 'none'; return; }

                        suggestions.innerHTML = items.map(emp =>
                            `<button type="button" class="list-group-item list-group-item-action"
                                     data-id="${emp.id}"
                                     data-name="${escHtml(emp.last_name + ', ' + emp.first_name)}">
                                ${escHtml(emp.last_name + ', ' + emp.first_name)}
                                <small class="text-muted">${escHtml(emp.EmpNo ?? '')}</small>
                             </button>`
                        ).join('');

                        suggestions.style.display = 'block';
                    })
                    .catch(() => { suggestions.style.display = 'none'; });
            }, 250);
        });

        suggestions.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-id]');
            if (!btn) return;
            hiddenId.value    = btn.dataset.id;
            searchInput.value = btn.dataset.name;
            suggestions.style.display = 'none';
        });

        document.addEventListener('click', function (e) {
            if (!searchInput.contains(e.target) && !suggestions.contains(e.target)) {
                suggestions.style.display = 'none';
            }
        });
    }

    // ─── Utility ──────────────────────────────────────────────────────────────
    function escHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ─── Bootstrap ────────────────────────────────────────────────────────────
    function init() {
        // Compute starting counter from existing rows so new rows don't clash
        const existingRows = document.querySelectorAll('#violationRowsContainer .violation-row');
        rowCounter = existingRows.length;

        // Wire up autocomplete on all existing rows (edit page pre-populated rows)
        existingRows.forEach(row => initRowEvents(row));

        initAddRowButton();
        initRemoveRows();
        initIndexFilterAutocomplete();
        updateEmptyState();

        // On the create page, start with one empty row
        if (document.getElementById('inspectionForm') && existingRows.length === 0) {
            addRow();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
