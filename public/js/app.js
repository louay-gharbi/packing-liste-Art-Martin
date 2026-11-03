// public/js/app.js

// Lit le jeton CSRF depuis la meta-balise injectee dans les layouts.
// A envoyer en en-tete X-CSRF-Token pour toute requete POST AJAX.
window.csrfToken = function () {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
};

// Déclarer les configurations communes dans la portée globale ou les attacher à `window`
// pour qu'elles soient accessibles par d'autres scripts.
window.dataTableConfigs = {
    frenchLanguage: {
        "url": baseUrl + "/vendor/datatables/fr-FR.json"
    },
    commonButtons: [
        {
            extend: 'copyHtml5',
            text: '<i class="fas fa-copy"></i> Copier',
            className: 'btn-sm btn-outline-secondary',
            exportOptions: { columns: ':visible:not(.no-export)' }
        },
        {
            extend: 'excelHtml5',
            text: '<i class="fas fa-file-excel"></i> Excel',
            className: 'btn-sm btn-outline-success',
            exportOptions: { columns: ':visible:not(.no-export)' },
            title: () => $('h1').text().trim() || 'Export'
        },
        {
            extend: 'pdfHtml5',
            text: '<i class="fas fa-file-pdf"></i> PDF',
            className: 'btn-sm btn-outline-danger',
            exportOptions: { columns: ':visible:not(.no-export)' },
            title: () => $('h1').text().trim() || 'Export'
        },
        {
            extend: 'print',
            text: '<i class="fas fa-print"></i> Imprimer',
            className: 'btn-sm btn-outline-info',
            exportOptions: { columns: ':visible:not(.no-export)' }
        },
        {
            extend: 'colvis',
            text: '<i class="fas fa-columns"></i> Colonnes',
            className: 'btn-sm btn-outline-dark',
            columnText: function(dt, idx, title) {
                // Icônes pour les colonnes courantes
                const icons = {
                    'Actions': '&#9881;',
                    'Statut': '&#9673;',
                    'Qté': '&#35;',
                    'Code': '&#9878;',
                    'Réf': '&#9878;',
                    'Libellé': '&#9776;',
                    'Commentaire': '&#9998;'
                };
                return (icons[title] ? icons[title] + ' ' : '') + title;
            }
        },
        {
            extend: 'collection',
            text: '<i class="fas fa-cog"></i> Options',
            className: 'btn-sm btn-outline-secondary',
            buttons: [
                {
                    text: '<i class="fas fa-undo-alt me-1"></i> Réinitialiser colonnes',
                    action: function(e, dt, node, config) {
                        // Réinitialiser la visibilité de toutes les colonnes
                        dt.columns().every(function() {
                            this.visible(true);
                        });
                        // Réinitialiser le tri
                        dt.order([]);
                        dt.draw();
                        // Supprimer les préférences sauvegardées
                        const tableId = dt.table().node().id;
                        if (tableId) {
                            localStorage.removeItem('dt_columns_' + tableId);
                            localStorage.removeItem('dt_order_' + tableId);
                        }
                        showToast('Colonnes réinitialisées', 'info');
                    }
                },
                {
                    text: '<i class="fas fa-save me-1"></i> Sauvegarder préférences',
                    action: function(e, dt, node, config) {
                        saveDataTablePreferences(dt);
                        showToast('Préférences sauvegardées', 'success');
                    }
                },
                {
                    text: '<i class="fas fa-sync-alt me-1"></i> Restaurer préférences',
                    action: function(e, dt, node, config) {
                        const restored = restoreDataTablePreferences(dt);
                        if (restored) {
                            showToast('Préférences restaurées', 'success');
                        } else {
                            showToast('Aucune préférence sauvegardée', 'warning');
                        }
                    }
                }
            ]
        }
    ]
};

/**
 * Sauvegarde les préférences d'une DataTable dans localStorage.
 */
function saveDataTablePreferences(dt) {
    const tableId = dt.table().node().id;
    if (!tableId) return;

    const visibleCols = [];
    dt.columns().every(function() {
        visibleCols.push(this.visible());
    });
    const order = dt.order();

    try {
        localStorage.setItem('dt_columns_' + tableId, JSON.stringify(visibleCols));
        localStorage.setItem('dt_order_' + tableId, JSON.stringify(order));
        localStorage.setItem('dt_pageLength_' + tableId, dt.page.len());
    } catch(e) {
        console.warn('Impossible de sauvegarder les préférences DataTable:', e);
    }
}

