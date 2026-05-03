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
        // Inline to avoid an extra HTTP request. The style + script are small and
        // only present when the page is loaded inside the preview iframe.
        return <<<'HTML'
<style>.clp-highlight{animation:clp-bg-fade 2.25s linear forwards}@keyframes clp-bg-fade{0%{box-shadow:0 0 0 24px rgba(244,124,0,0),inset 0 0 0 9999px rgba(244,124,0,0)}15%{box-shadow:0 0 0 24px rgba(244,124,0,.12),inset 0 0 0 9999px rgba(244,124,0,.12)}85%{box-shadow:0 0 0 24px rgba(244,124,0,.12),inset 0 0 0 9999px rgba(244,124,0,.12)}100%{box-shadow:0 0 0 24px rgba(244,124,0,0),inset 0 0 0 9999px rgba(244,124,0,0)}}</style>
<script>(function(){window.addEventListener('message',function(e){if(!e.data||'clp:highlight'!==e.data.type)return;var sels=e.data.selectors||[];var el=null;for(var i=0;i<sels.length;i++){el=document.querySelector(sels[i]);if(el)break;}if(!el)return;var bh=e.data.scrollBehavior||'smooth';el.scrollIntoView({behavior:bh,block:'center'});var t;function hl(){clearTimeout(t);window.removeEventListener('scrollend',hl);el.classList.add('clp-highlight');setTimeout(function(){el.classList.remove('clp-highlight')},2250);}if(bh==='instant'){hl();}else{if('onscrollend'in window)window.addEventListener('scrollend',hl,{once:true});t=setTimeout(hl,800);}})})();</script>
HTML;
    }
}
