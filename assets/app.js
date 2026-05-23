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

    const hydrateRow = (row) => {
        const productInput = row.querySelector('[data-product-search]');
        const productHidden = row.querySelector('[data-purchase-product]');
        const suggestions = row.querySelector('[data-product-suggestions]');
        const warrantyInput = row.querySelector('[data-purchase-warranty]');
        const costInput = row.querySelector('[data-purchase-cost]');
        let searchTimer = null;
        let searchToken = 0;

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
                button.querySelector('span').textContent = `Stock ${product.stock ?? 0} / Cost ${money(Number.parseFloat(product.cost || '0'))} / Warranty ${product.warranty ?? 0} mo`;
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

            const query = productInput.value.trim();

            if (productHidden) {
                productHidden.value = '';
            }

            if (query.length < 2) {
                closeSuggestions();
                return;
            }

            const token = ++searchToken;
            showSuggestionMessage('Searching...');

            fetch(`${productSearchUrl}?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
            })
                .then((response) => response.ok ? response.json() : { products: [] })
                .then((data) => {
                    if (token !== searchToken) {
                        return;
                    }

                    renderSuggestions(Array.isArray(data.products) ? data.products : []);
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

    const money = (value) => Number.isFinite(value) ? value.toFixed(2) : '0.00';

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
                button.querySelector('span').textContent = `Stock ${product.stock ?? 0} / Sell ${money(Number.parseFloat(product.price || '0'))}`;
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

            const query = productInput.value.trim();
            clearSelectedProduct();
            recalculateSale();

            if (query.length < 2) {
                closeSuggestions();
                return;
            }

            const token = ++searchToken;
            showSuggestionMessage('Searching...');

            fetch(`${saleProductSearchUrl}?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
            })
                .then((response) => response.ok ? response.json() : { products: [] })
                .then((data) => {
                    if (token !== searchToken) {
                        return;
                    }

                    renderSuggestions(Array.isArray(data.products) ? data.products : []);
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
    const itemSelect = returnForm.querySelector('[data-return-item]');
    const quantityInput = returnForm.querySelector('[data-return-quantity]');
    const refundInput = returnForm.querySelector('[data-return-refund]');
    const availableDisplay = returnForm.querySelector('[data-return-available]');
    const preview = returnForm.querySelector('[data-return-preview]');

    const money = (value) => Number.isFinite(value) ? value.toFixed(2) : '0.00';

    const renderReturnPreview = () => {
        const selected = itemSelect?.selectedOptions[0];
        const available = Number.parseInt(selected?.dataset.available || '0', 10);
        const price = Number.parseFloat(selected?.dataset.price || '0');
        const quantity = Math.max(0, Number.parseInt(quantityInput?.value || '0', 10));
        const maxRefund = Math.max(0, quantity * price);

        if (availableDisplay) {
            availableDisplay.textContent = selected?.value ? String(available) : 'Choose item';
        }

        if (quantityInput && selected?.value) {
            quantityInput.max = String(Math.max(1, available));
        }

        if (refundInput && selected?.value && Number.parseFloat(refundInput.value || '0') === 0) {
            refundInput.value = money(maxRefund);
        }

        if (!preview) {
            return;
        }

        if (!selected?.value) {
            preview.textContent = 'Select an item to preview the return.';
            return;
        }

        if (quantity > available) {
            preview.textContent = `Only ${available} unit(s) are still returnable.`;
            return;
        }

        preview.textContent = `Return ${quantity} unit(s). Maximum refund for this quantity: ${money(maxRefund)}.`;
    };

    if (itemSelect) {
        itemSelect.addEventListener('change', () => {
            if (refundInput) {
                refundInput.value = '0.00';
            }
            renderReturnPreview();
        });
    }

    [quantityInput, refundInput].forEach((input) => {
        if (input) {
            input.addEventListener('input', renderReturnPreview);
        }
    });

    renderReturnPreview();
}
