(function () {
  if (window.__pwtExternalCodeInstalled) return;
  window.__pwtExternalCodeInstalled = true;
  var loadedCategories = {};

  function consent() {
    return window.PragmaticCookies && window.PragmaticCookies.getConsent();
  }

  function activate() {
    var accepted = consent();
    if (!accepted) return;
    Object.keys(loadedCategories).forEach(function (category) {
      if (!accepted[category]) window.location.reload();
    });
    document.querySelectorAll('script[data-pwt-external-code]').forEach(function (placeholder) {
      var payload;
      try { payload = JSON.parse(placeholder.textContent); } catch (e) { return; }
      if (!accepted[payload.category]) return;
      loadedCategories[payload.category] = true;

      // Replace the placeholder first so repeated consent events cannot run it twice.
      var anchor = document.createComment('pwt external code');
      placeholder.replaceWith(anchor);
      var template = document.createElement('template');
      template.innerHTML = payload.code;
      function insert(node, parent, before) {
        if (node.nodeType === 1 && node.tagName === 'SCRIPT') {
          var script = document.createElement('script');
          Array.from(node.attributes).forEach(function (attribute) {
            script.setAttribute(attribute.name, attribute.value);
          });
          script.textContent = node.textContent;
          parent.insertBefore(script, before);
        } else {
          parent.insertBefore(node, before);
          if (node.nodeType === 1) {
            Array.from(node.querySelectorAll('script')).forEach(function (nested) {
              var replacement = document.createElement('script');
              Array.from(nested.attributes).forEach(function (attribute) {
                replacement.setAttribute(attribute.name, attribute.value);
              });
              replacement.textContent = nested.textContent;
              nested.replaceWith(replacement);
            });
          }
        }
      }
      Array.from(template.content.childNodes).forEach(function (node) {
        insert(node, anchor.parentNode, anchor);
      });
      anchor.remove();
    });
  }

  document.addEventListener('pragmaticCookiesConsentChanged', activate);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', activate);
  } else {
    activate();
  }
})();
