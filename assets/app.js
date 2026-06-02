const sidebar = document.querySelector('#sidebar');
const toggle = document.querySelector('[data-sidebar-toggle]');

if (window.lucide) {
    window.lucide.createIcons();
}

if (toggle && sidebar) {
    toggle.addEventListener('click', () => {
        sidebar.classList.toggle('open');
    });
}

document.querySelectorAll('[data-nav-dropdown]').forEach((dropdown) => {
    const button = dropdown.querySelector('.nav-dropdown-toggle');

    if (!button) {
        return;
    }

    button.addEventListener('click', () => {
        const isOpen = dropdown.classList.toggle('open');
        button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
});

document.querySelectorAll('[data-user-menu]').forEach((menu) => {
    const button = menu.querySelector('[data-user-menu-toggle]');

    if (!button) {
        return;
    }

    const closeMenu = () => {
        menu.classList.remove('open');
        button.setAttribute('aria-expanded', 'false');
    };

    button.addEventListener('click', (event) => {
        event.stopPropagation();
        const isOpen = menu.classList.toggle('open');
        button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    menu.addEventListener('click', (event) => {
        event.stopPropagation();
    });

    document.addEventListener('click', closeMenu);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMenu();
        }
    });
});

document.querySelectorAll('[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const message = form.getAttribute('data-confirm') || 'Are you sure?';

        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });
});

document.querySelectorAll('a[aria-disabled="true"]').forEach((link) => {
    link.addEventListener('click', (event) => event.preventDefault());
});

document.querySelectorAll('[data-backup-file-input]').forEach((input) => {
    const picker = input.closest('.backup-file-picker');
    const fileName = picker?.querySelector('[data-backup-file-name]');

    input.addEventListener('change', () => {
        if (!fileName) {
            return;
        }

        fileName.textContent = input.files?.[0]?.name || 'No file selected';
    });
});

const headerFilterMenus = Array.from(document.querySelectorAll('.th-filter-menu'));

if (headerFilterMenus.length) {
    const closeHeaderFilterMenus = (except = null) => {
        headerFilterMenus.forEach((menu) => {
            if (menu !== except) {
                menu.open = false;
            }
        });
    };

    headerFilterMenus.forEach((menu) => {
        menu.addEventListener('toggle', () => {
            if (menu.open) {
                closeHeaderFilterMenus(menu);
            }
        });

        menu.addEventListener('click', (event) => {
            event.stopPropagation();
        });
    });

    document.addEventListener('click', () => closeHeaderFilterMenus());
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeHeaderFilterMenus();
        }
    });
}

document.querySelectorAll('[data-permission-panel]').forEach((panel) => {
    const checkboxes = Array.from(panel.querySelectorAll('[data-permission-checkbox]'));
    const optionalCheckboxes = checkboxes.filter((checkbox) => !checkbox.disabled);
    const groupToggles = Array.from(panel.querySelectorAll('[data-permission-group-toggle]'));
    const actionButtons = Array.from(panel.querySelectorAll('[data-permission-action]'));

    const syncPermissionState = () => {
        checkboxes.forEach((checkbox) => {
            checkbox.closest('.permission-card')?.classList.toggle('checked', checkbox.checked);
        });

        groupToggles.forEach((toggle) => {
            const group = toggle.dataset.permissionGroupToggle;
            const groupBoxes = optionalCheckboxes.filter((checkbox) => checkbox.dataset.permissionGroup === group);
            const checkedCount = groupBoxes.filter((checkbox) => checkbox.checked).length;

            toggle.checked = groupBoxes.length > 0 && checkedCount === groupBoxes.length;
            toggle.indeterminate = checkedCount > 0 && checkedCount < groupBoxes.length;
        });
    };

    checkboxes.forEach((checkbox) => {
        checkbox.addEventListener('change', syncPermissionState);
    });

    groupToggles.forEach((toggle) => {
        toggle.addEventListener('change', () => {
            const group = toggle.dataset.permissionGroupToggle;

            optionalCheckboxes
                .filter((checkbox) => checkbox.dataset.permissionGroup === group)
                .forEach((checkbox) => {
                    checkbox.checked = toggle.checked;
                });

            syncPermissionState();
        });
    });

    actionButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const action = button.dataset.permissionAction;

            optionalCheckboxes.forEach((checkbox) => {
                checkbox.checked = action === 'all';
            });

            syncPermissionState();
        });
    });

    syncPermissionState();
});

const purchaseForm = document.querySelector('[data-purchase-form]');

