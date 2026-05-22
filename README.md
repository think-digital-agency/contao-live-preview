# Contao Live Preview

[![Packagist](https://img.shields.io/packagist/v/think-digital-agency/contao-live-preview.svg)](https://packagist.org/packages/think-digital-agency/contao-live-preview)
[![Downloads](https://img.shields.io/packagist/dt/think-digital-agency/contao-live-preview.svg)](https://packagist.org/packages/think-digital-agency/contao-live-preview)
[![License](https://img.shields.io/packagist/l/think-digital-agency/contao-live-preview.svg)](LICENSE)

**[English]** A native frontend preview panel for the Contao 5 backend. Open any page, article or content element — the sidebar immediately shows the matching frontend page. Save — only the edited article updates in the iframe, no page reload, no scroll loss. Hover over any element in the preview — a badge shows its type, click opens the edit form directly.

```bash
composer require think-digital-agency/contao-live-preview
```

---

**Schluss mit Backend und Browser hin- und herwechseln.** Contao Live Preview bringt eine direkte Vorschau des Frontends als ausklappbare Seitenleiste ins Contao 5 Backend — immer die richtige Seite, immer aktuell, mit einem nativen Gefühl das man so aus Contao nicht kennt.

![Contao Live Preview Screenshot](https://raw.githubusercontent.com/think-digital-agency/contao-live-preview/main/docs/preview.png)

---

## Was macht die Erweiterung?

Du öffnest einen Artikel oder ein Content Element im Backend — und rechts siehst du sofort, wie es im Frontend aussieht. Du speicherst — der Artikel wird **direkt im laufenden iFrame aktualisiert**, ohne Reload, ohne Scrollen. Du hoverst über ein Element in der Vorschau — ein Badge zeigt dir, was es ist, und ein Klick bringt dich direkt zur Bearbeitungsmaske.

Kein Plugin. Kein Builder. Kein Kompromiss. Einfach Contao, wie es sein sollte.

---

## Features

- **Live Vorschau im Backend** — öffnest du eine Seite, einen Artikel oder ein Content Element, zeigt die Seitenleiste sofort die passende Frontend-Seite. Ohne Konfiguration, ohne Klick.
- **Kein Reload nach dem Speichern** — nur der bearbeitete Artikel wird direkt im iFrame ausgetauscht. Scroll-Position bleibt, kein Flackern, natives Gefühl.
- **Automatische Aktualisierung bei Strukturänderungen** — löscht, dupliziert, verschiebt oder versteckt du ein Element, lädt der iFrame nach der Backend-Weiterleitung automatisch neu. Kein zweites Neuladen nötig.
- **Schnelle Aktionen im Badge** — das aktive Content-Element-Badge zeigt direkt Schaltflächen zum Duplizieren (⧉) und zum Einfügen eines neuen Elements danach (+). Ein Klick, keine Suche im Backend.
- **Hover-Inspektion** — fahre mit der Maus über Artikel oder Content Elemente in der Vorschau. Ein farbiges Badge zeigt Typ und Name — Klick auf den Stift öffnet die Bearbeitungsmaske direkt.
- **Doppeltes Highlighting** — das aktive Content Element wird blau umrahmt, der zugehörige Artikel gestrichelt. So siehst du immer Kontext und Detail gleichzeitig.
- **Immer eine Seite im iFrame** — auch wenn kein konkreter Seitenkontext aktiv ist, zeigt die Vorschau die Startseite statt leer zu bleiben.
- **Funktioniert mit jedem Theme** — die Extension setzt alle nötigen Marker automatisch. Keine Theme-Anpassungen nötig.
- **Panel frei verschiebbar** — per Drag in der Breite anpassbar, mit Zoom-Steuerung für kleine Bildschirme.
- **Bleibt beim Navigieren erhalten** — die Seitenleiste überlebt alle Backend-Navigationen, kein iFrame-Flash, kein Zustandsverlust.
- **Kostenlos und Open Source** — LGPL-3.0, keine Lizenzkosten, keine versteckten Features.

---

## Perfekt kombiniert: Contao Design+ Theme

Diese Extension wurde im Alltag mit dem **[Contao Design+ Theme](https://themes.contao.org/de/index/contao-design-plus)** entwickelt und ist dort vollständig integriert. Contao Design+ liefert alle nötigen Marker direkt in den Templates mit — die Live Vorschau, das Hover-Highlighting und der iFrame-Swap funktionieren damit auf Anhieb, ohne jegliche Konfiguration.

Wer professionelle Contao-Projekte baut, bekommt mit Contao Design+ + Live Preview einen Workflow mit einem nativen Gefühl — bei vollem Zugriff auf das gewohnte Contao Backend.

---

## Voraussetzungen

- PHP 8.2 oder höher
- Contao 5.3 oder höher (Contao 5.7+ empfohlen)

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
- zu einer anderen Seite, einem anderen Artikel oder Content Element navigierst
- einen Datensatz speicherst (direkter iFrame-Swap, kein Reload)
- innerhalb der Vorschau auf einen Link klickst

---

## Erweiterung: eigene Tabellen einbinden

Über das `PreviewUrlResolverInterface` lässt sich die Extension auf eigene Tabellen ausweiten — zum Beispiel News, Kalender oder Events:

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

- **Content Elemente ohne CSS-ID in mehrspaltigen Layouts**: Elemente können falsch zugeordnet werden, wenn Spalten im HTML in anderer Reihenfolge erscheinen als in der DB. Workaround: CSS-ID am betroffenen Element setzen.
- **Verschachtelte Content Elemente** (z. B. Akkordeon-Inhalte): die Positionszählung kann versetzt sein. Mit CSS-ID ist das Matching immer zuverlässig.
- **`noMarkup`-Artikel**: iFrame-Swap und Highlighting brauchen den Standard-`id="article-{N}"`-Wrapper — `noMarkup` unterdrückt diesen.
- **Seiten-Datensätze**: das Speichern einer Seite löst keinen iFrame-Swap aus (kein Artikel-Kontext). Ein manueller Reload des Panels ist nötig.

---

## Lizenz

LGPL-3.0-or-later — siehe [LICENSE](LICENSE).

Entwickelt von [Think Digital Agency](https://think-digital.agency).