/**
 * Restaure les préférences d'une DataTable depuis localStorage.
 * Retourne true si des préférences ont été restaurées.
 */
function restoreDataTablePreferences(dt) {
    const tableId = dt.table().node().id;
    if (!tableId) return false;

    let restored = false;
    
    try {
        const colsData = localStorage.getItem('dt_columns_' + tableId);
        if (colsData) {
            const cols = JSON.parse(colsData);
            dt.columns().every(function(idx) {
                if (idx < cols.length) {
                    this.visible(cols[idx]);
                }
            });
            restored = true;
        }

        const orderData = localStorage.getItem('dt_order_' + tableId);
        if (orderData) {
            const order = JSON.parse(orderData);
            if (Array.isArray(order) && order.length > 0) {
                dt.order(order);
            }
            restored = true;
        }

        const pageLen = localStorage.getItem('dt_pageLength_' + tableId);
        if (pageLen) {
            dt.page.len(parseInt(pageLen));
            restored = true;
        }
    } catch(e) {
        console.warn('Erreur restauration préférences:', e);
    }
    
    return restored;
}

/**
 * Affiche un toast (utilisé par les actions DataTables).
 */
function showToast(message, type) {
    // Utiliser le toast container existant ou en créer un
    let container = document.getElementById('scanToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.className = 'scan-toast-container';
        container.id = 'scanToastContainer';
        container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px;pointer-events:none;';
        document.body.appendChild(container);
    }
    
    const icons = {
        success: 'fas fa-check-circle',
        error: 'fas fa-exclamation-circle',
        info: 'fas fa-info-circle',
        warning: 'fas fa-exclamation-triangle'
    };
    
    const toast = document.createElement('div');
    toast.className = 'scan-toast scan-toast-' + (type || 'info');
    toast.innerHTML = '<i class="' + (icons[type] || icons.info) + '"></i> ' + message;
    toast.style.cssText = 'pointer-events:auto;min-width:280px;max-width:420px;padding:12px 20px;border-radius:8px;color:white;font-weight:500;box-shadow:0 4px 12px rgba(0,0,0,0.15);animation:slideInRight 0.4s ease-out forwards;display:flex;align-items:center;gap:10px;';
    
    if (type === 'error') toast.style.background = '#dc3545';
    else if (type === 'success') toast.style.background = '#28a745';
    else if (type === 'warning') { toast.style.background = '#ffc107'; toast.style.color = '#333'; }
    else toast.style.background = '#17a2b8';
    
    container.appendChild(toast);
    setTimeout(() => { if (toast.parentNode) toast.remove(); }, 3000);
}

/**
 * Fonction d'initialisation générique pour DataTables - VERSION AMÉLIORÉE.
 * Attachée à l'objet `window` pour être accessible depuis d'autres scripts.
 */
