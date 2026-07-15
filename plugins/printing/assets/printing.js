/**
 * MeshSilo Printing Plugin - JavaScript
 *
 * Handles print type selection, printed status, mass print-type actions,
 * slicer integration, and cost calculation.
 */

/* =====================
   Shared Helpers
   ===================== */

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
   Send to Slicer
   ===================== */

// URL protocols per slicer. Must match lib/slicers.php.
const slicerProtocols = {
    'bambustudio': 'bambustudio://open?file={url}',
    'orcaslicer': 'orcaslicer://open?file={url}',
    'prusaslicer': 'prusaslicer://open?file={url}',
    'cura': 'cura://open?file={url}',
    'superslicer': 'superslicer://open?file={url}'
};

/**
 * Determine which model/part ids a clicked slicer link targets.
 * Supports an explicit list (data-ids), a single part/model, or a whole folder.
 */
function slicerIdsFor(link) {
    if (link.dataset.ids) {
        return link.dataset.ids.split(',').filter(Boolean);
    }
    if (link.dataset.partId) {
        return [link.dataset.partId];
    }
    if (link.dataset.modelId) {
        return [link.dataset.modelId];
    }
    if (link.dataset.folder !== undefined) {
        var group = link.closest('.parts-group');
        if (group) {
            return Array.prototype.map.call(
                group.querySelectorAll('.part-item[data-part-id]'),
                function (p) { return p.dataset.partId; }
            );
        }
    }
    return [];
}

/**
 * Fetch signed download tokens for the given parts and hand them to the slicer
 * via its URL protocol. Multiple files are passed as repeated file= params.
 * Slicers without a protocol just download the file(s).
 */
async function sendToSlicer(slicerKey, ids) {
    if (!ids || !ids.length) {
        return;
    }
    try {
        const resp = await fetch('/actions/slicer?action=urls&ids=' + encodeURIComponent(ids.join(',')));
        const data = await resp.json();
        if (!data.success || !data.files || !data.files.length) {
            alert('Could not prepare files for the slicer: ' + (data.error || 'no sliceable files'));
            return;
        }
        const origin = window.location.origin;
        const urls = data.files.map(function (f) {
            return origin + '/actions/slicer?action=download&token=' + encodeURIComponent(f.token);
        });

        const proto = slicerProtocols[slicerKey];
        if (proto) {
            var intent;
            if (urls.length === 1) {
                intent = proto.replace('{url}', encodeURIComponent(urls[0]));
            } else {
                var base = proto.replace('file={url}', '');
                intent = base + urls.map(function (u) { return 'file=' + encodeURIComponent(u); }).join('&');
            }
            window.location.href = intent;
        } else {
            // Download-only slicer: fetch each file so it opens in the default handler.
            urls.forEach(function (u) { window.open(u, '_blank'); });
        }
    } catch (err) {
        console.error('Send to slicer failed:', err);
        alert('Failed to send to slicer');
    }
}

/*
 * Open a slicer dropdown by portaling its menu to <body>.
 *
 * Containers such as model cards use overflow:hidden AND a CSS transform (from
 * their entrance animation). The transform makes a nested position:fixed menu
 * resolve against the card rather than the viewport, and the overflow then clips
 * the lower entries - leaving some slicers unreachable. Moving the menu to <body>
 * on open (and restoring it on close) sidesteps both traps. Folder menus resolve
 * their part ids up front so id-gathering still works once the menu has left the
 * .parts-group it depended on.
 */
