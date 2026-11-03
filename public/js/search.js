document.addEventListener('DOMContentLoaded', () => {
    // -------------------------------------------------------------------------
    // 1. RÉFÉRENCES AUX ÉLÉMENTS DU DOM
    // -------------------------------------------------------------------------
    const mainSearchBar = document.getElementById('main-search-bar');
    const ofSearchBar = document.getElementById('of-search-bar');
    const skuSearchBar = document.getElementById('sku-search-bar');
    const searchInputs = [mainSearchBar, ofSearchBar, skuSearchBar];
    
    const resultsContainer = document.getElementById('search-results-container');
    const spinner = document.getElementById('search-spinner');
    
    const resultCardTemplate = document.getElementById('result-card-template');
    const recapCardTemplate = document.getElementById('recap-card-template');
    let currentDataTable = null;

    if (typeof baseUrl === 'undefined' || typeof window.initializeDataTable !== 'function') {
        console.error('ERREUR CRITIQUE: `baseUrl` ou `window.initializeDataTable` non défini. Vérifiez que app.js est chargé avant search.js.');
        return;
    }

    // -------------------------------------------------------------------------
    // 2. FONCTION UTILITAIRE : DEBOUNCE
    // -------------------------------------------------------------------------
    const debounce = (func, delay) => {
        let timeoutId;
        return (...args) => {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => { func.apply(this, args); }, delay);
        };
    };

    // -------------------------------------------------------------------------
    // 3. LOGIQUE PRINCIPALE : LA FONCTION DE RECHERCHE AJAX
    // -------------------------------------------------------------------------
    // ----- Helpers internes -----

    // Affiche un message dans le conteneur de resultats SANS innerHTML : tout texte
    // hostile (par exemple un payload serveur contenant <script>) est neutralise
    // par textContent (inerte en contexte de texte ET d attribut).
    const showAlert = (kind, message) => {
        const div = document.createElement('div');
        div.className = `alert alert-${kind}`;
        div.textContent = String(message ?? '');
        resultsContainer.replaceChildren(div);
    };

    // Reload borne : on previent l utilisateur via le conteneur ET on n efface
    // PAS sa saisie en cours. Si une saisie est en cours dans l un des champs,
    // on affiche un bouton "Recharger maintenant" plutot que de recharger
    // silencieusement (evite la perte de saisie).
    const requestReload = (reason) => {
        const hasTypedInput = searchInputs.some(inp => inp && inp.value && inp.value.trim() !== '');
        const container = document.createElement('div');
        container.className = 'alert alert-warning d-flex justify-content-between align-items-center';
        const span = document.createElement('span');
        span.textContent = reason || 'Session expirée. Veuillez recharger la page.';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-warning ms-3';
        btn.textContent = 'Recharger maintenant';
        btn.addEventListener('click', () => window.location.reload());
        container.appendChild(span);
        container.appendChild(btn);
        resultsContainer.replaceChildren(container);
        if (!hasTypedInput) {
            // Aucune saisie en cours : auto-reload apres 1.5s pour confort utilisateur.
            setTimeout(() => window.location.reload(), 1500);
        }
        // Sinon, l utilisateur conserve sa saisie ; il clique quand il veut.
    };

    const performSearch = async (query, type) => {
        if (currentDataTable) {
            currentDataTable.destroy();
            currentDataTable = null;
        }

        if (!query) {
            const div = document.createElement('div');
            div.className = 'text-center text-muted p-4';
            const p = document.createElement('p');
            p.textContent = 'Utilisez l\'un des champs ci-dessus.';
            div.appendChild(p);
            resultsContainer.replaceChildren(div);
            return;
        }

        spinner.style.display = 'block';
        let apiUrl = '';
        switch(type) {
            case 'of': apiUrl = `${baseUrl}/search/by-of`; break;
            case 'sku': apiUrl = `${baseUrl}/search/by-sku`; break;
            default: apiUrl = `${baseUrl}/search/ajax`;
        }

        try {
            const token = window.csrfToken ? window.csrfToken() : '';
            if (!token) {
                // Meta CSRF absente : page chargee avant deploiement Phase 1.
                requestReload('Session expirée (jeton manquant). Veuillez recharger la page.');
                return;
            }
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': token
                },
                body: JSON.stringify({ query: query })
            });

            // Tenter de parser le body comme JSON meme si la response n est pas ok
            // (notre middleware CSRF renvoie du JSON sur erreur).
            let payload = null;
            const contentType = response.headers.get('content-type') || '';
            if (contentType.includes('application/json')) {
                try { payload = await response.json(); } catch (_) { /* body non-JSON */ }
            }

            if (!response.ok) {
                // CSRF expire : signal explicite du serveur (reload:true). On borne le reload.
                if (payload && payload.reload) {
                    requestReload(payload.error || 'Session expirée. Veuillez recharger la page.');
                    return;
                }
                const msg = (payload && payload.error)
                    ? payload.error
                    : `Erreur (${response.status}) lors de la recherche.`;
                throw new Error(msg);
            }

            const data = payload !== null ? payload : await response.json();
            if (type === 'of' || type === 'sku') {
                renderRecapResult(data);
            } else {
                renderClassicResults(data);
            }
        } catch (error) {
            console.error('Erreur de recherche:', error);
            showAlert('danger', (error && error.message) ? error.message : 'Une erreur est survenue.');
        } finally {
            spinner.style.display = 'none';
        }
    };
    
    // -------------------------------------------------------------------------
    // 4. FONCTIONS DE RENDU (AFFICHAGE DES RÉSULTATS)
    // -------------------------------------------------------------------------
    const renderClassicResults = (results) => {
        // ... (cette fonction reste inchangée)
        resultsContainer.innerHTML = '';
        if (results.length === 0) {
            resultsContainer.innerHTML = `<div class="text-center text-muted p-4"><p>Aucun résultat trouvé.</p></div>`;
            return;
        }
        results.forEach(result => {
            const card = resultCardTemplate.content.cloneNode(true).querySelector('.result-card');
            card.querySelector('.icon i').classList.add(result.icon || 'fa-question-circle');
            const titleLink = (result.actions.view && result.actions.view.url) ? `${baseUrl}${result.actions.view.url}` : '#';
            card.querySelector('.title').innerHTML = `<a href="${titleLink}" target="_blank">${result.title}</a>`;
            const detailsHtml = Object.entries(result.details).map(([key, value]) => `<span class="me-3"><strong>${key}:</strong> ${value}</span>`).join('');
            card.querySelector('.details').innerHTML = detailsHtml;
            const contextHtml = Object.entries(result.context).filter(([, value]) => value !== null).map(([key, value]) => {
                return value.url ? `<span class="me-3">${key}: <a href="${baseUrl}${value.url}" target="_blank">${value.text}</a></span>` : `<span class="me-3">${key}: ${value.text}</span>`;
            }).join('');
            card.querySelector('.context').innerHTML = contextHtml;
            const actionsContainer = card.querySelector('.actions');
            if (result.actions.view && result.actions.view.url) {
                const viewLink = document.createElement('a');
                viewLink.href = `${baseUrl}${result.actions.view.url}`;
                viewLink.className = 'btn btn-sm btn-outline-primary';
                viewLink.title = 'Voir les détails';
                viewLink.innerHTML = '<i class="fas fa-eye"></i>';
                viewLink.target = '_blank';
                actionsContainer.appendChild(viewLink);
            }
            resultsContainer.appendChild(card);
        });
    };

    const renderRecapResult = (data) => {
        resultsContainer.innerHTML = '';
        if (!data || data.colis_count === 0) {
            resultsContainer.innerHTML = `<div class="text-center text-muted p-4"><p>Aucune donnée trouvée pour la référence <strong>${data.reference || ''}</strong>.</p></div>`;
            return;
        }

        const card = recapCardTemplate.content.cloneNode(true).querySelector('.card');
        
        // Remplir les statistiques (correct)
        card.querySelector('.recap-title').innerHTML = `Récapitulatif ${data.type}: <strong>${data.reference}</strong>`;
        card.querySelector('.total-quantity').textContent = data.total_quantity;
        card.querySelector('.total-colis').textContent = data.colis_count;
        card.querySelector('.total-palettes').textContent = data.palettes_count;

        // Remplir les listes de PL et Palettes
        const plListContainer = card.querySelector('.packing-lists-container ul');
        plListContainer.innerHTML = ''; // Vider la liste par défaut
        if (data.packing_lists && data.packing_lists.length > 0) {
            data.packing_lists.forEach(pl => {
                const li = document.createElement('li');
                li.className = 'list-group-item list-group-item-action';
                li.innerHTML = `<a href="${baseUrl}/packing-lists/show/${pl.id}" target="_blank" class="text-decoration-none d-flex justify-content-between align-items-center"><span>${pl.reference}</span><i class="fas fa-external-link-alt fa-xs text-muted"></i></a>`;
                plListContainer.appendChild(li);
            });
        } else {
             plListContainer.innerHTML = '<li class="list-group-item text-muted small">Aucune Packing List associée.</li>';
        }

        const paletteListContainer = card.querySelector('.palettes-container ul');
        paletteListContainer.innerHTML = ''; // Vider la liste par défaut
        if (data.palettes && data.palettes.length > 0) {
            data.palettes.forEach(palette => {
                const li = document.createElement('li');
                li.className = 'list-group-item list-group-item-action';
                li.innerHTML = `<a href="${baseUrl}/palettes/show/${palette.id}" target="_blank" class="text-decoration-none d-flex justify-content-between align-items-center"><span>${palette.code}</span><i class="fas fa-external-link-alt fa-xs text-muted"></i></a>`;
                paletteListContainer.appendChild(li);
            });
        } else {
            paletteListContainer.innerHTML = '<li class="list-group-item text-muted small">Aucune palette associée.</li>';
        }
        // --- FIN DU CODE RESTAURÉ ---

        // Construire la DataTable (logique inchangée et correcte)
        const tableContainer = card.querySelector('.colis-details-table-container');
        const tableId = `recap-table-${Date.now()}`;
        
        let tableHtml = `
            <table id="${tableId}" class="table table-sm table-striped table-bordered" style="width:100%">
                <thead>
                    <tr>
                        <th>N° Colis</th>
                        <th class="text-end">Quantité</th>
                        <th>Palette</th>
                        <th>Packing List</th>
                    </tr>
                </thead>
                <tbody>`;
        data.colis_details.forEach(colis => {
            tableHtml += `
                <tr>
                    <td>${colis.package_number}</td>
                    <td class="text-end">${colis.quantity}</td>
                    <td><a href="${baseUrl}/palettes/show/${colis.palette_id}" target="_blank">${colis.palette_code}</a></td>
                    <td><a href="${baseUrl}/packing-lists/show/${colis.pl_id}" target="_blank">${colis.pl_reference}</a></td>
                </tr>`;
        });
        tableHtml += `</tbody></table>`;
        tableContainer.innerHTML = tableHtml;
        
        resultsContainer.appendChild(card);

        // Appel à la fonction globale d'initialisation (inchangé et correct)
        setTimeout(() => {
            const tableSelector = `#${tableId}`;
            const orderConfig = [[ 3, "asc" ], [ 2, "asc" ], [ 0, "asc" ]];
            const extraOptions = {
                buttons: window.dataTableConfigs.commonButtons.map(button => {
                    if (['excelHtml5', 'pdfHtml5'].includes(button.extend)) {
                        let newButton = { ...button };
                        newButton.title = `Export Colis - ${data.type} ${data.reference}`;
                        return newButton;
                    }
                    return button;
                })
            };
            currentDataTable = window.initializeDataTable(tableSelector, orderConfig, [], 10, extraOptions);
        }, 0);
    };

    // -------------------------------------------------------------------------
    // 5. GESTION DES ÉVÉNEMENTS
    // -------------------------------------------------------------------------
    const debouncedSearch = debounce(performSearch, 400);
    searchInputs.forEach(activeInput => {
        activeInput.addEventListener('input', () => {
            const query = activeInput.value.trim();
            searchInputs.forEach(input => {
                if (input !== activeInput) input.disabled = !!query;
            });
            let type = 'global';
            if (activeInput.id === 'of-search-bar') type = 'of';
            if (activeInput.id === 'sku-search-bar') type = 'sku';
            debouncedSearch(query, type);
        });
    });
});