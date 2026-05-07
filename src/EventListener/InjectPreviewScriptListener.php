<?php

declare(strict_types=1);

namespace ThinkDigital\ContaoLivePreview\EventListener;

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
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -200)]
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
    }

    private function buildInjection(): string
    {
        // Inline to avoid extra HTTP requests. Only present when loaded inside the
        // preview iframe (?_clp=1). Handles two message types from the backend:
        //   clp:highlight — scroll to element, apply persistent blue outline + label badge
        //   clp:refresh   — fetch current page, swap article DOM node, then highlight
        return <<<'HTML'
<style>
.clp-sel{outline:2px solid #0594ff!important;outline-offset:2px;position:relative!important;z-index:9999!important}
.clp-sel-secondary{outline:2px dashed #0594ff!important;outline-offset:2px;position:relative!important;z-index:9999!important}
.clp-hover{outline:2px dashed #d946ef!important;outline-offset:2px;position:relative!important;z-index:9998!important}
.clp-badge,.clp-hover-badge{position:absolute;display:flex;align-items:center;gap:7px;color:#fff;font:700 11px/1 -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;padding:7px 12px;border-radius:3px;white-space:nowrap;pointer-events:none}
.clp-badge{background:#0594ff;z-index:2147483647}
.clp-hover-badge{background:#d946ef;z-index:2147483647}
.clp-badge-edit{all:unset;display:flex;align-items:center;cursor:pointer;opacity:.75;transition:opacity .15s;pointer-events:auto}
.clp-badge-edit:hover{opacity:1}
</style>
<script>(function(){
// _el/_elCe  = data elements (carry data-contao-* attrs; used for hover exclusion + DOM swap).
// _elVis/_elCeVis = visual targets (receive outline class + badge; child of data el when col-only-child).
var _el=null,_elVis=null,_elCe=null,_elCeVis=null,_badge=null,_badgeCe=null,_gen=0;
var _articleId=null,_contentElementId=null;
var _hoverEl=null,_hoverElVis=null,_hoverBadge=null;
var _refreshAbort=null;
var _editIcon='<svg style="flex-shrink:0" width="11" height="11" viewBox="0 0 10 10" fill="none"><path d="M7 1.5l1.5 1.5-5.5 5.5H1.5V7L7 1.5z" stroke="#fff" stroke-width="1.2" stroke-linejoin="round"/><line x1="5.8" y1="2.7" x2="7.3" y2="4.2" stroke="#fff" stroke-width="1.2"/></svg>';
function findEl(sels){var r=null;for(var i=0;i<sels.length;i++){r=document.querySelector(sels[i]);if(r)break;}return r;}
// When el is a single-child grid column wrapper (col-*), return the child as the visual target.
// The data element (el) is kept for DOM queries; only the outline and badge move to the child.
function clpVisTarget(el){var cc=String(el.className||'').split(/\s+/);for(var i=0;i<cc.length;i++){if(cc[i].indexOf('col-')===0){if(el.children.length===1)return el.children[0];break;}}return el;}
function clpClear(){if(_elVis){_elVis.classList.remove('clp-sel','clp-sel-secondary');_elVis=null;}_el=null;if(_elCeVis){_elCeVis.classList.remove('clp-sel');_elCeVis=null;}_elCe=null;if(_badge){_badge.remove();_badge=null;}if(_badgeCe){_badgeCe.remove();_badgeCe=null;}}
function clpHoverClear(){if(_hoverElVis){_hoverElVis.classList.remove('clp-hover');_hoverElVis=null;}_hoverEl=null;if(_hoverBadge){_hoverBadge.remove();_hoverBadge=null;}}
function clpBadgePos(b,el){var r=el.getBoundingClientRect();b.style.top=(window.scrollY+r.top+2)+'px';b.style.left=(window.scrollX+r.left+2)+'px';}
function _mkBadge(cls,lbl,table,editId){var b=document.createElement('div');b.className=cls;var s=document.createElement('span');s.textContent=lbl;b.appendChild(s);var btn=document.createElement('button');btn.type='button';btn.className='clp-badge-edit';btn.innerHTML=_editIcon;if(table&&editId){btn.addEventListener('click',function(ev){ev.stopPropagation();window.parent.postMessage({type:'clp:edit',table:table,id:editId},'*');});}b.appendChild(btn);document.body.appendChild(b);return b;}
function makeBadge(lbl,t,id){return _mkBadge('clp-badge',lbl,t,id);}
function makeHoverBadge(lbl,t,id){return _mkBadge('clp-hover-badge',lbl,t,id);}
function getCeLabel(el){if(el.dataset&&el.dataset.contaoLabel&&el.dataset.contaoLabel!==''){return el.dataset.contaoLabel.toUpperCase();}var cc=String(el.className||'').split(/\s+/);for(var i=0;i<cc.length;i++){if(cc[i].indexOf('ce_')===0){return cc[i].slice(3).replace(/([a-z])([A-Z])/g,'$1 $2').replace(/_/g,' ').toUpperCase();}}return 'INHALTSELEMENT';}
function clpReposAll(){if(_badge&&_elVis)clpBadgePos(_badge,_elVis);if(_badgeCe&&_elCeVis)clpBadgePos(_badgeCe,_elCeVis);if(_hoverBadge&&_hoverElVis)clpBadgePos(_hoverBadge,_hoverElVis);}
window.addEventListener('resize',clpReposAll,{passive:true});
function highlight(el,bh,label,table,editId){
  var vis=clpVisTarget(el);
  clpClear();_gen++;var myGen=_gen;
  var rect=vis.getBoundingClientRect();
  var targetY=window.scrollY+rect.top-(window.innerHeight-rect.height)/2;
  window.scrollTo({top:Math.max(0,targetY),left:0,behavior:bh||'smooth'});
  function apply(){if(_gen!==myGen)return;_el=el;_elVis=vis;vis.classList.add('clp-sel');if(label){_badge=makeBadge(label,table,editId);clpBadgePos(_badge,vis);}}
  if((bh||'smooth')==='instant'){apply();}
  else{var t;function hl(){clearTimeout(t);window.removeEventListener('scrollend',hl);apply();}if('onscrollend'in window)window.addEventListener('scrollend',hl,{once:true});t=setTimeout(hl,800);}
}
window.addEventListener('message',function(e){
  if(!e.data||!e.data.type)return;
  if(e.data.type==='clp:highlight'){
    _articleId=e.data.articleId||null;
    _contentElementId=e.data.contentElementId||null;
    var el=findEl(e.data.selectors||[]);
    var aEl=findEl(e.data.articleSelectors||[]);
    if(el&&aEl&&el!==aEl){
      var elVis=clpVisTarget(el);var aElVis=clpVisTarget(aEl);
      clpClear();_gen++;
      var rect=elVis.getBoundingClientRect();
      window.scrollTo({top:Math.max(0,window.scrollY+rect.top-(window.innerHeight-rect.height)/2),left:0,behavior:e.data.scrollBehavior||'instant'});
      _elCe=el;_elCeVis=elVis;elVis.classList.add('clp-sel');
      _el=aEl;_elVis=aElVis;aElVis.classList.add('clp-sel-secondary');
      // Prefer data-contao-label from the DOM — set by InjectContentElementMarkersListener
      // in fully-bootstrapped frontend context, so language files are always complete.
      var lbl=getCeLabel(el)||e.data.label||'';if(lbl){_badgeCe=makeBadge(lbl,'tl_content',_contentElementId);clpBadgePos(_badgeCe,elVis);}
      var albl=e.data.articleLabel||'';if(albl){_badge=makeBadge(albl,'tl_article',_articleId);_badge.style.zIndex='2147483646';clpBadgePos(_badge,aElVis);}
    }else if(el||aEl){
      var isCe=!!_contentElementId;
      var target=el||aEl;
      var lbl2=isCe?(getCeLabel(target)||e.data.label||''):(e.data.label||'');
      highlight(target,e.data.scrollBehavior,lbl2,isCe?'tl_content':'tl_article',isCe?_contentElementId:_articleId);
    }
    return;
  }
  if(e.data.type==='clp:refresh'){
    var articleId=e.data.articleId;var selectors=e.data.selectors||[];var label=e.data.label||'';
    var scrollX=window.scrollX,scrollY=window.scrollY;
    if(_refreshAbort){_refreshAbort.abort();}
    _refreshAbort=('AbortController'in window)?new AbortController():null;
    var fetchOpts={credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}};
    if(_refreshAbort){fetchOpts.signal=_refreshAbort.signal;}
    fetch(window.location.href,fetchOpts)
      .then(function(r){return r.text();})
      .then(function(html){
        _refreshAbort=null;
        var doc=new DOMParser().parseFromString(html,'text/html');
        var fresh=null,live=null;
        for(var i=0;i<selectors.length;i++){var f=doc.querySelector(selectors[i]);var l=document.querySelector(selectors[i]);if(f&&l){fresh=f;live=l;break;}}
        if(fresh&&live){live.replaceWith(fresh);window.scrollTo({top:scrollY,left:scrollX,behavior:'instant'});var el=findEl(selectors);if(el)highlight(el,'instant',label,'tl_article',_articleId);}
        window.parent.postMessage({type:'clp:refreshed',articleId:articleId},'*');
      })
      .catch(function(err){
        if(err&&err.name==='AbortError'){return;}
        _refreshAbort=null;
        window.parent.postMessage({type:'clp:refreshed',articleId:articleId},'*');
      });
    return;
  }
});
// Hover: fuchsia dashed outline + badge for any article/CE on the page.
// _hoverEl = data element (for exclusion check + mouseout boundary).
// _hoverElVis = visual target (receives outline class and badge position).
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
  vis.classList.add('clp-hover');
  _hoverBadge=makeHoverBadge(lbl,table,id);
  clpBadgePos(_hoverBadge,vis);
});
// mouseout: _hoverEl (the data/container element) defines the boundary.
// Covers both the col-* wrapper and its single child — don't clear until cursor
// truly leaves the container (or moves to the badge for edit-icon click).
document.addEventListener('mouseout',function(e){
  if(!_hoverEl)return;
  var rel=e.relatedTarget;
  if(rel&&(rel===_hoverEl||_hoverEl.contains(rel)))return;
  if(_hoverBadge&&rel&&(rel===_hoverBadge||_hoverBadge.contains(rel)))return;
  clpHoverClear();
});
// Keep ?_clp=1 on same-origin link navigation so the script survives page changes.
document.addEventListener('click',function(e){
  var a=e.target.closest?e.target.closest('a[href]'):null;
  if(!a)return;
  var href=a.getAttribute('href')||'';
  if(!href||href.charAt(0)==='#')return;
  try{
    var u=new URL(a.href,window.location.href);
    if(u.origin!==window.location.origin)return;
    if(u.searchParams.get('_clp')==='1')return;
    u.searchParams.set('_clp','1');
    e.preventDefault();
    window.location.href=u.toString();
  }catch(err){}
},true);
})();</script>
HTML;
    }
}