if (purchaseForm) {
    const rowsContainer = purchaseForm.querySelector('[data-purchase-rows]');
    const template = document.querySelector('[data-purchase-row-template]');
    const addButton = purchaseForm.querySelector('[data-add-purchase-row]');
    const subtotalInput = purchaseForm.querySelector('[data-purchase-subtotal]');
    const discountInput = purchaseForm.querySelector('[data-purchase-discount]');
    const totalInput = purchaseForm.querySelector('[data-purchase-total]');
    const paidInput = purchaseForm.querySelector('[data-purchase-paid]');
    const balanceInput = purchaseForm.querySelector('[data-purchase-balance]');
    const productSearchUrl = purchaseForm.dataset.productSearchUrl || '';
    const supplierSearchUrl = purchaseForm.dataset.supplierSearchUrl || '';
    const supplierInput = purchaseForm.querySelector('[data-supplier-search]');
    const supplierHidden = purchaseForm.querySelector('[data-purchase-supplier]');
    const supplierSuggestions = purchaseForm.querySelector('[data-supplier-suggestions]');
    let supplierSearchTimer = null;
    let supplierSearchToken = 0;

    const money = (value) => Number.isFinite(value) ? value.toFixed(2) : '0.00';

    const recalculate = () => {
        let subtotal = 0;

        rowsContainer.querySelectorAll('[data-purchase-row]').forEach((row) => {
            const quantity = Number.parseFloat(row.querySelector('[data-purchase-quantity]')?.value || '0');
            const cost = Number.parseFloat(row.querySelector('[data-purchase-cost]')?.value || '0');
            const lineTotal = Math.max(0, quantity) * Math.max(0, cost);
            const lineTotalInput = row.querySelector('[data-purchase-line-total]');

            if (lineTotalInput) {
                lineTotalInput.value = money(lineTotal);
            }

            subtotal += lineTotal;
        });

        const discount = Math.max(0, Number.parseFloat(discountInput?.value || '0'));
        const paid = Math.max(0, Number.parseFloat(paidInput?.value || '0'));
        const total = Math.max(0, subtotal - discount);
        const balance = Math.max(0, total - paid);

        if (subtotalInput) subtotalInput.value = money(subtotal);
        if (totalInput) totalInput.value = money(total);
        if (balanceInput) balanceInput.value = money(balance);
    };

    const refreshRemoveButtons = () => {
        const rows = rowsContainer.querySelectorAll('[data-purchase-row]');

        rows.forEach((row) => {
            const removeButton = row.querySelector('[data-remove-purchase-row]');
            if (removeButton) {
                removeButton.disabled = rows.length <= 1;
            }
        });
    };

    const closeSupplierSuggestions = () => {
        if (!supplierSuggestions) {
            return;
        }

        supplierSuggestions.hidden = true;
        supplierSuggestions.innerHTML = '';
    };

    const showSupplierSuggestionMessage = (message) => {
        if (!supplierSuggestions) {
            return;
        }

        supplierSuggestions.innerHTML = `<div class="product-suggestion-empty">${message}</div>`;
        supplierSuggestions.hidden = false;
    };

    const selectSupplier = (supplier) => {
        if (supplierHidden) {
            supplierHidden.value = String(supplier.id || '');
        }

        if (supplierInput) {
            supplierInput.value = supplier.label || supplier.name || '';
        }

        closeSupplierSuggestions();
    };

    const renderSupplierSuggestions = (suppliers) => {
        if (!supplierSuggestions) {
            return;
        }

        supplierSuggestions.innerHTML = '';

        if (!suppliers.length) {
            showSupplierSuggestionMessage('No suppliers found');
            return;
        }

        suppliers.forEach((supplier) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'product-suggestion-item';
            button.innerHTML = `
                <strong></strong>
                <span></span>
            `;
            button.querySelector('strong').textContent = supplier.label || supplier.name || '';
            button.querySelector('span').textContent = [supplier.contact, supplier.phone, supplier.email].filter(Boolean).join(' / ') || 'Supplier account';
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => selectSupplier(supplier));
            supplierSuggestions.appendChild(button);
        });

        supplierSuggestions.hidden = false;
    };

    const runSupplierSearch = () => {
        if (!supplierInput || !supplierSearchUrl) {
            return;
        }

        const query = supplierInput.value.trim();

        if (supplierHidden) {
            supplierHidden.value = '';
        }

        if (query.length < 2) {
            closeSupplierSuggestions();
            return;
        }

        const token = ++supplierSearchToken;
        showSupplierSuggestionMessage('Searching...');

        fetch(`${supplierSearchUrl}?q=${encodeURIComponent(query)}`, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.ok ? response.json() : { suppliers: [] })
            .then((data) => {
                if (token !== supplierSearchToken) {
                    return;
                }

                renderSupplierSuggestions(Array.isArray(data.suppliers) ? data.suppliers : []);
            })
            .catch(() => {
                if (token === supplierSearchToken) {
                    showSupplierSuggestionMessage('Search failed');
                }
            });
    };

    if (supplierInput) {
        supplierInput.addEventListener('input', () => {
            if (supplierHidden) {
                supplierHidden.value = '';
            }

            window.clearTimeout(supplierSearchTimer);
            supplierSearchTimer = window.setTimeout(runSupplierSearch, 180);
        });

        supplierInput.addEventListener('focus', () => {
            if (supplierInput.value.trim().length >= 2) {
                runSupplierSearch();
            }
        });

        supplierInput.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeSupplierSuggestions();
            }
        });

        supplierInput.addEventListener('blur', () => {
            window.setTimeout(closeSupplierSuggestions, 120);
        });
    }

    const hydrateRow = (row) => {
        const productInput = row.querySelector('[data-product-search]');
        const productHidden = row.querySelector('[data-purchase-product]');
        const suggestions = row.querySelector('[data-product-suggestions]');
        const warrantyInput = row.querySelector('[data-purchase-warranty]');
        const costInput = row.querySelector('[data-purchase-cost]');
        let searchTimer = null;
        let searchToken = 0;
        let selectedCategory = null;

        row.querySelectorAll('[data-purchase-quantity], [data-purchase-cost]').forEach((input) => {
            input.addEventListener('input', recalculate);
        });

        const closeSuggestions = () => {
            if (!suggestions) {
                return;
            }

            suggestions.hidden = true;
            suggestions.innerHTML = '';
        };

        const showSuggestionMessage = (message) => {
            if (!suggestions) {
                return;
            }

            suggestions.innerHTML = `<div class="product-suggestion-empty">${message}</div>`;
            suggestions.hidden = false;
        };

        const selectProduct = (product) => {
            selectedCategory = null;

            if (productHidden) {
                productHidden.value = String(product.id || '');
            }

            if (productInput) {
                productInput.value = product.label || '';
            }

            if (costInput) {
                costInput.value = money(Number.parseFloat(product.cost || '0'));
            }

            if (warrantyInput) {
                warrantyInput.value = String(Math.max(0, Number.parseInt(product.warranty || '0', 10) || 0));
            }

            closeSuggestions();
            recalculate();
        };

        const renderCategorySuggestions = (categories) => {
            if (!suggestions) {
                return;
            }

            suggestions.innerHTML = '';

            if (!categories.length) {
                showSuggestionMessage('No categories found');
                return;
            }

            categories.forEach((category) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'product-suggestion-item category-suggestion-item';
                button.innerHTML = `
                    <strong></strong>
                    <span></span>
                `;
                button.querySelector('strong').textContent = category.name || '';
                button.querySelector('span').textContent = `${category.product_count ?? 0} product(s)`;
                button.addEventListener('mousedown', (event) => event.preventDefault());
                button.addEventListener('click', () => {
                    selectedCategory = {
                        id: Number.parseInt(category.id || '0', 10) || 0,
                        name: category.name || '',
                    };

                    if (productInput) {
                        productInput.value = `@${selectedCategory.name} `;
                        productInput.focus();
                    }

                    runProductSearch();
                });
                suggestions.appendChild(button);
            });

            suggestions.hidden = false;
        };

        const renderSuggestions = (products) => {
            if (!suggestions) {
                return;
            }

            suggestions.innerHTML = '';

            if (!products.length) {
                showSuggestionMessage('No products found');
                return;
            }

            products.forEach((product) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'product-suggestion-item';
                button.innerHTML = `
                    <strong></strong>
                    <span></span>
                `;
                button.querySelector('strong').textContent = product.label || '';
                button.querySelector('span').textContent = `${product.category ? `${product.category} / ` : ''}Stock ${product.stock ?? 0} / Cost ${money(Number.parseFloat(product.cost || '0'))} / Warranty ${product.warranty ?? 0} mo`;
                button.addEventListener('mousedown', (event) => event.preventDefault());
                button.addEventListener('click', () => selectProduct(product));
                suggestions.appendChild(button);
            });

            suggestions.hidden = false;
        };

        const runProductSearch = () => {
            if (!productInput || !productSearchUrl) {
                return;
            }

            const rawQuery = productInput.value.trim();
            let query = rawQuery;
            let categoryId = 0;
            let categoryMode = rawQuery.startsWith('@');

            if (productHidden) {
                productHidden.value = '';
            }

            if (selectedCategory) {
                const categoryPrefix = `@${selectedCategory.name}`;
                const lowerRawQuery = rawQuery.toLowerCase();
                const lowerCategoryPrefix = categoryPrefix.toLowerCase();

                if (lowerRawQuery === lowerCategoryPrefix || lowerRawQuery.startsWith(`${lowerCategoryPrefix} `)) {
                    categoryMode = false;
                    categoryId = selectedCategory.id;
                    query = rawQuery.slice(categoryPrefix.length).trim();
                } else {
                    selectedCategory = null;
                    categoryMode = rawQuery.startsWith('@');
                    query = rawQuery;
                }
            }

            if (!categoryMode && categoryId <= 0 && query.length < 2) {
                closeSuggestions();
                return;
            }

            const token = ++searchToken;
            showSuggestionMessage(categoryMode ? 'Searching categories...' : 'Searching products...');

            const params = new URLSearchParams({ q: query });

            if (categoryId > 0) {
                params.set('category_id', String(categoryId));
            }

            fetch(`${productSearchUrl}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            })
                .then((response) => response.ok ? response.json() : { products: [] })
                .then((data) => {
                    if (token !== searchToken) {
                        return;
                    }

                    if (Array.isArray(data.categories)) {
                        renderCategorySuggestions(data.categories);
                    } else {
                        renderSuggestions(Array.isArray(data.products) ? data.products : []);
                    }
                })
                .catch(() => {
                    if (token === searchToken) {
                        showSuggestionMessage('Search failed');
                    }
                });
        };

        if (productInput) {
            productInput.addEventListener('input', () => {
                if (productHidden) {
                    productHidden.value = '';
                }

                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(runProductSearch, 180);
            });

            productInput.addEventListener('focus', () => {
                if (productInput.value.trim().length >= 2) {
                    runProductSearch();
                }
            });

            productInput.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeSuggestions();
                }
            });

            productInput.addEventListener('blur', () => {
                window.setTimeout(closeSuggestions, 120);
            });
        }

        const removeButton = row.querySelector('[data-remove-purchase-row]');
        if (removeButton) {
            removeButton.addEventListener('click', () => {
                if (rowsContainer.querySelectorAll('[data-purchase-row]').length <= 1) {
                    return;
                }

                row.remove();
                refreshRemoveButtons();
                recalculate();
            });
        }
    };

    rowsContainer.querySelectorAll('[data-purchase-row]').forEach(hydrateRow);

    if (addButton && template) {
        addButton.addEventListener('click', () => {
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-purchase-row]');

            rowsContainer.appendChild(fragment);

            if (row) {
                hydrateRow(row);
            }

            if (window.lucide) {
                window.lucide.createIcons();
            }

            refreshRemoveButtons();
            recalculate();
        });
    }

    [discountInput, paidInput].forEach((input) => {
        if (input) {
            input.addEventListener('input', recalculate);
        }
    });

    refreshRemoveButtons();
    recalculate();
}

const stockAdjustForm = document.querySelector('[data-stock-adjust-form]');

if (stockAdjustForm) {
    const productSelect = stockAdjustForm.querySelector('[data-stock-product]');
    const typeSelect = stockAdjustForm.querySelector('[data-stock-adjust-type]');
    const quantityField = stockAdjustForm.querySelector('[data-stock-quantity-field]');
    const exactField = stockAdjustForm.querySelector('[data-stock-exact-field]');
    const quantityInput = stockAdjustForm.querySelector('[data-stock-quantity]');
    const exactInput = stockAdjustForm.querySelector('[data-stock-exact]');
    const currentDisplay = stockAdjustForm.querySelector('[data-stock-current]');
    const preview = stockAdjustForm.querySelector('[data-stock-preview]');

    const selectedStock = () => {
        const selected = productSelect?.selectedOptions[0];
        return Number.parseInt(selected?.dataset.stock || '0', 10);
    };

    const renderStockPreview = () => {
        const hasProduct = Boolean(productSelect?.value);
        const currentStock = selectedStock();
        const type = typeSelect?.value || 'increase';
        const quantity = Math.max(0, Number.parseInt(quantityInput?.value || '0', 10));
        const exact = Math.max(0, Number.parseInt(exactInput?.value || '0', 10));
        let nextStock = currentStock;
        let change = 0;

        if (currentDisplay) {
            currentDisplay.textContent = hasProduct ? String(currentStock) : 'Choose product';
        }

        if (type === 'count') {
            nextStock = exact;
            change = nextStock - currentStock;
            quantityField?.classList.add('hidden-field');
            exactField?.classList.remove('hidden-field');
        } else {
            const direction = type === 'increase' ? 1 : -1;
            change = direction * quantity;
            nextStock = currentStock + change;
            quantityField?.classList.remove('hidden-field');
            exactField?.classList.add('hidden-field');
        }

        if (!preview) {
            return;
        }

        if (!hasProduct) {
            preview.textContent = 'Select a product to preview the stock change.';
            return;
        }

        const signedChange = change > 0 ? `+${change}` : String(change);
        preview.textContent = nextStock < 0
            ? 'This adjustment would make stock negative and cannot be saved.'
            : `Change ${signedChange}; stock will become ${nextStock}.`;
    };

    [productSelect, typeSelect, quantityInput, exactInput].forEach((input) => {
        if (input) {
            input.addEventListener('input', renderStockPreview);
            input.addEventListener('change', renderStockPreview);
        }
    });

    renderStockPreview();
}

const saleForm = document.querySelector('[data-sale-form]');

if (saleForm) {
    const rowsContainer = saleForm.querySelector('[data-sale-rows]');
    const template = document.querySelector('[data-sale-row-template]');
    const addButton = saleForm.querySelector('[data-add-sale-row]');
    const subtotalInput = saleForm.querySelector('[data-sale-subtotal]');
    const discountInput = saleForm.querySelector('[data-sale-discount]');
    const taxInput = saleForm.querySelector('[data-sale-tax]');
    const totalInput = saleForm.querySelector('[data-sale-total]');
    const paidInput = saleForm.querySelector('[data-sale-paid]');
    const balanceInput = saleForm.querySelector('[data-sale-balance]');
    const saleProductSearchUrl = saleForm.dataset.saleProductSearchUrl || '';
    const saleCustomerSearchUrl = saleForm.dataset.saleCustomerSearchUrl || '';
    const customerInput = saleForm.querySelector('[data-sale-customer-search]');
    const customerHidden = saleForm.querySelector('[data-sale-customer]');
    const customerPhoneInput = saleForm.querySelector('[data-sale-customer-phone]');
    const customerSuggestions = saleForm.querySelector('[data-sale-customer-suggestions]');
    let customerSearchTimer = null;
    let customerSearchToken = 0;

    const money = (value) => Number.isFinite(value) ? value.toFixed(2) : '0.00';

    const closeCustomerSuggestions = () => {
        if (!customerSuggestions) {
            return;
        }

        customerSuggestions.hidden = true;
        customerSuggestions.innerHTML = '';
    };

    const showCustomerSuggestionMessage = (message) => {
        if (!customerSuggestions) {
            return;
        }

        customerSuggestions.innerHTML = `<div class="product-suggestion-empty">${message}</div>`;
        customerSuggestions.hidden = false;
    };

    const selectCustomer = (customer) => {
        if (customerHidden) {
            customerHidden.value = String(customer.id || '');
        }

        if (customerInput) {
            customerInput.value = customer.name || customer.label || '';
        }

        if (customerPhoneInput) {
            customerPhoneInput.value = customer.phone || '';
        }

        closeCustomerSuggestions();
    };

    const renderCustomerSuggestions = (customers) => {
        if (!customerSuggestions) {
            return;
        }

        customerSuggestions.innerHTML = '';

        if (!customers.length) {
            showCustomerSuggestionMessage('No customers found');
            return;
        }

        customers.forEach((customer) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'product-suggestion-item';
            button.innerHTML = `
                <strong></strong>
                <span></span>
            `;
            button.querySelector('strong').textContent = customer.name || customer.label || '';
            button.querySelector('span').textContent = [customer.phone, customer.email].filter(Boolean).join(' / ') || 'Customer account';
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => selectCustomer(customer));
            customerSuggestions.appendChild(button);
        });

        customerSuggestions.hidden = false;
    };

    const runCustomerSearch = () => {
        if (!customerInput || !saleCustomerSearchUrl) {
            return;
        }

        const query = customerInput.value.trim();

        if (customerHidden) {
            customerHidden.value = '';
        }

        if (query.length < 2) {
            closeCustomerSuggestions();
            return;
        }

        const token = ++customerSearchToken;
        showCustomerSuggestionMessage('Searching...');

        fetch(`${saleCustomerSearchUrl}?q=${encodeURIComponent(query)}`, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.ok ? response.json() : { customers: [] })
            .then((data) => {
                if (token !== customerSearchToken) {
                    return;
                }

                renderCustomerSuggestions(Array.isArray(data.customers) ? data.customers : []);
            })
            .catch(() => {
                if (token === customerSearchToken) {
                    showCustomerSuggestionMessage('Search failed');
                }
            });
    };

    if (customerInput) {
        customerInput.addEventListener('input', () => {
            if (customerHidden) {
                customerHidden.value = '';
            }

            window.clearTimeout(customerSearchTimer);
            customerSearchTimer = window.setTimeout(runCustomerSearch, 180);
        });

        customerInput.addEventListener('focus', () => {
            if (customerInput.value.trim().length >= 2) {
                runCustomerSearch();
            }
        });

        customerInput.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeCustomerSuggestions();
            }
        });

        customerInput.addEventListener('blur', () => {
            window.setTimeout(closeCustomerSuggestions, 120);
        });
    }

    const recalculateSale = () => {
        let subtotal = 0;

        rowsContainer.querySelectorAll('[data-sale-row]').forEach((row) => {
            const quantity = Math.max(0, Number.parseFloat(row.querySelector('[data-sale-quantity]')?.value || '0'));
            const price = Math.max(0, Number.parseFloat(row.querySelector('[data-sale-price]')?.value || '0'));
            const discount = Math.max(0, Number.parseFloat(row.querySelector('[data-sale-line-discount]')?.value || '0'));
            const stock = Math.max(0, Number.parseInt(row.querySelector('[data-sale-product]')?.dataset.stock || '0', 10));
            const lineTotal = Math.max(0, (quantity * price) - discount);
            const lineTotalInput = row.querySelector('[data-sale-line-total]');
            const stockDisplay = row.querySelector('[data-sale-stock]');

            if (lineTotalInput) {
                lineTotalInput.value = money(lineTotal);
            }

            if (stockDisplay) {
                stockDisplay.textContent = String(stock);
                stockDisplay.classList.toggle('low', quantity > stock);
            }

            subtotal += lineTotal;
        });

        const invoiceDiscount = Math.max(0, Number.parseFloat(discountInput?.value || '0'));
        const tax = Math.max(0, Number.parseFloat(taxInput?.value || '0'));
        const paid = Math.max(0, Number.parseFloat(paidInput?.value || '0'));
        const total = Math.max(0, subtotal - invoiceDiscount + tax);
        const balance = Math.max(0, total - paid);

        if (subtotalInput) subtotalInput.value = money(subtotal);
        if (totalInput) totalInput.value = money(total);
        if (balanceInput) balanceInput.value = money(balance);
    };

    const refreshSaleRemoveButtons = () => {
        const rows = rowsContainer.querySelectorAll('[data-sale-row]');

        rows.forEach((row) => {
            const removeButton = row.querySelector('[data-remove-sale-row]');
            if (removeButton) {
                removeButton.disabled = rows.length <= 1;
            }
        });
    };

    const hydrateSaleRow = (row) => {
        const productInput = row.querySelector('[data-sale-product-search]');
        const productHidden = row.querySelector('[data-sale-product]');
        const suggestions = row.querySelector('[data-sale-product-suggestions]');
        const priceInput = row.querySelector('[data-sale-price]');
        const quantityInput = row.querySelector('[data-sale-quantity]');
        const stockDisplay = row.querySelector('[data-sale-stock]');
        let searchTimer = null;
        let searchToken = 0;
        let selectedCategory = null;

        row.querySelectorAll('[data-sale-quantity], [data-sale-price], [data-sale-line-discount]').forEach((input) => {
            input.addEventListener('input', recalculateSale);
        });

        const closeSuggestions = () => {
            if (!suggestions) {
                return;
            }

            suggestions.hidden = true;
            suggestions.innerHTML = '';
        };

        const showSuggestionMessage = (message) => {
            if (!suggestions) {
                return;
            }

            suggestions.innerHTML = `<div class="product-suggestion-empty">${message}</div>`;
            suggestions.hidden = false;
        };

        const clearSelectedProduct = () => {
            if (productHidden) {
                productHidden.value = '';
                productHidden.dataset.stock = '0';
                productHidden.dataset.price = '0';
                productHidden.dataset.cost = '0';
            }

            if (quantityInput) {
                quantityInput.removeAttribute('max');
            }

            if (stockDisplay) {
                stockDisplay.textContent = '0';
            }
        };

        const selectProduct = (product) => {
            const stock = Math.max(0, Number.parseInt(product.stock || '0', 10) || 0);
            const price = Math.max(0, Number.parseFloat(product.price || '0') || 0);
            selectedCategory = null;

            if (productHidden) {
                productHidden.value = String(product.id || '');
                productHidden.dataset.stock = String(stock);
                productHidden.dataset.price = String(price);
                productHidden.dataset.cost = String(Math.max(0, Number.parseFloat(product.cost || '0') || 0));
            }

            if (productInput) {
                productInput.value = product.label || '';
            }

            if (priceInput) {
                priceInput.value = money(price);
            }

            if (quantityInput) {
                quantityInput.max = String(stock);
            }

            if (stockDisplay) {
                stockDisplay.textContent = String(stock);
                stockDisplay.classList.toggle('low', stock <= 0);
            }

            closeSuggestions();
            recalculateSale();
        };

        const renderCategorySuggestions = (categories) => {
            if (!suggestions) {
                return;
            }

            suggestions.innerHTML = '';

            if (!categories.length) {
                showSuggestionMessage('No categories found');
                return;
            }

            categories.forEach((category) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'product-suggestion-item category-suggestion-item';
                button.innerHTML = `
                    <strong></strong>
                    <span></span>
                `;
                button.querySelector('strong').textContent = category.name || '';
                button.querySelector('span').textContent = `${category.product_count ?? 0} product(s) available`;
                button.addEventListener('mousedown', (event) => event.preventDefault());
                button.addEventListener('click', () => {
                    selectedCategory = {
                        id: Number.parseInt(category.id || '0', 10) || 0,
                        name: category.name || '',
                    };

                    if (productInput) {
                        productInput.value = `@${selectedCategory.name} `;
                        productInput.focus();
                    }

                    runProductSearch();
                });
                suggestions.appendChild(button);
            });

            suggestions.hidden = false;
        };

        const renderSuggestions = (products) => {
            if (!suggestions) {
                return;
            }

            suggestions.innerHTML = '';

            if (!products.length) {
                showSuggestionMessage('No products found');
                return;
            }

            products.forEach((product) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'product-suggestion-item';
                button.innerHTML = `
                    <strong></strong>
                    <span></span>
                `;
                button.querySelector('strong').textContent = product.label || '';
                button.querySelector('span').textContent = `${product.category ? `${product.category} / ` : ''}Stock ${product.stock ?? 0} / Sell ${money(Number.parseFloat(product.price || '0'))}`;
                button.addEventListener('mousedown', (event) => event.preventDefault());
                button.addEventListener('click', () => selectProduct(product));
                suggestions.appendChild(button);
            });

            suggestions.hidden = false;
        };

        const runProductSearch = () => {
            if (!productInput || !saleProductSearchUrl) {
                return;
            }

            const rawQuery = productInput.value.trim();
            let query = rawQuery;
            let categoryId = 0;
            let categoryMode = rawQuery.startsWith('@');

            if (selectedCategory) {
                const categoryPrefix = `@${selectedCategory.name}`;
                const lowerRawQuery = rawQuery.toLowerCase();
                const lowerCategoryPrefix = categoryPrefix.toLowerCase();

                if (lowerRawQuery === lowerCategoryPrefix || lowerRawQuery.startsWith(`${lowerCategoryPrefix} `)) {
                    categoryMode = false;
                    categoryId = selectedCategory.id;
                    query = rawQuery.slice(categoryPrefix.length).trim();
                } else {
                    selectedCategory = null;
                    categoryMode = rawQuery.startsWith('@');
                    query = rawQuery;
                }
            }

            clearSelectedProduct();
            recalculateSale();

            if (!categoryMode && categoryId <= 0 && query.length < 2) {
                closeSuggestions();
                return;
            }

            const token = ++searchToken;
            showSuggestionMessage(categoryMode ? 'Searching categories...' : 'Searching products...');

            const params = new URLSearchParams({ q: query });

            if (categoryId > 0) {
                params.set('category_id', String(categoryId));
            }

            fetch(`${saleProductSearchUrl}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            })
                .then((response) => response.ok ? response.json() : { products: [] })
                .then((data) => {
                    if (token !== searchToken) {
                        return;
                    }

                    if (Array.isArray(data.categories)) {
                        renderCategorySuggestions(data.categories);
                    } else {
                        renderSuggestions(Array.isArray(data.products) ? data.products : []);
                    }
                })
                .catch(() => {
                    if (token === searchToken) {
                        showSuggestionMessage('Search failed');
                    }
                });
        };

        if (productInput) {
            productInput.addEventListener('input', () => {
                clearSelectedProduct();
                recalculateSale();
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(runProductSearch, 180);
            });

            productInput.addEventListener('focus', () => {
                if (productInput.value.trim().length >= 2) {
                    runProductSearch();
                }
            });

            productInput.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeSuggestions();
                }
            });

            productInput.addEventListener('blur', () => {
                window.setTimeout(closeSuggestions, 120);
            });
        }

        const removeButton = row.querySelector('[data-remove-sale-row]');
        if (removeButton) {
            removeButton.addEventListener('click', () => {
                if (rowsContainer.querySelectorAll('[data-sale-row]').length <= 1) {
                    return;
                }

                row.remove();
                refreshSaleRemoveButtons();
                recalculateSale();
            });
        }
    };

    const createSaleRow = () => {
        if (!template) {
            return null;
        }

        const fragment = template.content.cloneNode(true);
        const row = fragment.querySelector('[data-sale-row]');
        rowsContainer.appendChild(fragment);

        if (row) {
            hydrateSaleRow(row);
        }

        if (window.lucide) {
            window.lucide.createIcons();
        }

        refreshSaleRemoveButtons();
        recalculateSale();

        return row;
    };

    rowsContainer.querySelectorAll('[data-sale-row]').forEach(hydrateSaleRow);

    if (addButton) {
        addButton.addEventListener('click', createSaleRow);
    }

    [discountInput, taxInput, paidInput].forEach((input) => {
        if (input) {
            input.addEventListener('input', recalculateSale);
        }
    });

    refreshSaleRemoveButtons();
    recalculateSale();
}

