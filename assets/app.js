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
    const field = input.closest('.backup-native-file, .backup-file-picker');
    const fileName = field?.querySelector('[data-backup-file-name]');

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
    const roleSelect = panel.closest('form')?.querySelector('[data-role-permission-select]');

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

    roleSelect?.addEventListener('change', () => {
        const selectedOption = roleSelect.options[roleSelect.selectedIndex];
        const rolePermissionKeys = new Set((selectedOption?.dataset.permissionKeys || '').split(',').filter(Boolean));

        checkboxes.forEach((checkbox) => {
            checkbox.checked = rolePermissionKeys.has(checkbox.value);
        });

        syncPermissionState();
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

            if (costInput && !product.cost_hidden) {
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
                const metaParts = [];

                if (product.category) {
                    metaParts.push(product.category);
                }

                metaParts.push(`Stock ${product.stock ?? 0}`);

                if (!product.cost_hidden) {
                    metaParts.push(`Cost ${money(Number.parseFloat(product.cost || '0'))}`);
                }

                metaParts.push(`Warranty ${product.warranty ?? 0} mo`);
                button.querySelector('span').textContent = metaParts.join(' / ');
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
    let salePaidManuallyEdited = saleForm.dataset.salePreservePaid === '1';

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
        const total = Math.max(0, subtotal - invoiceDiscount + tax);

        if (paidInput && !salePaidManuallyEdited) {
            paidInput.value = money(total);
        }

        const paid = Math.max(0, Number.parseFloat(paidInput?.value || '0'));
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

    [discountInput, taxInput].forEach((input) => {
        if (input) {
            input.addEventListener('input', recalculateSale);
        }
    });

    if (paidInput) {
        paidInput.addEventListener('input', () => {
            salePaidManuallyEdited = true;
            recalculateSale();
        });
    }

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

const serviceForm = document.querySelector('[data-service-form]');

if (serviceForm) {
    const lookupUrl = serviceForm.dataset.serviceLookupUrl || '';
    const searchInput = serviceForm.querySelector('[data-service-search]');
    const suggestions = serviceForm.querySelector('[data-service-suggestions]');
    const invoiceList = serviceForm.querySelector('[data-service-invoices]');
    const itemList = serviceForm.querySelector('[data-service-items]');
    const customerLabel = serviceForm.querySelector('[data-service-customer-label]');
    const invoiceLabel = serviceForm.querySelector('[data-service-invoice-label]');
    const itemHidden = serviceForm.querySelector('[data-service-item]');
    const outcomeHidden = serviceForm.querySelector('[data-service-outcome]');
    const pathStep = serviceForm.querySelector('[data-service-path-step]');
    const outcomeStep = serviceForm.querySelector('[data-service-outcome-step]');
    const detailsStep = serviceForm.querySelector('[data-service-details-step]');
    const normalOutcomes = serviceForm.querySelector('[data-service-normal-outcomes]');
    const damagedOutcomes = serviceForm.querySelector('[data-service-damaged-outcomes]');
    const quantityInput = serviceForm.querySelector('[data-service-quantity]');
    const refundInput = serviceForm.querySelector('[data-service-refund]');
    const refundFields = serviceForm.querySelectorAll('[data-service-refund-fields]');
    const preview = serviceForm.querySelector('[data-service-preview]');
    let selectedItem = null;
    let searchTimer = null;
    let searchToken = 0;
    let invoiceToken = 0;
    let itemToken = 0;

    const money = (value) => Number.isFinite(value) ? value.toFixed(2) : '0.00';
    const warrantyOutcomes = ['warranty_wait_supplier', 'warranty_refund_now', 'warranty_replace_now'];
    const replacementOutcomes = ['warranty_replace_now'];
    const refundOutcomes = ['normal_restock', 'warranty_refund_now'];

    const setListMessage = (container, message) => {
        if (container) {
            container.innerHTML = `<p class="return-choice-empty">${message}</p>`;
        }
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

    const resetAfterItem = () => {
        serviceForm.querySelectorAll('[data-service-path]').forEach((input) => {
            input.checked = false;
        });
        serviceForm.querySelectorAll('[data-service-outcome-choice]').forEach((input) => {
            input.checked = false;
        });

        if (outcomeHidden) {
            outcomeHidden.value = '';
        }

        if (pathStep) {
            pathStep.hidden = !selectedItem;
        }

        if (outcomeStep) {
            outcomeStep.hidden = true;
        }

        if (detailsStep) {
            detailsStep.hidden = true;
        }

        if (normalOutcomes) {
            normalOutcomes.hidden = true;
        }

        if (damagedOutcomes) {
            damagedOutcomes.hidden = true;
        }
    };

    const updateOutcomeAvailability = () => {
        const hasStock = Number.parseInt(selectedItem?.stock ?? '0', 10) > 0;

        serviceForm.querySelectorAll('[data-service-needs-warranty]').forEach((card) => {
            const input = card.querySelector('input');
            card.classList.remove('is-disabled');

            if (input) {
                input.disabled = false;
            }
        });

        replacementOutcomes.forEach((value) => {
            const input = serviceForm.querySelector(`[data-service-outcome-choice][value="${value}"]`);
            const card = input?.closest('.service-outcome-card');
            const disabled = !hasStock;
            card?.classList.toggle('is-disabled', disabled);

            if (input) {
                input.disabled = disabled;
                if (disabled) {
                    input.checked = false;
                }
            }
        });
    };

    const showOutcomeStep = (path) => {
        if (!outcomeStep) {
            return;
        }

        outcomeStep.hidden = false;
        normalOutcomes.hidden = path !== 'normal_return';
        damagedOutcomes.hidden = path !== 'damaged_item';
        detailsStep.hidden = true;

        if (outcomeHidden) {
            outcomeHidden.value = '';
        }

        serviceForm.querySelectorAll('[data-service-outcome-choice]').forEach((input) => {
            input.checked = false;
        });

        updateOutcomeAvailability();
    };

    const renderServicePreview = () => {
        if (!preview) {
            return;
        }

        const outcome = outcomeHidden?.value || '';

        if (!selectedItem || !outcome) {
            preview.textContent = 'Select an item and action.';
            return;
        }

        const quantity = Number.parseInt(quantityInput?.value || '1', 10) || 1;
        const refund = Number.parseFloat(refundInput?.value || '0') || 0;

        const text = {
            normal_restock: `Refund ${money(refund)} and return ${quantity} unit(s) to sellable stock.`,
            warranty_wait_supplier: 'Create a warranty case and mark it sent to supplier. Customer waits for the result.',
            warranty_refund_now: `Refund ${money(refund)} now and keep the case open for supplier decision.`,
            warranty_replace_now: 'Give one replacement from stock now. Supplier decision can be recorded later.',
        };

        preview.textContent = text[outcome] || 'Select an item and action.';
    };

    const showDetailsStep = (outcome) => {
        if (!selectedItem || !detailsStep || !outcomeHidden) {
            return;
        }

        outcomeHidden.value = outcome;
        detailsStep.hidden = false;

        const isRefund = refundOutcomes.includes(outcome);
        const isWarranty = warrantyOutcomes.includes(outcome);
        const fixedOne = isWarranty;

        refundFields.forEach((field) => {
            field.hidden = !isRefund;
            field.style.display = isRefund ? '' : 'none';
        });

        if (quantityInput) {
            quantityInput.max = String(selectedItem.available || 1);
            quantityInput.readOnly = fixedOne;
            quantityInput.value = fixedOne ? '1' : Math.min(Number.parseInt(quantityInput.value || '1', 10) || 1, selectedItem.available || 1);
        }

        if (refundInput && isRefund && Number.parseFloat(refundInput.value || '0') <= 0) {
            refundInput.value = money(Number.parseFloat(selectedItem.price || '0'));
        }

        renderServicePreview();
    };

    const clearSelectedItem = () => {
        selectedItem = null;

        if (itemHidden) {
            itemHidden.value = '';
        }

        resetAfterItem();

        if (preview) {
            preview.textContent = 'Select an item and action.';
        }
    };

    const selectItem = (item, button) => {
        selectedItem = item;

        if (itemHidden) {
            itemHidden.value = String(item.sale_item_id || '');
        }

        itemList?.querySelectorAll('.return-choice-card').forEach((card) => card.classList.remove('active'));
        button?.classList.add('active');

        if (quantityInput) {
            quantityInput.value = '1';
            quantityInput.max = String(item.available || 1);
        }

        if (refundInput) {
            refundInput.value = money(Number.parseFloat(item.price || '0'));
        }

        resetAfterItem();
    };

    const renderItems = (items) => {
        if (!itemList) {
            return;
        }

        clearSelectedItem();

        if (!items.length) {
            setListMessage(itemList, 'No available items found for this invoice.');
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
            button.querySelector('span').textContent = `Available ${item.available ?? 0} / Stock ${item.stock ?? 0} / Price ${money(Number.parseFloat(item.price || '0'))}`;
            button.querySelector('small').textContent = item.warranty_until
                ? `Invoice warranty until ${item.warranty_until}`
                : 'Invoice warranty not set';
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
        setListMessage(itemList, 'Loading items...');

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
                    setListMessage(itemList, 'Could not load items.');
                }
            });
    };

    const renderInvoices = (invoices) => {
        if (!invoiceList) {
            return;
        }

        clearSelectedItem();
        setListMessage(itemList, 'Select an invoice to view items.');

        if (!invoices.length) {
            setListMessage(invoiceList, 'No invoices found.');
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
            button.querySelector('small').textContent = `${invoice.available ?? 0} item(s), ${money(Number.parseFloat(invoice.total || '0'))}`;
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
        setListMessage(itemList, 'Select an invoice to view items.');

        if (customerLabel) {
            customerLabel.textContent = customer.label || 'Selected customer';
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
            showSuggestionMessage('No invoices found');
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

    serviceForm.querySelectorAll('[data-service-path]').forEach((input) => {
        input.addEventListener('change', () => showOutcomeStep(input.value));
    });

    serviceForm.querySelectorAll('[data-service-outcome-choice]').forEach((input) => {
        input.addEventListener('change', () => showDetailsStep(input.value));
    });

    [quantityInput, refundInput].forEach((input) => {
        input?.addEventListener('input', renderServicePreview);
    });

    searchInput?.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(runSearch, 180);
    });

    searchInput?.addEventListener('focus', () => {
        if (searchInput.value.trim().length >= 2) {
            runSearch();
        }
    });

    searchInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSuggestions();
        }
    });

    searchInput?.addEventListener('blur', () => {
        window.setTimeout(closeSuggestions, 120);
    });

    serviceForm.addEventListener('submit', (event) => {
        if (!itemHidden?.value || !outcomeHidden?.value) {
            event.preventDefault();

            if (preview) {
                preview.textContent = 'Choose the invoice item and handling action before saving.';
            }
        }
    });
}

