/**
 * MeshSilo Printing Plugin - JavaScript
 *
 * Handles print queue toggling, print type selection, printed status,
 * mass print-type actions, slicer integration, cost calculation,
 * and keyboard shortcuts for printing features.
 */

/* =====================
   Print Queue Toggle
   ===================== */

/**
 * Toggle a model's presence in the print queue via AJAX.
 *
 * @param {number} modelId - The model ID to toggle.
 * @param {HTMLElement} btn - The queue button element.
 */
async function togglePrintQueue(modelId, btn) {
    try {
        const response = await fetch('/actions/print-queue', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=toggle&model_id=' + modelId
        });
        const data = await response.json();
        if (data.success) {
            btn.classList.toggle('in-queue', data.in_queue);
            btn.title = data.in_queue ? 'Remove from print queue' : 'Add to print queue';
        }
    } catch (err) {
        console.error('Failed to toggle print queue:', err);
    }
}

/* =====================
   Calculate Print Cost
   ===================== */

/**
 * Request a print cost calculation for a model.
 *
 * @param {number} modelId - The model ID to calculate cost for.
 */
async function calculateCost(modelId) {
    const btn = document.getElementById('calc-cost-btn');
    if (btn) {
        btn.textContent = 'Calculating...';
        btn.disabled = true;
    }

    try {
        const formData = new FormData();
        formData.append('model_id', modelId);

        const response = await fetch('/actions/calculate-volume', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success && data.cost_estimate) {
            // Reload the page to show the cost estimate
            location.reload();
        } else {
            alert('Could not calculate cost: ' + (data.error || 'Unknown error'));
            if (btn) {
                btn.textContent = 'Calculate Print Cost';
                btn.disabled = false;
            }
        }
    } catch (err) {
        console.error('Failed to calculate cost:', err);
        alert('Failed to calculate cost');
        if (btn) {
            btn.textContent = 'Calculate Print Cost';
            btn.disabled = false;
        }
    }
}

/* =====================
   Slicer Protocol Definitions
   ===================== */

// Must match includes/slicers.php
const slicerProtocols = {
    'bambustudio': 'bambustudio://open?file={url}',
    'orcaslicer': 'orcaslicer://open?file={url}',
    'prusaslicer': 'prusaslicer://open?file={url}',
    'cura': 'cura://open?file={url}',
    'superslicer': 'superslicer://open?file={url}'
};

/* =====================
   DOMContentLoaded Handlers
   ===================== */

