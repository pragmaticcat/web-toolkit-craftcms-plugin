import{n as e}from"./translate.ts-CvxvI-3D.js";import{a as t,n,r,t as i,u as a}from"./decorators-B_wqftM0.js";import{t as o}from"./custom-element-Bov83m9_.js";import"./cp-cdZ_jgzd.js";import{t as s}from"./decorate-B3KLIx2E.js";import"./pane.ts-BW_r2KzV.js";import"./field.ts-DWwRQY7x.js";import{t as c}from"./create-element-selector-modal-BIn_8d48.js";var l={urlSuffix:``,target:``,title:``,class:``,id:``,rel:``,ariaLabel:``,download:!1,filename:``},u=[`type`,`value`,`label`,...Object.keys(l)],d=0,f=class extends r{constructor(...e){super(...e),this.advancedFields=[],this.showLabelField=!1,this.types=[],this.modelValue=null,this.name=``,this.disabled=!1,this.live=!1,this.defaultLabel=``,this.label=``,this.linkAttributes={...l},this.typeId=``,this.value=``,this.valueError=``,this.advancedPanelId=`craft-link-field-advanced-${++d}`,this.valueErrorId=`${this.advancedPanelId}-value-errors`,this.emittedValue=null}connectedCallback(){super.connectedCallback(),this.typeId||=this.types[0]?.id??``}willUpdate(e){if(e.has(`modelValue`)&&!this.isEcho(this.modelValue)){let e=this.modelValue??{};this.defaultLabel=e.defaultLabel??``,this.label=e.label??``,this.linkAttributes={urlSuffix:e.urlSuffix??``,target:e.target??``,title:e.title??``,class:e.class??``,id:e.id??``,rel:e.rel??``,ariaLabel:e.ariaLabel??``,download:!!e.download,filename:e.filename??``},this.typeId=e.type??``,this.value=e.value??``}(!this.typeId||!this.types.some(e=>e.id===this.typeId))&&(this.typeId=this.types[0]?.id??``)}isEcho(e){let t=this.emittedValue;return!!(t&&e&&Object.keys(t).every(n=>n===`href`||e[n]===t[n]))}get selectedType(){return this.types.find(e=>e.id===this.typeId)}hasAdvancedField(e){return this.advancedFields.includes(e)}get showAdvancedFields(){return this.showLabelField||this.advancedFields.length>0}handleTypeChange(e){e.target instanceof HTMLSelectElement&&(this.typeId=e.target.value,this.value=``,this.defaultLabel=``,this.valueError=``,this.change(`discrete`))}handleValueChange(e){let t=e.currentTarget;this.value=t.value,this.valueError=``,this.defaultLabel=this.defaultLabelFor(this.normalizeTextValue(this.value)),this.change(`typing`)}handleLabelChange(e){this.label=e.currentTarget.value,this.change(`typing`)}handleAttributeInput(e){return t=>{this.linkAttributes={...this.linkAttributes,[e]:t.currentTarget.value},this.change(`typing`)}}handleSwitch(e){return t=>{let{checked:n}=t.currentTarget;this.linkAttributes={...this.linkAttributes,...e(n)},this.change(`discrete`)}}async chooseElement(t){let n=this.selectedType;if(!n?.elementType||!n.refHandle)return;let r={...n.elementSelectConfig};delete r.elementType,delete r.limit,delete r.single,await this.dispatchElementSelectStartEvent(t.currentTarget instanceof HTMLElement?t.currentTarget:null);let i=await c(n.elementType,{...r,hideOnSelect:!0,modalTitle:e(`Choose {type}`,{type:n.label}),multiSelect:!1,onSelect:e=>{let[t]=e;t&&(this.value=`{${n.refHandle}:${t.id}@${t.siteId}:url}`,this.defaultLabel=String(t.label||``),this.valueError=``,this.change(`discrete`))}});i.on(`close`,()=>{this.dispatchElementSelectEvent(`element-select-end`),i.destroy()})}async dispatchElementSelectStartEvent(e){let t=[];this.dispatchEvent(new CustomEvent(`element-select-start`,{bubbles:!0,detail:{restoreFocusTo:e??void 0,waitUntil:e=>t.push(e)}})),await Promise.all(t)}dispatchElementSelectEvent(e){this.dispatchEvent(new CustomEvent(e,{bubbles:!0}))}normalizeTextValue(e){let t=this.selectedType;if(e=e.trim(),!e||t?.kind!==`text`||this.validateTextValue(e))return e;let n=`${t.prefixes?.[0]??``}${e}`;return this.validateTextValue(n)?n:e}normalizeUrlSuffix(e){return e=e.trim(),!e||e.startsWith(`#`)||e.startsWith(`?`)?e:`?${e}`}renderTextDestination(e,t){return e===`url`||e===`email`?t.replaceAll(` `,`+`):e===`sms`?this.renderSmsDestination(t):e===`tel`?t.replaceAll(` `,`-`):t}renderSmsDestination(e){let[,t=``,n=``]=e.match(/^([^?&]*)(?:[?&]+(.*))?$/)??[];return(n?`${t}&${n.replaceAll(` `,`%20`)}`:t).replaceAll(` `,`-`)}validateTextValue(e){let t=this.selectedType?.pattern;if(!t)return!0;try{return new RegExp(t,`i`).test(e)}catch{return!0}}defaultLabelFor(e){let t=this.selectedType;if(!e||t?.kind!==`text`)return this.defaultLabel;let n=e;for(let e of t.prefixes??[])n.toLowerCase().startsWith(e.toLowerCase())&&(n=n.slice(e.length));return/^[^/]+\/$/.test(n)?n.slice(0,-1):n}currentValue(){let e=this.selectedType;if(!e)return null;let t=e.kind===`text`?this.normalizeTextValue(this.value):this.value,n=this.normalizeUrlSuffix(this.linkAttributes.urlSuffix),r=e.kind===`text`?this.renderTextDestination(e.id,t):t;return{...this.linkAttributes,defaultLabel:this.defaultLabelFor(t),href:t?`${r}${n}`:``,label:this.label.trim(),title:this.linkAttributes.title.trim(),type:e.id,urlSuffix:n,value:t}}change(e){if(!this.live)return;let t=this.currentValue();t&&(this.emittedValue=t,this.modelValue=t,this.dispatchEvent(new CustomEvent(`link-change`,{bubbles:!0,detail:{kind:e,value:t}})))}apply(){let t=this.selectedType,n=this.currentValue();if(!(!t||!n)){if(!n.value){this.valueError=e(`{attribute} cannot be blank.`,{attribute:t.label});return}if(t.kind===`text`&&!this.validateTextValue(n.value)){this.valueError=e(`{attribute} is invalid.`,{attribute:t.label});return}this.valueError=``,this.modelValue=n,this.dispatchEvent(new CustomEvent(`apply`,{bubbles:!0,detail:n}))}}cancel(){this.dispatchEvent(new CustomEvent(`cancel`,{bubbles:!0}))}renderLabel(e){return a`<span slot="label">${e}</span>`}renderTypeSelect(){return this.types.length<2?t:a`
      <craft-field>
        ${this.renderLabel(e(`Link Type`))}
        <craft-select
          slot="input"
          .value=${this.typeId}
          .disabled=${this.disabled}
        >
          <select
            slot="input"
            .value=${this.typeId}
            @change=${this.handleTypeChange}
          >
            ${this.types.map(e=>a`<option value=${e.id}>${e.label}</option>`)}
          </select>
        </craft-select>
      </craft-field>
    `}renderTypeInput(){let n=this.selectedType;if(!n)return t;if(n.kind===`element`)return a`
        <craft-field>
          ${this.renderLabel(n.label)}
          <div slot="input" class="craft-link-field__element">
            <craft-button
              type="button"
              variant="dashed"
              icon=${this.value?t:`plus`}
              ?disabled=${this.disabled}
              aria-describedby=${this.valueError?this.valueErrorId:t}
              aria-invalid=${this.valueError?`true`:t}
              @click=${this.chooseElement}
            >
              ${this.value?e(`Change`):e(`Choose`)}
            </craft-button>
            ${this.value&&this.defaultLabel?a`<span class="craft-link-selected-label"
                  >${this.defaultLabel}</span
                >`:t}
          </div>
          ${this.renderValueError(`feedback`)}
        </craft-field>
      `;let r=n.inputAttributes?.type??`text`,i=n.inputAttributes?.inputmode??void 0;return a`
      <craft-field>
        ${this.renderLabel(n.label)}
        <craft-input
          slot="input"
          data-link-value
          type=${r}
          inputmode=${i??t}
          ?invalid=${!!this.valueError}
          .value=${this.value}
          .disabled=${this.disabled}
          @input=${this.handleValueChange}
        ></craft-input>
        ${this.renderValueError(`feedback`)}
      </craft-field>
    `}renderValueError(n){return this.valueError?a`
      <ul id=${this.valueErrorId} class="errors" slot=${n??t}>
        <li>
          <span class="sr-only">${e(`Error:`)}</span>
          ${this.valueError}
        </li>
      </ul>
    `:t}renderTextAttribute(e,n,r={}){return a`
      <craft-input
        label=${n}
        help-text=${r.helpText??t}
        placeholder=${r.placeholder??t}
        type="text"
        ?monospace=${r.code}
        .value=${this.linkAttributes[e]??``}
        .disabled=${this.disabled}
        @input=${this.handleAttributeInput(e)}
      ></craft-input>
    `}renderAdvancedField(n){let r=e(`Separate multiple values with spaces.`);switch(n){case`urlSuffix`:return this.renderTextAttribute(`urlSuffix`,e(`URL Suffix`),{helpText:e(`Query params (e.g. {ex1}) or a URI fragment (e.g. {ex2}) that should be appended to the URL.`,{ex1:`?p1=foo&p2=bar`,ex2:`#anchor`})});case`target`:return a`
          <craft-switch
            label=${e(`Open in a new tab`)}
            .checked=${this.linkAttributes.target===`_blank`}
            .disabled=${this.disabled}
            @change=${this.handleSwitch(e=>({target:e?`_blank`:``}))}
          ></craft-switch>
        `;case`title`:return this.renderTextAttribute(`title`,e(`Title Text`));case`class`:return this.renderTextAttribute(`class`,e(`Class Name`),{helpText:r,code:!0});case`id`:return this.renderTextAttribute(`id`,e(`ID`),{code:!0});case`rel`:return this.renderTextAttribute(`rel`,e(`Relation ({ex})`,{ex:`rel`}),{helpText:r,code:!0});case`ariaLabel`:return this.renderTextAttribute(`ariaLabel`,e(`ARIA Label`));case`download`:return a`
          <craft-switch
            label=${e(`Download`)}
            .checked=${this.linkAttributes.download}
            .disabled=${this.disabled}
            @change=${this.handleSwitch(e=>({download:e}))}
          ></craft-switch>
          ${this.linkAttributes.download?this.renderTextAttribute(`filename`,e(`Filename`)):t}
        `}}renderAdvancedFields(){if(!this.showAdvancedFields)return t;let n=[`urlSuffix`,`target`,`title`,`class`,`id`,`rel`,`ariaLabel`,`download`].filter(e=>this.hasAdvancedField(e));return a`
      <craft-disclosure id=${this.advancedPanelId} size="small">
        <craft-button
          slot="invoker"
          type="button"
          variant="plain"
          size="small"
          flush
          icon="chevron-right"
          icon-position="suffix"
        >
          ${e(`Advanced`)}
        </craft-button>
        <craft-field-group slot="content">
          ${this.showLabelField?a`
                <craft-input
                  label=${e(`Label`)}
                  type="text"
                  placeholder=${this.defaultLabel||t}
                  .value=${this.label}
                  .disabled=${this.disabled}
                  @input=${this.handleLabelChange}
                ></craft-input>
              `:t}
          ${n.map(e=>this.renderAdvancedField(e))}
        </craft-field-group>
      </craft-disclosure>
    `}renderButtons(){return this.live?t:a`
      <div class="buttons right">
        <craft-button
          type="button"
          variant="plain"
          size="small"
          ?disabled=${this.disabled}
          @click=${this.cancel}
        >
          ${e(`Cancel`)}
        </craft-button>
        <craft-button
          type="button"
          size="small"
          ?disabled=${this.disabled}
          @click=${this.apply}
        >
          ${e(`Apply`)}
        </craft-button>
      </div>
    `}renderHiddenInputs(){return this.name?u.map(e=>{let t=this.modelValue?.[e];return a`
        <input
          type="hidden"
          name=${`${this.name}[${e}]`}
          .value=${typeof t==`boolean`?t?`1`:``:t??``}
          ?disabled=${this.disabled}
        />
      `}):t}render(){let e=a`
      <div class="craft-link-field">
        <div class="craft-link-field__destination">
          ${this.renderTypeSelect()} ${this.renderTypeInput()}
        </div>
        ${this.renderAdvancedFields()} ${this.renderButtons()}
      </div>
    `;return a`
      ${this.live&&this.showAdvancedFields?a`<craft-pane
            class="craft-link-field__pane"
            appearance="outline"
            padding="lg"
            >${e}</craft-pane
          >`:e}
      ${this.renderHiddenInputs()}
    `}createRenderRoot(){return this}};s([n({attribute:`advanced-fields`,type:Array})],f.prototype,`advancedFields`,void 0),s([n({attribute:`show-label-field`,type:Boolean})],f.prototype,`showLabelField`,void 0),s([n({type:Array})],f.prototype,`types`,void 0),s([n({attribute:`model-value`,type:Object})],f.prototype,`modelValue`,void 0),s([n()],f.prototype,`name`,void 0),s([n({reflect:!0,type:Boolean})],f.prototype,`disabled`,void 0),s([n({reflect:!0,type:Boolean})],f.prototype,`live`,void 0),s([i()],f.prototype,`defaultLabel`,void 0),s([i()],f.prototype,`label`,void 0),s([i()],f.prototype,`linkAttributes`,void 0),s([i()],f.prototype,`typeId`,void 0),s([i()],f.prototype,`value`,void 0),s([i()],f.prototype,`valueError`,void 0),f=s([o(`craft-link-field`)],f);