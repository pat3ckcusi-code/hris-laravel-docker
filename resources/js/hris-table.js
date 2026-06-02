/**
 * HRIS Table Utilities
 * Shared JavaScript for HRIS table interactions (filters, search, etc.)
 */

(function(window) {
    'use strict';

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initHrisTables);
    } else {
        initHrisTables();
    }

    function initHrisTables() {
        initMonthFilters();
        initSearchDebounce();
        initExportButtons();
    }

    /**
     * Initialize month filter dropdowns
     */
    function initMonthFilters() {
        const monthSelects = document.querySelectorAll('.hris-filter-select');
        
        monthSelects.forEach(select => {
            select.addEventListener('change', function() {
                const formId = 'filter-form-' + this.name;
                const form = document.getElementById(formId);
                if (form) {
                    form.submit();
                }
            });
        });
    }

    /**
     * Add debounce to search forms to prevent excessive submissions
     */
    function initSearchDebounce() {
        const searchForms = document.querySelectorAll('.hris-search-form');
        const DEBOUNCE_DELAY = 300; // milliseconds
        let debounceTimer;

        searchForms.forEach(form => {
            const input = form.querySelector('.hris-search-input');
            
            if (input) {
                input.addEventListener('input', function() {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => {
                        form.submit();
                    }, DEBOUNCE_DELAY);
                });

                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        clearTimeout(debounceTimer);
                        form.submit();
                    }
                });
            }
        });
    }

    /**
     * Initialize export buttons
     */
    function initExportButtons() {
        const exportBtn = document.getElementById('export-btn');
        
        if (exportBtn) {
            exportBtn.addEventListener('click', function(e) {
                e.preventDefault();
                // Will be overridden per-module as needed
                console.log('Export functionality to be implemented per module');
            });
        }
    }

    /**
     * Utility: Format date as YYYY-MM-DD
     */
    window.formatDate = function(date) {
        if (typeof date === 'string') {
            date = new Date(date);
        }
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };

    /**
     * Utility: Get current month (1-12)
     */
    window.getCurrentMonth = function() {
        return new Date().getMonth() + 1;
    };

    /**
     * Utility: Get current year
     */
    window.getCurrentYear = function() {
        return new Date().getFullYear();
    };

    /**
     * Utility: Parse ISO date string to readable format
     */
    window.parseDate = function(dateString, format = 'MMM dd, yyyy') {
        try {
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return dateString;
            
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const year = date.getFullYear();
            const month = months[date.getMonth()];
            const day = String(date.getDate()).padStart(2, '0');
            const dayOfWeek = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][date.getDay()];
            
            // Support basic format variations
            if (format === 'MMM dd, yyyy') {
                return `${month} ${day}, ${year}`;
            } else if (format === 'MMM dd') {
                return `${month} ${day}`;
            } else if (format === 'yyyy-mm-dd') {
                return `${year}-${String(date.getMonth() + 1).padStart(2, '0')}-${day}`;
            }
            return dateString;
        } catch (e) {
            return dateString;
        }
    };

    /**
     * Utility: Add visual feedback to form submissions
     */
    window.setFormLoading = function(form, isLoading = true) {
        const buttons = form.querySelectorAll('button[type="submit"]');
        
        buttons.forEach(btn => {
            if (isLoading) {
                btn.disabled = true;
                btn.classList.add('is-loading');
                btn.setAttribute('data-original-text', btn.textContent);
                // Optionally update button text
                // btn.textContent = 'Processing...';
            } else {
                btn.disabled = false;
                btn.classList.remove('is-loading');
                const originalText = btn.getAttribute('data-original-text');
                if (originalText) {
                    btn.textContent = originalText;
                }
            }
        });
    };

    /**
     * Utility: Show alert with SweetAlert2 if available, fallback to native alert
     */
    window.showAlert = function(title, message, type = 'info') {
        if (window.Swal) {
            Swal.fire({
                title: title,
                text: message,
                icon: type,
                confirmButtonText: 'OK'
            });
        } else {
            alert(`${title}\n\n${message}`);
        }
    };

    /**
     * Utility: Show confirmation dialog
     */
    window.showConfirm = function(title, message) {
        return new Promise((resolve) => {
            if (window.Swal) {
                Swal.fire({
                    title: title,
                    text: message,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    resolve(result.isConfirmed);
                });
            } else {
                resolve(confirm(`${title}\n\n${message}`));
            }
        });
    };

})(window);