const warrantyClaimModal = document.querySelector('[data-warranty-claim-modal]');

if (warrantyClaimModal) {
    const claimIdInput = warrantyClaimModal.querySelector('[data-warranty-claim-id]');
    const statusSelect = warrantyClaimModal.querySelector('[data-warranty-claim-status]');
    const summary = warrantyClaimModal.querySelector('[data-warranty-claim-summary]');
    const closeButton = warrantyClaimModal.querySelector('[data-warranty-claim-close]');
    const supplierRefundInput = warrantyClaimModal.querySelector('[data-warranty-supplier-refund]');
    const supplierRefundDateInput = warrantyClaimModal.querySelector('[data-warranty-supplier-refund-date]');
    const supplierDecisionSelect = warrantyClaimModal.querySelector('[data-warranty-supplier-decision]');
    const replacementSummary = warrantyClaimModal.querySelector('[data-warranty-replacement-summary]');
    const supplierReplacementInput = warrantyClaimModal.querySelector('[data-warranty-supplier-replacement]');
    const customerReplacementInput = warrantyClaimModal.querySelector('[data-warranty-customer-replacement]');
    const supplierReplacementAction = warrantyClaimModal.querySelector('[data-warranty-supplier-action]');
    const customerReplacementAction = warrantyClaimModal.querySelector('[data-warranty-customer-action]');
    const statusField = warrantyClaimModal.querySelector('[data-warranty-status-field]');
    const resolvedField = warrantyClaimModal.querySelector('[data-warranty-resolved-field]');
    const resolvedDateInput = warrantyClaimModal.querySelector('[data-warranty-resolved-date]');
    const refundToggle = warrantyClaimModal.querySelector('[data-warranty-refund-toggle]');
    const refundToggleInput = warrantyClaimModal.querySelector('[data-warranty-refund-toggle-input]');
    const refundFields = Array.from(warrantyClaimModal.querySelectorAll('[data-warranty-refund-field]'));
    const stockActions = warrantyClaimModal.querySelector('[data-warranty-stock-actions]');
    const sendToSupplierOption = supplierDecisionSelect?.querySelector('option[value="send_to_supplier"]');
    const noSupplierWarrantyOption = supplierDecisionSelect?.querySelector('option[value="no_supplier_warranty"]');
    let activeClaimStatus = 'received';
    let activeCustomerReplacementStatus = 'pending';
    let activeSupplierReplacementStatus = 'pending';

    const closeWarrantyModal = () => {
        warrantyClaimModal.hidden = true;
        document.body.classList.remove('modal-open');
    };

    const replacementText = (customerStatus, supplierStatus) => {
        if (customerStatus === 'issued' && supplierStatus === 'received') {
            return 'Replacement complete.';
        }

        if (customerStatus === 'refunded') {
            const supplierText = supplierStatus === 'received' ? 'supplier recovery received.' : 'supplier recovery pending.';
            return `Customer already refunded; ${supplierText}`;
        }

        if (supplierStatus === 'none') {
            return customerStatus === 'issued'
                ? 'Customer replacement issued. No supplier warranty; shop loss.'
                : 'No supplier warranty recorded; shop loss.';
        }

        const customerText = customerStatus === 'issued' ? 'Customer already received replacement.' : 'Customer is waiting for replacement.';
        const supplierText = supplierStatus === 'received' ? 'Supplier replacement received.' : 'Supplier replacement pending.';

        return `${customerText} ${supplierText}`;
    };

    const setElementHidden = (element, hidden) => {
        if (element) {
            element.hidden = hidden;
        }
    };

    const money = (value) => Number.isFinite(value) ? value.toFixed(2) : '0.00';

    const configureStockAction = (input, container, completed, visible = true, reset = false) => {
        if (!input) {
            return;
        }

        if (reset) {
            input.checked = false;
        }

        input.disabled = completed;
        setElementHidden(container, !visible);
        container?.classList.toggle('is-disabled', completed);
    };

    const syncDecisionOptions = () => {
        if (!supplierDecisionSelect) {
            return;
        }

        const supplierFinal = ['received', 'none'].includes(activeSupplierReplacementStatus);
        supplierDecisionSelect.disabled = supplierFinal;

        if (sendToSupplierOption) {
            sendToSupplierOption.disabled = supplierFinal || activeClaimStatus === 'sent_to_supplier';
        }

        if (noSupplierWarrantyOption) {
            noSupplierWarrantyOption.disabled = activeSupplierReplacementStatus === 'received';
        }
    };

    const refreshWarrantyModalFields = () => {
        const decision = supplierDecisionSelect?.value || '';
        const status = statusSelect?.value || activeClaimStatus;
        const hasSupplierRefund = Math.max(0, Number.parseFloat(supplierRefundInput?.value || '0')) > 0;
        const showRefundFields = !!refundToggleInput?.checked || hasSupplierRefund;
        const noSupplierCover = decision === 'no_supplier_warranty' || activeSupplierReplacementStatus === 'none';
        const supplierFinal = ['received', 'none'].includes(activeSupplierReplacementStatus);
        const customerFinal = ['issued', 'refunded'].includes(activeCustomerReplacementStatus);
        const showSupplierAction = !noSupplierCover && (!supplierFinal || !!supplierReplacementInput?.checked);
        const showCustomerAction = !noSupplierCover
            && activeCustomerReplacementStatus !== 'refunded'
            && (!customerFinal || !!customerReplacementInput?.checked);

        setElementHidden(statusField, true);
        setElementHidden(resolvedField, !(decision === 'no_supplier_warranty' || ['resolved', 'rejected'].includes(status)));

        if (refundToggleInput) {
            refundToggleInput.checked = showRefundFields;
        }

        refundFields.forEach((field) => setElementHidden(field, !showRefundFields));
        configureStockAction(supplierReplacementInput, supplierReplacementAction, supplierFinal || noSupplierCover, showSupplierAction);
        configureStockAction(customerReplacementInput, customerReplacementAction, customerFinal, showCustomerAction);
        setElementHidden(stockActions, !(showSupplierAction || showCustomerAction));

        if (noSupplierCover && supplierReplacementInput) {
            supplierReplacementInput.checked = false;
            supplierReplacementInput.disabled = true;
        }
    };

    const syncWarrantyStatusFromActions = () => {
        if (!statusSelect) {
            return;
        }

        const supplierDone = ['received', 'none'].includes(activeSupplierReplacementStatus) || !!supplierReplacementInput?.checked;
        const supplierReceived = activeSupplierReplacementStatus === 'received' || !!supplierReplacementInput?.checked;
        const customerDone = ['issued', 'refunded'].includes(activeCustomerReplacementStatus) || !!customerReplacementInput?.checked;

        if (supplierDone && customerDone) {
            statusSelect.value = 'resolved';
        } else if (supplierReceived) {
            statusSelect.value = 'ready_for_pickup';
        } else if (customerDone && statusSelect.value === 'received') {
            statusSelect.value = 'sent_to_supplier';
        }
    };

    const openWarrantyModal = (row) => {
        const claimId = row.dataset.claimId || '';
        const claimNo = row.dataset.claimNo || 'Claim';
        const status = row.dataset.claimStatus || 'received';
        const customer = row.dataset.claimCustomer || 'Walk-in Customer';
        const product = row.dataset.claimProduct || '';
        const refundAmount = row.dataset.claimRefundAmount || '0.00';
        const refundDate = row.dataset.claimRefundDate || new Date().toISOString().slice(0, 10);
        activeClaimStatus = status;
        activeCustomerReplacementStatus = row.dataset.customerReplacementStatus || 'pending';
        activeSupplierReplacementStatus = row.dataset.supplierReplacementStatus || 'pending';

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

        if (replacementSummary) {
            replacementSummary.textContent = replacementText(activeCustomerReplacementStatus, activeSupplierReplacementStatus);
        }

        configureStockAction(
            supplierReplacementInput,
            supplierReplacementAction,
            ['received', 'none'].includes(activeSupplierReplacementStatus),
            true,
            true
        );
        configureStockAction(
            customerReplacementInput,
            customerReplacementAction,
            ['issued', 'refunded'].includes(activeCustomerReplacementStatus),
            true,
            true
        );

        if (supplierDecisionSelect) {
            supplierDecisionSelect.value = '';
        }

        if (resolvedDateInput && !resolvedDateInput.value) {
            resolvedDateInput.value = new Date().toISOString().slice(0, 10);
        }

        syncDecisionOptions();
        refreshWarrantyModalFields();

        warrantyClaimModal.hidden = false;
        document.body.classList.add('modal-open');
        (supplierDecisionSelect && !supplierDecisionSelect.disabled ? supplierDecisionSelect : closeButton)?.focus();
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

    supplierReplacementInput?.addEventListener('change', () => {
        syncWarrantyStatusFromActions();
        refreshWarrantyModalFields();
    });
    customerReplacementInput?.addEventListener('change', () => {
        syncWarrantyStatusFromActions();
        refreshWarrantyModalFields();
    });
    supplierDecisionSelect?.addEventListener('change', () => {
        if (!statusSelect) {
            return;
        }

        if (supplierDecisionSelect.value === 'send_to_supplier') {
            statusSelect.value = 'sent_to_supplier';
        } else if (supplierDecisionSelect.value === 'no_supplier_warranty') {
            statusSelect.value = 'resolved';
            if (supplierReplacementInput) {
                supplierReplacementInput.checked = false;
            }
        } else {
            statusSelect.value = activeClaimStatus;
        }

        refreshWarrantyModalFields();
    });
    refundToggleInput?.addEventListener('change', () => {
        const enabled = refundToggleInput.checked;

        if (!enabled && supplierRefundInput) {
            supplierRefundInput.value = money(0);
        }

        refundFields.forEach((field) => setElementHidden(field, !enabled));
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

const lotCorrectModal = document.querySelector('[data-lot-correct-modal]');

if (lotCorrectModal) {
    const productInput = lotCorrectModal.querySelector('[data-lot-correct-product]');
    const lotInput = lotCorrectModal.querySelector('[data-lot-correct-lot]');
    const currentStock = lotCorrectModal.querySelector('[data-lot-correct-current]');
    const exactInput = lotCorrectModal.querySelector('[data-lot-correct-exact]');
    const costInput = lotCorrectModal.querySelector('[data-lot-correct-cost]');
    const summary = lotCorrectModal.querySelector('[data-lot-correct-summary]');
    const closeButton = lotCorrectModal.querySelector('[data-lot-correct-close]');

    const closeLotCorrectModal = () => {
        lotCorrectModal.hidden = true;
        document.body.classList.remove('modal-open');
    };

    const openLotCorrectModal = (button) => {
        const lotId = button.dataset.lotId || '';
        const productId = button.dataset.productId || '';
        const stock = button.dataset.currentStock || '0';
        const lotCost = button.dataset.lotCost || '0.00';
        const lotSummary = button.dataset.lotSummary || 'Selected stock lot';

        if (productInput) {
            productInput.value = productId;
        }

        if (lotInput) {
            lotInput.value = lotId;
        }

        if (currentStock) {
            currentStock.textContent = stock;
        }

        if (exactInput) {
            exactInput.value = stock;
        }

        if (costInput) {
            costInput.value = lotCost;
        }

        if (summary) {
            summary.textContent = lotSummary;
        }

        lotCorrectModal.hidden = false;
        document.body.classList.add('modal-open');
        exactInput?.focus();
        exactInput?.select();
    };

    document.querySelectorAll('[data-lot-correct-button]').forEach((button) => {
        button.addEventListener('click', () => openLotCorrectModal(button));
    });

    closeButton?.addEventListener('click', closeLotCorrectModal);
    lotCorrectModal.addEventListener('click', (event) => {
        if (event.target === lotCorrectModal) {
            closeLotCorrectModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !lotCorrectModal.hidden) {
            closeLotCorrectModal();
        }
    });
}
