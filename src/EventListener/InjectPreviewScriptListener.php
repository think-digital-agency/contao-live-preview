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
        //   clp:highlight — scroll to element and flash an orange outline
        //   clp:refresh   — fetch current page, swap article DOM node, then highlight
        return <<<'HTML'
<style>.clp-highlight{animation:clp-bg-fade 2.25s linear forwards}@keyframes clp-bg-fade{0%{box-shadow:0 0 0 24px rgba(244,124,0,0),inset 0 0 0 9999px rgba(244,124,0,0)}15%{box-shadow:0 0 0 24px rgba(244,124,0,.12),inset 0 0 0 9999px rgba(244,124,0,.12)}85%{box-shadow:0 0 0 24px rgba(244,124,0,.12),inset 0 0 0 9999px rgba(244,124,0,.12)}100%{box-shadow:0 0 0 24px rgba(244,124,0,0),inset 0 0 0 9999px rgba(244,124,0,0)}}</style>
<script>(function(){
function findEl(sels){var el=null;for(var i=0;i<sels.length;i++){el=document.querySelector(sels[i]);if(el)break;}return el;}
function highlight(el,bh){
  el.scrollIntoView({behavior:bh||'smooth',block:'center'});
  var t;
  function hl(){clearTimeout(t);window.removeEventListener('scrollend',hl);el.classList.add('clp-highlight');setTimeout(function(){el.classList.remove('clp-highlight');},2250);}
  if((bh||'smooth')==='instant'){hl();}else{if('onscrollend'in window)window.addEventListener('scrollend',hl,{once:true});t=setTimeout(hl,800);}
}
window.addEventListener('message',function(e){
  if(!e.data||!e.data.type)return;
  if(e.data.type==='clp:highlight'){
    var el=findEl(e.data.selectors||[]);
    if(el)highlight(el,e.data.scrollBehavior);
    return;
  }
  if(e.data.type==='clp:refresh'){
    var articleId=e.data.articleId;
    var selectors=e.data.selectors||[];
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
          if(el)highlight(el,'instant');
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
