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
/**
 * Read the plugin's CSRF token (rendered by boot.php into #printing-csrf) and
 * return it as a URL-encoded "name=value" pair for inclusion in fetch bodies,
 * or an empty string when no token is available.
 *
 * @returns {string}
 */
function printingCsrfBody() {
    const holder = document.getElementById('printing-csrf');
    if (!holder) return '';
    const input = holder.querySelector('input[name]');
    if (!input || !input.value) return '';
    return encodeURIComponent(input.name) + '=' + encodeURIComponent(input.value);
}

async function togglePrintQueue(modelId, btn) {
    try {
        let body = 'action=toggle&model_id=' + encodeURIComponent(modelId);
        const csrf = printingCsrfBody();
        if (csrf) body += '&' + csrf;

        const response = await fetch('/actions/print-queue', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
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
        formData.append('action', 'calculate');
        formData.append('model_id', modelId);
        // Forward any cost inputs the page provides to the calculator endpoint.
        ['filament_used_g', 'print_time_minutes', 'filament_type'].forEach(function (name) {
            const el = document.getElementById(name) || document.querySelector('[name="' + name + '"]');
            if (el && el.value) formData.append(name, el.value);
        });

        const response = await fetch('/actions/cost-calculator', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success && data.breakdown) {
            const currency = data.currency || 'USD';
            const total = data.breakdown.total;
            const target = document.getElementById('cost-result');
            if (target) {
                target.textContent = currency + ' ' + total;
            } else {
                alert('Estimated print cost: ' + currency + ' ' + total);
            }
            if (btn) {
                btn.textContent = 'Calculate Print Cost';
                btn.disabled = false;
            }
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
   Mesh Analysis
   ===================== */

/** Escape a string for safe insertion as HTML text. */
function meshEscapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

/** Render a mesh analysis result object into HTML. */
function renderMeshAnalysis(a) {
    if (!a) return '<p class="mesh-empty">No analysis available.</p>';

    var html = '';
    if (a.is_manifold === true) {
        html += '<p class="mesh-ok">&#10003; Mesh is manifold (watertight).</p>';
    } else if (a.is_manifold === false) {
        html += '<p class="mesh-bad">&#9888; Mesh is not watertight.</p>';
    } else {
        html += '<p class="mesh-empty">Manifold status could not be determined' + (a.tool === 'basic' ? ' (basic analysis)' : '') + '.</p>';
    }

    if (a.issues && a.issues.length) {
        html += '<ul class="mesh-issues">';
        a.issues.forEach(function (i) {
            html += '<li class="mesh-issue mesh-sev-' + (i.severity || 'info') + '">' + meshEscapeHtml(i.message || i.type || '') + '</li>';
        });
        html += '</ul>';
    } else {
        html += '<p class="mesh-ok">No issues detected.</p>';
    }

    if (a.stats && a.stats.facets) {
        html += '<p class="mesh-stats">' + Number(a.stats.facets).toLocaleString() + ' facets'
            + (a.stats.format ? ' (' + meshEscapeHtml(a.stats.format) + ' STL)' : '') + '</p>';
    }
    return html;
}

/** Run an analyze/repair request for the mesh-analysis container holding btn. */
async function runMeshAction(btn, action) {
    var container = btn.closest('.mesh-analysis');
    if (!container) return;

    var modelId = container.dataset.modelId;
    var resultEl = container.querySelector('.mesh-result');
    var actionsEl = container.querySelector('.mesh-actions');
    var original = btn.textContent;
    btn.disabled = true;
    btn.textContent = action === 'repair' ? 'Repairing...' : 'Analyzing...';

    try {
        var body = 'action=' + action + '&model_id=' + encodeURIComponent(modelId);
        var csrf = printingCsrfBody();
        if (csrf) body += '&' + csrf;

        var response = await fetch('/actions/mesh', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        });
        var data = await response.json();

        if (data.success) {
            if (resultEl) resultEl.innerHTML = renderMeshAnalysis(data.analysis);
            var hasRepair = actionsEl && actionsEl.querySelector('.mesh-repair-btn');
            if (data.analysis && data.analysis.can_repair && actionsEl && !hasRepair) {
                var rb = document.createElement('button');
                rb.type = 'button';
                rb.className = 'btn btn-warning mesh-repair-btn';
                rb.dataset.modelId = modelId;
                rb.textContent = 'Repair Mesh';
                actionsEl.appendChild(rb);
            } else if (data.analysis && !data.analysis.can_repair && hasRepair) {
                hasRepair.remove();
            }
        } else if (resultEl) {
            resultEl.innerHTML = '<p class="mesh-bad">' + meshEscapeHtml(data.error || 'Action failed') + '</p>';
        }
    } catch (err) {
        console.error('Mesh ' + action + ' failed:', err);
        if (resultEl) resultEl.innerHTML = '<p class="mesh-bad">Request failed</p>';
    } finally {
        btn.disabled = false;
        btn.textContent = original;
    }
}

// Delegated handler so buttons work even when the tab is rendered lazily.
document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var analyzeBtn = e.target.closest('.mesh-analyze-btn');
    if (analyzeBtn) { runMeshAction(analyzeBtn, 'analyze'); return; }
    var repairBtn = e.target.closest('.mesh-repair-btn');
    if (repairBtn) { runMeshAction(repairBtn, 'repair'); }
});

/* =====================
   Slicer Protocol Definitions
   ===================== */

// Must match lib/slicers.php
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
