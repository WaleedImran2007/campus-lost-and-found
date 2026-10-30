/* Small progressive-enhancement helpers. The site still works without JS
   because every rule is also checked on the server. */
(function () {
    'use strict';

    // --- dark mode toggle (remembered in localStorage)
    var themeBtn = document.querySelector('[data-theme-toggle]');
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var cur = document.documentElement.getAttribute('data-theme');
            var next = cur === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('theme', next); } catch (e) {}
        });
    }

    // --- mobile menu
    var navBtn = document.querySelector('[data-nav-toggle]');
    if (navBtn) {
        navBtn.addEventListener('click', function () {
            document.getElementById('main-nav').classList.toggle('open');
        });
    }

    // --- flash messages fade out after a few seconds (errors stay)
    document.querySelectorAll('.flash-success, .flash-info').forEach(function (el) {
        setTimeout(function () { el.classList.add('fade'); }, 5000);
    });

    // --- confirm before dangerous actions:  <form data-confirm="Are you sure?">
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('submit', function (ev) {
            if (!window.confirm(el.getAttribute('data-confirm'))) ev.preventDefault();
        });
    });

    // --- disable submit buttons after first click (double-click protection, UI side)
    document.querySelectorAll('form[data-once]').forEach(function (form) {
        form.addEventListener('submit', function () {
            form.querySelectorAll('button[type=submit]').forEach(function (b) {
                setTimeout(function () { b.disabled = true; b.textContent = 'Please wait...'; }, 0);
            });
        });
    });

    // --- live character counters:  <textarea data-maxlen="500">
    document.querySelectorAll('[data-maxlen]').forEach(function (field) {
        var max = parseInt(field.getAttribute('data-maxlen'), 10);
        var out = document.createElement('div');
        out.className = 'counter';
        field.insertAdjacentElement('afterend', out);
        function update() {
            out.textContent = field.value.length + ' / ' + max;
            out.classList.toggle('over', field.value.length > max);
        }
        field.addEventListener('input', update);
        update();
    });

    // --- registration: check the university email domain while typing
    var emailField = document.querySelector('[data-domain]');
    if (emailField) {
        var domain = emailField.getAttribute('data-domain');
        var hint = document.createElement('div');
        hint.className = 'hint';
        emailField.insertAdjacentElement('afterend', hint);
        emailField.addEventListener('input', function () {
            var v = emailField.value.trim().toLowerCase();
            if (!v) { hint.textContent = ''; return; }
            var ok = v.endsWith('@' + domain);
            hint.textContent = ok ? 'Looks good' : 'Use your @' + domain + ' email';
            hint.className = 'hint ' + (ok ? 'ok' : 'bad');
        });
    }

    // --- password strength meter:  <input data-strength>
    var pw = document.querySelector('[data-strength]');
    if (pw) {
        var bar = document.createElement('div');
        bar.className = 'strength';
        bar.innerHTML = '<span></span>';
        pw.insertAdjacentElement('afterend', bar);
        pw.addEventListener('input', function () {
            var v = pw.value, score = 0;
            if (v.length >= 8) score++;
            if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
            if (/\d/.test(v)) score++;
            if (/[^A-Za-z0-9]/.test(v)) score++;
            bar.firstChild.style.width = (score * 25) + '%';
            bar.firstChild.className = 's' + score;
        });
    }

    // --- listing form: show/hide fields depending on lost / found
    var typeInputs = document.querySelectorAll('input[name=report_type]');
    if (typeInputs.length) {
        var foundOnly = document.querySelectorAll('[data-found-only]');
        function syncType() {
            var val = document.querySelector('input[name=report_type]:checked');
            var isFound = val && val.value === 'found';
            foundOnly.forEach(function (el) { el.style.display = isFound ? '' : 'none'; });
        }
        typeInputs.forEach(function (r) { r.addEventListener('change', syncType); });
        syncType();
    }

    // --- image picker: preview + enforce max count/size in the browser
    document.querySelectorAll('input[type=file][data-preview]').forEach(function (input) {
        var max = parseInt(input.getAttribute('data-max-files') || '4', 10);
        var box = document.createElement('div');
        box.className = 'previews';
        input.insertAdjacentElement('afterend', box);
        input.addEventListener('change', function () {
            box.innerHTML = '';
            var files = Array.prototype.slice.call(input.files);
            if (files.length > max) {
                alert('You can attach at most ' + max + ' images here.');
                input.value = '';
                return;
            }
            files.forEach(function (f) {
                if (!f.type.startsWith('image/')) return;
                var img = document.createElement('img');
                img.src = URL.createObjectURL(f);
                img.alt = f.name;
                box.appendChild(img);
            });
        });
    });

    // --- search page: auto-submit when a dropdown changes
    document.querySelectorAll('form[data-autosubmit] select').forEach(function (s) {
        s.addEventListener('change', function () { s.form.submit(); });
    });
})();