const collectionForm = document.querySelector('[data-collection-form]');

if (collectionForm) {
    const invoiceSelect = collectionForm.querySelector('[data-collection-invoice]');
    const amountInput = collectionForm.querySelector('[data-collection-amount]');
    const balanceDisplay = collectionForm.querySelector('[data-collection-balance]');
    const preview = collectionForm.querySelector('[data-collection-preview]');
    const collectionLabel = collectionForm.dataset.collectionLabel || 'invoice';

    const money = (value) => Number.isFinite(value) ? value.toFixed(2) : '0.00';

    const renderCollectionPreview = () => {
        const selected = invoiceSelect?.selectedOptions[0];
        const balance = Number.parseFloat(selected?.dataset.balance || '0');
        const amount = Math.max(0, Number.parseFloat(amountInput?.value || '0'));
        const remaining = Math.max(0, balance - amount);

        if (balanceDisplay) {
            balanceDisplay.textContent = selected?.value ? money(balance) : `Choose ${collectionLabel}`;
        }

        if (!preview) {
            return;
        }

        if (!selected?.value) {
            preview.textContent = `Select a ${collectionLabel} to preview the remaining balance.`;
            return;
        }

        preview.textContent = amount > balance
            ? `Payment is higher than the ${collectionLabel} balance and cannot be saved.`
            : `Remaining balance after payment: ${money(remaining)}.`;
    };

    if (invoiceSelect) {
        invoiceSelect.addEventListener('change', () => {
            const selected = invoiceSelect.selectedOptions[0];
            const balance = Number.parseFloat(selected?.dataset.balance || '0');

            if (amountInput && balance > 0) {
                amountInput.value = money(balance);
                amountInput.max = money(balance);
            }

            renderCollectionPreview();
        });
    }

    if (amountInput) {
        amountInput.addEventListener('input', renderCollectionPreview);
    }

    renderCollectionPreview();
}

