import{n as e}from"./translate.ts-CvxvI-3D.js";import{a as t,d as n,f as r,n as i,r as a,t as o,u as s}from"./decorators-B_wqftM0.js";import{t as c}from"./query-Df4-SAIv.js";import{F as l,z as u}from"./info-icon.ts-_ZwabIo9.js";import{t as d}from"./Config.ts-CMTyoefP.js";import{i as f,r as p}from"./actionClient-DlCWcHgA-CpnvcqLv.js";import"./cp-cdZ_jgzd.js";import{o as m,r as h}from"./thumbnail-loader.ts-CIj7Slek.js";import"./actionClient.ts-gf4koq8m.js";import{t as g}from"./legacy-ui-DO8bFifx.js";import{t as _}from"./login-form.styles-By9RItzW.js";import{t as v}from"./decorate-B3KLIx2E.js";import{t as y}from"./useAnnouncer-Boqz3JC5.js";function b(e){let t=new Uint8Array(e),n=``;for(let e of t)n+=String.fromCharCode(e);return btoa(n).replace(/\+/g,`-`).replace(/\//g,`_`).replace(/=/g,``)}function x(e){let t=e.replace(/-/g,`+`).replace(/_/g,`/`),n=(4-t.length%4)%4,r=t.padEnd(t.length+n,`=`),i=atob(r),a=new ArrayBuffer(i.length),o=new Uint8Array(a);for(let e=0;e<i.length;e++)o[e]=i.charCodeAt(e);return a}function S(){return C.stubThis(globalThis?.PublicKeyCredential!==void 0&&typeof globalThis.PublicKeyCredential==`function`)}var C={stubThis:e=>e};function w(e){let{id:t}=e;return{...e,id:x(t),transports:e.transports}}function T(e){return e===`localhost`||/^((xn--[a-z0-9-]+|[a-z0-9]+(-[a-z0-9]+)*)\.)+([a-z]{2,}|xn--[a-z0-9-]+)$/i.test(e)}var E=class extends Error{constructor({message:e,code:t,cause:n,name:r}){super(e,{cause:n}),Object.defineProperty(this,"code",{enumerable:!0,configurable:!0,writable:!0,value:void 0}),this.name=r??n.name,this.code=t}};function D({error:e,options:t}){let{publicKey:n}=t;if(!n)throw Error(`options was missing required publicKey property`);if(e.name===`AbortError`){if(t.signal instanceof AbortSignal)return new E({message:`Registration ceremony was sent an abort signal`,code:`ERROR_CEREMONY_ABORTED`,cause:e})}else if(e.name===`ConstraintError`){if(n.authenticatorSelection?.requireResidentKey===!0)return new E({message:`Discoverable credentials were required but no available authenticator supported it`,code:`ERROR_AUTHENTICATOR_MISSING_DISCOVERABLE_CREDENTIAL_SUPPORT`,cause:e});if(t.mediation===`conditional`&&n.authenticatorSelection?.userVerification===`required`)return new E({message:`User verification was required during automatic registration but it could not be performed`,code:`ERROR_AUTO_REGISTER_USER_VERIFICATION_FAILURE`,cause:e});if(n.authenticatorSelection?.userVerification===`required`)return new E({message:`User verification was required but no available authenticator supported it`,code:`ERROR_AUTHENTICATOR_MISSING_USER_VERIFICATION_SUPPORT`,cause:e})}else if(e.name===`InvalidStateError`)return new E({message:`The authenticator was previously registered`,code:`ERROR_AUTHENTICATOR_PREVIOUSLY_REGISTERED`,cause:e});else if(e.name===`NotAllowedError`)return new E({message:e.message,code:`ERROR_PASSTHROUGH_SEE_CAUSE_PROPERTY`,cause:e});else if(e.name===`NotSupportedError`)return n.pubKeyCredParams.filter(e=>e.type===`public-key`).length===0?new E({message:`No entry in pubKeyCredParams was of type "public-key"`,code:`ERROR_MALFORMED_PUBKEYCREDPARAMS`,cause:e}):new E({message:`No available authenticator supported any of the specified pubKeyCredParams algorithms`,code:`ERROR_AUTHENTICATOR_NO_SUPPORTED_PUBKEYCREDPARAMS_ALG`,cause:e});else if(e.name===`SecurityError`){let t=globalThis.location.hostname;if(!T(t))return new E({message:`${globalThis.location.hostname} is an invalid domain`,code:`ERROR_INVALID_DOMAIN`,cause:e});if(n.rp.id!==t)return new E({message:`The RP ID "${n.rp.id}" is invalid for this domain`,code:`ERROR_INVALID_RP_ID`,cause:e})}else if(e.name===`TypeError`){if(n.user.id.byteLength<1||n.user.id.byteLength>64)return new E({message:`User ID was not between 1 and 64 characters`,code:`ERROR_INVALID_USER_ID_LENGTH`,cause:e})}else if(e.name===`UnknownError`)return new E({message:`The authenticator was unable to process the specified options, or could not create a new credential`,code:`ERROR_AUTHENTICATOR_GENERAL_ERROR`,cause:e});return e}var O=new class{constructor(){Object.defineProperty(this,"controller",{enumerable:!0,configurable:!0,writable:!0,value:void 0})}createNewAbortSignal(){if(this.controller){let e=Error(`Cancelling existing WebAuthn API call for new one`);e.name=`AbortError`,this.controller.abort(e)}let e=new AbortController;return this.controller=e,e.signal}cancelCeremony(){if(this.controller){let e=Error(`Manually cancelling existing WebAuthn API call`);e.name=`AbortError`,this.controller.abort(e),this.controller=void 0}}},k=[`cross-platform`,`platform`];function A(e){if(e&&!(k.indexOf(e)<0))return e}async function j(e){!e.optionsJSON&&e.challenge&&(console.warn(`startRegistration() was not called correctly. It will try to continue with the provided options, but this call should be refactored to use the expected call structure instead. See https://simplewebauthn.dev/docs/packages/browser#typeerror-cannot-read-properties-of-undefined-reading-challenge for more information.`),e={optionsJSON:e});let{optionsJSON:t,useAutoRegister:n=!1}=e;if(!S())throw Error(`WebAuthn is not supported in this browser`);let r={...t,challenge:x(t.challenge),user:{...t.user,id:x(t.user.id)},excludeCredentials:t.excludeCredentials?.map(w)},i={};n&&(i.mediation=`conditional`),i.publicKey=r,i.signal=O.createNewAbortSignal();let a;try{a=await navigator.credentials.create(i)}catch(e){throw D({error:e,options:i})}if(!a)throw Error(`Registration was not completed`);let{id:o,rawId:s,response:c,type:l}=a,u;typeof c.getTransports==`function`&&(u=c.getTransports());let d;if(typeof c.getPublicKeyAlgorithm==`function`)try{d=c.getPublicKeyAlgorithm()}catch(e){M(`getPublicKeyAlgorithm()`,e)}let f;if(typeof c.getPublicKey==`function`)try{let e=c.getPublicKey();e!==null&&(f=b(e))}catch(e){M(`getPublicKey()`,e)}let p;if(typeof c.getAuthenticatorData==`function`)try{p=b(c.getAuthenticatorData())}catch(e){M(`getAuthenticatorData()`,e)}return{id:o,rawId:b(s),response:{attestationObject:b(c.attestationObject),clientDataJSON:b(c.clientDataJSON),transports:u,publicKeyAlgorithm:d,publicKey:f,authenticatorData:p},type:l,clientExtensionResults:a.getClientExtensionResults(),authenticatorAttachment:A(a.authenticatorAttachment)}}function M(e,t){console.warn(`The browser extension that intercepted this WebAuthn API call incorrectly implemented ${e}. You should report this error to them.\n`,t)}function N(){if(!S())return P.stubThis(new Promise(e=>e(!1)));let e=globalThis.PublicKeyCredential;return e?.isConditionalMediationAvailable===void 0?P.stubThis(new Promise(e=>e(!1))):P.stubThis(e.isConditionalMediationAvailable())}var P={stubThis:e=>e};function F({error:e,options:t}){let{publicKey:n}=t;if(!n)throw Error(`options was missing required publicKey property`);if(e.name===`AbortError`){if(t.signal instanceof AbortSignal)return new E({message:`Authentication ceremony was sent an abort signal`,code:`ERROR_CEREMONY_ABORTED`,cause:e})}else if(e.name===`NotAllowedError`)return new E({message:e.message,code:`ERROR_PASSTHROUGH_SEE_CAUSE_PROPERTY`,cause:e});else if(e.name===`SecurityError`){let t=globalThis.location.hostname;if(!T(t))return new E({message:`${globalThis.location.hostname} is an invalid domain`,code:`ERROR_INVALID_DOMAIN`,cause:e});if(n.rpId!==t)return new E({message:`The RP ID "${n.rpId}" is invalid for this domain`,code:`ERROR_INVALID_RP_ID`,cause:e})}else if(e.name===`UnknownError`)return new E({message:`The authenticator was unable to process the specified options, or could not create a new assertion signature`,code:`ERROR_AUTHENTICATOR_GENERAL_ERROR`,cause:e});return e}async function I(e){!e.optionsJSON&&e.challenge&&(console.warn(`startAuthentication() was not called correctly. It will try to continue with the provided options, but this call should be refactored to use the expected call structure instead. See https://simplewebauthn.dev/docs/packages/browser#typeerror-cannot-read-properties-of-undefined-reading-challenge for more information.`),e={optionsJSON:e});let{optionsJSON:t,useBrowserAutofill:n=!1,verifyBrowserAutofillInput:r=!0}=e;if(!S())throw Error(`WebAuthn is not supported in this browser`);let i;t.allowCredentials?.length!==0&&(i=t.allowCredentials?.map(w));let a={...t,challenge:x(t.challenge),allowCredentials:i},o={};if(n){if(!await N())throw Error(`Browser does not support WebAuthn autofill`);if(document.querySelectorAll(`input[autocomplete$='webauthn']`).length<1&&r)throw Error('No <input> with "webauthn" as the only or last value in its `autocomplete` attribute was detected');o.mediation=`conditional`,a.allowCredentials=[]}o.publicKey=a,o.signal=O.createNewAbortSignal();let s;try{s=await navigator.credentials.get(o)}catch(e){throw F({error:e,options:o})}if(!s)throw Error(`Authentication was not completed`);let{id:c,rawId:l,response:u,type:d}=s,f;return u.userHandle&&(f=b(u.userHandle)),{id:c,rawId:b(l),response:{authenticatorData:b(u.authenticatorData),clientDataJSON:b(u.clientDataJSON),signature:b(u.signature),userHandle:f},type:d,clientExtensionResults:s.getClientExtensionResults(),authenticatorAttachment:A(s.authenticatorAttachment)}}function L(){return S()?PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable():new Promise(e=>e(!1))}var R=`craft-checkbox>input[type=checkbox],input[type=checkbox].cp-checkbox,craft-radio>input[type=radio],input[type=radio].cp-radio{appearance:none;line-height:inherit;margin-block:calc((1lh - var(--c-checkbox-size)) / 2);width:var(--c-checkbox-size);height:var(--c-checkbox-size);border:var(--c-form-control-border-width) var(--c-form-control-border-style) var(--c-checkbox-border-color,var(--c-form-control-border-color));border-radius:var(--c-checkbox-radius);background-color:var(--c-input-fill);color:var(--c-checkbox-checked-mark,var(--c-color-accent-on-loud));cursor:pointer;flex-shrink:0;place-items:center;margin-inline:0;display:inline-grid}:is(craft-checkbox>input[type=checkbox],input[type=checkbox].cp-checkbox,craft-radio>input[type=radio],input[type=radio].cp-radio):before{content:"";aspect-ratio:1;visibility:hidden;background-color:currentColor;width:65%}:is(craft-checkbox>input[type=checkbox],input[type=checkbox].cp-checkbox,craft-radio>input[type=radio],input[type=radio].cp-radio):checked{background-color:var(--c-checkbox-checked-fill,var(--c-color-accent-fill-loud));border-color:#0000}:is(craft-checkbox>input[type=checkbox],input[type=checkbox].cp-checkbox,craft-radio>input[type=radio],input[type=radio].cp-radio):checked:before{visibility:visible}:is(craft-checkbox>input[type=checkbox],input[type=checkbox].cp-checkbox,craft-radio>input[type=radio],input[type=radio].cp-radio):disabled{opacity:.5;cursor:not-allowed}@media (forced-colors:active){:is(craft-checkbox>input[type=checkbox],input[type=checkbox].cp-checkbox,craft-radio>input[type=radio],input[type=radio].cp-radio):checked{border-color:highlight}:is(craft-checkbox>input[type=checkbox],input[type=checkbox].cp-checkbox,craft-radio>input[type=radio],input[type=radio].cp-radio):before{forced-color-adjust:none;background-color:highlight}}:is(craft-checkbox>input[type=checkbox],input[type=checkbox].cp-checkbox):before{-webkit-mask-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' version='1.1' viewBox='0 0 255.8 234.9'%3E%3Cpath d='M245.9,4.6c-10.7-7.8-25.7-5.4-33.5,5.3l-119.4,164.2-52.1-52.1c-9.4-9.4-24.6-9.4-33.9,0-9.3,9.4-9.4,24.6,0,33.9l72,72c5,5,11.8,7.5,18.8,7s13.4-4.1,17.5-9.8L251.2,38.1c7.8-10.7,5.4-25.7-5.3-33.5Z'/%3E%3C/svg%3E");mask-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' version='1.1' viewBox='0 0 255.8 234.9'%3E%3Cpath d='M245.9,4.6c-10.7-7.8-25.7-5.4-33.5,5.3l-119.4,164.2-52.1-52.1c-9.4-9.4-24.6-9.4-33.9,0-9.3,9.4-9.4,24.6,0,33.9l72,72c5,5,11.8,7.5,18.8,7s13.4-4.1,17.5-9.8L251.2,38.1c7.8-10.7,5.4-25.7-5.3-33.5Z'/%3E%3C/svg%3E");-webkit-mask-position:50%;mask-position:50%;-webkit-mask-size:contain;mask-size:contain;-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat}:is(craft-checkbox>input[type=checkbox],input[type=checkbox].cp-checkbox):indeterminate{background-color:var(--c-checkbox-checked-fill,var(--c-color-accent-fill-loud));border-color:#0000}:is(craft-checkbox>input[type=checkbox],input[type=checkbox].cp-checkbox):indeterminate:before{visibility:visible;aspect-ratio:auto;border-radius:1px;width:55%;height:2px;-webkit-mask-image:none;mask-image:none}@media (forced-colors:active){:is(craft-checkbox>input[type=checkbox],input[type=checkbox].cp-checkbox):indeterminate{border-color:highlight}}craft-radio>input[type=radio],input[type=radio].cp-radio{border-radius:50%}:is(craft-radio>input[type=radio],input[type=radio].cp-radio):before{border-radius:50%;width:40%}craft-radio[data-button]>input[type=radio]{border-radius:inherit;border:0;width:100%;height:100%;margin:0;position:absolute;inset:0}craft-radio[data-button]>input[type=radio],craft-radio[data-button]>input[type=radio]:checked{background-color:#0000}craft-radio[data-button]>input[type=radio]:checked{cursor:default}craft-radio[data-button]>input[type=radio]:before{display:none}craft-radio[data-button]>input[type=radio]:disabled{opacity:1}craft-radio[data-card]>input[type=radio]{border:0;border-radius:0;outline:none;width:100%;height:100%;margin:0;position:absolute;inset:0}craft-radio[data-card]>input[type=radio],craft-radio[data-card]>input[type=radio]:checked{background-color:#0000}craft-radio[data-card]>input[type=radio]:checked{cursor:default}craft-radio[data-card]>input[type=radio]:before{display:none}craft-radio[data-card]>input[type=radio]:disabled{opacity:1}craft-radio>[slot=icon]{block-size:var(--c-radio-icon-size);inline-size:auto;min-inline-size:0;max-inline-size:100%;margin-block:calc((1lh - var(--c-radio-icon-size)) / 2);object-fit:contain;flex-shrink:0;display:block}craft-radio>[slot=icon]>svg{block-size:100%;inline-size:100%;display:block}craft-radio[data-card]>[slot=icon]{block-size:var(--c-radio-card-icon-size);margin-block:0}craft-radio[data-card][checked]>[slot=icon]{--c-illustration-surface:var(--color-base-50);--c-illustration-line:var(--color-blue-500)}craft-radio[data-button][data-button-start]>input[type=radio]{border-start-start-radius:calc(var(--c-button-radius,var(--c-form-control-radius)) - 1px);border-end-start-radius:calc(var(--c-button-radius,var(--c-form-control-radius)) - 1px)}craft-radio[data-button][data-button-end]>input[type=radio]{border-start-end-radius:calc(var(--c-button-radius,var(--c-form-control-radius)) - 1px);border-end-end-radius:calc(var(--c-button-radius,var(--c-form-control-radius)) - 1px)}`,z=new Map,B=class extends a{constructor(...e){super(...e),this.returnUrl=``,this._state=`idle`}static{this.styles=[n`
      :host {
        display: block;
        width: 100%;
      }

      .spinner-overlay {
        display: grid;
        place-items: center;
      }

      .login-form__fields {
        display: flex;
        gap: var(--c-spacing-md);
        align-items: end;
      }

      .login-form__actions {
        margin-block-start: var(--c-spacing-lg);
      }

      .login-form__error {
        margin-block-start: var(--c-spacing-md);
      }

      .alternative-login-methods {
        margin-block-start: var(--c-spacing-lg);
      }

      hr {
        margin-block: var(--c-spacing-lg);
        border: none;
        border-block-end: 1px solid var(--c-color-border-quiet);
      }
    `]}firstUpdated(){this._input?.focus()}async _onSubmit(e){e.preventDefault(),await this._submit(this._input?.value??``)}async _submit(t){if(this._state!==`loading`){this._state=`loading`;try{await p.post(this.endpoint,{code:t}),this.dispatchEvent(new CustomEvent(`login-verified`,{bubbles:!0,composed:!0,detail:{returnUrl:this.returnUrl}})),this._state=`success`,setTimeout(()=>{this._state=`idle`},2e3)}catch(t){this._state=`error`,this.dispatchEvent(new CustomEvent(`login-failed`,{bubbles:!0,composed:!0,detail:{message:t?.response?.data?.message??e(`A server error occurred.`)}}))}}}renderInput(){return s`<slot name="input">
      <div>
        ${e(`Implement the renderInput() method or provide an input slot`)}
      </div>
    </slot>`}renderFields(){return s`
      <slot name="fields">
        ${this.renderInput()}
        <slot name="submit-button">
          <craft-button
            slot="after"
            type="submit"
            variant="primary"
            ?loading="${this._state===`loading`}"
          >
            ${e(`Verify`)}
          </craft-button>
        </slot>
      </slot>
    `}render(){return s`
      <form
        class="login-form"
        accept-charset="UTF-8"
        @submit="${this._onSubmit}"
      >
        <div class="login-form__fields">${this.renderFields()}</div>
      </form>
    `}static register(e,t){customElements.get(e)||customElements.define(e,t),z.set(t.METHOD,t)}static isNative(e){return z.has(e)}};v([i({attribute:`return-url`})],B.prototype,`returnUrl`,void 0),v([o()],B.prototype,`_state`,void 0),v([c(`craft-input`)],B.prototype,`_input`,void 0);var V=class extends B{static{this.METHOD=`CraftCms\\Cms\\Auth\\Methods\\TOTP`}get endpoint(){return`auth/verify-totp`}renderInput(){return s`
      <craft-input
        label="${e(`Verification Code`)}"
        id="totp-code"
        class="totp-code"
        name="code"
        .maxlength="${6}"
        autocomplete="one-time-code"
        inputmode="numeric"
        aria-required="true"
      ></craft-input>
    `}};v([c(`craft-input.totp-code`)],V.prototype,`_input`,void 0),B.register(`craft-totp-form`,V);var H=class extends B{static{this.METHOD=`CraftCms\\Cms\\Auth\\Methods\\RecoveryCodes`}get endpoint(){return`auth/verify-recovery-code`}renderInput(){return s`
      <craft-input
        label="${e(`Recovery Code`)}"
        id="recovery-code"
        class="recovery-code"
        name="code"
        autocomplete="off"
        aria-required="true"
      ></craft-input>
    `}};v([c(`craft-input.recovery-code`)],H.prototype,`_input`,void 0),B.register(`craft-recovery-code-form`,H);var U=class extends a{constructor(...e){super(...e),this._switching=!1,this.#e=!1,this.#t=null}static{this.styles=[_]}#e;#t;async updated(e){super.updated(e),!B.isNative(this.data?.authMethod)&&!this.#e&&!this._switching&&this._container&&(this.#e=!0,await this.#n())}async#n(){if(!this._container)return;let t=document.createElement(`template`);t.innerHTML=this.data.authForm.trim();let n=t.content.firstElementChild;if(n){if(this.#t!==this.data&&(this.#t=this.data,await h(this.data.headHtml),await m(this.data.bodyHtml)),g(n),typeof Craft.createAuthFormHandler!=`function`){this.#r(e(`This authentication method isn’t available here.`));return}Craft.createAuthFormHandler(this.data.authMethod,n,()=>{this.dispatchEvent(new CustomEvent(`login-verified`,{bubbles:!0,composed:!0,detail:{returnUrl:this.data.returnUrl}}))},e=>this.#r(e)),this._container.append(n),this._container.querySelector(`:focus-visible, input, button`)?.focus()}}#r(e){this.dispatchEvent(new CustomEvent(`login-failed`,{bubbles:!0,composed:!0,detail:{message:e}}))}async#i(t){this._switching=!0,this.#e=!1;let n=null;try{let r=await fetch(t.url,{headers:{Accept:`application/json`,"Content-Type":`application/json`}}),i=await r.json().catch(()=>null);r.ok&&i?this.data=i:n=i?.message??e(`A server error occurred.`)}catch{n=e(`A server error occurred.`)}finally{this._switching=!1}n!==null&&this.#r(n)}render(){return this._switching?s`
        <craft-pane>
          <div class="spinner-overlay">
            <craft-spinner></craft-spinner>
          </div>
        </craft-pane>
      `:s`
      <craft-pane>
        <div class="auth-form-container">
          ${B.isNative(this.data?.authMethod)?l(this.data.authForm):t}
        </div>
        ${this.data.otherMethods.length?s`
              <hr />
              <craft-action-menu>
                <craft-button slot="invoker" variant="plain" size="zero">
                  <craft-icon slot="prefix" name="chevron-down"></craft-icon>
                  ${e(`Try another way`)}
                </craft-button>

                <div slot="content">
                  ${this.data.otherMethods.map(e=>s`
                      <craft-action-item
                        @click="${()=>this.#i(e)}"
                      >
                        ${e.name}
                      </craft-action-item>
                    `)}
                </div>
              </craft-action-menu>
            `:t}
      </craft-pane>
    `}};v([i({attribute:!1})],U.prototype,`data`,void 0),v([o()],U.prototype,`_switching`,void 0),v([c(`.auth-form-container`)],U.prototype,`_container`,void 0),customElements.get(`craft-login-challenge`)||customElements.define(`craft-login-challenge`,U);var W=class extends a{constructor(...e){super(...e),this.useEmailAsUsername=!1,this.username=``,this._busy=!1,this._error=``}static{this.styles=[_]}firstUpdated(){this.updateComplete.then(()=>{this._input?.focus()})}#e(){return this.useEmailAsUsername?e(`Email`):e(`Username or Email`)}#t(e){let t=e.trim();this._error=t,y().announce(t)}async#n(t){t.preventDefault(),this._error=``,this._busy=!0;try{await p.post(`users/send-password-reset-email`,{loginName:this._input.value});let t=document.createElement(`craft-dialog`);t.setAttribute(`label`,e(`Check your email`)),t.setAttribute(`open`,``);let n=document.createElement(`p`);n.textContent=e(`Check your email for instructions to reset your password.`),t.appendChild(n),document.body.appendChild(t)}catch(t){this.#t(t?.response?.data?.message??e(`A server error occurred.`))}finally{this._busy=!1}}#r(){this.dispatchEvent(new CustomEvent(`craft:login:reset-back`,{bubbles:!0,composed:!0,detail:{username:this._input?.value??``}}))}render(){return s`
      <craft-pane>
        <form
          class="auth-form auth-form--reset"
          method="post"
          accept-charset="UTF-8"
          @submit="${this.#n}"
        >
          <craft-field-group>
            <craft-input
              label="${this.#e()}"
              id="reset-username"
              type="${this.useEmailAsUsername?`email`:`text`}"
              class="reset-username"
              name="username"
              .value="${this.username}"
              autocomplete="username"
              autocapitalize="off"
              required
            >
            </craft-input>
          </craft-field-group>

          <div class="auth-form__actions">
            <craft-button
              type="submit"
              variant="accent"
              ?loading="${this._busy}"
            >
              ${e(`Reset password`)}
            </craft-button>
          </div>

          ${this._error?s`<craft-callout variant="danger" class="auth-form__error"
                >${this._error}</craft-callout
              >`:t}
        </form>

        <hr />

        <craft-button
          type="button"
          variant="link"
          size="small"
          @click="${this.#r}"
        >
          <craft-icon slot="prefix" name="arrow-left"></craft-icon>
          ${e(`Back to sign in`)}
        </craft-button>
      </craft-pane>
    `}};v([i({type:Boolean,attribute:`use-email-as-username`})],W.prototype,`useEmailAsUsername`,void 0),v([i()],W.prototype,`username`,void 0),v([o()],W.prototype,`_busy`,void 0),v([o()],W.prototype,`_error`,void 0),v([c(`.reset-username`)],W.prototype,`_input`,void 0),customElements.get(`craft-login-reset-password`)||customElements.define(`craft-login-reset-password`,W);var G=class extends a{constructor(...e){super(...e),this.showPasskeyBtn=!0,this.showResetPassword=!1,this.showRememberMe=!1,this.username=``,this.staticEmail=``,this.useEmailAsUsername=!1,this.rememberMeLabel=``,this.initialError=``,this.action=``,this._view=`login`,this._error=``,this._loginBusy=!1,this._passkeyBusy=!1,this._canUsePasskey=!1,this._twoFactorData=null,this._resetUsername=``}static{this.styles=[u,r(R),_]}async connectedCallback(){super.connectedCallback(),this.initialError&&(this._error=this.initialError),this.showPasskeyBtn&&S()&&(this._canUsePasskey=await L())}focus(e){this.updateComplete.then(()=>{(this.staticEmail?this._passwordInput:this._usernameInput??this._passwordInput)?.focus(e)})}#e(){return this.useEmailAsUsername?e(`Email`):e(`Username or Email`)}async#t(e){e.preventDefault(),this._error=``,this._loginBusy=!0;try{await f.refreshToken();let e=await fetch(this.action,{method:`post`,headers:{Accept:`application/json`,"Content-Type":`application/json`,"X-CSRF-TOKEN":d.getInstance().get(`csrfTokenValue`)},body:JSON.stringify({loginName:this._usernameInput.value,password:this._passwordInput.value,rememberMe:this._rememberMeInput?.checked?`1`:``})}),t=await e.json();if(!e.ok)throw Error(t.message||`A server error occurred.`);t.authMethod?(this._twoFactorData=t,this._view=`challenge`,this._loginBusy=!1):(await this.#c(t.returnUrl),this._loginBusy=!1)}catch(e){this._loginBusy=!1,this.#s(e.message)}}async#n(){if(!this._passkeyBusy){this._error=``,this._passkeyBusy=!0;try{let{data:e}=await p.post(`auth/passkey-request-options`),t=await I({optionsJSON:JSON.parse(e.options)}),{data:n}=await p.post(`users/login-with-passkey`,{requestOptions:e.options,authResponse:JSON.stringify(t)});await this.#c(n.returnUrl),this._passkeyBusy=!1}catch(e){this._passkeyBusy=!1;let t=e?.response?.data?.message;t?this.#s(t):console.warn(e)}}}#r(){this._error=``,this._resetUsername=this._usernameInput?.value??``,this._view=`reset-password`}#i(e){let t=e.detail?.username??``;this._view=`login`,this.updateComplete.then(()=>{t&&this._usernameInput&&(this._usernameInput.value=t),this._usernameInput?.focus()})}async#a(e){try{await this.#c(e.detail.returnUrl)}catch(e){this.#s(e.message)}}#o(e){let{message:t}=e.detail,n=new CustomEvent(`craft:login:error`,{bubbles:!0,composed:!0,cancelable:!0,detail:{message:t}});this.dispatchEvent(n),n.defaultPrevented||this.#s(t)}#s(e){let{announce:t}=y();this._error=e.trim(),t(this._error)}async#c(e){await f.refreshToken();let t=new CustomEvent(`craft:login:success`,{bubbles:!0,composed:!0,cancelable:!0,detail:{returnUrl:e}});this.dispatchEvent(t),t.defaultPrevented||(window.location.href=e)}render(){return s`
      <div>
        <span
          class="cp-visually-hidden"
          role="status"
          aria-live="polite"
          aria-atomic="true"
        ></span>

        ${this._view===`login`?this.#l():this._view===`reset-password`?s`
                <craft-login-reset-password
                  ?use-email-as-username="${this.useEmailAsUsername}"
                  username="${this._resetUsername}"
                  @craft:login:reset-back="${this.#i}"
                ></craft-login-reset-password>
              `:s`
                <craft-login-challenge
                  .data="${this._twoFactorData}"
                  @login-verified="${this.#a}"
                  @login-failed="${this.#o}"
                ></craft-login-challenge>
              `}
      </div>
    `}#l(){let n=this._canUsePasskey||this.querySelector(`[slot="alternative-methods"]`);return s`
      <craft-pane>
        <form
          class="auth-form"
          method="post"
          accept-charset="UTF-8"
          @submit="${this.#t}"
        >
          <craft-field-group>
            ${this.staticEmail?s`<input
                  type="hidden"
                  class="login-username"
                  name="username"
                  .value="${this.staticEmail}"
                />`:s`
                  <div class="field">
                    <craft-input
                      label="${this.#e()}"
                      id="login-username"
                      type="${this.useEmailAsUsername?`email`:`text`}"
                      class="login-username"
                      name="username"
                      .value="${this.username}"
                      autocomplete="username"
                      autocapitalize="off"
                      required
                    />
                  </div>
                `}

            <div>
              <craft-input-password
                label="${e(`Password`)}"
                id="login-password"
                class="login-password"
                name="password"
                autocomplete="current-password"
                required
              ></craft-input-password>

              ${this.showResetPassword?s`
                    <craft-button
                      type="button"
                      size="small"
                      variant="link"
                      @click="${this.#r}"
                      style="margin-block-start: var(--c-spacing-sm)"
                    >
                      ${e(`Forgot password?`)}
                    </craft-button>
                  `:t}
            </div>

            ${this.showRememberMe?s`
                  <div class="remember-me-row">
                    <craft-checkbox
                      label="${this.rememberMeLabel||e(`Stay signed in`)}"
                      type="checkbox"
                      id="login-remember-me"
                      class="login-remember-me"
                    ></craft-checkbox>
                  </div>
                `:t}
          </craft-field-group>

          <div class="auth-form__actions">
            <craft-button
              type="submit"
              variant="primary"
              ?loading="${this._loginBusy}"
              style="width: 100%"
            >
              ${e(`Sign in`)}
            </craft-button>
          </div>
        </form>

        ${this._error?s`<craft-callout class="auth-form__error" variant="danger"
              >${this._error}</craft-callout
            >`:t}
      </craft-pane>

      ${n?s`
            <div class="alternative-login-methods">
              ${this._canUsePasskey?s`
                    <craft-button
                      type="button"
                      variant="fill"
                      ?loading="${this._passkeyBusy}"
                      @click="${this.#n}"
                      style="width: 100%"
                    >
                      ${e(`Sign in with a passkey`)}
                    </craft-button>
                  `:t}
              <slot name="alternative-methods"></slot>
            </div>
          `:t}
    `}};v([i({type:Boolean,attribute:`show-passkey-btn`})],G.prototype,`showPasskeyBtn`,void 0),v([i({type:Boolean,attribute:`show-reset-password`})],G.prototype,`showResetPassword`,void 0),v([i({type:Boolean,attribute:`show-remember-me`})],G.prototype,`showRememberMe`,void 0),v([i()],G.prototype,`username`,void 0),v([i({attribute:`static-email`})],G.prototype,`staticEmail`,void 0),v([i({type:Boolean,attribute:`use-email-as-username`})],G.prototype,`useEmailAsUsername`,void 0),v([i({attribute:`remember-me-label`})],G.prototype,`rememberMeLabel`,void 0),v([i({attribute:`initial-error`})],G.prototype,`initialError`,void 0),v([i()],G.prototype,`action`,void 0),v([o()],G.prototype,`_view`,void 0),v([o()],G.prototype,`_error`,void 0),v([o()],G.prototype,`_loginBusy`,void 0),v([o()],G.prototype,`_passkeyBusy`,void 0),v([o()],G.prototype,`_canUsePasskey`,void 0),v([o()],G.prototype,`_twoFactorData`,void 0),v([o()],G.prototype,`_resetUsername`,void 0),v([c(`.login-username`)],G.prototype,`_usernameInput`,void 0),v([c(`craft-input-password.login-password`)],G.prototype,`_passwordInput`,void 0),v([c(`.login-remember-me`)],G.prototype,`_rememberMeInput`,void 0),customElements.get(`craft-login-form`)||customElements.define(`craft-login-form`,G);export{S as i,L as n,j as r,B as t};