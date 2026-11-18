/* =====================================================================
   SIKILAT - Interaksi modul CRUD tahap 2
   - Mengisi modal edit dari atribut data-record
   - Mereset modal tambah
   - Membuka modal otomatis saat validasi server gagal
   - Tombol pilih semua checkbox
   - Filter otomatis saat select berubah
   ===================================================================== */
(function () {
    'use strict';

    function parseRecord(element) {
        var raw = element.getAttribute('data-record') || '{}';
        try {
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
            return {};
        }
    }

    function fillForm(form, record) {
        Object.keys(record).forEach(function (key) {
            var value = record[key];

            if (Array.isArray(value)) {
                var boxes = form.querySelectorAll('[name="' + key + '[]"]');
                var selected = value.map(String);
                boxes.forEach(function (box) {
                    box.checked = selected.indexOf(String(box.value)) !== -1;
                });
                return;
            }

            var field = form.elements.namedItem(key);
            if (!field) {
                return;
            }

            if (field instanceof RadioNodeList) {
                Array.prototype.forEach.call(field, function (item) {
                    if (item.type === 'radio') {
                        item.checked = String(item.value) === String(value);
                    }
                });
                return;
            }

            if (field.type === 'checkbox') {
                field.checked = value === true || value === 1 || value === '1';
                return;
            }

            if (field.type === 'password') {
                field.value = '';
                return;
            }

            field.value = value === null || typeof value === 'undefined' ? '' : String(value);
        });
    }

    function resetModalForm(modal) {
        var form = modal.querySelector('form[data-crud-form]');
        if (!form) {
            return;
        }

        form.reset();

        var idField = form.querySelector('[name="id"]');
        if (idField) {
            idField.value = '';
        }

        form.querySelectorAll('[data-server-error]').forEach(function (alert) {
            alert.remove();
        });
    }

    function prepareModal(modal, trigger) {
        var form = modal.querySelector('form[data-crud-form]');
        if (!form) {
            return;
        }

        var titleElement = modal.querySelector('[data-modal-title]');
        var record = trigger ? parseRecord(trigger) : {};
        var isEdit = trigger && trigger.hasAttribute('data-record');

        if (!trigger) {
            return;
        }

        if (isEdit) {
            form.reset();
            var editId = form.querySelector('[name="id"]');
            if (editId) {
                editId.value = '';
            }
            fillForm(form, record);
            if (titleElement) {
                titleElement.textContent = trigger.getAttribute('data-modal-title') || 'Ubah Data';
            }
        } else {
            resetModalForm(modal);
            if (titleElement) {
                titleElement.textContent = trigger.getAttribute('data-modal-title') || 'Tambah Data';
            }
        }

        form.querySelectorAll('[data-server-error]').forEach(function (alert) {
            alert.remove();
        });
    }

    var autoOpen = document.querySelector('[data-auto-modal]');
    if (autoOpen && window.bootstrap) {
        var autoModal = document.querySelector(autoOpen.getAttribute('data-auto-modal'));
        if (autoModal) {
            window.bootstrap.Modal.getOrCreateInstance(autoModal).show();
        }
    }

    document.querySelectorAll('.modal').forEach(function (modal) {
        modal.addEventListener('show.bs.modal', function (event) {
            prepareModal(modal, event.relatedTarget);
        });

        modal.addEventListener('hidden.bs.modal', function () {
            var form = modal.querySelector('form[data-crud-form]');
            if (!form) {
                return;
            }
            form.querySelectorAll('[data-server-error]').forEach(function (alert) {
                alert.remove();
            });
        });
    });

    document.addEventListener('click', function (event) {
        var checkAll = event.target.closest('[data-check-all]');
        if (!checkAll) {
            return;
        }

        var target = document.querySelector(checkAll.getAttribute('data-check-all'));
        if (!target) {
            return;
        }

        var boxes = target.querySelectorAll('input[type="checkbox"]');
        var allChecked = Array.prototype.every.call(boxes, function (box) {
            return box.checked;
        });

        boxes.forEach(function (box) {
            box.checked = !allChecked;
        });

        checkAll.innerHTML = allChecked
            ? '<i class="bi bi-check2-square me-1"></i>Pilih semua'
            : '<i class="bi bi-x-square me-1"></i>Batalkan semua';
    });

    document.querySelectorAll('form[data-auto-submit]').forEach(function (form) {
        form.querySelectorAll('select').forEach(function (select) {
            select.addEventListener('change', function () {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        });
    });
})();