const returnForm = document.querySelector('[data-return-form]');

if (returnForm) {
    const lookupUrl = returnForm.dataset.returnLookupUrl || '';
    const searchInput = returnForm.querySelector('[data-return-search]');
    const suggestions = returnForm.querySelector('[data-return-suggestions]');
    const invoiceList = returnForm.querySelector('[data-return-invoices]');
    const itemList = returnForm.querySelector('[data-return-items]');
    const customerLabel = returnForm.querySelector('[data-return-customer-label]');
    const invoiceLabel = returnForm.querySelector('[data-return-invoice-label]');
    const itemHidden = returnForm.querySelector('[data-return-item]');
    const quantityInput = returnForm.querySelector('[data-return-quantity]');
    const refundInput = returnForm.querySelector('[data-return-refund]');
    const availableDisplay = returnForm.querySelector('[data-return-available]');
    const preview = returnForm.querySelector('[data-return-preview]');
    let searchTimer = null;
    let searchToken = 0;
    let invoiceToken = 0;
    let itemToken = 0;

    const money = (value) => Number.isFinite(value) ? value.toFixed(2) : '0.00';

    const setListMessage = (container, message) => {
        if (!container) {
            return;
        }

        container.innerHTML = `<p class="return-choice-empty">${message}</p>`;
    };

    const closeSuggestions = () => {
        if (!suggestions) {
            return;
        }

        suggestions.hidden = true;
        suggestions.innerHTML = '';
    };

    const showSuggestionMessage = (message) => {
        if (!suggestions) {
            return;
        }

        suggestions.innerHTML = `<div class="product-suggestion-empty">${message}</div>`;
        suggestions.hidden = false;
    };

    const clearSelectedItem = () => {
        if (itemHidden) {
            itemHidden.value = '';
            itemHidden.dataset.available = '0';
            itemHidden.dataset.price = '0';
        }

        if (quantityInput) {
            quantityInput.value = '1';
            quantityInput.removeAttribute('max');
        }

        if (refundInput) {
            refundInput.value = '0.00';
        }
    };

    const renderReturnPreview = () => {
        const hasItem = Boolean(itemHidden?.value);
        const available = Number.parseInt(itemHidden?.dataset.available || '0', 10);
        const price = Number.parseFloat(itemHidden?.dataset.price || '0');
        const quantity = Math.max(0, Number.parseInt(quantityInput?.value || '0', 10));
        const maxRefund = Math.max(0, quantity * price);

        if (availableDisplay) {
            availableDisplay.textContent = hasItem ? String(available) : 'Choose item';
        }

        if (quantityInput && hasItem) {
            quantityInput.max = String(Math.max(1, available));
        }

        if (refundInput && hasItem && Number.parseFloat(refundInput.value || '0') === 0) {
            refundInput.value = money(maxRefund);
        }

        if (!preview) {
            return;
        }

        if (!hasItem) {
            preview.textContent = 'Search customer, select invoice, then select the item to return.';
            return;
        }

        if (quantity > available) {
            preview.textContent = `Only ${available} unit(s) are still returnable.`;
            return;
        }

        preview.textContent = `Return ${quantity} unit(s). Maximum refund for this quantity: ${money(maxRefund)}.`;
    };

    const selectItem = (item, button) => {
        if (itemHidden) {
            itemHidden.value = String(item.sale_item_id || '');
            itemHidden.dataset.available = String(item.available || 0);
            itemHidden.dataset.price = String(item.price || '0');
        }

        if (quantityInput) {
            quantityInput.value = '1';
            quantityInput.max = String(Math.max(1, Number.parseInt(item.available || '0', 10) || 1));
        }

        if (refundInput) {
            refundInput.value = money(Number.parseFloat(item.price || '0') || 0);
        }

        itemList?.querySelectorAll('.return-choice-card').forEach((card) => card.classList.remove('active'));
        button?.classList.add('active');
        renderReturnPreview();
    };

    const renderItems = (items) => {
        if (!itemList) {
            return;
        }

        clearSelectedItem();

        if (!items.length) {
            setListMessage(itemList, 'No returnable items found for this invoice.');
            renderReturnPreview();
            return;
        }

        itemList.innerHTML = '';

        items.forEach((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'return-choice-card';
            button.innerHTML = `
                <strong></strong>
                <span></span>
                <small></small>
            `;
            button.querySelector('strong').textContent = item.label || '';
            button.querySelector('span').textContent = `Sold ${item.sold ?? 0} / Returned ${item.returned ?? 0} / Available ${item.available ?? 0}`;
            button.querySelector('small').textContent = `Price ${money(Number.parseFloat(item.price || '0'))}`;
            button.addEventListener('click', () => selectItem(item, button));
            itemList.appendChild(button);
        });
    };

    const loadItems = (invoice) => {
        if (!lookupUrl || !invoice?.sale_id) {
            return;
        }

        const token = ++itemToken;
        clearSelectedItem();
        setListMessage(itemList, 'Loading invoice items...');

        if (invoiceLabel) {
            invoiceLabel.textContent = `${invoice.invoice_no || invoice.label} / ${invoice.customer || 'Walk-in Customer'}`;
        }

        fetch(`${lookupUrl}?type=items&sale_id=${encodeURIComponent(invoice.sale_id)}`, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.ok ? response.json() : { items: [] })
            .then((data) => {
                if (token !== itemToken) {
                    return;
                }

                renderItems(Array.isArray(data.items) ? data.items : []);
            })
            .catch(() => {
                if (token === itemToken) {
                    setListMessage(itemList, 'Could not load invoice items.');
                }
            });
    };

    const renderInvoices = (invoices) => {
        if (!invoiceList) {
            return;
        }

        clearSelectedItem();
        setListMessage(itemList, 'Select an invoice to view returnable items.');

        if (!invoices.length) {
            setListMessage(invoiceList, 'No returnable invoices found.');
            return;
        }

        invoiceList.innerHTML = '';

        invoices.forEach((invoice) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'return-choice-card';
            button.innerHTML = `
                <strong></strong>
                <span></span>
                <small></small>
            `;
            button.querySelector('strong').textContent = invoice.invoice_no || invoice.label || '';
            button.querySelector('span').textContent = `${invoice.date || ''} / ${invoice.customer || 'Walk-in Customer'}`;
            button.querySelector('small').textContent = `${invoice.items ?? 0} item(s), ${invoice.available ?? 0} returnable unit(s), ${money(Number.parseFloat(invoice.total || '0'))}`;
            button.addEventListener('click', () => {
                invoiceList.querySelectorAll('.return-choice-card').forEach((card) => card.classList.remove('active'));
                button.classList.add('active');
                loadItems(invoice);
            });
            invoiceList.appendChild(button);
        });
    };

    const loadInvoices = (customer) => {
        if (!lookupUrl || !customer?.id) {
            return;
        }

        const token = ++invoiceToken;
        clearSelectedItem();
        setListMessage(invoiceList, 'Loading invoices...');
        setListMessage(itemList, 'Select an invoice to view returnable items.');

        if (customerLabel) {
            customerLabel.textContent = customer.label || 'Selected customer';
        }

        if (invoiceLabel) {
            invoiceLabel.textContent = 'Select an invoice to view returnable items.';
        }

        fetch(`${lookupUrl}?type=invoices&customer_id=${encodeURIComponent(customer.id)}`, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.ok ? response.json() : { invoices: [] })
            .then((data) => {
                if (token !== invoiceToken) {
                    return;
                }

                renderInvoices(Array.isArray(data.invoices) ? data.invoices : []);
            })
            .catch(() => {
                if (token === invoiceToken) {
                    setListMessage(invoiceList, 'Could not load invoices.');
                }
            });
    };

    const selectSearchMatch = (match) => {
        closeSuggestions();

        if (searchInput) {
            searchInput.value = match.type === 'invoice'
                ? `${match.invoice_no || match.label} / ${match.customer || 'Walk-in Customer'}`
                : match.label || '';
        }

        if (match.type === 'invoice') {
            if (customerLabel) {
                customerLabel.textContent = match.customer || 'Selected invoice';
            }

            renderInvoices([match]);
            loadItems(match);
            return;
        }

        loadInvoices(match);
    };

    const renderSearchMatches = (matches) => {
        if (!suggestions) {
            return;
        }

        suggestions.innerHTML = '';

        if (!matches.length) {
            showSuggestionMessage('No returnable invoices found');
            return;
        }

        matches.forEach((match) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'product-suggestion-item';
            button.innerHTML = `
                <strong></strong>
                <span></span>
            `;
            button.querySelector('strong').textContent = match.type === 'invoice'
                ? `Invoice ${match.invoice_no || match.label}`
                : match.label || '';
            button.querySelector('span').textContent = `${match.type === 'invoice' ? 'Invoice' : 'Customer'} / ${match.meta || ''}`;
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => selectSearchMatch(match));
            suggestions.appendChild(button);
        });

        suggestions.hidden = false;
    };

    const runSearch = () => {
        if (!searchInput || !lookupUrl) {
            return;
        }

        const query = searchInput.value.trim();

        if (query.length < 2) {
            closeSuggestions();
            return;
        }

        const token = ++searchToken;
        showSuggestionMessage('Searching...');

        fetch(`${lookupUrl}?type=search&q=${encodeURIComponent(query)}`, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.ok ? response.json() : { matches: [] })
            .then((data) => {
                if (token !== searchToken) {
                    return;
                }

                renderSearchMatches(Array.isArray(data.matches) ? data.matches : []);
            })
            .catch(() => {
                if (token === searchToken) {
                    showSuggestionMessage('Search failed');
                }
            });
    };

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(runSearch, 180);
        });

        searchInput.addEventListener('focus', () => {
            if (searchInput.value.trim().length >= 2) {
                runSearch();
            }
        });

        searchInput.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeSuggestions();
            }
        });

        searchInput.addEventListener('blur', () => {
            window.setTimeout(closeSuggestions, 120);
        });
    }

    [quantityInput, refundInput].forEach((input) => {
        if (input) {
            input.addEventListener('input', renderReturnPreview);
        }
    });

    renderReturnPreview();
}

