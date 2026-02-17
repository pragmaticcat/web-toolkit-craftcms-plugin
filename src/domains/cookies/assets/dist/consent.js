(function() {
  'use strict';

  var config = window.PragmaticCookiesConfig || {};
  var COOKIE_NAME = config.cookieName || 'pragmatic_cookies_consent';
  var CONSENT_EXPIRY = config.consentExpiry || 365;
  var LOG_CONSENT = config.logConsent !== false;
  var SAVE_URL = config.saveUrl || '/pragmatic-cookies/consent/save';
  var CATEGORIES = config.categories || [];

  var callbacks = {};

  // ── Cookie helpers ──

  function getConsent() {
    var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + COOKIE_NAME + '=([^;]*)'));
    if (!match) return null;
    try {
      return JSON.parse(decodeURIComponent(match[1]));
    } catch (e) {
      return null;
    }
  }

  function setConsent(consent) {
    var value = encodeURIComponent(JSON.stringify(consent));
    var expires = new Date();
    expires.setDate(expires.getDate() + CONSENT_EXPIRY);
    document.cookie = COOKIE_NAME + '=' + value + ';path=/;expires=' + expires.toUTCString() + ';SameSite=Lax';

    // Activate scripts for consented categories
    activateScripts(consent);

    // Fire callbacks
    Object.keys(consent).forEach(function(category) {
      if (consent[category] && callbacks[category]) {
        callbacks[category].forEach(function(cb) {
          try { cb(); } catch(e) { console.error('PragmaticCookies callback error:', e); }
        });
        callbacks[category] = [];
      }
    });

    // Log consent
    if (LOG_CONSENT) {
      logConsentToServer(consent);
    }
  }

  function logConsentToServer(consent) {
    var visitorId = getOrCreateVisitorId();
    var data = new FormData();
    data.append('consent', JSON.stringify(consent));
    data.append('visitorId', visitorId);

    // Get CSRF token
    var csrfToken = getCsrfToken();
    if (csrfToken) {
      data.append(csrfToken.name, csrfToken.value);
    }

    fetch(SAVE_URL, {
      method: 'POST',
      body: data,
      credentials: 'same-origin'
    }).catch(function() {});
  }

  function getCsrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) {
      var name = document.querySelector('meta[name="csrf-param"]');
      return {
        name: name ? name.getAttribute('content') : 'CRAFT_CSRF_TOKEN',
        value: meta.getAttribute('content')
      };
    }

    // Try window.Craft
    if (window.Craft && window.Craft.csrfTokenName) {
      return {
        name: window.Craft.csrfTokenName,
        value: window.Craft.csrfTokenValue
      };
    }

    return null;
  }

  function getOrCreateVisitorId() {
    var key = 'pragmatic_cookies_visitor_id';
    var id = localStorage.getItem(key);
    if (!id) {
      id = 'v_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
      localStorage.setItem(key, id);
    }
    return id;
  }

  // ── Script activation ──

  function activateScripts(consent) {
    var scripts = document.querySelectorAll('script[type="text/plain"][data-cookie-category]');
    scripts.forEach(function(script) {
      var category = script.getAttribute('data-cookie-category');
      if (consent[category]) {
        var newScript = document.createElement('script');

        // Copy attributes except type and data-cookie-category
        Array.from(script.attributes).forEach(function(attr) {
          if (attr.name !== 'type' && attr.name !== 'data-cookie-category') {
            newScript.setAttribute(attr.name, attr.value);
          }
        });

        newScript.type = 'text/javascript';

        if (!script.src) {
          newScript.textContent = script.textContent;
        }

        script.parentNode.replaceChild(newScript, script);
      }
    });
  }

  // ── Popup management ──

  function showPopup() {
    var popup = document.getElementById('pragmatic-cookies-popup');
    var overlay = document.getElementById('pragmatic-cookies-overlay');
    if (popup) {
      popup.classList.add('pragmatic-cookies--visible');
      popup.setAttribute('aria-hidden', 'false');
    }
    if (overlay) {
      overlay.classList.add('pragmatic-cookies--visible');
    }
  }

  function hidePopup() {
    var popup = document.getElementById('pragmatic-cookies-popup');
    var overlay = document.getElementById('pragmatic-cookies-overlay');
    if (popup) {
      popup.classList.remove('pragmatic-cookies--visible');
      popup.setAttribute('aria-hidden', 'true');
    }
    if (overlay) {
      overlay.classList.remove('pragmatic-cookies--visible');
    }
  }

  function acceptAll() {
    var consent = {};
    CATEGORIES.forEach(function(cat) {
      consent[cat.handle] = true;
    });
    setConsent(consent);
    hidePopup();
  }

  function rejectAll() {
    var consent = {};
    CATEGORIES.forEach(function(cat) {
      consent[cat.handle] = !!cat.isRequired;
    });
    setConsent(consent);
    hidePopup();
  }

  function savePreferences() {
    var consent = {};
    CATEGORIES.forEach(function(cat) {
      if (cat.isRequired) {
        consent[cat.handle] = true;
      } else {
        var toggle = document.getElementById('pragmatic-cookie-toggle-' + cat.handle);
        consent[cat.handle] = toggle ? toggle.checked : false;
      }
    });
    setConsent(consent);
    hidePopup();
  }

  // ── Initialize ──

  function init() {
    // Bind popup buttons
    var acceptBtn = document.getElementById('pragmatic-cookies-accept-all');
    var rejectBtn = document.getElementById('pragmatic-cookies-reject-all');
    var saveBtn = document.getElementById('pragmatic-cookies-save');

    if (acceptBtn) acceptBtn.addEventListener('click', acceptAll);
    if (rejectBtn) rejectBtn.addEventListener('click', rejectAll);
    if (saveBtn) saveBtn.addEventListener('click', savePreferences);

    // Bind open-preferences triggers
    document.querySelectorAll('[data-pragmatic-open-preferences]').forEach(function(el) {
      el.addEventListener('click', function(e) {
        e.preventDefault();
        showPopup();
      });
    });

    // Overlay click closes popup
    var overlay = document.getElementById('pragmatic-cookies-overlay');
    if (overlay) {
      overlay.addEventListener('click', hidePopup);
    }

    // Show popup if no consent yet
    var existing = getConsent();
    if (!existing) {
      showPopup();
    } else {
      // Activate scripts based on existing consent
      activateScripts(existing);
    }
  }

  // Public API
  window.PragmaticCookies = {
    getConsent: getConsent,
    hasConsent: function(category) {
      var consent = getConsent();
      return consent ? !!consent[category] : false;
    },
    onConsent: function(category, callback) {
      // If already consented, fire immediately
      var consent = getConsent();
      if (consent && consent[category]) {
        try { callback(); } catch(e) { console.error(e); }
        return;
      }
      // Otherwise queue for later
      if (!callbacks[category]) callbacks[category] = [];
      callbacks[category].push(callback);
    },
    openPreferences: function() {
      showPopup();
    },
    acceptAll: acceptAll,
    rejectAll: rejectAll
  };

  // Run on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
