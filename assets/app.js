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
        row.querySelectorAll('[data-purchase-quantity], [data-purchase-cost]').forEach((input) => {
            input.addEventListener('input', recalculate);
        });

        const productSelect = row.querySelector('[data-purchase-product]');
        const costInput = row.querySelector('[data-purchase-cost]');

        if (productSelect && costInput) {
            productSelect.addEventListener('change', () => {
                const selected = productSelect.selectedOptions[0];
                const cost = selected?.dataset.cost;

                if (cost && Number.parseFloat(cost) > 0) {
                    costInput.value = Number.parseFloat(cost).toFixed(2);
                }

                recalculate();
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
    const barcodeInput = saleForm.querySelector('[data-sale-barcode]');
    const subtotalInput = saleForm.querySelector('[data-sale-subtotal]');
    const discountInput = saleForm.querySelector('[data-sale-discount]');
    const taxInput = saleForm.querySelector('[data-sale-tax]');
    const totalInput = saleForm.querySelector('[data-sale-total]');
    const paidInput = saleForm.querySelector('[data-sale-paid]');
    const balanceInput = saleForm.querySelector('[data-sale-balance]');

    const money = (value) => Number.isFinite(value) ? value.toFixed(2) : '0.00';

    const recalculateSale = () => {
        let subtotal = 0;

        rowsContainer.querySelectorAll('[data-sale-row]').forEach((row) => {
            const quantity = Math.max(0, Number.parseFloat(row.querySelector('[data-sale-quantity]')?.value || '0'));
            const price = Math.max(0, Number.parseFloat(row.querySelector('[data-sale-price]')?.value || '0'));
            const discount = Math.max(0, Number.parseFloat(row.querySelector('[data-sale-line-discount]')?.value || '0'));
            const stock = Math.max(0, Number.parseInt(row.querySelector('[data-sale-product]')?.selectedOptions[0]?.dataset.stock || '0', 10));
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
        row.querySelectorAll('[data-sale-quantity], [data-sale-price], [data-sale-line-discount]').forEach((input) => {
            input.addEventListener('input', recalculateSale);
        });

        const productSelect = row.querySelector('[data-sale-product]');
        const priceInput = row.querySelector('[data-sale-price]');
        const quantityInput = row.querySelector('[data-sale-quantity]');

        if (productSelect && priceInput) {
            productSelect.addEventListener('change', () => {
                const selected = productSelect.selectedOptions[0];
                const price = selected?.dataset.price;
                const stock = Number.parseInt(selected?.dataset.stock || '0', 10);

                if (price && Number.parseFloat(price) > 0) {
                    priceInput.value = Number.parseFloat(price).toFixed(2);
                }

                if (quantityInput) {
                    quantityInput.max = String(Math.max(0, stock));
                }

                recalculateSale();
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

    if (barcodeInput) {
        barcodeInput.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();

            const query = barcodeInput.value.trim().toLowerCase();
            if (!query) {
                return;
            }

            const rows = Array.from(rowsContainer.querySelectorAll('[data-sale-row]'));
            let targetRow = rows.find((row) => !row.querySelector('[data-sale-product]')?.value) || null;

            if (!targetRow) {
                targetRow = createSaleRow();
            }

            const select = targetRow?.querySelector('[data-sale-product]');
            if (!select) {
                return;
            }

            const option = Array.from(select.options).find((item) => {
                const text = item.textContent?.toLowerCase() || '';
                const barcode = item.dataset.barcode?.toLowerCase() || '';
                return item.value && (barcode === query || text.includes(query));
            });

            if (option) {
                select.value = option.value;
                select.dispatchEvent(new Event('change'));
                barcodeInput.value = '';
            }
        });
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

    const money = (value) => Number.isFinite(value) ? value.toFixed(2) : '0.00';

    const renderCollectionPreview = () => {
        const selected = invoiceSelect?.selectedOptions[0];
        const balance = Number.parseFloat(selected?.dataset.balance || '0');
        const amount = Math.max(0, Number.parseFloat(amountInput?.value || '0'));
        const remaining = Math.max(0, balance - amount);

        if (balanceDisplay) {
            balanceDisplay.textContent = selected?.value ? money(balance) : 'Choose invoice';
        }

        if (!preview) {
            return;
        }

        if (!selected?.value) {
            preview.textContent = 'Select an invoice to preview the remaining balance.';
            return;
        }

        preview.textContent = amount > balance
            ? 'Payment is higher than the invoice balance and cannot be saved.'
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