const warrantyForm = document.querySelector('[data-warranty-form]');

if (warrantyForm) {
    const lookupUrl = warrantyForm.dataset.warrantyLookupUrl || '';
    const searchInput = warrantyForm.querySelector('[data-warranty-search]');
    const suggestions = warrantyForm.querySelector('[data-warranty-suggestions]');
    const invoiceList = warrantyForm.querySelector('[data-warranty-invoices]');
    const itemList = warrantyForm.querySelector('[data-warranty-items]');
    const customerLabel = warrantyForm.querySelector('[data-warranty-customer-label]');
    const invoiceLabel = warrantyForm.querySelector('[data-warranty-invoice-label]');
    const itemHidden = warrantyForm.querySelector('[data-warranty-item]');
    const preview = warrantyForm.querySelector('[data-warranty-preview]');
    let searchTimer = null;
    let searchToken = 0;
    let invoiceToken = 0;
    let itemToken = 0;

    const money = (value) => Number.isFinite(value) ? value.toFixed(2) : '0.00';

    const setListMessage = (container, message) => {
        if (!container) {
            return;
        }

        container.innerHTML = `<p class="return-choice-empty">${message}</p>`;
    };

    const closeSuggestions = () => {
        if (!suggestions) {
            return;
        }

        suggestions.hidden = true;
        suggestions.innerHTML = '';
    };

    const showSuggestionMessage = (message) => {
        if (!suggestions) {
            return;
        }

        suggestions.innerHTML = `<div class="product-suggestion-empty">${message}</div>`;
        suggestions.hidden = false;
    };

    const clearSelectedItem = () => {
        if (itemHidden) {
            itemHidden.value = '';
            itemHidden.dataset.label = '';
            itemHidden.dataset.until = '';
        }

        if (preview) {
            preview.textContent = 'Search customer, select invoice, then select the warranty item.';
        }
    };

    const renderWarrantyPreview = () => {
        if (!preview) {
            return;
        }

        const label = itemHidden?.dataset.label || '';
        const until = itemHidden?.dataset.until || '';

        preview.textContent = itemHidden?.value
            ? `${label} is covered until ${until}.`
            : 'Search customer, select invoice, then select the warranty item.';
    };

    const selectItem = (item, button) => {
        if (itemHidden) {
            itemHidden.value = String(item.sale_item_id || '');
            itemHidden.dataset.label = item.label || '';
            itemHidden.dataset.until = item.warranty_until || '';
        }

        itemList?.querySelectorAll('.return-choice-card').forEach((card) => card.classList.remove('active'));
        button?.classList.add('active');
        renderWarrantyPreview();
    };

    const renderItems = (items) => {
        if (!itemList) {
            return;
        }

        clearSelectedItem();

        if (!items.length) {
            setListMessage(itemList, 'No warranty items found for this invoice.');
            renderWarrantyPreview();
            return;
        }

        itemList.innerHTML = '';

        items.forEach((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'return-choice-card';
            button.innerHTML = `
                <strong></strong>
                <span></span>
                <small></small>
            `;
            button.querySelector('strong').textContent = item.label || '';
            button.querySelector('span').textContent = `Sold ${item.sold ?? 0} / Warranty ${item.warranty_months ?? 0} mo / Until ${item.warranty_until || ''}`;
            button.querySelector('small').textContent = `Price ${money(Number.parseFloat(item.price || '0'))}`;
            button.addEventListener('click', () => selectItem(item, button));
            itemList.appendChild(button);
        });
    };

    const loadItems = (invoice) => {
        if (!lookupUrl || !invoice?.sale_id) {
            return;
        }

        const token = ++itemToken;
        clearSelectedItem();
        setListMessage(itemList, 'Loading warranty items...');

        if (invoiceLabel) {
            invoiceLabel.textContent = `${invoice.invoice_no || invoice.label} / ${invoice.customer || 'Walk-in Customer'}`;
        }

        fetch(`${lookupUrl}?type=items&sale_id=${encodeURIComponent(invoice.sale_id)}`, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.ok ? response.json() : { items: [] })
            .then((data) => {
                if (token !== itemToken) {
                    return;
                }

                renderItems(Array.isArray(data.items) ? data.items : []);
            })
            .catch(() => {
                if (token === itemToken) {
                    setListMessage(itemList, 'Could not load warranty items.');
                }
            });
    };

    const renderInvoices = (invoices) => {
        if (!invoiceList) {
            return;
        }

        clearSelectedItem();
        setListMessage(itemList, 'Select an invoice to view warranty items.');

        if (!invoices.length) {
            setListMessage(invoiceList, 'No warranty invoices found.');
            return;
        }

        invoiceList.innerHTML = '';

        invoices.forEach((invoice) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'return-choice-card';
            button.innerHTML = `
                <strong></strong>
                <span></span>
                <small></small>
            `;
            button.querySelector('strong').textContent = invoice.invoice_no || invoice.label || '';
            button.querySelector('span').textContent = `${invoice.date || ''} / ${invoice.customer || 'Walk-in Customer'}`;
            button.querySelector('small').textContent = `${invoice.items ?? 0} warranty item(s), ${invoice.available ?? 0} unit(s), ${money(Number.parseFloat(invoice.total || '0'))}`;
            button.addEventListener('click', () => {
                invoiceList.querySelectorAll('.return-choice-card').forEach((card) => card.classList.remove('active'));
                button.classList.add('active');
                loadItems(invoice);
            });
            invoiceList.appendChild(button);
        });
    };

    const loadInvoices = (customer) => {
        if (!lookupUrl || !customer?.id) {
            return;
        }

        const token = ++invoiceToken;
        clearSelectedItem();
        setListMessage(invoiceList, 'Loading invoices...');
        setListMessage(itemList, 'Select an invoice to view warranty items.');

        if (customerLabel) {
            customerLabel.textContent = customer.label || 'Selected customer';
        }

        if (invoiceLabel) {
            invoiceLabel.textContent = 'Select an invoice to view warranty items.';
        }

        fetch(`${lookupUrl}?type=invoices&customer_id=${encodeURIComponent(customer.id)}`, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.ok ? response.json() : { invoices: [] })
            .then((data) => {
                if (token !== invoiceToken) {
                    return;
                }

                renderInvoices(Array.isArray(data.invoices) ? data.invoices : []);
            })
            .catch(() => {
                if (token === invoiceToken) {
                    setListMessage(invoiceList, 'Could not load invoices.');
                }
            });
    };

    const selectSearchMatch = (match) => {
        closeSuggestions();

        if (searchInput) {
            searchInput.value = match.type === 'invoice'
                ? `${match.invoice_no || match.label} / ${match.customer || 'Walk-in Customer'}`
                : match.label || '';
        }

        if (match.type === 'invoice') {
            if (customerLabel) {
                customerLabel.textContent = match.customer || 'Selected invoice';
            }

            renderInvoices([match]);
            loadItems(match);
            return;
        }

        loadInvoices(match);
    };

    const renderSearchMatches = (matches) => {
        if (!suggestions) {
            return;
        }

        suggestions.innerHTML = '';

        if (!matches.length) {
            showSuggestionMessage('No warranty invoices found');
            return;
        }

        matches.forEach((match) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'product-suggestion-item';
            button.innerHTML = `
                <strong></strong>
                <span></span>
            `;
            button.querySelector('strong').textContent = match.type === 'invoice'
                ? `Invoice ${match.invoice_no || match.label}`
                : match.label || '';
            button.querySelector('span').textContent = `${match.type === 'invoice' ? 'Invoice' : 'Customer'} / ${match.meta || ''}`;
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => selectSearchMatch(match));
            suggestions.appendChild(button);
        });

        suggestions.hidden = false;
    };

    const runSearch = () => {
        if (!searchInput || !lookupUrl) {
            return;
        }

        const query = searchInput.value.trim();

        if (query.length < 2) {
            closeSuggestions();
            return;
        }

        const token = ++searchToken;
        showSuggestionMessage('Searching...');

        fetch(`${lookupUrl}?type=search&q=${encodeURIComponent(query)}`, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.ok ? response.json() : { matches: [] })
            .then((data) => {
                if (token !== searchToken) {
                    return;
                }

                renderSearchMatches(Array.isArray(data.matches) ? data.matches : []);
            })
            .catch(() => {
                if (token === searchToken) {
                    showSuggestionMessage('Search failed');
                }
            });
    };

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(runSearch, 180);
        });

        searchInput.addEventListener('focus', () => {
            if (searchInput.value.trim().length >= 2) {
                runSearch();
            }
        });

        searchInput.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeSuggestions();
            }
        });

        searchInput.addEventListener('blur', () => {
            window.setTimeout(closeSuggestions, 120);
        });
    }

    renderWarrantyPreview();
}