function slicerOpen(dropdown) {
    var menu = dropdown.querySelector('.dropdown-menu');
    var toggle = dropdown.querySelector('.slicer-toggle');
    if (!menu || !toggle) {
        return;
    }
    // Resolve folder ids before the menu is detached from its .parts-group.
    var folderLinks = menu.querySelectorAll('.slicer-link[data-folder]');
    if (folderLinks.length && !folderLinks[0].dataset.ids) {
        var ids = slicerIdsFor(folderLinks[0]);
        if (ids.length) {
            var joined = ids.join(',');
            Array.prototype.forEach.call(folderLinks, function (fl) { fl.dataset.ids = joined; });
        }
    }

    dropdown.classList.add('open');
    dropdown._slicerMenu = menu;
    menu._slicerHome = { parent: menu.parentNode, next: menu.nextSibling };
    document.body.appendChild(menu);
    menu.classList.add('slicer-menu-portal');

    var r = toggle.getBoundingClientRect();
    var mw = menu.offsetWidth || 200;
    var mh = menu.offsetHeight;
    menu.style.position = 'fixed';
    menu.style.left = Math.max(8, Math.min(r.right - mw, window.innerWidth - mw - 8)) + 'px';
    // Flip above the toggle if it would overflow the viewport bottom.
    menu.style.top = ((r.bottom + 4 + mh > window.innerHeight - 8 && r.top - mh - 4 > 8)
        ? (r.top - mh - 4)
        : (r.bottom + 4)) + 'px';
}

/* Close a slicer dropdown, restoring a portaled menu to its original place. */
function slicerClose(dropdown) {
    if (!dropdown.classList.contains('open')) {
        return;
    }
    dropdown.classList.remove('open');
    var menu = dropdown._slicerMenu;
    if (menu && menu._slicerHome) {
        menu.classList.remove('slicer-menu-portal');
        menu.style.position = menu.style.top = menu.style.left = '';
        menu._slicerHome.parent.insertBefore(menu, menu._slicerHome.next);
        menu._slicerHome = null;
    }
    dropdown._slicerMenu = null;
}

function slicerCloseAll(except) {
    document.querySelectorAll('.slicer-dropdown.open').forEach(function (d) {
        if (d !== except) {
            slicerClose(d);
        }
    });
}

// Toggle handling. Capture phase: model cards wrap the dropdown in an element
// that calls event.stopPropagation() in the bubble phase (to suppress card
// navigation), which would otherwise hide this click from a bubble-phase handler.
document.addEventListener('click', function (e) {
    var toggle = e.target.closest ? e.target.closest('.slicer-toggle') : null;
    if (!toggle) {
        // Click anywhere else closes any open slicer dropdown.
        slicerCloseAll(null);
        return;
    }
    e.preventDefault();
    var current = toggle.closest('.slicer-dropdown');
    var wasOpen = current.classList.contains('open');
    slicerCloseAll(current);
    if (wasOpen) {
        slicerClose(current);
    } else {
        slicerOpen(current);
    }
}, true);

// Slicer link activation (capture phase, see above). Works for part rows, model
// cards, and injected folder dropdowns - including once a menu has been portaled.
document.addEventListener('click', function (e) {
    var link = e.target.closest ? e.target.closest('.slicer-link') : null;
    if (!link) {
        return;
    }
    e.preventDefault();
    var slicer = link.dataset.slicer;
    var ids = slicerIdsFor(link);
    slicerCloseAll(null);
    sendToSlicer(slicer, ids);
}, true);

// A portaled menu is positioned once; drop it if the page scrolls or resizes so
// it can never float detached from its toggle.
window.addEventListener('scroll', function () { slicerCloseAll(null); }, true);
window.addEventListener('resize', function () { slicerCloseAll(null); });

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
       Folder "Send to Slicer" injection - the model page renders part folders
       (.parts-group > .folder-actions) with no plugin hook, so add a slicer
       dropdown per folder here. The delegated handler gathers the folder's parts.
       ----------------------- */

    document.querySelectorAll('.parts-group .folder-actions').forEach(function (actions) {
        if (actions.querySelector('.slicer-dropdown')) {
            return;
        }
        var links = (window.printingSlicers || []).map(function (s) {
            return '<a href="#" class="dropdown-item slicer-link" data-slicer="' + s.key
                + '" data-folder="1">' + s.name + '</a>';
        }).join('');
        if (!links) {
            return;
        }
        var wrap = document.createElement('div');
        wrap.className = 'dropdown slicer-dropdown';
        wrap.innerHTML =
            '<button type="button" class="btn btn-small btn-secondary slicer-toggle">Send to slicer <span class="dropdown-arrow">&#9662;</span></button>'
            + '<div class="dropdown-menu dropdown-menu-right">' + links + '</div>';
        actions.appendChild(wrap);
    });

});