window.initializeDataTable = function(selector, orderConfig = [], columnDefsConfig = [], pageLength = 100, extraOptions = {}) {
    const table = $(selector);
    if (table.length && !$.fn.DataTable.isDataTable(selector)) {
        try {
            const tableId = $(selector).attr('id');
            
            // Restaurer les préférences sauvegardées
            let restoredOrder = null;
            let restoredPageLength = pageLength;
            
            if (tableId) {
                try {
                    const savedOrder = localStorage.getItem('dt_order_' + tableId);
                    if (savedOrder) {
                        const parsed = JSON.parse(savedOrder);
                        if (Array.isArray(parsed) && parsed.length > 0) {
                            restoredOrder = parsed;
                        }
                    }
                    const savedPageLen = localStorage.getItem('dt_pageLength_' + tableId);
                    if (savedPageLen) {
                        restoredPageLength = parseInt(savedPageLen);
                    }
                } catch(e) { /* ignore */ }
            }
            
            // Construire le sélecteur de pageLength personnalisé
            const lengthMenu = [
                [25, 50, 100, 200, -1],
                ['25', '50', '100', '200', 'Tous']
            ];
            
            // Utiliser l'order restauré si disponible
            const finalOrder = restoredOrder || orderConfig;
            
            const dt = table.DataTable(Object.assign({
                language: window.dataTableConfigs.frenchLanguage,
                order: finalOrder,
                columnDefs: columnDefsConfig,
                pageLength: restoredPageLength,
                lengthMenu: lengthMenu,
                responsive: true,
                stateSave: false, // On gère nous-mêmes avec localStorage
                dom:  "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
                      "<'row'<'col-sm-12'tr>>" +
                      "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>" +
                      "<'row'<'col-sm-12 mt-2'B>>",
                buttons: window.dataTableConfigs.commonButtons,
                initComplete: function(settings, json) {
                    // Au chargement initial, restaurer la visibilité des colonnes
                    if (tableId) {
                        restoreDataTablePreferences(this.api());
                    }
                    
                    // Ajouter des champs de recherche individuels par colonne
                    // si le tableau a la classe 'searchable-columns'
                    const dtTable = this;
                    if ($(selector).hasClass('searchable-columns')) {
                        this.api().columns().every(function() {
                            const column = this;
                            const header = $(column.header());
                            // Ne pas ajouter de champ sur les colonnes sans tri
                            const colIdx = column.index();
                            const isOrderable = columnDefsConfig.some(function(def) {
                                return def.targets === colIdx && def.orderable === false;
                            });
                            if (isOrderable) return;
                            
                            const input = $('<input type="text" class="form-control form-control-sm mt-1" placeholder="Filtrer ' + header.text().trim() + '" style="font-size:0.75rem;padding:2px 6px;" />');
                            header.append(input);
                            
                            input.on('keyup change clear', function() {
                                if (column.search() !== this.value) {
                                    column.search(this.value).draw();
                                }
                            });
                            
                            input.on('click', function(e) {
                                e.stopPropagation();
                            });
                        });
                    }
                    
                    // Appliquer un style après initialisation
                    $(selector + '_wrapper .dt-buttons').addClass('mb-0');
                    // Sortir la barre de boutons du .table-responsive (overflow) :
                    // sinon les menus déroulants Colonnes / Options sont coupés.
                    const responsiveBox = $(selector).closest('.table-responsive');
                    if (responsiveBox.length) {
                        this.api().buttons().container().addClass('mt-2').insertAfter(responsiveBox);
                    }
                },
                drawCallback: function() {
                    // Sauvegarder l'ordre à chaque redessin
                    const dtApi = this.api();
                    const id = $(selector).attr('id');
                    if (id) {
                        try {
                            localStorage.setItem('dt_order_' + id, JSON.stringify(dtApi.order()));
                            localStorage.setItem('dt_pageLength_' + id, dtApi.page.len());
                        } catch(e) { /* ignore quota */ }
                    }
                }
            }, extraOptions));
            
            console.log("DataTable améliorée initialisée pour : " + selector);
            return dt;
        } catch(e) {
            console.error("Erreur initialisation DataTable pour " + selector + ":", e);
        }
    } else if ($(selector).length && $.fn.DataTable.isDataTable(selector)) {
        console.warn("DataTable déjà initialisée pour : " + selector);
        return $(selector).DataTable();
    }
    return null;
};

/**
 * Fonction de recherche globale sur toutes les DataTables de la page.
 */
window.searchAllTables = function(query) {
    $('.dataTable').each(function() {
        if ($.fn.DataTable.isDataTable(this)) {
            $(this).DataTable().search(query).draw();
        }
    });
};