const warrantyClaimModal = document.querySelector('[data-warranty-claim-modal]');

if (warrantyClaimModal) {
    const claimIdInput = warrantyClaimModal.querySelector('[data-warranty-claim-id]');
    const statusSelect = warrantyClaimModal.querySelector('[data-warranty-claim-status]');
    const summary = warrantyClaimModal.querySelector('[data-warranty-claim-summary]');
    const closeButton = warrantyClaimModal.querySelector('[data-warranty-claim-close]');
    const supplierRefundInput = warrantyClaimModal.querySelector('[data-warranty-supplier-refund]');
    const supplierRefundDateInput = warrantyClaimModal.querySelector('[data-warranty-supplier-refund-date]');

    const closeWarrantyModal = () => {
        warrantyClaimModal.hidden = true;
        document.body.classList.remove('modal-open');
    };

    const openWarrantyModal = (row) => {
        const claimId = row.dataset.claimId || '';
        const claimNo = row.dataset.claimNo || 'Claim';
        const status = row.dataset.claimStatus || 'received';
        const customer = row.dataset.claimCustomer || 'Walk-in Customer';
        const product = row.dataset.claimProduct || '';
        const refundAmount = row.dataset.claimRefundAmount || '0.00';
        const refundDate = row.dataset.claimRefundDate || new Date().toISOString().slice(0, 10);

        if (claimIdInput) {
            claimIdInput.value = claimId;
        }

        if (statusSelect) {
            statusSelect.value = status;
        }

        if (summary) {
            summary.textContent = `${claimNo} / ${customer}${product ? ` / ${product}` : ''}`;
        }

        if (supplierRefundInput) {
            supplierRefundInput.value = refundAmount;
        }

        if (supplierRefundDateInput) {
            supplierRefundDateInput.value = refundDate;
        }

        warrantyClaimModal.hidden = false;
        document.body.classList.add('modal-open');
        statusSelect?.focus();
    };

    document.querySelectorAll('[data-warranty-claim-row]').forEach((row) => {
        row.addEventListener('click', () => openWarrantyModal(row));
        row.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openWarrantyModal(row);
            }
        });
    });

    closeButton?.addEventListener('click', closeWarrantyModal);
    warrantyClaimModal.addEventListener('click', (event) => {
        if (event.target === warrantyClaimModal) {
            closeWarrantyModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !warrantyClaimModal.hidden) {
            closeWarrantyModal();
        }
    });
}
