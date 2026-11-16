import{t as e}from"./decorate-B3KLIx2E-B3KLIx2E.js";import{a as t,d as n,n as r,r as i,u as a}from"./decorators-B_wqftM0.js";import{a as o,i as s,n as c,o as l,r as u}from"./Config.ts-CMTyoefP.js";var d=new WeakMap,f=new WeakMap,p=new WeakMap,m=new WeakMap,h=new WeakMap,g=new WeakMap,_=new WeakMap,v=new WeakMap,y=new WeakMap,b=new WeakMap,x=new WeakMap,S=new WeakMap,C=new WeakSet,w=class extends i{constructor(...e){super(...e),o(this,C),this.progress=0,this.failed=!1,this.color=`currentColor`,this.bgColor=`#a3afbb`,this.failColor=`#da5a47`,this.label=`Progress`,this.autoComplete=!1,u(this,d,null),u(this,f,0),u(this,p,0),u(this,m,0),u(this,h,0),u(this,g,0),u(this,_,null),u(this,v,null),u(this,y,0),u(this,b,null),u(this,x,0),u(this,S,!1)}connectedCallback(){super.connectedCallback(),c(S,this,window.matchMedia(`(prefers-reduced-motion: reduce)`).matches),this.hasUpdated&&l(C,this,T).call(this)}disconnectedCallback(){l(C,this,M).call(this),super.disconnectedCallback()}firstUpdated(){c(d,this,this.renderRoot.querySelector(`canvas`)),l(C,this,E).call(this),l(C,this,D).call(this)}updated(e){e.has(`progress`)?l(C,this,D).call(this):(e.has(`color`)||e.has(`bgColor`)||e.has(`failColor`)||e.has(`failed`))&&l(C,this,k).call(this)}get canvas(){return s(d,this)}get prefersReducedMotion(){return s(S,this)}runCompleteAnimation(){return new Promise(e=>{if(s(S,this)){c(g,this,1),s(d,this)&&(s(d,this).style.opacity=`0`),l(C,this,k).call(this),e();return}l(C,this,j).call(this,1,()=>{s(d,this)&&(s(d,this).style.transition=`opacity 0.4s`,s(d,this).style.opacity=`0`),setTimeout(e,400)})})}async complete(){await this.runCompleteAnimation(),this.dispatchEvent(new CustomEvent(`craft-complete`,{bubbles:!0,composed:!0}))}render(){return a`
      <canvas
        part="canvas"
        role="progressbar"
        aria-valuenow=${(this.progress>=0?this.progress:void 0)??t}
        aria-valuemin="0"
        aria-valuemax="100"
        aria-label=${this.label}
      ></canvas>
      <span class="visually-hidden">
        ${this.failed?`Failed`:this.progress<0?`Loading`:`${this.progress}%`}
      </span>
    `}};function T(){if(this.progress<0)s(b,this)===null&&l(C,this,O).call(this);else if(s(v,this)){let{targetEndPos:e,callback:t}=s(v,this);l(C,this,j).call(this,e,t)}}function E(){let e=getComputedStyle(this),t=parseFloat(e.getPropertyValue(`--_size`)),n=parseFloat(e.getPropertyValue(`--_stroke-width`)),r=window.devicePixelRatio>1?2:1;c(f,this,t*r),c(p,this,s(f,this)/2),c(h,this,n*r),c(m,this,(t/2-n/2)*r),s(d,this)&&(s(d,this).width=s(f,this),s(d,this).height=s(f,this))}function D(){if(this.progress>=0&&s(b,this)!==null&&(cancelAnimationFrame(s(b,this)),c(b,this,null),c(y,this,0)),this.progress<0){s(b,this)===null&&l(C,this,O).call(this);return}let e=this.progress/100;if(this.autoComplete&&this.progress>=100&&s(x,this)<100){c(x,this,this.progress),this.complete();return}s(x,this)>0&&this.progress>s(x,this)&&!s(S,this)?l(C,this,j).call(this,e):(c(g,this,e),l(C,this,k).call(this)),c(x,this,this.progress)}function O(){if(s(S,this)){c(g,this,.25),l(C,this,k).call(this);return}let e=()=>{c(y,this,s(y,this)+.05),c(g,this,.15+.1*Math.sin(s(y,this)*3)),l(C,this,k).call(this),c(b,this,requestAnimationFrame(e))};c(b,this,requestAnimationFrame(e))}function k(){let e=s(d,this)?.getContext(`2d`);if(e){if(e.clearRect(0,0,s(f,this),s(f,this)),this.failed){l(C,this,A).call(this,e,this.failColor,1,0);return}if(l(C,this,A).call(this,e,this.bgColor,1,0),s(g,this)>0){let t=this.progress<0?s(y,this):-Math.PI/2;l(C,this,A).call(this,e,this.color,s(g,this),t)}}}function A(e,t,n,r){e.strokeStyle=t,e.lineWidth=s(h,this),e.lineCap=`round`,e.beginPath(),e.arc(s(p,this),s(p,this),s(m,this),r,r+n*2*Math.PI),e.stroke()}function j(e,t){l(C,this,M).call(this),c(v,this,{targetEndPos:e,callback:t});let n=performance.now(),r=s(g,this),i=a=>{let o=a-n,s=Math.min(o/500,1),u=1-(1-s)**3;c(g,this,r+(e-r)*u),l(C,this,k).call(this),s<1?c(_,this,requestAnimationFrame(i)):(c(_,this,null),c(v,this,null),t?.())};c(_,this,requestAnimationFrame(i))}function M(){s(_,this)!==null&&(cancelAnimationFrame(s(_,this)),c(_,this,null)),s(b,this)!==null&&(cancelAnimationFrame(s(b,this)),c(b,this,null))}w.styles=n`
    :host {
      --_size: var(--c-progress-size, 16px);
      --_stroke-width: var(--c-progress-stroke-width, 3px);

      display: inline-block;
      position: relative;
      width: var(--_size);
      height: var(--_size);
    }

    canvas {
      position: absolute;
      inset-block-start: 0;
      inset-inline-start: 0;
      width: var(--_size);
      height: var(--_size);
    }

    .visually-hidden {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }
  `,e([r({type:Number})],w.prototype,`progress`,void 0),e([r({type:Boolean})],w.prototype,`failed`,void 0),e([r({type:String})],w.prototype,`color`,void 0),e([r({type:String,attribute:`bg-color`})],w.prototype,`bgColor`,void 0),e([r({type:String,attribute:`fail-color`})],w.prototype,`failColor`,void 0),e([r({type:String})],w.prototype,`label`,void 0),e([r({type:Boolean,attribute:`auto-complete`})],w.prototype,`autoComplete`,void 0),customElements.get(`craft-progress`)||customElements.define(`craft-progress`,w);