$(document).ready(function() {
    // === RECHERCHE GLOBALE PERSISTANTE ===
    (function() {
        const searchInput = document.getElementById('globalSearchInput');
        const resultsContainer = document.getElementById('globalSearchResults');
        const resultsContent = document.getElementById('globalSearchContent');
        const emptyState = document.getElementById('globalSearchEmpty');
        let debounceTimer = null;
        let currentHighlightIndex = -1;
        let currentItems = [];

        if (!searchInput) return;

        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
            if (e.key === 'Escape' && resultsContainer.classList.contains('open')) {
                closeResults();
                searchInput.blur();
            }
            if (resultsContainer.classList.contains('open')) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    navigateResults(1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    navigateResults(-1);
                } else if (e.key === 'Enter' && currentHighlightIndex >= 0) {
                    e.preventDefault();
                    if (currentItems[currentHighlightIndex]) {
                        window.location.href = currentItems[currentHighlightIndex].url;
                    }
                }
            }
        });

        function closeResults() {
            resultsContainer.classList.remove('open');
            currentHighlightIndex = -1;
            currentItems = [];
        }

        function navigateResults(direction) {
            const items = resultsContent.querySelectorAll('.result-item[data-url]');
            if (items.length === 0) return;
            if (currentHighlightIndex >= 0 && items[currentHighlightIndex]) {
                items[currentHighlightIndex].classList.remove('highlighted');
            }
            currentHighlightIndex += direction;
            if (currentHighlightIndex < 0) currentHighlightIndex = items.length - 1;
            if (currentHighlightIndex >= items.length) currentHighlightIndex = 0;
            const newItem = items[currentHighlightIndex];
            newItem.classList.add('highlighted');
            newItem.scrollIntoView({ block: 'nearest' });
        }

        searchInput.addEventListener('input', function() {
            const query = this.value.trim();
            clearTimeout(debounceTimer);
            if (query.length < 2) {
                resultsContainer.classList.remove('open');
                emptyState.innerHTML = '<i class="fas fa-search fa-2x mb-2 d-block"></i>Tapez au moins 2 caractères pour rechercher';
                emptyState.style.display = 'block';
                resultsContent.innerHTML = '';
                currentItems = [];
                return;
            }
            debounceTimer = setTimeout(function() {
                performSearch(query);
            }, 300);
        });

        function performSearch(query) {
            fetch(baseUrl + '/search/global?q=' + encodeURIComponent(query), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(response) { return response.json(); })
            .then(function(data) { renderResults(data); })
            .catch(function(err) {
                console.error('Global search error:', err);
                emptyState.innerHTML = '<i class="fas fa-exclamation-triangle fa-2x mb-2 d-block"></i>Erreur de recherche';
                emptyState.style.display = 'block';
                resultsContent.innerHTML = '';
                resultsContainer.classList.add('open');
            });
        }

        function renderResults(data) {
            let html = '';
            let itemCount = 0;
            currentItems = [];

            if (data.colis && data.colis.length > 0) {
                html += '<div class="result-section"><i class="fas fa-box me-1"></i>Colis (' + data.colis.length + ')</div>';
                data.colis.forEach(function(c) {
                    const subtitle = (c.of_number || '') + (c.sku ? ' — ' + c.sku : '');
                    // Rediriger vers la palette si la colis en a une (directe ou via parent),
                    // sinon vers la PL si connue, sinon repli search.
                    let colisUrlEncoded;
                    if (c.palette_id) {
                        colisUrlEncoded = baseUrl + '/palettes/show/' + c.palette_id;
                    } else if (c.pl_id) {
                        colisUrlEncoded = baseUrl + '/packing-lists/show/' + c.pl_id;
                    } else {
                        colisUrlEncoded = baseUrl + '/search?q=' + encodeURIComponent(c.package_number);
                    }
                    html += '<a class="result-item" data-url="' + colisUrlEncoded + '" href="' + colisUrlEncoded + '">' +
                        '<div class="result-icon result-icon-colis"><i class="fas fa-box"></i></div>' +
                        '<div class="result-info"><div class="result-title">' + escapeHtml(c.package_number) + '</div>' +
                        '<div class="result-subtitle">' + escapeHtml(subtitle) + '</div></div>' +
                        (c.palette_code ? '<span class="result-badge bg-light text-muted">' + escapeHtml(c.palette_code) + '</span>' : (c.pl_reference ? '<span class="result-badge bg-light text-muted">' + escapeHtml(c.pl_reference) + '</span>' : '')) +
                    '</a>';
                    currentItems.push({ url: colisUrlEncoded, type: 'colis' });
                    itemCount++;
                });
            }

            if (data.palettes && data.palettes.length > 0) {
                html += '<div class="result-section"><i class="fas fa-pallet me-1"></i>Palettes (' + data.palettes.length + ')</div>';
                data.palettes.forEach(function(p) {
                    const statusClass = p.status === 'open' ? 'bg-success' : (p.status === 'closed' ? 'bg-secondary' : 'bg-info');
                    html += '<a class="result-item" data-url="' + baseUrl + '/palettes/show/' + p.id + '" href="' + baseUrl + '/palettes/show/' + p.id + '">' +
                        '<div class="result-icon result-icon-palette"><i class="fas fa-pallet"></i></div>' +
                        '<div class="result-info"><div class="result-title">' + escapeHtml(p.code) + '</div>' +
                        '<div class="result-subtitle">' + (p.pl_reference ? escapeHtml(p.pl_reference) : '') + '</div></div>' +
                        '<span class="result-badge ' + statusClass + ' text-white">' + p.status + '</span>' +
                    '</a>';
                    currentItems.push({ url: baseUrl + '/palettes/show/' + p.id, type: 'palette' });
                    itemCount++;
                });
            }

            if (data.packing_lists && data.packing_lists.length > 0) {
                html += '<div class="result-section"><i class="fas fa-list-alt me-1"></i>Packing Lists (' + data.packing_lists.length + ')</div>';
                data.packing_lists.forEach(function(pl) {
                    const statusClass = pl.status === 'open' ? 'bg-success' : 'bg-secondary';
                    html += '<a class="result-item" data-url="' + baseUrl + '/packing-lists/show/' + pl.id + '" href="' + baseUrl + '/packing-lists/show/' + pl.id + '">' +
                        '<div class="result-icon result-icon-pl"><i class="fas fa-list-alt"></i></div>' +
                        '<div class="result-info"><div class="result-title">' + escapeHtml(pl.reference) + '</div>' +
                        '<div class="result-subtitle">' + (pl.site_name ? escapeHtml(pl.site_name) : '') + '</div></div>' +
                        '<span class="result-badge ' + statusClass + ' text-white">' + pl.status + '</span>' +
                    '</a>';
                    currentItems.push({ url: baseUrl + '/packing-lists/show/' + pl.id, type: 'pl' });
                    itemCount++;
                });
            }

            if (itemCount === 0) {
                emptyState.innerHTML = '<i class="fas fa-search-minus fa-2x mb-2 d-block"></i>Aucun résultat pour "' + escapeHtml(searchInput.value) + '"';
                emptyState.style.display = 'block';
                resultsContent.innerHTML = '';
            } else {
                emptyState.style.display = 'none';
                html += '<div class="result-footer"><small>Navigation avec ↑↓ et Entrée · <kbd>Esc</kbd> pour fermer</small></div>';
                resultsContent.innerHTML = html;
            }

            resultsContainer.classList.add('open');
            currentHighlightIndex = -1;
        }

        document.addEventListener('click', function(e) {
            const wrapper = document.querySelector('.global-search-wrapper');
            if (wrapper && !wrapper.contains(e.target)) {
                closeResults();
            }
        });

        function escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    })();

    // ===== INITIALISATION DATATABLES AVEC SAUVEGARDE =====
    initializeDataTable('#packingListsTable', [[1, "asc"], [2, "desc"]], [{ "orderable": false, "targets": [0, 6] }]);
    initializeDataTable('#usersTable', [[1, "asc"]], [{ "orderable": false, "targets": [8] }]);
    initializeDataTable('#rolesTable', [[1, "asc"]], [{ "orderable": false, "targets": [3] }]);
    initializeDataTable('#permissionsTable', [[1, "asc"]], [{ "orderable": false, "targets": [3] }]);
    initializeDataTable('#sitesTable', [[2, "asc"]], [{ "orderable": false, "targets": [7] }]);
    initializeDataTable('#palettesTableOnPLShowPage', [[5, "desc"]], [{ "orderable": false, "targets": [0, 6] }]);
    initializeDataTable('#allPalettesTable', [[9, "desc"]], [{ "orderable": false, "targets": [0, 10] }]);
    initializeDataTable('#colisTableOnPaletteDetail', [], [{ "orderable": false, "targets": [8] }], -1, { ordering: false, paging: false });
    initializeDataTable('#deviationsFollowUpTable', [], [], 50, { "searching": true });

    // ===== BOUTON RAFRAÎCHIR SUR LES DATATABLES =====
    // Ajoute un bouton "Rafraîchir" à chaque DataTable après initialisation
    $('.dataTable').each(function() {
        const id = $(this).attr('id');
        if (id && $.fn.DataTable.isDataTable(this)) {
            const wrapper = $('#' + id + '_wrapper');
            const buttonsContainer = wrapper.find('.dt-buttons');
            if (buttonsContainer.length) {
                buttonsContainer.append(
                    '<button class="btn btn-sm btn-outline-secondary dt-refresh-btn ml-1" title="Rafraîchir les données" onclick="window.location.reload()">' +
                    '<i class="fas fa-sync-alt"></i>' +
                    '</button>'
                );
            }
        }
    });

    // Activer les tooltips Bootstrap
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl)
    });
});