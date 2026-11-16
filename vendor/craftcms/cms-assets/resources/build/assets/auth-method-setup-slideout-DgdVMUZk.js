import{n as e}from"./translate.ts-CvxvI-3D.js";import{n as t,t as n}from"./craft-global-CrhHoql6.js";import{n as r}from"./recovery-codes-setup-B_5E4E74.js";var i=t(r);Object.assign(i,{defaults:r.defaults,instances:r.instances,openPanels:r.openPanels,positionProp:r.positionProp,totalPanels:r.totalPanels,addPanel:r.addPanel,removePanel:r.removePanel,updateStyles:r.updateStyles}),n({Slideout:i});var a=class t extends r{constructor(e){super(),this.methodName=null,new.target===t&&this.initSetup(e)}initSetup(t){this.methodName=t.methodName;let n=`
<div class="slideout__body">${t.html}</div>
<div class="slideout__footer">
  <div class="flex-grow"></div>
  <div class="flex flex-nowrap">
    <craft-button type="button" class="auth-method-close-btn">${e(`Cancel`)}</craft-button>
  </div>
</div>
`;super.init(n,{containerAttributes:{id:t.containerId}});for(let t of this.container.querySelectorAll(`[id*="qr-code-wrapper"] svg`))t.setAttribute(`role`,`img`),t.setAttribute(`aria-label`,e(`QR Code`))}showSuccess(){let t=e(`{name} added successfully.`,{name:this.methodName}),n=this.container;for(let e of n.querySelectorAll(`.so-body`))e.classList.add(`auth-method-setup-success`),e.innerHTML=`
        <craft-icon name="check"></craft-icon>
        <h1 class="auth-method-setup-success-message" tabindex="-1">${t}</h1>
      `;n.querySelector(`.auth-method-setup-success-message`)?.focus();for(let t of n.querySelectorAll(`.auth-method-close-btn`))t.textContent=e(`Close`)}};export{a as t};