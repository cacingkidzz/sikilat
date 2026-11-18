/* =====================================================================
   SIKILAT - JavaScript aplikasi
   - Notifikasi flash dengan SweetAlert2
   - Toggle tampil/sembunyi password
   - Inisialisasi DataTables (Bahasa Indonesia)
   - Konfirmasi form dengan SweetAlert2
   ===================================================================== */
(function () {
    'use strict';

    var flashElement = document.getElementById('flash-data');
    if (flashElement && window.Swal) {
        var flashes = [];
        try {
            flashes = JSON.parse(flashElement.getAttribute('data-flash') || '[]');
        } catch (error) {
            flashes = [];
        }

        if (Array.isArray(flashes)) {
            flashes.forEach(function (flash) {
                var icon = ['success', 'error', 'warning', 'info'].indexOf(flash.type) !== -1
                    ? flash.type
                    : 'info';

                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: icon,
                    title: String(flash.message || ''),
                    showConfirmButton: false,
                    timer: 4500,
                    timerProgressBar: true,
                    didOpen: function (toast) {
                        toast.addEventListener('mouseenter', Swal.stopTimer);
                        toast.addEventListener('mouseleave', Swal.resumeTimer);
                    }
                });
            });
        }
    }

    document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
        button.addEventListener('click', function () {
            var selector = button.getAttribute('data-target');
            var target = selector ? document.querySelector(selector) : null;
            if (!target) {
                return;
            }

            var showPassword = target.getAttribute('type') === 'password';
            target.setAttribute('type', showPassword ? 'text' : 'password');

            var icon = button.querySelector('i');
            if (icon) {
                icon.classList.toggle('bi-eye', !showPassword);
                icon.classList.toggle('bi-eye-slash', showPassword);
            }

            button.setAttribute('aria-label', showPassword ? 'Sembunyikan password' : 'Tampilkan password');
        });
    });

    if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.DataTable === 'function') {
        var dataTablesLanguage = {
            emptyTable: 'Belum ada data',
            info: 'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',
            infoEmpty: 'Menampilkan 0 data',
            infoFiltered: '(difilter dari _MAX_ total data)',
            lengthMenu: 'Tampilkan _MENU_ data',
            loadingRecords: 'Memuat...',
            processing: 'Memproses...',
            search: 'Cari:',
            searchPlaceholder: 'Ketik kata kunci...',
            zeroRecords: 'Data tidak ditemukan',
            paginate: {
                first: 'Pertama',
                last: 'Terakhir',
                next: 'Berikutnya',
                previous: 'Sebelumnya'
            },
            aria: {
                sortAscending: ': aktifkan untuk mengurutkan naik',
                sortDescending: ': aktifkan untuk mengurutkan turun'
            }
        };

        document.querySelectorAll('table[data-datatable]').forEach(function (table) {
            try {
                window.jQuery(table).DataTable({
                    language: dataTablesLanguage,
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'Semua']],
                    order: [],
                    autoWidth: false
                });
            } catch (error) {
                console.error('DataTables gagal diinisialisasi:', error);
            }
        });
    }

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.getAttribute('data-confirmed') === '1') {
                return;
            }

            event.preventDefault();

            var message = form.getAttribute('data-confirm') || 'Lanjutkan tindakan ini?';
            var title = form.getAttribute('data-confirm-title') || 'Konfirmasi';

            if (!window.Swal) {
                if (window.confirm(message)) {
                    form.setAttribute('data-confirmed', '1');
                    form.submit();
                }
                return;
            }

            Swal.fire({
                title: title,
                text: message,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Ya, lanjutkan',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#dc3545',
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) {
                    form.setAttribute('data-confirmed', '1');
                    form.submit();
                }
            });
        });
    });

    if (window.bootstrap) {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (element) {
            new window.bootstrap.Tooltip(element);
        });
    }
})();
