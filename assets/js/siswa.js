/* =====================================================================
   SIKILAT - Interaksi halaman siswa
   - Sinkronisasi input cepat dengan pilihan per nomor
   - Mendukung PG, Benar/Salah, dan Multiple Response
   - Pratinjau jawaban, progres, timer, draft, dan proteksi klik ganda
   ===================================================================== */
(function () {
    'use strict';

    var DRAFT_PREFIX = 'siswa-draft:';

    function safeStorageGet(key) {
        try {
            return window.sessionStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function safeStorageSet(key, value) {
        try {
            window.sessionStorage.setItem(key, value);
        } catch (error) {
            /* Mode privat atau kuota penuh: draft opsional diabaikan. */
        }
    }

    function clearDraftKeys() {
        try {
            var keys = [];
            for (var index = 0; index < window.sessionStorage.length; index++) {
                var key = window.sessionStorage.key(index);
                if (key && key.indexOf(DRAFT_PREFIX) === 0) {
                    keys.push(key);
                }
            }
            keys.forEach(function (key) {
                window.sessionStorage.removeItem(key);
            });
        } catch (error) {
            /* abaikan */
        }
    }

    function initConfirmForms() {
        var forms = document.querySelectorAll('form[data-confirm]');

        Array.prototype.forEach.call(forms, function (form) {
            form.addEventListener('submit', function (event) {
                if (form.getAttribute('data-confirmed') === '1') {
                    return;
                }

                event.preventDefault();

                var proceed = function () {
                    form.setAttribute('data-confirmed', '1');
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                };

                var title = form.getAttribute('data-confirm-title') || 'Lanjutkan?';
                var text = form.getAttribute('data-confirm-text') || 'Pastikan pilihan Anda sudah benar.';
                var confirmText = form.getAttribute('data-confirm-button') || 'Ya, lanjutkan';

                if (window.Swal) {
                    window.Swal.fire({
                        title: title,
                        text: text,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: confirmText,
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#0f5bd7',
                        cancelButtonColor: '#6b7789',
                        reverseButtons: true
                    }).then(function (result) {
                        if (result.isConfirmed) {
                            proceed();
                        }
                    });
                } else if (window.confirm(title + '\n\n' + text)) {
                    proceed();
                }
            });
        });
    }

    function initPrint() {
        var printButton = document.querySelector('[data-print-result]');
        if (!printButton) {
            return;
        }

        printButton.addEventListener('click', function () {
            window.print();
        });
    }

    function formatElapsed(seconds) {
        var hours = Math.floor(seconds / 3600);
        var minutes = Math.floor((seconds % 3600) / 60);
        var remaining = seconds % 60;
        var pad = function (value) {
            return String(value).padStart(2, '0');
        };

        if (hours > 0) {
            return pad(hours) + ':' + pad(minutes) + ':' + pad(remaining);
        }

        return pad(minutes) + ':' + pad(remaining);
    }

    function initTimer() {
        var timer = document.querySelector('[data-siswa-timer]');
        if (!timer) {
            return;
        }

        var start = parseInt(timer.getAttribute('data-start-ts') || '0', 10);
        if (!Number.isFinite(start) || start <= 0) {
            return;
        }

        var text = timer.querySelector('[data-timer-text]') || timer;

        var tick = function () {
            var elapsed = Math.max(0, Math.floor(Date.now() / 1000) - start);
            text.textContent = formatElapsed(elapsed);
        };

        tick();
        window.setInterval(tick, 1000);
    }

    function initAnswerForm(form) {
        var total = parseInt(form.getAttribute('data-total') || '0', 10);
        if (total < 1) {
            return;
        }

        var token = form.getAttribute('data-attempt-token') || '';
        var draftKey = token !== '' ? DRAFT_PREFIX + token : '';
        var bulk = form.querySelector('[data-bulk-input]');
        var bulkCounter = document.querySelector('[data-bulk-counter]');
        var bulkMessage = document.querySelector('[data-bulk-message]');
        var clearButton = document.querySelector('[data-clear-all]');
        var modalElement = document.getElementById('modalReview');
        var reviewList = document.querySelector('[data-review-list]');
        var reviewCheck = document.querySelector('[data-review-confirm]');
        var confirmButton = document.querySelector('[data-confirm-submit]');
        var reviewWarning = document.querySelector('[data-review-warning]');
        var groups = new Map();
        var syncing = false;
        var dirty = false;
        var submitting = false;
        var messageTimer = 0;
        var modalInstance = null;

        var types = {};
        try {
            var rawTypes = JSON.parse(form.getAttribute('data-types') || '{}');
            if (rawTypes && typeof rawTypes === 'object') {
                types = rawTypes;
            }
        } catch (error) {
            types = {};
        }

        var hasMulti = form.getAttribute('data-has-multi') === '1';

        if (!bulk || !reviewList || !confirmButton) {
            return;
        }

        function typeOf(number) {
            return types[String(number)] || 'pg';
        }

        function isMulti(number) {
            return typeOf(number) === 'multiple_response';
        }

        function isBenarSalah(number) {
            return typeOf(number) === 'benar_salah';
        }

        function groupIsMulti(number) {
            var group = groups.get(number) || [];
            return group.length > 0 && group[0].type === 'checkbox';
        }

        Array.prototype.forEach.call(
            form.querySelectorAll('input[data-answer-input]'),
            function (input) {
                var number = parseInt(input.getAttribute('data-answer-input') || '0', 10);
                if (number < 1 || number > total) {
                    return;
                }
                if (!groups.has(number)) {
                    groups.set(number, []);
                }
                groups.get(number).push(input);
            }
        );

        function getAnswer(number) {
            var group = groups.get(number) || [];

            if (groupIsMulti(number)) {
                var checked = [];
                group.forEach(function (input) {
                    if (input.checked && input.value !== '') {
                        checked.push(input.value);
                    }
                });
                checked.sort();

                return checked.length > 0 ? checked.join(',') : null;
            }

            for (var index = 0; index < group.length; index++) {
                if (group[index].checked) {
                    return group[index].value === '' ? null : group[index].value;
                }
            }

            return null;
        }

        function setAnswer(number, letter) {
            var group = groups.get(number) || [];
            var expected = letter || '';

            if (groupIsMulti(number)) {
                var letters = expected === '' ? [] : expected.split(',');
                group.forEach(function (input) {
                    input.checked = input.value !== '' && letters.indexOf(input.value) !== -1;
                });
                return;
            }

            group.forEach(function (input) {
                input.checked = input.value === expected;
            });
        }

        function normalizeMultiToken(value) {
            var seen = {};
            var letters = [];

            value.toUpperCase().split('').forEach(function (character) {
                if (/[A-E]/.test(character) && !seen[character]) {
                    seen[character] = true;
                    letters.push(character);
                }
            });

            letters.sort();

            return letters.join(',');
        }

        function displayAnswer(number, answer) {
            if (answer === null || answer === '') {
                return '';
            }

            if (isBenarSalah(number)) {
                return answer === 'B' ? 'Benar' : 'Salah';
            }

            if (isMulti(number)) {
                return answer.split(',').join(', ');
            }

            return answer;
        }

        function buildBulk() {
            var lastAnswered = 0;
            for (var number = 1; number <= total; number++) {
                if (getAnswer(number) !== null) {
                    lastAnswered = number;
                }
            }

            if (lastAnswered === 0) {
                return '';
            }

            var text = '';

            if (hasMulti) {
                var tokens = [];
                for (var index = 1; index <= lastAnswered; index++) {
                    var answer = getAnswer(index);
                    if (answer === null) {
                        tokens.push('-');
                    } else if (isMulti(index)) {
                        tokens.push(answer.replace(/,/g, ''));
                    } else {
                        tokens.push(answer);
                    }
                }

                return tokens.join(' ');
            }

            for (var position = 1; position <= lastAnswered; position++) {
                text += getAnswer(position) || '-';
            }

            return text;
        }

        function hasServerAnswer() {
            var inputs = form.querySelectorAll('input[data-answer-input]:checked');
            for (var index = 0; index < inputs.length; index++) {
                if (inputs[index].value !== '') {
                    return true;
                }
            }
            return false;
        }

        function showBulkMessage(message, ok) {
            if (!bulkMessage) {
                return;
            }

            bulkMessage.textContent = message;
            bulkMessage.classList.toggle('is-ok', Boolean(ok));

            if (messageTimer) {
                window.clearTimeout(messageTimer);
            }

            messageTimer = window.setTimeout(function () {
                bulkMessage.textContent = '';
                bulkMessage.classList.remove('is-ok');
            }, 3200);
        }

        function updateProgress() {
            var answered = 0;
            for (var number = 1; number <= total; number++) {
                var row = form.querySelector('[data-answer-row="' + number + '"]');
                var isAnswered = getAnswer(number) !== null;
                if (isAnswered) {
                    answered++;
                }
                if (row) {
                    row.classList.toggle('is-answered', isAnswered);
                }
            }

            var empty = total - answered;
            var percent = total > 0 ? Math.round((answered / total) * 100) : 0;

            Array.prototype.forEach.call(document.querySelectorAll('[data-answered-count]'), function (element) {
                element.textContent = String(answered);
            });
            Array.prototype.forEach.call(document.querySelectorAll('[data-empty-count]'), function (element) {
                element.textContent = String(empty);
            });
            Array.prototype.forEach.call(document.querySelectorAll('[data-progress-percent]'), function (element) {
                element.textContent = String(percent);
            });
            Array.prototype.forEach.call(document.querySelectorAll('[data-footer-answered]'), function (element) {
                element.textContent = String(answered);
            });

            if (bulkCounter) {
                bulkCounter.textContent = answered + ' / ' + total + ' terisi';
            }

            var bar = document.querySelector('[data-progress-bar]');
            var track = document.querySelector('[data-progress-track]');
            if (bar) {
                bar.style.width = percent + '%';
            }
            if (track) {
                track.setAttribute('aria-valuenow', String(percent));
            }

            return { answered: answered, empty: empty };
        }

        function saveDraft() {
            if (draftKey === '') {
                return;
            }

            var data = {};
            for (var number = 1; number <= total; number++) {
                data[number] = getAnswer(number) || '';
            }

            safeStorageSet(draftKey, JSON.stringify(data));
        }

        function restoreDraft() {
            if (draftKey === '' || hasServerAnswer()) {
                return;
            }

            var raw = safeStorageGet(draftKey);
            if (!raw) {
                return;
            }

            var data;
            try {
                data = JSON.parse(raw);
            } catch (error) {
                return;
            }

            if (!data || typeof data !== 'object') {
                return;
            }

            var restored = false;
            for (var number = 1; number <= total; number++) {
                var value = data[number];
                var valid = false;

                if (typeof value === 'string' && value !== '') {
                    if (isMulti(number)) {
                        valid = /^[A-E](,[A-E])*$/.test(value);
                    } else if (isBenarSalah(number)) {
                        valid = /^[BS]$/.test(value);
                    } else {
                        valid = /^[A-E]$/.test(value);
                    }
                }

                if (valid) {
                    setAnswer(number, value);
                    restored = true;
                } else {
                    setAnswer(number, null);
                }
            }

            if (restored) {
                dirty = true;
            }
        }

        function parseBulk(raw) {
            var tokens = [];
            var invalid = '';
            var overflow = false;

            if (hasMulti) {
                var pieces = raw.split(/[\s,;]+/).filter(function (piece) {
                    return piece !== '';
                });

                pieces.forEach(function (piece) {
                    if (tokens.length >= total) {
                        overflow = true;
                        return;
                    }

                    if (piece === '-' || piece === '_' || piece === '.') {
                        tokens.push(null);
                        return;
                    }

                    var token = piece;
                    var wrapped = token.match(/^\((.*)\)$/);
                    if (wrapped) {
                        token = wrapped[1];
                    }

                    var number = tokens.length + 1;

                    if (isMulti(number)) {
                        if (/^[A-E]+$/i.test(token)) {
                            tokens.push(normalizeMultiToken(token));
                        } else {
                            invalid += token.charAt(0);
                        }
                        return;
                    }

                    if (isBenarSalah(number)) {
                        var upper = token.toUpperCase();
                        if (upper === 'B' || upper === 'BENAR') {
                            tokens.push('B');
                        } else if (upper === 'S' || upper === 'SALAH') {
                            tokens.push('S');
                        } else {
                            invalid += token.charAt(0);
                        }
                        return;
                    }

                    if (/^[A-E]$/i.test(token)) {
                        tokens.push(token.toUpperCase());
                    } else {
                        invalid += token.charAt(0);
                    }
                });

                return { tokens: tokens, invalid: invalid, overflow: overflow };
            }

            for (var index = 0; index < raw.length; index++) {
                var character = raw.charAt(index);
                if (/[\s,;]/.test(character)) {
                    continue;
                }

                if (tokens.length >= total) {
                    overflow = true;
                    break;
                }

                if (character === '-' || character === '_' || character === '.') {
                    tokens.push(null);
                    continue;
                }

                var position = tokens.length + 1;
                var type = typeOf(position);

                if (type === 'benar_salah') {
                    if (/^[BS]$/i.test(character)) {
                        tokens.push(character.toUpperCase());
                    } else {
                        invalid += character;
                    }
                } else if (/^[A-E]$/i.test(character)) {
                    tokens.push(character.toUpperCase());
                } else {
                    invalid += character;
                }
            }

            return { tokens: tokens, invalid: invalid, overflow: overflow };
        }

        function caretTokenPosition(raw, caret) {
            var tokens = 0;
            var limit = Math.min(caret, raw.length);
            for (var index = 0; index < limit; index++) {
                if (/[\s,;]/.test(raw.charAt(index))) {
                    continue;
                }
                tokens++;
            }
            return tokens;
        }

        function applyBulk() {
            var raw = bulk.value;
            var caret = bulk.selectionStart || raw.length;
            var parsed = parseBulk(raw);
            var compact = '';
            var index;

            if (hasMulti) {
                compact = parsed.tokens.map(function (value) {
                    return value === null ? '-' : value.replace(/,/g, '');
                }).join(' ');
            } else {
                for (index = 0; index < parsed.tokens.length; index++) {
                    compact += parsed.tokens[index] || '-';
                }
            }

            if (parsed.invalid !== '') {
                var allowed = hasMulti
                    ? 'A–E, B/S, atau -'
                    : 'A–E, B/S, atau -';
                showBulkMessage('Karakter "' + parsed.invalid.charAt(0) + '" diabaikan. Gunakan ' + allowed + '.', false);
            } else if (parsed.overflow) {
                showBulkMessage('Jumlah jawaban dibatasi ' + total + ' soal.', false);
            }

            syncing = true;
            if (bulk.value !== compact) {
                if (hasMulti) {
                    bulk.value = compact;
                    try {
                        bulk.setSelectionRange(compact.length, compact.length);
                    } catch (error) {
                        /* abaikan */
                    }
                } else {
                    var tokenPosition = caretTokenPosition(raw, caret);
                    bulk.value = compact;
                    var newCaret = Math.min(tokenPosition, compact.length);
                    try {
                        bulk.setSelectionRange(newCaret, newCaret);
                    } catch (error) {
                        /* abaikan */
                    }
                }
            }

            for (index = 1; index <= total; index++) {
                setAnswer(index, index <= parsed.tokens.length ? parsed.tokens[index - 1] : null);
            }
            syncing = false;

            dirty = true;
            updateProgress();
            saveDraft();
        }

        function restoreBulkFromRadios() {
            syncing = true;
            bulk.value = buildBulk();
            syncing = false;
        }

        function refreshReview() {
            var answered = 0;
            var empty = 0;

            reviewList.innerHTML = '';
            var fragment = document.createDocumentFragment();

            for (var number = 1; number <= total; number++) {
                var answer = getAnswer(number);
                if (answer === null) {
                    empty++;
                } else {
                    answered++;
                }

                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'review-item' + (answer === null ? ' is-empty' : '');
                item.setAttribute('data-review-jump', String(number));

                var numberElement = document.createElement('span');
                numberElement.className = 'review-item-number';
                numberElement.textContent = String(number);

                var answerElement = document.createElement('span');
                answerElement.className = 'review-item-answer';
                answerElement.textContent = displayAnswer(number, answer);

                item.appendChild(numberElement);
                item.appendChild(answerElement);
                fragment.appendChild(item);
            }

            reviewList.appendChild(fragment);
            document.querySelectorAll('[data-review-answered]').forEach(function (element) {
                element.textContent = String(answered);
            });
            document.querySelectorAll('[data-review-empty]').forEach(function (element) {
                element.textContent = String(empty);
            });

            if (reviewWarning) {
                reviewWarning.hidden = empty === 0;
            }

            if (reviewCheck) {
                reviewCheck.checked = false;
            }
            confirmButton.disabled = true;
        }

        function openReview() {
            refreshReview();

            if (!modalElement || !window.bootstrap || !window.bootstrap.Modal) {
                var count = updateProgress();
                var message = 'Anda akan mengirim jawaban.\n\n'
                    + count.answered + ' terjawab, ' + count.empty + ' kosong dari ' + total + ' soal.\n'
                    + 'Jawaban tidak dapat diubah setelah dikirim.';
                if (window.confirm(message)) {
                    form.setAttribute('data-confirmed', '1');
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }
                return;
            }

            modalInstance = window.bootstrap.Modal.getOrCreateInstance(modalElement);
            modalInstance.show();
        }

        function jumpToNumber(number) {
            var row = document.getElementById('soal-' + number);
            if (!row) {
                return;
            }

            if (modalInstance) {
                modalInstance.hide();
            }

            row.classList.add('is-just-jumped');
            window.setTimeout(function () {
                row.classList.remove('is-just-jumped');
            }, 1000);

            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            var firstInput = row.querySelector('input[data-answer-input]');
            if (firstInput) {
                window.setTimeout(function () {
                    firstInput.focus({ preventScroll: true });
                }, 260);
            }
        }

        bulk.addEventListener('input', applyBulk);

        bulk.addEventListener('paste', function () {
            window.setTimeout(applyBulk, 0);
        });

        form.addEventListener('change', function (event) {
            var input = event.target;
            if (!input || !input.matches || !input.matches('input[data-answer-input]')) {
                return;
            }
            if (syncing) {
                return;
            }

            restoreBulkFromRadios();
            dirty = true;
            updateProgress();
            saveDraft();
        });

        form.addEventListener('click', function (event) {
            var clearMulti = event.target.closest('[data-clear-multi]');
            if (!clearMulti) {
                return;
            }

            var number = parseInt(clearMulti.getAttribute('data-clear-multi') || '0', 10);
            if (number < 1 || number > total) {
                return;
            }

            setAnswer(number, null);
            restoreBulkFromRadios();
            dirty = true;
            updateProgress();
            saveDraft();
        });

        if (clearButton) {
            clearButton.addEventListener('click', function () {
                var count = updateProgress();
                if (count.answered === 0) {
                    bulk.value = '';
                    for (var number = 1; number <= total; number++) {
                        setAnswer(number, null);
                    }
                    updateProgress();
                    saveDraft();
                    return;
                }

                var clearAll = function () {
                    for (var index = 1; index <= total; index++) {
                        setAnswer(index, null);
                    }
                    bulk.value = '';
                    dirty = true;
                    updateProgress();
                    saveDraft();
                    showBulkMessage('Semua jawaban dikosongkan.', true);
                };

                if (window.Swal) {
                    window.Swal.fire({
                        title: 'Kosongkan semua jawaban?',
                        text: 'Seluruh pilihan pada lembar jawaban akan dihapus.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Ya, kosongkan',
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#c9374a',
                        cancelButtonColor: '#6b7789',
                        reverseButtons: true
                    }).then(function (result) {
                        if (result.isConfirmed) {
                            clearAll();
                        }
                    });
                } else if (window.confirm('Kosongkan semua jawaban?')) {
                    clearAll();
                }
            });
        }

        if (reviewCheck) {
            reviewCheck.addEventListener('change', function () {
                confirmButton.disabled = !reviewCheck.checked;
            });
        }

        if (reviewList) {
            reviewList.addEventListener('click', function (event) {
                var target = event.target.closest('[data-review-jump]');
                if (!target) {
                    return;
                }
                var number = parseInt(target.getAttribute('data-review-jump') || '0', 10);
                if (number > 0) {
                    jumpToNumber(number);
                }
            });
        }

        confirmButton.addEventListener('click', function () {
            if (reviewCheck && !reviewCheck.checked) {
                return;
            }

            form.setAttribute('data-confirmed', '1');
            if (modalInstance) {
                modalInstance.hide();
            }
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });

        form.addEventListener('submit', function (event) {
            if (form.getAttribute('data-confirmed') !== '1') {
                event.preventDefault();
                openReview();
                return;
            }

            if (submitting) {
                event.preventDefault();
                return;
            }

            submitting = true;
            dirty = false;

            Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"]'), function (button) {
                button.disabled = true;
                button.classList.add('is-loading');
            });
            confirmButton.disabled = true;
            confirmButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Mengirim...';
        });

        window.addEventListener('beforeunload', function (event) {
            if (!dirty || submitting) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        });

        restoreDraft();
        if (!bulk.value.trim()) {
            restoreBulkFromRadios();
        }
        updateProgress();
    }

    var clearDraftFlag = document.querySelector('[data-clear-siswa-draft]');
    if (clearDraftFlag) {
        clearDraftKeys();
    }

    initConfirmForms();
    initPrint();
    initTimer();

    var answerForm = document.querySelector('[data-answer-form]');
    if (answerForm) {
        initAnswerForm(answerForm);
    }
})();