document.addEventListener('DOMContentLoaded', function () {

    /* -----------------------
       Print Type Selection
       ----------------------- */

    document.querySelectorAll('.print-type-select').forEach(function (select) {
        select.addEventListener('change', function () {
            const partId = this.dataset.partId;
            const printType = this.value;
            const partItem = this.closest('.part-item');

            // Create form data
            const formData = new FormData();
            formData.append('part_id', partId);
            formData.append('print_type', printType);

            // Send AJAX request
            fetch('/actions/update-part', {
                method: 'POST',
                body: formData
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    // Update the badge
                    var badge = partItem.querySelector('.print-type-badge');
                    if (printType) {
                        if (!badge) {
                            badge = document.createElement('span');
                            badge.className = 'print-type-badge';
                            partItem.querySelector('.part-name').after(badge);
                        }
                        badge.className = 'print-type-badge print-type-' + printType;
                        badge.textContent = printType.toUpperCase();
                    } else if (badge) {
                        badge.remove();
                    }
                } else {
                    alert('Failed to update: ' + (data.error || 'Unknown error'));
                    // Reset select to previous value
                    location.reload();
                }
            })
            .catch(function (err) {
                console.error('Error updating print type:', err);
                alert('Failed to update print type');
            });
        });
    });

    /* -----------------------
       Printed Status Toggle
       ----------------------- */

    document.querySelectorAll('.printed-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            const partId = this.dataset.partId;
            const isPrinted = this.checked ? '1' : '0';
            const partItem = this.closest('.part-item');
            const self = this;

            fetch('/actions/update-part', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'part_id=' + partId + '&is_printed=' + isPrinted
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    // Update the badge
                    var badge = partItem.querySelector('.printed-badge');
                    if (self.checked) {
                        if (!badge) {
                            badge = document.createElement('span');
                            badge.className = 'printed-badge';
                            badge.textContent = 'Printed';
                            var printTypeBadge = partItem.querySelector('.print-type-badge');
                            if (printTypeBadge) {
                                printTypeBadge.after(badge);
                            } else {
                                partItem.querySelector('.part-name').after(badge);
                            }
                        }
                    } else if (badge) {
                        badge.remove();
                    }
                } else {
                    alert('Failed to update: ' + (data.error || 'Unknown error'));
                    self.checked = !self.checked;
                }
            })
            .catch(function (err) {
                console.error('Error updating printed status:', err);
                alert('Failed to update printed status');
                self.checked = !self.checked;
            });
        });
    });

    /* -----------------------
       Mass Action: Print Type
       ----------------------- */

    var massPrintType = document.getElementById('mass-print-type');

    if (massPrintType) {
        massPrintType.addEventListener('change', async function () {
            const printType = this.value;
            if (printType === '') return;

            const ids = Array.from(document.querySelectorAll('.part-checkbox:checked')).map(function (cb) {
                return cb.value;
            });
            if (ids.length === 0) return;

            const formData = new FormData();
            formData.append('action', 'set_print_type');
            formData.append('print_type', printType === 'Clear' ? '' : printType);
            ids.forEach(function (id) { formData.append('ids[]', id); });

            try {
                const response = await fetch('/actions/mass-action', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    location.reload();
                } else {
                    alert('Failed: ' + (result.error || 'Unknown error'));
                }
            } catch (err) {
                console.error('Mass action error:', err);
                alert('Failed to perform mass action');
            }

            this.value = '';
        });
    }

    /* -----------------------
       Slicer Link Handler
       ----------------------- */

    document.querySelectorAll('.slicer-link').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();

            const slicer = this.dataset.slicer;
            const partId = this.dataset.partId;
            const hasProtocol = this.dataset.hasProtocol === '1';

            // Build the download URL
            const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
            const downloadUrl = baseUrl + '/actions/download.php?id=' + partId;

            if (hasProtocol && slicerProtocols[slicer]) {
                // Open using slicer's URL protocol
                var slicerUrl = slicerProtocols[slicer].replace('{url}', encodeURIComponent(downloadUrl));
                window.location.href = slicerUrl;
            } else {
                // No protocol support - just download the file
                // User will need to open it manually in their slicer
                window.location.href = downloadUrl;
            }

            // Close the dropdown
            this.closest('.dropdown').classList.remove('open');
        });
    });

    /* -----------------------
       Keyboard Shortcuts
       ----------------------- */

    // Register printing-related keyboard shortcuts.
    // These integrate with the existing KeyboardNav class if present,
    // or fall back to a standalone keydown listener.

    if (typeof KeyboardNav !== 'undefined' && window.keyboardNav) {
        // KeyboardNav is already instantiated - shortcuts are registered
        // via its constructor in main.js ('q' and 'g q'). No duplicate
        // registration needed when the core shortcuts object already
        // contains these entries.
    } else {
        // Standalone fallback: register shortcuts directly when KeyboardNav
        // is not available (e.g., on pages that don't load main.js).
        var pendingKey = null;
        var pendingTimer = null;

        document.addEventListener('keydown', function (e) {
            // Skip when user is typing in an input, textarea, or select
            var tag = (e.target.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) {
                return;
            }

            var key = e.key;

            // Handle two-key combos (g q)
            if (pendingKey === 'g') {
                clearTimeout(pendingTimer);
                pendingKey = null;

                if (key === 'q') {
                    // Go to print queue page
                    window.location.href = '/print-queue';
                    return;
                }
            }

            if (key === 'g') {
                pendingKey = 'g';
                pendingTimer = setTimeout(function () { pendingKey = null; }, 500);
                return;
            }

            if (key === 'q') {
                // Toggle print queue for the focused/selected model
                var queueBtn = document.querySelector('.queue-btn');
                if (queueBtn) {
                    queueBtn.click();
                }
            }
        });
    }

});
