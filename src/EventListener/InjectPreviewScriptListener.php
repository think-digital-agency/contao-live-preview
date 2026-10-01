<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\EventListener;

use Contao\System;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * When the frontend preview iframe loads a page with ?_clp=1, injects a tiny
 * postMessage listener + highlight CSS before </body>. This enables the backend
 * JS to scroll to and briefly outline the currently edited article/element.
 *
 * Only fires for frontend HTML responses — never for backend, JSON, or assets.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -300)]
class InjectPreviewScriptListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        // Backend scope, non-HTML, or no preview marker → skip.
        if ('backend' === $request->attributes->get('_scope')) {
            return;
        }

        if (!$request->query->getBoolean('_clp')) {
            return;
        }

        $response = $event->getResponse();

        if (!str_contains((string) $response->headers->get('Content-Type', ''), 'text/html')) {
            return;
        }

        $content = $response->getContent();

        if (false === $content || !str_contains($content, '</body>')) {
            return;
        }

        $response->setContent(str_replace('</body>', $this->buildInjection() . '</body>', $content));

        // HOOK: add custom preview script injection
        if (isset($GLOBALS['TL_HOOKS']['injectPreviewScript']) && \is_array($GLOBALS['TL_HOOKS']['injectPreviewScript']))
        {
            foreach ($GLOBALS['TL_HOOKS']['injectPreviewScript'] as $callback)
            {
                $response = System::importStatic($callback[0])->{$callback[1]}($response);
            }
        }

        // no-cache: browser always revalidates before using a cached response.
        // A 304 Not Modified costs only one RTT (no body) so navigation stays snappy,
        // while stale content after saves or back-navigations within 60 s is impossible.
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->headers->remove('Pragma');
    }

    private function buildInjection(): string
    {
        // Inline to avoid extra HTTP requests. Only present when loaded inside the
        // preview iframe (?_clp=1). Handles two message types from the backend:
        //   clp:highlight — scroll to element, apply persistent blue outline + label badge
        //   clp:refresh   — fetch current page, swap article DOM node, then highlight
        $html = <<<'HTML'
<style>
.clp-pos-fix{position:relative}
.clp-sel::before{content:'';display:block;position:absolute;top:0;left:0;width:100%;height:100%;outline:2px solid #0594ff!important;outline-offset:-2px;z-index:2147483647;pointer-events:none}
.clp-sel-secondary::before{content:'';display:block;position:absolute;top:0;left:0;width:100%;height:100%;outline:2px dashed #0594ff!important;outline-offset:-2px;z-index:2147483647;pointer-events:none}
.clp-hover::before{content:'';display:block;position:absolute;top:0;left:0;width:100%;height:100%;outline:2px dashed #d946ef!important;outline-offset:-2px;z-index:2147483647;pointer-events:none}
.clp-badge,.clp-hover-badge{position:absolute;display:flex;align-items:center;gap:5px;color:#fff;font:700 11px/1 -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;padding:7px 9px 8px 10px;border-radius:3px;white-space:nowrap;transition:top .15s}
.clp-badge{background:#0594ff;z-index:2147483647}
.clp-hover-badge{background:#d946ef;z-index:2147483647}
.clp-badge-edit{all:unset;display:flex;align-items:center;cursor:pointer;opacity:.75;transition:opacity .15s;pointer-events:auto;padding:8px;margin:-8px}
.clp-badge-edit:hover{opacity:1}
.clp-badge-sep{display:inline-block;width:1px;height:12px;background:rgba(255,255,255,.3);margin:0 2px;flex-shrink:0;align-self:center}
.clp-badge-action{all:unset;display:flex;align-items:center;justify-content:center;cursor:pointer;opacity:.75;transition:opacity .15s;pointer-events:auto;padding:5px;margin:-5px -2px;line-height:1}
.clp-badge-action:hover{opacity:1}
/* Box model lines — horizontal ones span full doc width, vertical ones span full doc height */
.clp-bm-h{position:absolute;left:0;right:0;height:1px;pointer-events:none;display:none}
.clp-bm-v{position:absolute;top:0;bottom:0;width:1px;pointer-events:none;display:none}
</style>
<script>(function(){
// _el/_elCe  = data elements (carry data-contao-* attrs; used for hover exclusion + DOM swap).
// _elVis/_elCeVis = visual targets (receive outline class + badge; child of data el when col-only-child).
var _el=null,_elVis=null,_elCe=null,_elCeVis=null,_badge=null,_badgeCe=null,_gen=0;
var _articleId=null,_contentElementId=null;
var _hoverEl=null,_hoverElVis=null,_hoverBadge=null;
var _hoverParentEl=null,_hoverParentElVis=null,_hoverParentBadge=null;
var _refreshAbort=null;
// Active and hover badges share one margin (flush against the outline, no
// gap) so a badge never jumps position when the same element transitions
// between hover and active — only clpDeconflict()/clpDeconflictHover() ever
// move a badge away from this baseline, and only on an actual collision.
var _badgeMargin=0;
var _editIcon='<svg style="flex-shrink:0" width="11" height="11" viewBox="0 0 10 10" fill="none"><path d="M7 1.5l1.5 1.5-5.5 5.5H1.5V7L7 1.5z" stroke="#fff" stroke-width="1.2" stroke-linejoin="round"/><line x1="5.8" y1="2.7" x2="7.3" y2="4.2" stroke="#fff" stroke-width="1.2"/></svg>';
var _dupIcon='<svg style="flex-shrink:0" width="11" height="11" viewBox="0 0 11 11" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="3.5" y="3.5" width="6.5" height="6.5" rx=".8"/><path d="M1 7.5V1h6.5v2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
var _addIcon='<svg style="flex-shrink:0" width="11" height="11" viewBox="0 0 11 11" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="5.5" y1="1.5" x2="5.5" y2="9.5"/><line x1="1.5" y1="5.5" x2="9.5" y2="5.5"/></svg>';
/**
 * CLP_FE — frontend registry (lives inside the preview iframe). Two concerns:
 *   1. Badge actions: id-keyed set/remove/move/each, grouped into two sections:
 *      'primary' (e.g. the edit pencil) and 'secondary' (e.g. duplicate/insert).
 *      _mkBadge renders all primaries, one separator, then all secondaries.
 *      An action's section decides where it renders; the button's CSS class is
 *      purely styling (default 'clp-badge-action') and does NOT decide the section.
 *      A badge with no registered actions shows only the label — a valid state.
 *   2. Incoming message handlers (_ih): id-keyed on/off/dispatch, parallel to
 *      CLP_BE. The bundle's message listener calls CLP_FE.dispatch(e); any
 *      clp:* message with no registered handler is re-dispatched as a
 *      CustomEvent on `document` so third-party FE scripts can listen via
 *      document.addEventListener('clp:acme:foo', …). See docs/EXTENDING.md.
 */
window.CLP_FE={
  version:1,
  _a:[],
  _ih:{},
  set:function(id,provider,opts){
    opts=opts||{};
    var hasSection=opts.section!==undefined&&opts.section!==null;
    var section=hasSection&&opts.section==='primary'?'primary':'secondary';
    var hasPos=opts.position!==undefined&&opts.position!==null;
    var pos=hasPos?opts.position:'last';
    var i=this._a.findIndex(function(x){return x.id===id;});
    var rec={id:id,provider:provider,section:section,pos:pos,prev:null};
    if(i>=0){
      // store action as prev for chaining/fallback (preserved across multiple overrides)
      rec.prev=this._a[i];
      // use existing section/position (unless set from options)
      if (!hasPos){rec.pos=rec.prev.pos;}
      if (!hasSection){rec.section=rec.prev.section;}
      if(hasPos||hasSection){
        // remove existing action from list (will be added at new position/section)
        this._a.splice(i,1);
      }else{
        // replace existing action with new one (no position change)
        this._a[i]=rec;
        return this;
      }
    }
    // add new action to list
    this._a.push(rec);
    return this;
  },
  remove:function(id,provider){
    if (!provider) {
      // remove action from ALL elements
      this._a=this._a.filter(function(x){return x.id!==id;});
    } else {
      // add removeProvider to action
      var i=this._a.findIndex(function(x){return x.id===id;});
      if(i>=0){
        this._a[i].removeProvider=provider;
      }
    }
    return this;
  },
  move:function(id,pos){
    var i=this._a.findIndex(function(x){return x.id===id;});
    if(i<0)return this;
    var rec=this._a.splice(i,1)[0];
    rec.pos=(pos!==undefined&&pos!==null)?pos:'last';
    this._a.push(rec);
    return this;
  },
  each:function(ctx,post){
    var ui=this._ui(post,ctx);
    var out=[];
    for(var i=0;i<this._a.length;i++){
      var r=this._run(this._a[i],ctx,post,ui);
      if(r)out.push({id:this._a[i].id,section:this._a[i].section,pos:this._a[i].pos,button:r});
    }
    return out;
  },
  // Run a record's provider; on null/undefined fall back to .prev (the chained
  // previous provider, which may itself have a .prev). Throwing → null → fall back.
  // If the current record has a removeProvider that returns `true`, do
  // not fall back but directly return null.
  _run:function(rec,ctx,post,ui){
    var cur=rec;
    while(cur){
      var r=null;
      if (cur.removeProvider) {
        try{r=cur.removeProvider(ctx,post,ui);}catch(e){r=null;}
        if(r)return null;
      }
      try{r=cur.provider(ctx,post,ui);}catch(e){r=null;}
      if(r)return r;
      cur=cur.prev;
    }
    return null;
  },
  post:function(msg){window.parent.postMessage(Object.assign({version:1},msg),'*');},
  // ui.button(opts) — builds a styled button/<a> so providers don't hand-roll markup.
  // opts: { icon, title?, class?, postOptions?, href?, callback? } — icon is required.
  //   icon     → HTML string (intended to be an icon: svg/img/emoji). Becomes innerHTML.
  //   title    → the title attribute (tooltip); optional.
  //   class    → extra CSS class APPENDED to the base ('clp-badge-action' by default,
  //              'clp-badge-edit' for the primary style). Purely styling — does NOT
  //              decide the badge section (that's the action's section at registration).
  //   postOptions → message payload posted on click; post() stamps version:1.
  //   href     → if set, renders an <a href> instead of <button> for a link preview.
  //              Cosmetic — click preventDefaults + posts when postOptions is set;
  //              pure link when only href. Not for core edit/duplicate/insert-after.
  //   callback → function(ev, ctx) run on click BEFORE postOptions. Always gets
  //              stopPropagation + (for links) preventDefault so the click doesn't
  //              navigate/bubble. Use for FE-only behaviour (modal, toggle) without a
  //              BE round-trip; combine with postOptions to also post afterwards.
  _ui:function(post,ctx){
    return {
      button:function(opts){
        opts=opts||{};
        if(opts.icon===undefined||opts.icon==='')throw new Error('CLP_FE.button: icon required');
        var isLink=opts.href!==undefined&&opts.href!=='';
        var el=document.createElement(isLink?'a':'button');
        if(!isLink)el.type='button';
        // Base class is always set (consistent layout); opts.class is appended.
        var base=opts.class==='clp-badge-edit'?'clp-badge-edit':'clp-badge-action';
        el.className=base+(opts.class&&opts.class!==base?(' '+opts.class):'');
        if(opts.title)el.title=opts.title;
        el.innerHTML=opts.icon;
        if(isLink)el.href=opts.href;
        el.addEventListener('click', function(ev){
          ev.stopPropagation();
          if (typeof opts.callback === 'function') {
            if(isLink)ev.preventDefault();
            opts.callback(ev, ctx);
          }
          if(opts.postOptions){
            if(isLink)ev.preventDefault();
            post(opts.postOptions);
          }
        });
        return el;
      }
    };
  },
  on:function(type,fn,id){((this._ih[type]||(this._ih[type]=new Map())).set(id||type,fn));return this;},
  off:function(type,id){if(this._ih[type])this._ih[type].delete(id||type);return this;},
  // Dispatch an incoming message to registered handlers. Returns true if a
  // handler ran, false otherwise (used by the listener to decide CustomEvent
  // re-dispatch for unrecognised clp:* types). Throwing handlers are skipped.
  dispatch:function(e){
    var d=e.data;if(!d||typeof d.type!=='string')return false;
    var map=this._ih[d.type];if(!map||map.size===0)return false;
    map.forEach(function(fn){try{fn(d,e);}catch(_){}});return true;
  }
};
function _clpSep(){var s=document.createElement('span');s.className='clp-badge-sep';return s;}
function findEl(sels){var r=null;for(var i=0;i<sels.length;i++){r=document.querySelector(sels[i]);if(r)break;}return r;}
// When el is a single-child grid column wrapper (col-*), return the child as the visual target.
// The data element (el) is kept for DOM queries; only the outline and badge move to the child.
function clpVisTarget(el){var cc=String(el.className||'').split(/\s+/);for(var i=0;i<cc.length;i++){if(cc[i].indexOf('col-')===0){if(el.children.length===1)return el.children[0];break;}}return el;}
// The ::before outline overlay is positioned absolute against its parent, which
// needs any non-static position to act as its containing block. Forcing
// position:relative unconditionally would override position:fixed (headers,
// offbars, …), pulling them out of their fixed stacking context and into the
// document flow — causing the element to jump under the cursor on hover. Only
// elements that are actually position:static (the common case) need the fix.
function clpVisClassAdd(el,cls){el.classList.add(cls);if(getComputedStyle(el).position==='static')el.classList.add('clp-pos-fix');}
function clpVisClassRemove(el,cls){el.classList.remove(cls);if(!el.classList.contains('clp-sel')&&!el.classList.contains('clp-sel-secondary')&&!el.classList.contains('clp-hover'))el.classList.remove('clp-pos-fix');}
// Split so clp:highlight can clear just the CE half when switching between
// two content elements in the same article — see clp:highlight below for why.
function clpClearPrimary(){if(_elVis){clpVisClassRemove(_elVis,'clp-sel');clpVisClassRemove(_elVis,'clp-sel-secondary');_elVis=null;}_el=null;if(_badge){_badge.remove();_badge=null;}}
function clpClearCe(){if(_elCeVis){clpVisClassRemove(_elCeVis,'clp-sel');_elCeVis=null;}_elCe=null;if(_badgeCe){_badgeCe.remove();_badgeCe=null;}}
function clpClear(){clpClearPrimary();clpClearCe();}
// Clears both the primary hover target and the parent-boost target (see
// mouseover below) unconditionally — on every call, not just when one of them
// happens to be set. A previous attempt at boosting the parent's hover state
// (ADR-022 point 6) left its badge on screen in exactly the case where this
// clear was conditional; unconditional removal here is the fix.
function clpHoverClear(){
  if(_hoverElVis){clpVisClassRemove(_hoverElVis,'clp-hover');_hoverElVis=null;}_hoverEl=null;
  if(_hoverBadge){_hoverBadge.remove();_hoverBadge=null;}
  if(_hoverParentElVis){clpVisClassRemove(_hoverParentElVis,'clp-hover');_hoverParentElVis=null;}_hoverParentEl=null;
  if(_hoverParentBadge){_hoverParentBadge.remove();_hoverParentBadge=null;}
}
function clpIsFixed(el){var n=el;while(n&&n!==document.body){if(getComputedStyle(n).position==='fixed')return true;n=n.parentElement;}return false;}
// Container badges (article, or a group-type CE with marked children of its own —
// Accordion, Card, Tabs, Elementgruppe, …) always render OUTSIDE/above their box,
// even when the box is tall enough to hold them — a badge sitting inside a
// container's top-left corner reads as belonging to whatever is rendered right
// there (its first child), not to the container itself. Leaf badges (a plain CE,
// or a tl_news record) always render INSIDE/top-left, even when the box is
// shorter than the badge (was: rendered above in that case — now: always inside,
// by design; small elements simply get an overflowing badge). This fixed rule
// replaces a per-badge "too short → above" fallback that, combined with
// clpDeconflict()'s reactive overlap push, produced inconsistent stacking when a
// container had little/no top padding. The outside/inside decision is
// precomputed by _mkBadge() into b.dataset.clpOutside at creation time.
function clpBadgePos(b,el){
  var r=el.getBoundingClientRect();
  var bh=b.offsetHeight||24;
  var m=_badgeMargin;
  var above=b.dataset.clpOutside==='1';
  if(clpIsFixed(el)){
    b.style.position='fixed';
    b.style.top=(above?Math.max(m,r.top-bh-m):r.top+m)+'px';
    b.style.left=(r.left+m)+'px';
  }else{
    b.style.position='';
    var t=window.scrollY+r.top;
    b.style.top=(above?Math.max(window.scrollY+m,t-bh-m):t+m)+'px';
    b.style.left=(window.scrollX+r.left+m)+'px';
  }
}
function _rectsOverlap(a,c){return !(a.right<=c.left||c.right<=a.left||a.bottom<=c.top||c.bottom<=a.top);}
// Dual-highlight mode stacks the content-element badge and the article badge on
// the same top-left corner whenever the article/group has no own padding. Push
// the (secondary) article badge above the CE badge so both stay readable.
function clpDeconflict(){
  if(!_badge||!_badgeCe)return;
  if(_rectsOverlap(_badge.getBoundingClientRect(),_badgeCe.getBoundingClientRect())){
    _badge.style.top=((parseFloat(_badge.style.top)||0)-_badgeCe.offsetHeight)+'px';
  }
}
function _deconflictOne(active,hover){
  if(active&&hover&&_rectsOverlap(active.getBoundingClientRect(),hover.getBoundingClientRect())){
    active.style.top=((parseFloat(active.style.top)||0)-hover.offsetHeight)+'px';
  }
}
function clpDeconflictHover(){
  _deconflictOne(_badge,_hoverBadge);
  _deconflictOne(_badgeCe,_hoverBadge);
  _deconflictOne(_badge,_hoverParentBadge);
  _deconflictOne(_badgeCe,_hoverParentBadge);
}
// _mkBadge renders the label, then primary actions, one separator, then
// secondary actions. ctx exposes the data the bundle already has at the call
// site so providers can gate by element kind without patching this script.
// ceType is the stable CE type key from the data attribute (NOT the translated label);
// null for non-CE tables.
function _sortButtons(buttons){
  var out={primary:[],secondary:[]};
  for(var i=0;i<buttons.length;i++){
    const rec = buttons[i];
    const pos = (rec.pos !== undefined && rec.pos !== null) ? rec.pos : 'last';
    let target = out[rec.section==='primary'?'primary':'secondary'];
    if(pos==='first'){
        target.unshift(rec);
        continue;
    }
    if(pos==='last'){
        target.push(rec);
        continue;
    }
    if(typeof pos==='number'){
        const numPos=Math.max(0,Math.min(pos,target.length));
        target.splice(numPos, 0, rec);
        continue;
    }
    const anchor=pos.before||pos.after;
    if (anchor) {
        let anchorRec = null;
        // Find the anchor record from original buttons array.
        for (let k = 0; k < buttons.length; k++) {
            if (buttons[k].id === anchor) {
                anchorRec = buttons[k];
                break;
            }
        }
        if (!anchorRec) {
            target.push(rec);
            continue;
        }
        target = out[anchorRec.section];
        let ai = -1;
        // Find the anchor index in the sorted array.
        for(let k = 0; k < target.length; k++) {
            if (target[k].id === anchor) {
                ai = k;
                break;
            }
        }
        if (ai < 0) {
            target.push(rec);
            continue;
        }
        rec.section = anchorRec.section;
        target.splice((pos.after?ai+1:ai), 0, rec);
        continue;
    }
    target.push(rec);
  }
  return {
      primaries: out.primary.map(function(b){return b.button;}),
      secondaries: out.secondary.map(function(b){return b.button;})
  };
}
function _mkBadge(cls,lbl,table,editId,parentTable,el){
  var b=document.createElement('div');b.className=cls;
  var s=document.createElement('span');s.textContent=lbl;b.appendChild(s);
  var vis=el?clpVisTarget(el):null;
  var ceType=(el&&table==='tl_content')?getCeType(el):null;
  var ctx={table:table,id:editId||0,parentTable:parentTable||'',el:el||null,vis:vis,ceType:ceType,ceLabel:el&&ceType?getCeLabel(el):null};
  // Container badge (outside/above) vs leaf badge (inside/top-left) — see clpBadgePos().
  // tl_article is always a container. tl_content is a container only when it has a
  // marked descendant of its own (a group/Accordion/Card/Tabs CE nesting other CEs);
  // a plain leaf CE has none. tl_news (and anything else) is always a leaf, even
  // when — on a news reader page — its own content elements happen to be marked.
  var outside=table==='tl_article'||(table==='tl_content'&&!!(el&&el.querySelector('[data-contao-table]')));
  b.dataset.clpOutside=outside?'1':'';
  // each() returns {section,button} pairs in registration order. Split into the two
  // sections, render primaries, one separator (only if secondaries exist), then secondaries.
  var {primaries,secondaries}=_sortButtons(CLP_FE.each(ctx,CLP_FE.post.bind(CLP_FE)));
  for(var p=0;p<primaries.length;p++)b.appendChild(primaries[p]);
  if(secondaries.length)b.appendChild(_clpSep());
  for(var q=0;q<secondaries.length;q++) {
      b.appendChild(secondaries[q]);
  }
  document.body.appendChild(b);return b;
}
function makeBadge(lbl,t,id,pt,el){return _mkBadge('clp-badge',lbl,t,id,pt,el);}
function makeHoverBadge(lbl,t,id,pt,el){return _mkBadge('clp-hover-badge',lbl,t,id,pt,el);}
// --- Core badge actions (registered once, replaceable/removable by id) ---
// clp:edit is the primary action; clp:duplicate / clp:insert-after are secondary.
CLP_FE.set('clp:edit',function(ctx,post,ui){
  if(!ctx.table||!ctx.id)return null;
  return ui.button({icon:_editIcon,class:'clp-badge-edit',title:'Element bearbeiten',postOptions:{type:'clp:edit',table:ctx.table,id:ctx.id,parentTable:ctx.parentTable||''}});
},{section:'primary'});
CLP_FE.set('clp:duplicate',function(ctx,post,ui){
  if(ctx.table!=='tl_content'||!ctx.id)return null;
  return ui.button({icon:_dupIcon,title:'Element duplizieren',postOptions:{type:'clp:duplicate',id:ctx.id,parentTable:ctx.parentTable||''}});
});
CLP_FE.set('clp:insert-after',function(ctx,post,ui){
  if(ctx.table!=='tl_content'||!ctx.id)return null;
  return ui.button({icon:_addIcon,title:'Neues Element danach',postOptions:{type:'clp:insert-after',id:ctx.id,parentTable:ctx.parentTable||''}});
});
function getCeLabel(el){if(el.dataset&&el.dataset.contaoLabel&&el.dataset.contaoLabel!==''){return el.dataset.contaoLabel.toUpperCase();}var cc=String(el.className||'').split(/\s+/);for(var i=0;i<cc.length;i++){if(cc[i].indexOf('ce_')===0){return cc[i].slice(3).replace(/([a-z])([A-Z])/g,'$1 $2').replace(/_/g,' ').toUpperCase();}if(cc[i].indexOf('content-')===0&&cc[i]!=='content-'){return cc[i].slice(8).replace(/-/g,' ').toUpperCase();}}return 'INHALTSELEMENT';}
// Stable CE type key (e.g. 'image','text','accordion') from the data attribute — NOT the
// translated label. Used for ctx.ceType so providers can gate by element type
// regardless of the backend language. Returns null when the attribute is missing.
function getCeType(el){return (el.dataset&&el.dataset.contaoType&&el.dataset.contaoType!=='') ? el.dataset.contaoType : null;}
// Nearest marker ancestor (excluding el itself) decides the ptable: tl_news
// wrapper for news CEs, the group CE wrapper for element-group children,
// the article wrapper for top-level CEs. Consumers only test tl_news /
// tl_content; everything else falls back to the DCA default.
function getCeParentTable(el){
  var p=el.parentElement;
  var m=p&&p.closest('[data-contao-table]');
  return m?m.dataset.contaoTable:'';
}
function clpReposAll(){if(_badge&&_elVis)clpBadgePos(_badge,_elVis);if(_badgeCe&&_elCeVis)clpBadgePos(_badgeCe,_elCeVis);if(_hoverBadge&&_hoverElVis)clpBadgePos(_hoverBadge,_hoverElVis);if(_hoverParentBadge&&_hoverParentElVis)clpBadgePos(_hoverParentBadge,_hoverParentElVis);clpDeconflict();clpDeconflictHover();}
window.addEventListener('resize',clpReposAll,{passive:true});
// Runs fn once el's geometry is unlikely to still be settling, not just once
// the page has "loaded". A fresh ?_clp=1 load delivers clp:highlight as soon
// as this injected script's own message listener is registered;
// `document.readyState==='complete'` can already be true at that point while
// an openOnLoad accordion panel (or any other JS-driven expand/collapse,
// carousel init, …) hasn't started its transition yet — measured directly,
// across repeated runs: its target element's rect read as a flat 0 for
// 224–474ms after the highlight message arrived (the spread itself says this
// isn't on a fixed schedule — font/resource loading, a staggered animation
// delay, whatever it is, varies run to run), then jumped straight to its
// final value and never changed again (no gradual animation to detect, no
// further settling to wait out). That rules out "stop once the rect holds
// steady across two frames" as a detector on its own — the pre-transition 0
// reads just as steady as the post-transition real one, so a naive stability
// check fires during the wrong plateau; a 400ms baseline tried first still
// landed inside the slower end of that measured spread and reproduced the
// bug. There's no generic signal for "a delayed component init is about to
// run" to poll for instead, so this pays a fixed baseline with real margin
// over the slower measurement (900ms, not 400) and only then starts the
// cheap two-frame stability check — by which point the kind of delayed init
// measured above has already happened, so the plateau the check finds is the
// real one. The stability check's own budget caps the total wait for content
// that keeps animating indefinitely.
//
// That baseline only matters for the race it was measured against: a fresh
// ?_clp=1 document whose own load-triggered JS hasn't run yet. Once this
// script has waited it out once, the page is settled for as long as this
// same document stays loaded — a later clp:highlight on the same page (e.g.
// switching from one content element's badge to another's within the same
// article, which doesn't reload the iframe) has nothing left to race and
// paying 900ms again each time reads as the UI stalling, not settling. Found
// exactly that way: switching between two CEs made the article's own badge
// (whose target hadn't moved and didn't need the wait at all) flash away and
// back, because every clp:highlight call — not just the first — went through
// the same fixed delay regardless of whether anything was actually settling.
var _clpSettledOnce=false;
function clpWhenSettled(el,fn){
  if(_clpSettledOnce){requestAnimationFrame(fn);return;}
  function afterBaseline(){
    var tries=0,maxTries=48,last=null;
    function check(){
      var r=el.getBoundingClientRect();
      var sig=r.top+'|'+r.left+'|'+r.width+'|'+r.height;
      if(sig===last||++tries>=maxTries){_clpSettledOnce=true;fn();return;}
      last=sig;
      requestAnimationFrame(check);
    }
    requestAnimationFrame(check);
  }
  function go(){setTimeout(afterBaseline,900);}
  if(document.readyState==='complete')go();else window.addEventListener('load',go,{once:true});
}
function highlight(el,bh,label,table,editId){
  clpClear();_gen++;var myGen=_gen;
  var vis=clpVisTarget(el);
  clpWhenSettled(vis,function(){
    if(_gen!==myGen)return;
    var rect=vis.getBoundingClientRect();
    var targetY=window.scrollY+rect.top-(window.innerHeight-rect.height)/2;
    window.scrollTo({top:Math.max(0,targetY),left:0,behavior:bh||'smooth'});
    function apply(){if(_gen!==myGen)return;_el=el;_elVis=vis;clpVisClassAdd(vis,'clp-sel');if(label){_badge=makeBadge(label,table,editId,getCeParentTable(el),el);clpBadgePos(_badge,vis);}}
    if((bh||'smooth')==='instant'){apply();}
    else{var t;function hl(){clearTimeout(t);window.removeEventListener('scrollend',hl);apply();}if('onscrollend'in window)window.addEventListener('scrollend',hl,{once:true});t=setTimeout(hl,800);}
  });
}
// Incoming messages from the parent are dispatched through CLP_FE so third
// parties can register/override handlers without patching this script. Any
// clp:* message with no registered handler is re-dispatched as a CustomEvent
// on document (see listener below). Core handlers are registered after this.
window.addEventListener('message',function(e){
  var handled=CLP_FE.dispatch(e);
  if(handled)return;
  // Unrecognised clp:* message → re-dispatch as a CustomEvent so third-party
  // FE scripts can document.addEventListener('clp:acme:foo', …) without adding
  // their own message listener. detail is the full message payload.
  var d=e.data;
  if(d&&typeof d.type==='string'&&d.type.indexOf('clp:')===0){
    try{document.dispatchEvent(new CustomEvent(d.type,{detail:d}));}catch(_){}
  }
});

// --- Core incoming-message handlers (registered once, replaceable by id) ---

// clp:highlight — scroll to and outline the edited article/CE, render badges.
CLP_FE.on('clp:highlight',function(d){
  _articleId=d.articleId||null;
  _contentElementId=d.contentElementId||null;
  var el=findEl(d.selectors||[]);
  var aEl=findEl(d.articleSelectors||[]);
  if(el&&aEl&&el!==aEl){
    var elVis=clpVisTarget(el);var aElVis=clpVisTarget(aEl);
    // Switching from one CE to another inside the same article (edit badge,
    // or re-selecting a different element) must not touch the article's own
    // badge/outline at all — it didn't change. Clearing and recreating it
    // unconditionally made it visibly flash away and back on every such
    // switch, since its removal (clpClear) and its recreation (inside
    // clpWhenSettled below) are no longer the same synchronous step once
    // clpWhenSettled can defer. Only the CE half is ever cleared here now;
    // the article half is touched only when it has actually changed.
    var articleUnchanged=(_el===aEl&&_elVis===aElVis&&!!_badge);
    clpClearCe();_gen++;var myGen=_gen;
    if(!articleUnchanged){clpClearPrimary();_el=aEl;_elVis=aElVis;clpVisClassAdd(aElVis,'clp-sel-secondary');}
    _elCe=el;_elCeVis=elVis;clpVisClassAdd(elVis,'clp-sel');
    clpWhenSettled(elVis,function(){
      if(_gen!==myGen)return;
      var rect=elVis.getBoundingClientRect();
      window.scrollTo({top:Math.max(0,window.scrollY+rect.top-(window.innerHeight-rect.height)/2),left:0,behavior:d.scrollBehavior||'instant'});
      // Prefer data-contao-label from the DOM — set by InjectContentElementMarkersListener
      // in fully-bootstrapped frontend context, so language files are always complete.
      var lbl=getCeLabel(el)||d.label||'';if(lbl){_badgeCe=makeBadge(lbl,'tl_content',_contentElementId,getCeParentTable(el),el);clpBadgePos(_badgeCe,elVis);}
      if(!articleUnchanged){
        var albl=d.articleLabel||'';if(albl){_badge=makeBadge(albl,'tl_article',_articleId,'',aEl);_badge.style.zIndex='2147483646';clpBadgePos(_badge,aElVis);}
      }
      clpDeconflict();
    });
  }else if(el||aEl){
    var isCe=!!_contentElementId;
    var isNews=!isCe&&!!d.newsId;
    var target=el||aEl;
    var lbl2=isCe?(getCeLabel(target)||d.label||''):(d.label||'');
    highlight(target,d.scrollBehavior,lbl2,isCe?'tl_content':(isNews?'tl_news':'tl_article'),isCe?_contentElementId:(isNews?d.newsId:_articleId));
  }
});

// clp:refresh — partial DOM swap of the article node from a fresh fetch of the
// current page URL. Preserves scroll position, posts clp:refreshed on completion.
CLP_FE.on('clp:refresh',function(d){
  var articleId=d.articleId;var selectors=d.selectors||[];var label=d.label||'';
  var scrollX=window.scrollX,scrollY=window.scrollY;
  if(_refreshAbort){_refreshAbort.abort();}
  _refreshAbort=('AbortController'in window)?new AbortController():null;
  var fetchOpts={credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}};
  if(_refreshAbort){fetchOpts.signal=_refreshAbort.signal;}
  fetch(window.location.href,fetchOpts)
    .then(function(r){return r.text();})
    .then(function(html){
      _refreshAbort=null;
      var doc=new DOMParser().parseFromString(html,'text/html');
      var fresh=null,live=null;
      for(var i=0;i<selectors.length;i++){var f=doc.querySelector(selectors[i]);var l=document.querySelector(selectors[i]);if(f&&l){fresh=f;live=l;break;}}
      if(fresh&&live){for(var ai=0;ai<fresh.attributes.length;ai++){live.setAttribute(fresh.attributes[ai].name,fresh.attributes[ai].value);}live.innerHTML=fresh.innerHTML;var el=findEl(selectors);if(el){var vis=clpVisTarget(el);clpClear();_el=el;_elVis=vis;clpVisClassAdd(vis,'clp-sel');if(label){_badge=makeBadge(label,'tl_article',_articleId,'',el);clpBadgePos(_badge,vis);}}}
      window.parent.postMessage({version:1,type:'clp:refreshed',articleId:articleId},'*');
    })
    .catch(function(err){
      if(err&&err.name==='AbortError'){return;}
      _refreshAbort=null;
      window.parent.postMessage({version:1,type:'clp:refreshed',articleId:articleId},'*');
    });
});

// clp:grid — toggle the box-model overlay (margin/border/padding/content lines)
// and persist the on/off state across reloads.
CLP_FE.on('clp:grid',function(d){
  document.body.classList.toggle('clp-grid-on',!!d.on);
  if(!d.on&&typeof _bmHide==='function')_bmHide();
  try{localStorage.setItem('clp_grid_overlay',d.on?'1':'0');}catch(_){}
});
// Hover: fuchsia dashed outline + badge for any article/CE on the page.
// _hoverEl = data element (for exclusion check + mouseout boundary).
// _hoverElVis = visual target (receives outline class and badge position).
//
// Parent boost: hovering a CE nested directly in a group or article also
// shows the group's/article's own hover outline+badge (_hoverParentEl/*) —
// a leaf CE usually fills its container's entire box, leaving no room to
// hover the container itself otherwise. The nearest marked ancestor of any
// CE is structurally always a group CE or an article (nothing else carries
// a data-contao-table marker), so this needs no table/type check of its own.
// Active always wins over hover, independently for each of the two targets:
// a parent that is itself the active element is not also given a hover
// badge just because its child is being hovered.
document.addEventListener('mouseover',function(e){
  if(e.target.closest&&e.target.closest('.clp-badge,.clp-hover-badge'))return;
  var el=e.target.closest?e.target.closest('[data-contao-table]'):null;
  if(!el){clpHoverClear();return;}
  if(el===_hoverEl)return;
  clpHoverClear();
  if(el===_el||el===_elCe)return;
  var table=el.dataset.contaoTable;
  var id=parseInt(el.dataset.contaoId,10)||0;
  if(!table||!id)return;
  var lbl=table==='tl_article'?'ARTIKEL':getCeLabel(el);
  var vis=clpVisTarget(el);
  _hoverEl=el;_hoverElVis=vis;
  clpVisClassAdd(vis,'clp-hover');
  _hoverBadge=makeHoverBadge(lbl,table,id,getCeParentTable(el),el);
  clpBadgePos(_hoverBadge,vis);
  var parent=el.parentElement&&el.parentElement.closest?el.parentElement.closest('[data-contao-table]'):null;
  if(parent&&parent!==_el&&parent!==_elCe){
    var pTable=parent.dataset.contaoTable;
    var pId=parseInt(parent.dataset.contaoId,10)||0;
    if(pTable&&pId){
      var pLbl=pTable==='tl_article'?'ARTIKEL':getCeLabel(parent);
      var pVis=clpVisTarget(parent);
      _hoverParentEl=parent;_hoverParentElVis=pVis;
      clpVisClassAdd(pVis,'clp-hover');
      _hoverParentBadge=makeHoverBadge(pLbl,pTable,pId,getCeParentTable(parent),parent);
      clpBadgePos(_hoverParentBadge,pVis);
    }
  }
  clpDeconflictHover();
});
// mouseout: the boundary is the outermost hovered element — the parent when
// a parent boost is active (it structurally contains the child), otherwise
// the primary hover target. Covers both the col-* wrapper and its single
// child — don't clear until the cursor truly leaves that boundary (or moves
// to one of the badges, for edit-icon clicks).
document.addEventListener('mouseout',function(e){
  if(!_hoverEl)return;
  var rel=e.relatedTarget;
  var boundary=_hoverParentEl||_hoverEl;
  if(rel&&(rel===boundary||boundary.contains(rel)))return;
  if(_hoverBadge&&rel&&(rel===_hoverBadge||_hoverBadge.contains(rel)))return;
  if(_hoverParentBadge&&rel&&(rel===_hoverParentBadge||_hoverParentBadge.contains(rel)))return;
  clpHoverClear();
  clpReposAll();
});
// --- Box-model line overlay (active only when body.clp-grid-on) ---
// 32 persistent 1px divs: 16 horizontal (full doc width) + 16 vertical (full doc height).
// 4 layers × 4 sides × 2 orientations = 32. Colors: margin=amber, border=yellow,
// padding=green, content=blue (Chrome DevTools palette).
// Lines at identical positions are deduplicated (e.g. when margin=0).
var _bmLines=[];
var _bmEl=null;
var _bmColors={margin:'rgba(246,140,60,.9)',border:'rgba(235,195,60,.9)',padding:'rgba(73,185,100,.9)',content:'rgba(66,135,245,.9)'};
var _bmLayers=['margin','border','padding','content'];
// Create 16 h-lines + 16 v-lines (4 layers × 4 sides each)
(function(){
  var zBase=2147483640;
  for(var li=0;li<_bmLayers.length;li++){
    var col=_bmColors[_bmLayers[li]];
    for(var si=0;si<4;si++){
      // horizontal line
      var h=document.createElement('div');
      h.className='clp-bm-h';
      h.setAttribute('data-clp-bm','1');
      h.style.cssText='position:absolute;left:0;right:0;height:1px;pointer-events:none;display:none;z-index:'+(zBase+li)+';background:'+col;
      document.body.appendChild(h);
      _bmLines.push({el:h,axis:'h',layer:_bmLayers[li],side:si});
      // vertical line
      var v=document.createElement('div');
      v.className='clp-bm-v';
      v.setAttribute('data-clp-bm','1');
      v.style.cssText='position:absolute;top:0;bottom:0;width:1px;pointer-events:none;display:none;z-index:'+(zBase+li)+';background:'+col;
      document.body.appendChild(v);
      _bmLines.push({el:v,axis:'v',layer:_bmLayers[li],side:si});
    }
  }
})();
function _bmHide(){for(var i=0;i<_bmLines.length;i++)_bmLines[i].el.style.display='none';_bmEl=null;}
function _bmCalcPositions(el){
  var cs=getComputedStyle(el);
  var r=el.getBoundingClientRect();
  var isFixed=(cs.position==='fixed');
  var sx=isFixed?0:window.scrollX,sy=isFixed?0:window.scrollY;
  var mt=parseFloat(cs.marginTop)||0,mr=parseFloat(cs.marginRight)||0,
      mb=parseFloat(cs.marginBottom)||0,ml=parseFloat(cs.marginLeft)||0;
  var bt=parseFloat(cs.borderTopWidth)||0,brw=parseFloat(cs.borderRightWidth)||0,
      bb=parseFloat(cs.borderBottomWidth)||0,blw=parseFloat(cs.borderLeftWidth)||0;
  var pt=parseFloat(cs.paddingTop)||0,prw=parseFloat(cs.paddingRight)||0,
      pb=parseFloat(cs.paddingBottom)||0,pl=parseFloat(cs.paddingLeft)||0;
  var L=r.left+sx,T=r.top+sy,R=r.right+sx,B=r.bottom+sy;
  // y positions for each layer's top and bottom line, x positions for left and right
  // side 0=top/left, 1=bottom/right for h/v respectively (reuse 4 slots per layer)
  return {
    isFixed:isFixed,
    // [layer][top-y, bottom-y, left-x, right-x]
    margin:  [T-mt,   B+mb,   L-ml,   R+mr  ],
    border:  [T,      B,      L,      R      ],
    padding: [T+bt,   B-bb,   L+blw,  R-brw  ],
    content: [T+bt+pt,B-bb-pb,L+blw+pl,R-brw-prw]
  };
}
function _bmApply(el){
  var skip=['HTML','BODY','SCRIPT','STYLE','HEAD','NOSCRIPT','SVG','PATH'];
  if(skip.indexOf(el.tagName)!==-1){_bmHide();_bmEl=null;return;}
  var cs=getComputedStyle(el);
  if(cs.display==='none'||cs.display==='contents'||cs.display==='inline'||cs.visibility==='hidden'){_bmHide();_bmEl=null;return;}
  _bmEl=el;
  var pos=_bmCalcPositions(el);
  var posStr=pos.isFixed?'fixed':'absolute';
  // For each of the 32 lines, compute its pixel position and show/hide
  // We iterate _bmLines in order: for each layer, we have 4 h and 4 v lines
  // h lines use side 0,1,2,3 → top, bottom (sides 0+1 are meaningful; 2+3 are spares, hide)
  // v lines use side 0,1,2,3 → left, right (sides 0+1 meaningful; 2+3 hide)
  // Layout of _bmLines: margin-h0,margin-v0,margin-h1,margin-v1,...
  // Reorganise: per layer we have 8 lines (h0,v0,h1,v1,h2,v2,h3,v3)
  var shown={};// dedup by rounded position
  var idx=0;
  for(var li=0;li<_bmLayers.length;li++){
    var layer=_bmLayers[li];
    var coords=pos[layer];// [top-y, bottom-y, left-x, right-x]
    // h0 = top line, h1 = bottom line, h2,h3 unused
    // v0 = left line, v1 = right line, v2,v3 unused
    var hYs=[coords[0],coords[1],null,null];
    var vXs=[coords[2],coords[3],null,null];
    for(var si=0;si<4;si++){
      var hLine=_bmLines[idx++];
      var vLine=_bmLines[idx++];
      // horizontal
      var hy=hYs[si];
      if(hy!==null){
        var hKey='h'+Math.round(hy);
        if(!shown[hKey]){
          shown[hKey]=true;
          hLine.el.style.cssText='position:'+posStr+';left:0;right:0;height:1px;pointer-events:none;display:block;z-index:'+hLine.el.style.zIndex+';background:'+hLine.el.style.background+';top:'+hy+'px';
        }else{hLine.el.style.display='none';}
      }else{hLine.el.style.display='none';}
      // vertical
      var vx=vXs[si];
      if(vx!==null){
        var vKey='v'+Math.round(vx);
        if(!shown[vKey]){
          shown[vKey]=true;
          vLine.el.style.cssText='position:'+posStr+';top:0;bottom:0;width:1px;pointer-events:none;display:block;z-index:'+vLine.el.style.zIndex+';background:'+vLine.el.style.background+';left:'+vx+'px';
        }else{vLine.el.style.display='none';}
      }else{vLine.el.style.display='none';}
    }
  }
}
document.addEventListener('mouseover',function(e){
  if(!document.body.classList.contains('clp-grid-on'))return;
  var el=e.target;
  if(!el||el.getAttribute&&el.getAttribute('data-clp-bm'))return;
  if(el===_bmEl)return;
  _bmApply(el);
},true);
document.addEventListener('mouseout',function(e){
  if(!document.body.classList.contains('clp-grid-on'))return;
  var rel=e.relatedTarget;
  if(!rel||rel===document.documentElement){_bmHide();return;}
  if(rel.getAttribute&&rel.getAttribute('data-clp-bm'))return;
  if(e.target&&e.target.contains&&e.target.contains(rel))return;
  _bmHide();
},true);
// Reposition on scroll so lines follow the element
window.addEventListener('scroll',function(){
  if(!document.body.classList.contains('clp-grid-on'))return;
  if(!_bmEl)return;
  _bmApply(_bmEl);
},{passive:true});
// Keep ?_clp=1 on same-origin in-frame navigation so the preview script is
// re-injected on every page the editor browses to. Without this, following an
// internal link (or submitting a form) drops the marker/hover/refresh machinery
// until the next backend resolve.
function _clpRewrite(raw){
  try{
    var u=new URL(raw,window.location.href);
    if(u.origin!==window.location.origin)return null;
    if(u.searchParams.get('_clp')!=='1')u.searchParams.set('_clp','1');
    return u;
  }catch(err){return null;}
}
// Bubble phase (false) so JS toggle handlers (e.g. mobile menu) can call
// preventDefault() first — if they did, we skip navigation entirely.
document.addEventListener('click',function(e){
  if(e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
  var a=e.target.closest?e.target.closest('a[href]'):null;
  if(!a||a.hasAttribute('download'))return;
  if(a.target&&a.target!==''&&a.target!=='_self')return; // _blank etc. → real new tab, leave it
  var href=a.getAttribute('href')||'';
  if(!href||href.charAt(0)==='#'||/^(mailto:|tel:|javascript:)/i.test(href))return;
  var u=_clpRewrite(a.href);
  if(!u)return;
  // Pure in-page anchor on the current URL → let the browser scroll.
  if(u.hash&&u.pathname===window.location.pathname&&u.search===window.location.search)return;
  e.preventDefault();
  window.location.assign(u.toString());
},false);
// Forms lose the marker on submit: GET rebuilds the query from fields (so _clp
// must ride as a hidden input), POST keeps it only if it is in the action URL.
document.addEventListener('submit',function(e){
  var f=e.target;
  if(!f||f.tagName!=='FORM')return;
  var u=_clpRewrite(f.getAttribute('action')||window.location.href);
  if(!u)return;
  if((f.method||'get').toLowerCase()==='get'){
    if(!f.querySelector('input[name="_clp"]')){
      var i=document.createElement('input');i.type='hidden';i.name='_clp';i.value='1';f.appendChild(i);
    }
  }else{
    f.setAttribute('action',u.toString());
  }
 },true);
// Restore grid overlay state from localStorage on load.
try{if(localStorage.getItem('clp_grid_overlay')==='1'){document.body.classList.add('clp-grid-on');}}catch(_){}
})();</script>
HTML;
        return $html;
    }
}
