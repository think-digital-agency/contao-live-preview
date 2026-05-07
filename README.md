# Contao Live Preview

[![Packagist](https://img.shields.io/packagist/v/think-digital-agency/contao-live-preview.svg)](https://packagist.org/packages/think-digital-agency/contao-live-preview)
[![License](https://img.shields.io/packagist/l/think-digital-agency/contao-live-preview.svg)](LICENSE)

**Schluss mit Backend und Browser hin- und herwechseln.** Contao Live Preview bringt eine kontextbewusste Frontend-Vorschau direkt in das Contao 5 Backend — als ausklappbare Seitenleiste, die immer die richtige Seite zeigt, automatisch aktualisiert und dich per Klick zu jedem Element navigiert.

![Contao Live Preview Screenshot](https://raw.githubusercontent.com/think-digital-agency/contao-live-preview/main/docs/preview.png)

---

## Was macht das?

Du öffnest einen Artikel oder ein Content Element im Backend — und rechts siehst du sofort, wie es im Frontend aussieht. Du speicherst — der Artikel wird **im laufenden iframe ausgetauscht**, ohne Reload, ohne Scrollen. Du hoverst über ein Element in der Vorschau — ein Badge zeigt dir, was es ist, und ein Klick bringt dich direkt zur Bearbeitungsmaske.

Kein Plugin. Kein Builder. Kein Kompromiss. Einfach Contao, wie es sein sollte.

---

## Features

- **Kontextbewusste Vorschau** — öffnest du eine Seite, einen Artikel oder ein Content Element, zeigt die Seitenleiste automatisch die passende Frontend-URL. Ohne Konfiguration.
- **Partieller DOM-Swap nach dem Speichern** — nur der bearbeitete Artikel wird im iframe aktualisiert. Scroll-Position bleibt erhalten, kein Flackern, kein Reload.
- **Hover-Inspektion** — fahre mit der Maus über beliebige Artikel oder Content Elemente in der Vorschau. Ein farbiges Badge zeigt Typ und Name — Klick auf den Stift öffnet die Bearbeitungsmaske direkt.
- **Doppeltes Highlighting** — das aktive Content Element wird blau umrahmt, der zugehörige Artikel gestrichelt — so siehst du immer Kontext und Detail gleichzeitig.
- **Root-Page Fallback** — auch wenn kein konkreter Seitenkontext aktiv ist, zeigt die Vorschau immer die Startseite statt leer zu bleiben.
- **Funktioniert mit jedem Contao-Theme** — die Extension injiziert alle nötigen `data-contao-*`-Attribute automatisch über Contao Hooks und Symfony Events. Keine Theme-Anpassungen nötig.
- **Resizable Sidebar** — per Drag frei in der Breite veränderbar, Zoom-Steuerung für kleine Bildschirme.
- **Turbo-kompatibel** — die Seitenleiste überlebt alle Turbo-Navigationen (`data-turbo-permanent`), kein iframe-Flash, kein Zustandsverlust.
- **Kostenlos und Open Source** — LGPL-3.0, keine Lizenzkosten, keine versteckten Features.

---

## Perfekt kombiniert: Design Plus Theme

Diese Extension wurde im Alltag mit dem **[Contao Design Plus Theme](https://themes.contao.org/de/index/contao-design-plus)** entwickelt und ist dort vollständig integriert. Design Plus liefert die `data-contao-*`-Attribute direkt in den Templates mit — das Zusammenspiel ist damit noch präziser: Hover-Inspektion, DOM-Swap und Highlighting funktionieren auf Anhieb, ohne jegliche Konfiguration.

Wer professionelle Contao-Projekte baut, bekommt mit Design Plus + Live Preview einen Workflow, der sich anfühlt wie ein modernes Page-Builder-Erlebnis — bei vollem Zugriff auf das gewohnte Contao Backend.

---

## Voraussetzungen

- PHP 8.2 oder höher
- Contao 5.3 oder höher

---

## Installation

```bash
composer require think-digital-agency/contao-live-preview
php bin/console assets:install
php bin/console cache:clear
```

Die Extension registriert sich automatisch über den Contao Manager Plugin. Keine weitere Konfiguration erforderlich.

---

## Verwendung

Öffne eine beliebige Seite, einen Artikel oder ein Content Element im Contao Backend. Oben rechts im Backend-Header erscheint der **„Live Preview"**-Button. Klicken — fertig.

Die Vorschau aktualisiert sich automatisch wenn du:
- zu einer anderen Seite / einem anderen Artikel / CE navigierst
- einen Datensatz speicherst (Artikel-DOM-Swap, kein Reload)
- innerhalb des Vorschau-iframes auf einen Link klickst (der `?_clp=1`-Marker bleibt erhalten)

---

## Erweiterung: Custom Table Support

Über das `PreviewUrlResolverInterface` lässt sich die Extension auf eigene Tabellen (News, Kalender, Events etc.) ausweiten:

```php
// src/Service/ExtendedPreviewUrlResolver.php
use ThinkDigital\ContaoLivePreview\Service\PreviewUrlResolver;
use ThinkDigital\ContaoLivePreview\Service\PreviewUrlResolverInterface;

class ExtendedPreviewUrlResolver implements PreviewUrlResolverInterface
{
    public function __construct(
        private readonly PreviewUrlResolver $inner,
        private readonly Connection $db,
    ) {}

    public function resolve(string $table, int $id): ?array
    {
        if ('tl_news' === $table) {
            $row = $this->db->fetchAssociative(
                'SELECT a.pid FROM tl_news n JOIN tl_news_archive a ON a.id = n.pid WHERE n.id = ?',
                [$id],
            );
            return $row ? $this->inner->resolve('tl_page', (int) $row['pid']) : null;
        }
        return $this->inner->resolve($table, $id);
    }
}
```

```yaml
# config/services.yaml
ThinkDigital\ContaoLivePreview\Service\PreviewUrlResolverInterface:
    alias: App\Service\ExtendedPreviewUrlResolver
```

---

## Bekannte Einschränkungen

- **Twig-first CEs in Multi-Column-Layouts**: Content Elemente ohne CSS-ID können falsch zugeordnet werden, wenn Spalten im HTML in anderer Reihenfolge erscheinen als in der DB. Workaround: CSS-ID am betroffenen Element setzen.
- **Verschachtelte CEs** (z. B. Akkordeon-Inhalte): innere CE-Positionszählung kann versetzt sein. CSS-ID-Matching ist immer zuverlässig.
- **`noMarkup`-Artikel**: DOM-Swap und Highlighting erfordern den Standard-`id="article-{N}"`-Wrapper; `noMarkup` unterdrückt diesen.
- **`tl_page`-Kontext**: Das Speichern eines Seiten-Datensatzes löst keinen DOM-Swap aus (kein Artikel-Kontext); ein manueller Sidebar-Reload ist nötig.

---

## Lizenz

LGPL-3.0-or-later — siehe [LICENSE](LICENSE).

Entwickelt von [Think Digital Agency](https://think-digital.agency).
