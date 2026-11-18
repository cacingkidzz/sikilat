/* =====================================================================
   SIKILAT - Interaksi editor kunci jawaban
   - Tipe soal per nomor: PG, Benar/Salah, Multiple Response
   - Progres pengisian langsung
   - Navigasi keyboard dan input massal type-aware
   - Peringatan perubahan belum disimpan
   ===================================================================== */
(function () {
    'use strict';

    var OPTION_KEYS = ['A', 'B', 'C', 'D', 'E'];
    var BS_KEYS = ['B', 'S'];
    var form = document.querySelector('[data-key-form]');

    function toArray(list) {
        return Array.prototype.slice.call(list);
    }

    function typeSelects() {
        return form ? toArray(form.querySelectorAll('[data-key-type-select]')) : [];
    }

    function hasMultiType() {
        return typeSelects().some(function (select) {
            return select.value === 'multiple_response';
        });
    }

    function activeBlock(row) {
        var select = row ? row.querySelector('[data-key-type-select]') : null;
        var type = select ? select.value : 'pg';
        return row ? row.querySelector('[data-key-type="' + type + '"]') : null;
    }

    function syncRowType(row) {
        var select = row ? row.querySelector('[data-key-type-select]') : null;
        var controls = row ? row.querySelector('[data-key-controls]') : null;
        if (!select || !controls) {
            return;
        }

        var type = select.value || 'pg';
        row.setAttribute('data-tipe', type);
        controls.setAttribute('data-active', type);
    }

    /* -----------------------------------------------------------------
     * Input massal: bersihkan, hitung, dan batasi sesuai jumlah soal
     * ------------------------------------------------------------------ */
    var bulkInput = document.querySelector('[data-bulk-input]');

    if (bulkInput) {
        var bulkMax = parseInt(bulkInput.getAttribute('data-max') || '0', 10) || 0;
        var bulkForm = bulkInput.form || bulkInput.closest('form');
        var bulkCount = document.querySelector('[data-bulk-count]');
        var bulkSubmit = bulkForm ? toArray(bulkForm.elements).filter(function (element) {
            return element.type === 'submit' && element.value === 'bulk';
        })[0] : null;
        var bulkHint = document.querySelector('[data-bulk-hint]');

        var sanitizeBulk = function (text, multiMode) {
            var value = String(text).toUpperCase();

            if (multiMode) {
                return value.replace(/[^A-EBS()\s,;._-]/g, '');
            }

            return value.replace(/[^A-EBS\s,;._-]/g, '');
        };

        var countTokens = function (text, multiMode) {
            if (multiMode) {
                return text.split(/[\s,;]+/).filter(function (token) {
                    return token !== '';
                }).length;
            }

            return text.replace(/[\s,;]/g, '').length;
        };

        var updateBulk = function () {
            var multiMode = hasMultiType();
            var raw = bulkInput.value;
            var clean = sanitizeBulk(raw, multiMode);

            if (clean !== raw) {
                bulkInput.value = clean;
                try {
                    bulkInput.setSelectionRange(clean.length, clean.length);
                } catch (error) {
                    /* abaikan browser tanpa dukungan selection */
                }
            }

            var total = countTokens(clean, multiMode);
            var tooMany = bulkMax > 0 && total > bulkMax;

            if (bulkCount) {
                bulkCount.textContent = total + (bulkMax > 0 ? ' / ' + bulkMax : '')
                    + (multiMode ? ' token' : ' pilihan');
                bulkCount.classList.toggle('text-danger', tooMany);
                bulkCount.classList.toggle('fw-semibold', tooMany);
                bulkCount.classList.toggle('text-secondary', !tooMany);
            }

            if (bulkHint) {
                bulkHint.classList.toggle('d-none', !tooMany);
            }

            if (bulkSubmit) {
                bulkSubmit.disabled = tooMany || total === 0;
            }

            return total;
        };

        bulkInput.addEventListener('input', updateBulk);
        bulkInput.addEventListener('paste', function () {
            window.setTimeout(updateBulk, 0);
        });

        if (bulkForm) {
            bulkForm.addEventListener('submit', function (event) {
                var submitter = event.submitter || null;
                if (submitter && submitter.value !== 'bulk') {
                    return;
                }

                var total = updateBulk();
                if (total === 0 || (bulkMax > 0 && total > bulkMax)) {
                    event.preventDefault();
                }
            });
        }

        bulkInput.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                event.preventDefault();
                if (bulkForm && bulkSubmit && !bulkSubmit.disabled && typeof bulkForm.requestSubmit === 'function') {
                    bulkForm.requestSubmit(bulkSubmit);
                }
            }
        });

        updateBulk();
    }

    /* -----------------------------------------------------------------
     * Editor tabel kunci
     * ------------------------------------------------------------------ */
    if (!form) {
        return;
    }

    var table = form.querySelector('.key-table');
    var rows = table ? toArray(table.querySelectorAll('tr[data-key-row]')) : [];
    var total = parseInt(form.getAttribute('data-total') || '0', 10) || rows.length;

    var progressText = document.querySelector('[data-progress-text]');
    var progressBar = document.querySelector('[data-progress-bar]');
    var progressWrap = document.querySelector('[data-progress-wrap]');
    var progressValue = document.querySelector('[data-progress-percent]');
    var filledLabel = document.querySelector('[data-filled-count]');
    var missingLabel = document.querySelector('[data-missing-count]');
    var saveLabel = form.querySelector('[data-save-label]');

    var rowByNumber = {};
    rows.forEach(function (row) {
        rowByNumber[row.getAttribute('data-key-row')] = row;
        syncRowType(row);
    });

    var selectedKey = function (row) {
        var select = row.querySelector('[data-key-type-select]');
        var type = select ? select.value : 'pg';

        if (type === 'multiple_response') {
            var checked = toArray(
                row.querySelectorAll('[data-key-type="multiple_response"] input:checked')
            ).map(function (input) {
                return input.value;
            }).sort();

            return checked.join(',');
        }

        var block = row.querySelector('[data-key-type="' + type + '"]');
        var input = block ? block.querySelector('input:checked') : null;

        return input ? input.value : '';
    };

    var snapshot = function () {
        return rows.map(selectedKey).join('|');
    };

    var initialSnapshot = snapshot();
    var dirty = false;

    var refresh = function () {
        var filled = 0;

        rows.forEach(function (row) {
            var hasKey = selectedKey(row) !== '';
            row.classList.toggle('key-row-filled', hasKey);
            if (hasKey) {
                filled++;
            }
        });

        var missing = Math.max(0, total - filled);
        var percent = total > 0 ? Math.round((filled / total) * 100) : 0;
        var color = missing === 0 ? 'success' : (filled > 0 ? 'warning' : 'secondary');

        if (progressText) {
            progressText.textContent = filled + ' / ' + total + ' terisi';
        }

        if (progressValue) {
            progressValue.textContent = percent + '%';
        }

        if (progressBar) {
            progressBar.style.width = percent + '%';
            progressBar.classList.remove('bg-success', 'bg-warning', 'bg-secondary');
            progressBar.classList.add('bg-' + color);
        }

        if (progressWrap) {
            progressWrap.setAttribute('aria-valuenow', String(percent));
        }

        if (filledLabel) {
            filledLabel.textContent = String(filled);
        }

        if (missingLabel) {
            missingLabel.textContent = String(missing);
            missingLabel.classList.toggle('text-danger', missing > 0);
            missingLabel.classList.toggle('text-secondary', missing === 0);
        }

        if (saveLabel) {
            saveLabel.textContent = 'Simpan Kunci (' + filled + '/' + total + ')';
        }

        return filled;
    };

    var markDirty = function () {
        dirty = snapshot() !== initialSnapshot;
    };

    var focusInput = function (row, option) {
        if (!row) {
            return;
        }

        var select = row.querySelector('[data-key-type-select]');
        var type = select ? select.value : 'pg';
        var block = row.querySelector('[data-key-type="' + type + '"]');

        if (!block) {
            return;
        }

        var keys = type === 'benar_salah' ? BS_KEYS : OPTION_KEYS;
        if (keys.indexOf(option) === -1) {
            option = keys[0];
        }

        var target = block.querySelector('input[value="' + option + '"]');
        if (!target) {
            return;
        }

        try {
            target.focus({ preventScroll: true });
        } catch (error) {
            target.focus();
        }

        row.scrollIntoView({ block: 'nearest' });
    };

    var currentRow = function (element) {
        return element.closest ? element.closest('tr[data-key-row]') : null;
    };

    var rowNumber = function (row) {
        return parseInt(row.getAttribute('data-key-row') || '0', 10) || 0;
    };

    var clearRow = function (row) {
        toArray(row.querySelectorAll('input[data-key-input]')).forEach(function (input) {
            input.checked = false;
        });
    };

    form.addEventListener('change', function (event) {
        var target = event.target;
        if (!target) {
            return;
        }

        if (target.matches && target.matches('[data-key-type-select]')) {
            var changedRow = currentRow(target);
            if (changedRow) {
                syncRowType(changedRow);
                changedRow.setAttribute('data-touched', '1');
            }

            refresh();
            markDirty();
            if (bulkInput) {
                bulkInput.dispatchEvent(new Event('input'));
            }

            return;
        }

        if (!target.matches || !target.matches('input[data-key-input]')) {
            return;
        }

        var row = currentRow(target);
        if (row) {
            row.setAttribute('data-touched', '1');
        }

        refresh();
        markDirty();
    });

    form.addEventListener('keydown', function (event) {
        var target = event.target;
        if (!target || !target.matches || !target.matches('input[data-key-input]')) {
            return;
        }

        var row = currentRow(target);
        if (!row) {
            return;
        }

        var select = row.querySelector('[data-key-type-select]');
        var type = select ? select.value : 'pg';
        var number = rowNumber(row);
        var key = event.key;
        var upper = typeof key === 'string' ? key.toUpperCase() : '';
        var keys = type === 'benar_salah' ? BS_KEYS : OPTION_KEYS;
        var isChoiceKey = keys.indexOf(upper) !== -1;

        if (isChoiceKey) {
            event.preventDefault();

            var choice = row.querySelector(
                '[data-key-type="' + type + '"] input[value="' + upper + '"]'
            );

            if (choice) {
                if (type === 'multiple_response') {
                    choice.checked = !choice.checked;
                } else {
                    choice.checked = true;
                }
                choice.dispatchEvent(new Event('change', { bubbles: true }));
            }

            if (type !== 'multiple_response' && number < total) {
                focusInput(rowByNumber[number + 1], upper);
            }

            return;
        }

        if (key === 'ArrowDown' || key === 'ArrowUp') {
            event.preventDefault();
            var nextNumber = key === 'ArrowDown' ? number + 1 : number - 1;
            var nextRow = rowByNumber[nextNumber];
            if (!nextRow) {
                return;
            }

            var option = selectedKey(row).split(',')[0] || target.value || 'A';
            focusInput(nextRow, option);
            return;
        }

        if (key === 'ArrowRight' || key === 'ArrowLeft') {
            event.preventDefault();
            var index = keys.indexOf(upper);
            if (index === -1) {
                index = keys.indexOf(target.value);
            }
            var nextIndex = key === 'ArrowRight' ? index + 1 : index - 1;
            if (nextIndex < 0 || nextIndex >= keys.length) {
                return;
            }
            focusInput(row, keys[nextIndex]);
            return;
        }

        if (key === 'Backspace' || key === 'Delete') {
            event.preventDefault();
            var block = row.querySelector('[data-key-type="' + type + '"]');
            toArray(block ? block.querySelectorAll('input') : []).forEach(function (input) {
                input.checked = false;
            });
            refresh();
            markDirty();
            return;
        }

        if (key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        }
    });

    form.addEventListener('click', function (event) {
        var optionLabel = event.target.closest('label[for^="kunci_"]');
        if (optionLabel) {
            var optionInput = document.getElementById(optionLabel.getAttribute('for'));
            if (optionInput) {
                optionInput.focus();
            }
        }

        var clearButton = event.target.closest('[data-clear-row]');
        if (clearButton) {
            var row = currentRow(clearButton) || clearButton.closest('tr[data-key-row]');
            if (!row) {
                return;
            }

            clearRow(row);
            refresh();
            markDirty();

            var number = rowNumber(row);
            focusInput(rowByNumber[number], selectedKey(row) || 'A');
            return;
        }

        var clearAll = event.target.closest('[data-clear-all]');
        if (clearAll) {
            var runClear = function () {
                toArray(form.querySelectorAll('input[data-key-input]')).forEach(function (input) {
                    input.checked = false;
                });
                refresh();
                markDirty();
                focusInput(rows[0], 'A');
            };

            if (!window.Swal) {
                if (window.confirm('Kosongkan semua kunci yang tampil? Perubahan baru tersimpan setelah tombol simpan ditekan.')) {
                    runClear();
                }
                return;
            }

            window.Swal.fire({
                title: 'Kosongkan semua kunci?',
                text: 'Seluruh pilihan pada paket ini akan dihapus dari tampilan. Data tersimpan baru berubah setelah tombol simpan ditekan.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Ya, kosongkan',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#dc3545',
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) {
                    runClear();
                }
            });
            return;
        }

        var jump = event.target.closest('[data-focus-first-empty]');
        if (jump) {
            event.preventDefault();
            var emptyRow = null;
            for (var index = 0; index < rows.length; index++) {
                if (selectedKey(rows[index]) === '') {
                    emptyRow = rows[index];
                    break;
                }
            }

            if (emptyRow) {
                focusInput(emptyRow, 'A');
                if (window.Swal) {
                    window.Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'info',
                        title: 'Lompat ke nomor ' + rowNumber(emptyRow),
                        showConfirmButton: false,
                        timer: 2200
                    });
                }
            } else if (window.Swal) {
                window.Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Semua nomor sudah terisi',
                    showConfirmButton: false,
                    timer: 2200
                });
            }
        }
    });

    form.addEventListener('submit', function () {
        dirty = false;
    });

    window.addEventListener('beforeunload', function (event) {
        if (!dirty) {
            return;
        }

        event.preventDefault();
        event.returnValue = 'Perubahan kunci jawaban belum disimpan. Yakin ingin meninggalkan halaman?';
        return event.returnValue;
    });

    refresh();
})();
