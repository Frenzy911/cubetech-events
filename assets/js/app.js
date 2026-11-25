/* CubeTech Events - small helpers used on every page.
 * The server always validates too; this just gives faster feedback. */
(function () {
  'use strict';

  var MESSAGES = {
    name: function (v) {
      if (!v) return 'Please enter your full name.';
      if (v.length < 2) return 'Please enter your full name (at least 2 characters).';
      if (v.length > 120) return 'Name must be 120 characters or less.';
      return '';
    },
    email: function (v) {
      if (!v) return 'Please enter your email address.';
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) return 'Please enter a valid email address, for example juan@example.com.';
      return '';
    },
    mobile: function (v) {
      var m = v.replace(/[\s\-().]/g, '').replace(/^\+?63(9\d{9})$/, '0$1');
      if (!m) return 'Please enter your mobile number.';
      if (!/^09\d{9}$/.test(m)) return 'Mobile number must be 11 digits and start with 09, for example 09171234567.';
      return '';
    },
    required: function (v) {
      return v ? '' : 'This field is required.';
    }
  };

  function showError(input, message) {
    var field = input.closest('.field');
    if (!field) return;
    var box = field.querySelector('.field-error');
    var text = box && box.querySelector('span');
    field.classList.toggle('has-error', !!message);
    if (message) {
      input.setAttribute('aria-invalid', 'true');
    } else {
      input.removeAttribute('aria-invalid');
    }
    if (box && text) {
      text.textContent = message;
      box.hidden = !message;
    }
  }

  function validateInput(input) {
    var rule = MESSAGES[input.getAttribute('data-rule')];
    if (!rule) return '';
    var message = rule(input.value.trim());
    showError(input, message);
    return message;
  }

  document.querySelectorAll('form[data-validate]').forEach(function (form) {
    var inputs = form.querySelectorAll('[data-rule]');
    var submitButton = form.querySelector('button[type=submit]');
    // When the user presses the submit button, skip blur validation: an error message appearing
    // on blur would push the button down so the click misses it. The submit handler validates all.
    var pressingSubmit = false;
    if (submitButton) {
      ['pointerdown', 'mousedown', 'touchstart'].forEach(function (type) {
        submitButton.addEventListener(type, function () {
          pressingSubmit = true;
          setTimeout(function () { pressingSubmit = false; }, 800);
        }, { passive: true });
      });
    }
    inputs.forEach(function (input) {
      input.addEventListener('blur', function () {
        if (pressingSubmit) return;
        if (input.value.trim() !== '' || input.closest('.has-error')) validateInput(input);
      });
      input.addEventListener('input', function () {
        if (input.closest('.has-error')) validateInput(input);
      });
    });
    form.addEventListener('submit', function (event) {
      var firstBad = null;
      inputs.forEach(function (input) {
        if (validateInput(input) && !firstBad) firstBad = input;
      });
      if (firstBad) {
        event.preventDefault();
        firstBad.focus();
        return;
      }
      var button = form.querySelector('button[type=submit]');
      if (button) {
        // prevent double submits
        setTimeout(function () { button.disabled = true; }, 0);
      }
    });
  });

  // Confirmation before destructive actions: <form data-confirm="...">
  document.addEventListener('submit', function (event) {
    var form = event.target;
    var message = form.getAttribute && form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }, true);

  // Mobile menu: on small screens the nav collapses behind a Menu button.
  // Without JavaScript the nav stays visible and scrolls sideways instead.
  var header = document.querySelector('.site-header');
  var navToggle = header && header.querySelector('.nav-toggle');
  var nav = document.getElementById('main-nav');
  if (navToggle && nav) {
    var setNavOpen = function (open) {
      header.classList.toggle('nav-open', open);
      navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    header.classList.add('has-nav-toggle');
    navToggle.hidden = false;
    navToggle.addEventListener('click', function () {
      setNavOpen(navToggle.getAttribute('aria-expanded') !== 'true');
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && header.classList.contains('nav-open')) {
        setNavOpen(false);
        navToggle.focus();
      }
    });
    document.addEventListener('click', function (event) {
      if (header.classList.contains('nav-open') && !header.contains(event.target)) setNavOpen(false);
    });
  }

  // Show / hide password: an eye button inside every password box.
  var EYE = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
  var EYE_OFF = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>'
    + '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>'
    + '<path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
  function eyeIcon(paths) {
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
      + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + paths + '</svg>';
  }
  document.querySelectorAll('input[type=password]').forEach(function (input, i) {
    if (!input.id) input.id = 'password-' + i;
    var wrap = document.createElement('span');
    wrap.className = 'password-field';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'password-toggle';
    toggle.setAttribute('aria-controls', input.id);
    var setShown = function (shown) {
      input.type = shown ? 'text' : 'password';
      toggle.setAttribute('aria-pressed', shown ? 'true' : 'false');
      toggle.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');
      toggle.title = shown ? 'Hide password' : 'Show password';
      toggle.innerHTML = eyeIcon(shown ? EYE_OFF : EYE);
    };
    setShown(false);
    // Keep the cursor in the box (and the phone keyboard as it is) when the eye is tapped.
    toggle.addEventListener('mousedown', function (event) { event.preventDefault(); });
    toggle.addEventListener('click', function () {
      var focused = document.activeElement === input;
      var start = input.selectionStart, end = input.selectionEnd;
      setShown(input.type === 'password');
      if (focused) {
        try { input.setSelectionRange(start, end); } catch (e) { /* not supported */ }
      }
    });
    wrap.appendChild(toggle);
    // Always send the form with the password hidden again.
    if (input.form) input.form.addEventListener('submit', function () { setShown(false); });
  });

  // Print buttons
  document.querySelectorAll('[data-print]').forEach(function (button) {
    button.addEventListener('click', function () { window.print(); });
  });

  // Ticket code inputs: uppercase as you type
  document.querySelectorAll('.input-code').forEach(function (input) {
    input.addEventListener('input', function () {
      var pos = input.selectionStart;
      input.value = input.value.toUpperCase();
      try { input.setSelectionRange(pos, pos); } catch (e) { /* not supported on some input types */ }
    });
  });
})();
