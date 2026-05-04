<?php

declare(strict_types=1);

namespace Vendor\ContaoLivePreviewBundle\EventListener;

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
<style>.clp-sel{outline:2px solid #0594ff!important;outline-offset:4px;position:relative!important;z-index:9999!important}.clp-badge{position:absolute;display:flex;align-items:center;gap:5px;background:#0594ff;color:#fff;font:500 11px/22px -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;padding:0 8px;border-radius:3px 3px 0 0;white-space:nowrap;pointer-events:none;z-index:2147483647}</style>
<script>(function(){
var _el=null,_badge=null,_gen=0;
var _icon='<svg style="flex-shrink:0" width="9" height="11" viewBox="0 0 9 11" fill="none"><path d="M1 .5h5l2 2v8H1z" stroke="#fff" stroke-width="1.2"/><path d="M6 .5v2h2" stroke="#fff" stroke-width="1.2"/><line x1="2.5" y1="5" x2="6.5" y2="5" stroke="#fff" stroke-width="1"/><line x1="2.5" y1="7" x2="6.5" y2="7" stroke="#fff" stroke-width="1"/></svg>';
function findEl(sels){var r=null;for(var i=0;i<sels.length;i++){r=document.querySelector(sels[i]);if(r)break;}return r;}
function clpClear(){if(_el){_el.classList.remove('clp-sel');_el=null;}if(_badge){_badge.remove();_badge=null;}}
function clpBadgePos(el){var r=el.getBoundingClientRect();_badge.style.top=Math.max(0,window.scrollY+r.top-28)+'px';_badge.style.left=(window.scrollX+r.left+30)+'px';}
function highlight(el,bh,label){
  clpClear();
  _gen++;var myGen=_gen;
  var rect=el.getBoundingClientRect();
  var targetY=window.scrollY+rect.top-(window.innerHeight-rect.height)/2;
  window.scrollTo({top:Math.max(0,targetY),left:0,behavior:bh||'smooth'});
  function apply(){
    if(_gen!==myGen)return;
    _el=el;el.classList.add('clp-sel');
    if(label){
      _badge=document.createElement('div');_badge.className='clp-badge';
      _badge.innerHTML=_icon;
      var s=document.createElement('span');s.textContent=label;_badge.appendChild(s);
      document.body.appendChild(_badge);clpBadgePos(el);
    }
  }
  if((bh||'smooth')==='instant'){apply();}
  else{var t;function hl(){clearTimeout(t);window.removeEventListener('scrollend',hl);apply();}if('onscrollend'in window)window.addEventListener('scrollend',hl,{once:true});t=setTimeout(hl,800);}
}
window.addEventListener('message',function(e){
  if(!e.data||!e.data.type)return;
  if(e.data.type==='clp:highlight'){
    var el=findEl(e.data.selectors||[]);
    if(el)highlight(el,e.data.scrollBehavior,e.data.label||'');
    return;
  }
  if(e.data.type==='clp:refresh'){
    var articleId=e.data.articleId;
    var selectors=e.data.selectors||[];
    var label=e.data.label||'';
    var scrollX=window.scrollX,scrollY=window.scrollY;
    fetch(window.location.href,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){return r.text();})
      .then(function(html){
        var doc=new DOMParser().parseFromString(html,'text/html');
        var sel='[data-contao-table="tl_article"][data-contao-id="'+articleId+'"]';
        var fresh=doc.querySelector(sel);
        var live=document.querySelector(sel);
        if(fresh&&live){
          live.replaceWith(fresh);
          window.scrollTo({top:scrollY,left:scrollX,behavior:'instant'});
          var el=findEl(selectors);
          if(el)highlight(el,'instant',label);
        }
        window.parent.postMessage({type:'clp:refreshed',articleId:articleId},'*');
      })
      .catch(function(){window.parent.postMessage({type:'clp:refreshed',articleId:articleId},'*');});
    return;
  }
});
})();</script>
HTML;
    }
}
