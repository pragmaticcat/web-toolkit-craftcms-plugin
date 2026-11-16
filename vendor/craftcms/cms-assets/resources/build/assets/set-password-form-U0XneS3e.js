import{n as e}from"./translate.ts-CvxvI-3D.js";import{a as t,n,r,t as i,u as a}from"./decorators-B_wqftM0.js";import"./cp-cdZ_jgzd.js";import{t as o}from"./login-form.styles-By9RItzW.js";import{t as s}from"./decorate-B3KLIx2E.js";var c=class extends r{constructor(...e){super(...e),this.action=``,this.uid=``,this.code=``,this.passwordRules=``,this.initialError=``,this.newUser=!1,this._busy=!1}static{this.styles=[o]}#e(){return this.newUser?e(`Choose a password`):e(`Choose a new password`)}#t(){this._busy=!0}render(){return a`
      <craft-pane>
        <form
          class="auth-form"
          method="post"
          action="${this.action}"
          accept-charset="UTF-8"
          @submit="${this.#t}"
        >
          <input type="hidden" name="uid" value="${this.uid}" />
          <input type="hidden" name="code" value="${this.code}" />

          <craft-field-group>
            <craft-input-password
              label="${this.#e()}"
              id="newPassword"
              name="newPassword"
              autocomplete="new-password"
              passwordrules="${this.passwordRules}"
              required
              autofocus
            ></craft-input-password>
          </craft-field-group>

          <div class="auth-form__actions">
            <craft-button
              type="submit"
              variant="accent"
              ?loading="${this._busy}"
              style="width: 100%"
            >
              ${e(`Set Password`)}
            </craft-button>
          </div>
        </form>

        ${this.initialError?a`<craft-callout class="auth-form__error" variant="danger"
              >${this.initialError}</craft-callout
            >`:t}
      </craft-pane>
    `}};s([n()],c.prototype,`action`,void 0),s([n()],c.prototype,`uid`,void 0),s([n()],c.prototype,`code`,void 0),s([n({attribute:`password-rules`})],c.prototype,`passwordRules`,void 0),s([n({attribute:`initial-error`})],c.prototype,`initialError`,void 0),s([n({type:Boolean,attribute:`new-user`})],c.prototype,`newUser`,void 0),s([i()],c.prototype,`_busy`,void 0),customElements.get(`craft-set-password-form`)||customElements.define(`craft-set-password-form`,c);