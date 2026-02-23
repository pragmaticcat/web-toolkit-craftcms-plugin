(function() {
  if (!window.Craft || !window.PragmaticWebToolkitTranslations) {
    return;
  }

  var config = window.PragmaticWebToolkitTranslations;

  function t(message) {
    return (window.Craft && typeof Craft.t === 'function')
      ? Craft.t('pragmatic-web-toolkit', message)
      : message;
  }

  function getCkeditorInstance(textarea) {
    if (!textarea) return null;
    if (window.Craft && Craft.CKEditor) {
      if (Craft.CKEditor.instances && Craft.CKEditor.instances[textarea.id]) {
        return Craft.CKEditor.instances[textarea.id];
      }
      if (typeof Craft.CKEditor.getInstanceById === 'function') {
        return Craft.CKEditor.getInstanceById(textarea.id);
      }
    }
    if (textarea.ckeditorInstance) return textarea.ckeditorInstance;
    if (window.CKEDITOR && CKEDITOR.instances && CKEDITOR.instances[textarea.id]) {
      return CKEDITOR.instances[textarea.id];
    }
    return null;
  }

  function setFieldValue(fieldEl, value) {
    if (!fieldEl) return;

    var textarea = fieldEl.querySelector('textarea[name^="fields["]');
    if (textarea) {
      var editor = getCkeditorInstance(textarea);
      if (editor && typeof editor.setData === 'function') {
        editor.setData(value);
        return;
      }
      textarea.value = value;
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
      textarea.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }

    var input = fieldEl.querySelector('input[type="text"][name^="fields["]')
      || fieldEl.querySelector('input[type="text"][name$="[title]"]')
      || fieldEl.querySelector('input#title');
    if (input) {
      input.value = value;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function normalizeSite(site) {
    if (!site) return null;
    var id = parseInt(site.id, 10);
    if (!id) return null;
    return {
      id: id,
      name: site.name || site.label || ('Site #' + id),
      handle: site.handle || '',
      language: site.language || ''
    };
  }

  function resolveCurrentSiteId(fieldEl) {
    var currentSiteId = parseInt(config.currentSiteId, 10);

    if (fieldEl && typeof fieldEl.closest === 'function') {
      var form = fieldEl.closest('form');
      if (form) {
        var siteInput = form.querySelector('input[name="siteId"]');
        var formSiteId = siteInput ? parseInt(siteInput.value, 10) : 0;
        if (formSiteId > 0) {
          currentSiteId = formSiteId;
        }
      }
    }

    if ((!currentSiteId || currentSiteId <= 0) && window.Craft && Craft.cp && Craft.cp.siteId) {
      var cpSiteId = parseInt(Craft.cp.siteId, 10);
      if (cpSiteId > 0) {
        currentSiteId = cpSiteId;
      }
    }

    return currentSiteId;
  }

  function getAllSites() {
    var mapped = [];
    if (Array.isArray(config.sites)) {
      mapped = config.sites.map(normalizeSite).filter(Boolean);
    }

    if (mapped.length <= 1 && Array.isArray(window.Craft && Craft.sites ? Craft.sites : null)) {
      mapped = Craft.sites.map(normalizeSite).filter(Boolean);
    }

    var deduped = {};
    mapped.forEach(function(site) {
      deduped[site.id] = site;
    });
    return Object.keys(deduped).map(function(id) { return deduped[id]; });
  }

  function fetchAvailableSites(entryId, targetSiteId) {
    if (!config.autotranslateSourcesUrl) {
      return Promise.resolve(getAllSites().filter(function(site) {
        return site.id !== targetSiteId;
      }));
    }

    return Craft.sendActionRequest('POST', config.autotranslateSourcesUrl, {
      data: {
        entryId: entryId,
        targetSiteId: targetSiteId
      }
    }).then(function(response) {
      if (response.data && response.data.success && Array.isArray(response.data.sites)) {
        var dynamicSites = response.data.sites.map(normalizeSite).filter(Boolean);
        if (dynamicSites.length > 0) {
          return dynamicSites;
        }
      }
      return getAllSites().filter(function(site) {
        return site.id !== targetSiteId;
      });
    }).catch(function() {
      return getAllSites().filter(function(site) {
        return site.id !== targetSiteId;
      });
    });
  }

  function openInfoModal(title, bodyHtml, buttonLabel) {
    var modalEl = document.createElement('div');
    modalEl.className = 'modal fitted';
    modalEl.innerHTML =
      '<div class="body">' +
        '<h2>' + title + '</h2>' +
        bodyHtml +
        '<div class="buttons" style="margin-top: 12px;">' +
          '<button class="btn submit" type="button" id="pwt-at-close">' + (buttonLabel || t('Close')) + '</button>' +
        '</div>' +
      '</div>';

    var modal = new Garnish.Modal(modalEl, {
      autoShow: true,
      closeOtherModals: true,
      onHide: function() { modal.destroy(); }
    });
    modalEl.querySelector('#pwt-at-close').addEventListener('click', function() {
      modal.hide();
    });
  }

  config.openModal = function(fieldEl, entryId, fieldHandle) {
    if (!config.autotranslateEnabled) {
      Craft.cp.displayError(t('Auto-translation is disabled in settings.'));
      return;
    }

    if (!config.canManageTranslations) {
      Craft.cp.displayError(t('You do not have permission to use auto-translation.'));
      return;
    }

    if (!config.googleTranslateConfigured) {
      openInfoModal(
        t('Google Translate not configured'),
        '<p>' + t('To use auto-translation, configure the Google Translate settings first.') + '</p>' +
        (config.settingsUrl ? '<p><a href="' + config.settingsUrl + '" class="go">' + t('Open translation options') + '</a></p>' : ''),
        t('Close')
      );
      return;
    }

    var currentSiteId = resolveCurrentSiteId(fieldEl);
    fetchAvailableSites(entryId, currentSiteId).then(function(sites) {
      if (!sites.length) {
        Craft.cp.displayError(t('No other sites available for this entry.'));
        return;
      }

      var modalEl = document.createElement('div');
      modalEl.className = 'modal fitted';
      modalEl.innerHTML =
        '<div class="body">' +
          '<h2>' + t('Translate from site...') + '</h2>' +
          '<p class="light">' + t('Select the source language/site to translate from.') + '</p>' +
          '<div class="field">' +
            '<div class="select">' +
              '<select id="pwt-at-source-site">' +
                sites.map(function(site) {
                  return '<option value="' + site.id + '">' + site.name + ' (' + site.language + ')</option>';
                }).join('') +
              '</select>' +
            '</div>' +
          '</div>' +
          '<div class="buttons right" style="margin-top: 12px;">' +
            '<button type="button" class="btn" id="pwt-at-cancel">' + t('Cancel') + '</button>' +
            '<button type="button" class="btn submit" id="pwt-at-confirm">' + t('Confirm') + '</button>' +
          '</div>' +
        '</div>';

      var modal = new Garnish.Modal(modalEl, {
        autoShow: true,
        closeOtherModals: true,
        onHide: function() { modal.destroy(); }
      });

      var cancelBtn = modalEl.querySelector('#pwt-at-cancel');
      var confirmBtn = modalEl.querySelector('#pwt-at-confirm');

      if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
          modal.hide();
        });
      }

      if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
          var sourceSiteId = parseInt(modalEl.querySelector('#pwt-at-source-site').value, 10);
          confirmBtn.disabled = true;
          confirmBtn.classList.add('loading');

          Craft.sendActionRequest('POST', config.autotranslateUrl, {
            data: {
              entryId: entryId,
              fieldHandle: fieldHandle,
              sourceSiteId: sourceSiteId,
              targetSiteId: currentSiteId
            }
          }).then(function(response) {
            if (response.data && response.data.success) {
              setFieldValue(fieldEl, response.data.text || '');
              Craft.cp.displayNotice(t('Translated.'));
              modal.hide();
              return;
            }
            var errorMessage = (response.data && response.data.error)
              ? response.data.error
              : t('Translation failed.');
            Craft.cp.displayError(errorMessage);
            confirmBtn.disabled = false;
            confirmBtn.classList.remove('loading');
          }).catch(function(error) {
            var message = error && error.response && error.response.data && error.response.data.error
              ? error.response.data.error
              : t('Translation failed.');
            Craft.cp.displayError(message);
            confirmBtn.disabled = false;
            confirmBtn.classList.remove('loading');
          });
        });
      }
    });
  };
})();
