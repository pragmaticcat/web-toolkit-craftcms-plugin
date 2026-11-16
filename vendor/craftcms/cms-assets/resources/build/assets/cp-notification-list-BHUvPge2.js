import{n as e}from"./translate.ts-CvxvI-3D.js";import{a as t,d as n,r,u as i}from"./decorators-B_wqftM0.js";import{t as a}from"./custom-element-Bov83m9_.js";import{F as o}from"./info-icon.ts-_ZwabIo9.js";import{r as s}from"./actionClient-DlCWcHgA-CpnvcqLv.js";import"./cp-cdZ_jgzd.js";import{i as c,n as l,t as u}from"./wayfinder-Byp9MxZi.js";import"./actionClient.ts-gf4koq8m.js";import{a as d}from"./messages-Dp1S8aQI.js";import{t as f}from"./decorate-B3KLIx2E.js";import"./button.ts-DWwRQY7x.js";var p=(e,t)=>({url:p.url(e,t),method:`post`});p.definition={methods:[`post`],url:`/{cpTrigger?}/notifications/mark-read`},p.url=(e,t)=>{(typeof e==`string`||typeof e==`number`)&&(e={cpTrigger:e}),Array.isArray(e)&&(e={cpTrigger:e[0]}),e=u(e),c(e,[`cpTrigger`]);let n={cpTrigger:e?.cpTrigger??`admin`};return p.definition.url.replace(`{cpTrigger?}`,n.cpTrigger?.toString()??``).replace(/\/+$/,``)+l(t)},p.post=(e,t)=>({url:p.url(e,t),method:`post`});var m=null,h=new Set;function g(){return m??window.Craft?.notifications??[]}function _(e){e!==m&&(m=e,h.forEach(e=>e()))}function v(e){return h.add(e),()=>h.delete(e)}function y(e,t){_(g().map(n=>e.includes(n.id)?{...n,unread:t}:n))}async function b(t){let n=g().filter(e=>e.unread&&t.includes(e.id)).map(e=>e.id);if(n.length){y(n,!1);try{await s.post(p().url,{ids:n})}catch{y(n,!0),d(e(`Couldn’t mark notifications as read.`))}}}function x(e){if(new URL(e).origin!==window.location.origin){window.location.assign(e);return}window.dispatchEvent(new CustomEvent(`action:redirect`,{cancelable:!0,detail:{url:e}}))&&window.location.assign(e)}var S=class extends r{static{this.styles=n`
    :host {
      display: block;
    }

    .notification-item {
      position: relative;
      display: flex;
      gap: 0.75rem;
      padding: 1rem;
      border-block-end: 1px solid var(--c-color-neutral-border-quiet);
    }

    .notification-item--read {
      opacity: 0.7;
    }

    .notification-card-link {
      position: absolute;
      z-index: 1;
      inset: 0;
    }

    .notification-visual {
      display: flex;
      flex: none;
      align-items: center;
      justify-content: center;
      width: 2.5rem;
      height: 2.5rem;
      overflow: hidden;
      border-radius: var(--c-radius-md);
    }

    .notification-visual--icon {
      color: var(--c-text-default);
      background-color: var(--c-color-neutral-fill-quiet);
    }

    .notification-visual--icon craft-icon {
      width: 1.5rem;
      height: 1.5rem;
      font-size: 1.5rem;
    }

    .notification-visual--icon svg {
      display: block;
      width: 100%;
      height: 100%;
    }

    .notification-visual craft-thumbnail {
      --c-thumbnail-size: 100%;
    }

    .notification-visual img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .notification-content {
      min-width: 0;
      flex-grow: 1;
    }

    .notification-title-row {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .notification-title {
      min-width: 0;
      margin: 0;
      font-size: var(--c-text-lg);
      font-weight: var(--font-weight-semibold);
      line-height: 1.25;
    }

    .notification-message {
      position: relative;
      margin-block-start: 0.25rem;
      font-size: var(--c-text-base);
    }

    .notification-message a {
      position: relative;
      z-index: 2;
    }

    .notification-message p {
      margin: 0;
    }

    .notification-meta {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 0.25rem;
      margin-block-start: 0.5rem;
      color: var(--c-text-quiet);
      font-size: var(--c-text-sm);
    }

    .notification-mark-read {
      position: relative;
      z-index: 2;
      font-size: inherit;
    }

    .notification-actions {
      position: relative;
      z-index: 2;
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      margin-block-start: 0.75rem;
    }
  `}#e;connectedCallback(){super.connectedCallback(),this.#e=v(()=>this.requestUpdate())}disconnectedCallback(){super.disconnectedCallback(),this.#e?.()}async#t(e,t){let n=b(e);await this.updateComplete,t()?.focus(),await n}#n(e,t){return e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey||t.target!==``&&t.target!==`_self`}async#r(e,t){let n=t.composedPath().find(e=>e instanceof HTMLAnchorElement);if(n){if(this.#n(t,n)){e.unread&&b([e.id]);return}t.preventDefault();try{await b([e.id])}finally{this.dispatchEvent(new CustomEvent(`cp-notification-navigate`,{bubbles:!0,composed:!0,cancelable:!0,detail:{url:n.href}}))&&x(n.href)}}}#i(e){return i`
      <div
        class="notification-visual ${e.image?``:`notification-visual--icon`}"
      >
        ${e.image?i`
              <craft-thumbnail>
                <img
                  src=${e.image}
                  alt=${e.imageAlt??``}
                />
              </craft-thumbnail>
            `:e.icon?i`
                <craft-icon
                  name=${e.icon}
                  appearance="plain"
                  aria-hidden="true"
                ></craft-icon>
              `:t}
      </div>
    `}#a(n,r){return i`
      <article
        part=${r?`item first-item`:`item`}
        class="notification-item ${n.unread?``:`notification-item--read`}"
        role="listitem"
        @click=${e=>this.#r(n,e)}
      >
        ${n.url?i`
              <a
                class="notification-card-link"
                href=${n.url}
                aria-label=${n.title??e(`Open notification`)}
              ></a>
            `:t}
        ${this.#i(n)}

        <div class="notification-content">
          <div class="notification-title-row">
            ${n.title?i`<h3 class="notification-title">${n.title}</h3>`:t}
            ${n.unread?i`
                  <craft-badge-indicator
                    alt-text=${e(`Unread`)}
                  ></craft-badge-indicator>
                `:t}
          </div>

          <div class="notification-message">
            ${o(n.messageHtml)}
          </div>

          <div class="notification-meta">
            ${n.byline?i`
                  <span>${n.byline}</span>
                  <span aria-hidden="true">·</span>
                `:t}
            <time datetime=${n.createdAt}
              >${n.createdAtLabel}</time
            >
            ${n.unread?i`
                  <span aria-hidden="true">·</span>
                  <craft-button
                    class="notification-mark-read"
                    type="button"
                    size="small"
                    variant="plain"
                    flush
                    @click=${e=>{e.stopPropagation();let t=e.currentTarget.closest(`article`);this.#t([n.id],()=>t?.querySelector(`.notification-card-link`))}}
                  >
                    ${e(`Mark as read`)}
                  </craft-button>
                `:t}
          </div>

          ${n.buttons.length?i`
                <div class="notification-actions">
                  ${n.buttons.map(e=>i`
                      <craft-button
                        .href=${e.url}
                        .target=${e.target}
                        .icon=${e.icon}
                        variant=${e.variant}
                        size="small"
                      >
                        ${e.label}
                      </craft-button>
                    `)}
                </div>
              `:t}
        </div>
      </article>
    `}render(){return i`
      <div role="list">
        ${g().map((e,t)=>this.#a(e,t===0))}
      </div>
    `}};S=f([a(`cp-notification-list`)],S);export{_ as a,v as i,b as n,x as r,g as t};