/* CubeTech Events - door check-in screen.
 * Camera scanning uses the built-in BarcodeDetector or jsQR (loaded on demand). Manual entry and USB/Bluetooth
 * scanners (which type the code + Enter) always work, even offline or without a camera. */
(function () {
  'use strict';

  var root = document.querySelector('[data-checkin]');
  if (!root) return;

  var cfg = JSON.parse(document.getElementById('checkin-config').textContent);
  var resultBox = document.getElementById('result');
  var el = {
    icon: resultBox.querySelector('[data-result-icon]'),
    title: resultBox.querySelector('[data-result-title]'),
    name: resultBox.querySelector('[data-result-name]'),
    detail: resultBox.querySelector('[data-result-detail]'),
    code: resultBox.querySelector('[data-result-code]'),
    form: document.getElementById('manual-form'),
    input: document.getElementById('manual-code'),
    camButton: document.getElementById('camera-toggle'),
    camStatus: document.getElementById('camera-status'),
    reader: document.getElementById('reader'),
    log: document.getElementById('scan-log'),
    statIn: root.querySelector('[data-stat="checked_in"]'),
    statReg: root.querySelector('[data-stat="registered"]'),
    progress: root.querySelector('[data-progress]')
  };

  var ICONS = {
    idle: '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="7" y1="12" x2="17" y2="12"/>',
    pending: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    green: '<polyline points="20 6 9 17 4 12"/>',
    yellow: '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    red: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
    blue: '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>'
  };

  var busy = false;
  var resetTimer = null;
  var cameraOn = false;
  var cameraPaused = false;
  var lastScan = { code: '', at: 0 };

  function svg(name) {
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || '') + '</svg>';
  }

  function setResult(tone, title, name, detail, code) {
    resultBox.className = 'result result-' + tone + ' result-flash';
    el.icon.innerHTML = svg(tone);
    el.title.textContent = title;
    el.name.textContent = name || '';
    el.detail.textContent = detail || '';
    el.code.textContent = code || '';
  }

  function setIdle() {
    setResult('idle', 'Ready to scan', '', cameraOn ? 'Hold the next QR code in front of the camera, or type a code.' : 'Scan a QR code or type a ticket code.', '');
  }

  function updateStats(stats) {
    if (!stats) return;
    el.statIn.textContent = stats.checked_in;
    el.statReg.textContent = stats.registered;
    var pct = stats.registered > 0 ? Math.round(stats.checked_in / stats.registered * 100) : 0;
    el.progress.style.width = Math.min(100, pct) + '%';
  }

  function post(data) {
    var body = new FormData();
    Object.keys(data).forEach(function (key) { body.append(key, data[key]); });
    body.append('event_id', cfg.eventId);
    body.append('csrf', cfg.csrf);
    return fetch(cfg.api, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (res) {
      if (res.status === 401) {
        window.location.href = cfg.login + '?next=' + encodeURIComponent(window.location.pathname + window.location.search);
        throw new Error('signed out');
      }
      return res.json().catch(function () {
        throw new Error('Unexpected response from the server (' + res.status + ').');
      });
    });
  }

  function feedback(tone) {
    if (navigator.vibrate) navigator.vibrate(tone === 'green' ? 80 : [60, 60, 60]);
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      feedback.ctx = feedback.ctx || new Ctx();
      var ctx = feedback.ctx;
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.frequency.value = tone === 'green' ? 880 : tone === 'yellow' ? 520 : 220;
      gain.gain.value = 0.08;
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + (tone === 'green' ? 0.12 : 0.3));
    } catch (e) { /* sound is optional */ }
  }

  function addLog(data) {
    var empty = el.log.querySelector('.scan-empty');
    if (empty) empty.remove();

    var li = document.createElement('li');
    var dot = document.createElement('span');
    dot.className = 'scan-dot scan-dot-' + data.tone;
    dot.setAttribute('aria-hidden', 'true');

    var main = document.createElement('div');
    main.className = 'scan-main';
    var title = document.createElement('div');
    title.className = 'scan-title';
    title.textContent = data.title + (data.name ? ' — ' + data.name : '');
    var sub = document.createElement('div');
    sub.className = 'scan-sub';
    sub.textContent = (data.at || '') + (data.code ? ' · ' + data.code : '');
    main.appendChild(title);
    main.appendChild(sub);

    li.appendChild(dot);
    li.appendChild(main);

    if (data.can_undo && data.code) {
      var undo = document.createElement('button');
      undo.type = 'button';
      undo.className = 'btn btn-sm btn-secondary';
      undo.textContent = 'Undo';
      undo.setAttribute('aria-label', 'Undo check-in for ' + (data.name || data.code));
      undo.addEventListener('click', function () {
        if (!window.confirm('Undo the check-in for ' + (data.name || data.code) + '? Their ticket can then be checked in again.')) return;
        undo.disabled = true;
        runAction({ action: 'undo', code: data.code }).then(function (res) {
          if (res && res.result === 'undone') {
            undo.textContent = 'Undone';
          } else {
            undo.disabled = false;
          }
        });
      });
      li.appendChild(undo);
    }

    el.log.insertBefore(li, el.log.firstChild);
    while (el.log.children.length > 15) el.log.removeChild(el.log.lastChild);
  }

  function runAction(payload) {
    busy = true;
    clearTimeout(resetTimer);
    setResult('pending', 'Checking…', '', payload.code || '', '');
    return post(payload).then(function (data) {
      if (!data || data.ok === false) {
        setResult('red', 'Error', '', (data && data.error) || 'Something went wrong. Try again.', payload.code);
        feedback('red');
        return data;
      }
      setResult(data.tone, data.title, data.name, data.detail, data.code);
      feedback(data.tone);
      updateStats(data.stats);
      addLog(data);
      return data;
    }).catch(function (err) {
      if (err && err.message === 'signed out') return null;
      setResult('red', 'Connection problem', '', 'Could not reach the server. Check the Wi-Fi and try again.', payload.code);
      feedback('red');
      return null;
    }).then(function (data) {
      busy = false;
      // Show the result for about 2 seconds, then get ready for the next scan automatically.
      resetTimer = setTimeout(setIdle, cfg.resetMs);
      return data;
    });
  }

  function checkIn(code) {
    code = (code || '').trim();
    if (!code || busy) return Promise.resolve(null);
    return runAction({ action: 'checkin', code: code });
  }

  /* ---------- Manual entry / USB scanner ---------- */
  el.form.addEventListener('submit', function (event) {
    event.preventDefault();
    var code = el.input.value;
    el.input.value = '';
    checkIn(code).then(function () {
      if (!cameraOn) el.input.focus();
    });
  });

  /* ---------- Camera scanning ----------
   * Uses the browser's built-in BarcodeDetector when available (Chrome on Android, etc.),
   * otherwise jsQR (loaded on demand). Each frame is decoded about 8 times per second. */
  var stream = null;
  var video = null;
  var detector = null;
  var frameCanvas = document.createElement('canvas');
  var frameCtx = frameCanvas.getContext('2d', { willReadFrequently: true });
  var decoding = false;
  var lastFrameAt = 0;
  var rafId = null;

  function setCamStatus(text) { el.camStatus.textContent = text; }

  function setCamButton(on) {
    el.camButton.querySelector('span').textContent = on ? 'Stop camera' : 'Start camera';
    el.camButton.classList.toggle('btn-primary', !on);
    el.camButton.classList.toggle('btn-secondary', on);
  }

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = resolve;
      s.onerror = function () { s.remove(); reject(new Error('load failed')); };
      document.head.appendChild(s);
    });
  }

  function loadJsQR() {
    if (window.jsQR) return Promise.resolve();
    var sources = cfg.scanLibs.slice();
    function next() {
      if (!sources.length) return Promise.reject(new Error('Scanner library unavailable'));
      return loadScript(sources.shift()).then(function () {
        if (!window.jsQR) return next();
      }, next);
    }
    return next();
  }

  function prepareDecoder() {
    if (!('BarcodeDetector' in window)) return loadJsQR();
    var check = window.BarcodeDetector.getSupportedFormats
      ? window.BarcodeDetector.getSupportedFormats()
      : Promise.resolve(['qr_code']);
    return check.then(function (formats) {
      if (formats.indexOf('qr_code') === -1) return loadJsQR();
      detector = new window.BarcodeDetector({ formats: ['qr_code'] });
    }).catch(loadJsQR);
  }

  function decodeFrame() {
    if (detector) {
      return detector.detect(video).then(function (codes) {
        return codes.length ? codes[0].rawValue : null;
      });
    }
    var w = video.videoWidth;
    var h = video.videoHeight;
    if (!w || !h) return Promise.resolve(null);
    var scale = Math.min(1, 720 / Math.max(w, h)); // smaller frames decode faster
    frameCanvas.width = Math.round(w * scale);
    frameCanvas.height = Math.round(h * scale);
    frameCtx.drawImage(video, 0, 0, frameCanvas.width, frameCanvas.height);
    var img = frameCtx.getImageData(0, 0, frameCanvas.width, frameCanvas.height);
    var found = window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
    return Promise.resolve(found ? found.data : null);
  }

  function scanLoop(now) {
    if (!cameraOn) return;
    rafId = requestAnimationFrame(scanLoop);
    if (decoding || busy || cameraPaused || !video || video.readyState < 2 || now - lastFrameAt < 120) return;
    lastFrameAt = now;
    decoding = true;
    decodeFrame().then(function (text) {
      decoding = false;
      if (text) onDecoded(text);
    }, function () {
      decoding = false;
    });
  }

  function onDecoded(text) {
    if (busy || cameraPaused) return;
    var now = Date.now();
    if (text === lastScan.code && now - lastScan.at < 4000) return; // same code still in view
    lastScan = { code: text, at: now };
    cameraPaused = true; // stop decoding while the result is shown
    el.reader.classList.add('is-paused');
    checkIn(text).then(function () {
      setTimeout(function () {
        cameraPaused = false;
        el.reader.classList.remove('is-paused');
      }, cfg.resetMs);
    });
  }

  function startCamera() {
    if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setCamStatus('The camera needs HTTPS (or http://localhost). Use the ticket code box or a USB scanner instead.');
      return;
    }
    el.camButton.disabled = true;
    setCamStatus('Starting camera…');
    prepareDecoder().then(function () {
      return navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
        audio: false
      });
    }).then(function (s) {
      stream = s;
      video = document.createElement('video');
      video.setAttribute('playsinline', '');
      video.muted = true;
      video.srcObject = stream;
      el.reader.innerHTML = '<div class="scan-frame" aria-hidden="true"></div>';
      el.reader.insertBefore(video, el.reader.firstChild);
      el.reader.hidden = false;
      return video.play();
    }).then(function () {
      cameraOn = true;
      setCamButton(true);
      setCamStatus('Camera on' + (detector ? '' : ' (jsQR)') + '. Hold the QR code inside the frame. Each result shows for about 2 seconds, then the next scan starts automatically.');
      setIdle();
      rafId = requestAnimationFrame(scanLoop);
    }).catch(function (err) {
      releaseCamera();
      var name = (err && err.name) || '';
      var msg = (err && err.message) || String(err || '');
      if (/Scanner library/.test(msg)) {
        setCamStatus('Could not load the camera scanner (no internet?). Use the ticket code box or a USB scanner.');
      } else if (name === 'NotAllowedError' || /Permission|denied/i.test(msg)) {
        setCamStatus('Camera access was blocked. Allow the camera in your browser settings, or use the ticket code box.');
      } else if (name === 'NotFoundError' || name === 'OverconstrainedError') {
        setCamStatus('No camera was found on this device. Use the ticket code box or a USB scanner.');
      } else {
        setCamStatus('Camera not available (' + (name || msg) + '). Use the ticket code box or a USB scanner.');
      }
    }).then(function () {
      el.camButton.disabled = false;
    });
  }

  function releaseCamera() {
    cameraOn = false;
    cameraPaused = false;
    if (rafId) cancelAnimationFrame(rafId);
    rafId = null;
    if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
    stream = null;
    video = null;
    el.reader.hidden = true;
    el.reader.innerHTML = '';
    el.reader.classList.remove('is-paused');
  }

  function stopCamera() {
    releaseCamera();
    setCamButton(false);
    setCamStatus('Camera off.');
    setIdle();
    el.input.focus();
  }

  el.camButton.addEventListener('click', function () {
    if (cameraOn) stopCamera(); else startCamera();
  });
  window.addEventListener('pagehide', releaseCamera);

  /* ---------- Live counter (other devices check people in too) ---------- */
  setInterval(function () {
    if (busy || document.hidden) return;
    post({ action: 'stats' }).then(function (data) {
      if (data && data.ok) updateStats(data.stats);
    }).catch(function () { /* try again next time */ });
  }, cfg.pollMs);

  setIdle();
})();
