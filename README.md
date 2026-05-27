# Contao Live Preview

[![Packagist](https://img.shields.io/packagist/v/think-digital-agency/contao-live-preview.svg)](https://packagist.org/packages/think-digital-agency/contao-live-preview)
[![Downloads](https://img.shields.io/packagist/dt/think-digital-agency/contao-live-preview.svg)](https://packagist.org/packages/think-digital-agency/contao-live-preview)
[![License](https://img.shields.io/packagist/l/think-digital-agency/contao-live-preview.svg)](LICENSE)

**[English]** A native live preview panel for the Contao 5 backend. Open any page, article or content element — the sidebar immediately shows the matching frontend view. Save — only the edited section refreshes instantly, no page reload, no scroll loss. Hover over any element in the preview — a label shows its type, click opens the edit form directly.

```bash
composer require think-digital-agency/contao-live-preview
```

---

**Schluss mit dem ewigen Tab-Wechsel.** Contao Live Preview bringt eine direkte Frontend-Vorschau als ausklappbare Sidebar ins Contao 5 Backend — immer passend zu dem, was du gerade bearbeitest. Mit einem Workflow-Gefühl, das man so aus Contao nicht kennt.

![Contao Live Preview Screenshot](https://raw.githubusercontent.com/think-digital-agency/contao-live-preview/main/docs/preview.png)

---

## Was macht die Erweiterung?

Du öffnest einen Artikel oder ein Inhaltselement im Backend — und siehst sofort, wie es im Frontend aussieht. Du speicherst — nur der bearbeitete Bereich aktualisiert sich direkt in der Vorschau, ohne Reload, ohne Scrollen. Du fährst mit der Maus über ein Element in der Vorschau — ein Hover-Label zeigt dir Typ und Name, ein Klick öffnet die Bearbeitungsmaske direkt.

Kein Builder. Kein Kompromiss. Einfach Contao, wie es sein sollte.

---

## Features

- **Live-Vorschau im Backend** — öffnest du eine Seite, einen Artikel oder ein Inhaltselement, zeigt die Sidebar sofort die passende Frontend-Ansicht. Ohne Konfiguration, ohne zusätzlichen Klick.
- **Hot-Refresh nach dem Speichern** — nur der bearbeitete Abschnitt wird direkt in der Vorschau aktualisiert. Scroll-Position bleibt, kein Flackern.
- **Automatische Aktualisierung bei Strukturänderungen** — löscht, dupliziert, verschiebt oder versteckst du ein Element, lädt die Vorschau nach der Backend-Weiterleitung automatisch neu.
- **Direkte Aktionen aus der Vorschau** — das aktive Element zeigt inline Schaltflächen zum Duplizieren (⧉) und zum Einfügen eines neuen Elements danach (+). Ein Klick, keine Suche im Backend.
- **Hover-Inspektion** — fährt man mit der Maus über Artikel oder Inhaltselemente, erscheint ein kontextuelles Label mit Typ und Name. Klick auf den Stift öffnet die Bearbeitungsmaske direkt.
- **Kontextuelles Highlighting** — das aktive Inhaltselement wird blau hervorgehoben, der zugehörige Artikel gestrichelt umrahmt. Kontext und Detail immer gleichzeitig sichtbar.
- **Funktioniert mit jedem Theme** — die Extension setzt alle nötigen Marker automatisch. Keine Template-Anpassungen erforderlich.
- **Frei anpassbares Panel** — per Drag in der Breite anpassbar, mit Zoom-Steuerung für kleinere Bildschirme.
- **Persistente Sidebar** — die Vorschau überlebt alle Backend-Navigationen ohne Zustandsverlust.
- **Kostenlos und Open Source** — LGPL-3.0, keine Lizenzkosten, keine versteckten Features.
- **Nativ integriert im [Contao Design+ Theme](https://themes.contao.org/de/index/contao-design-plus)** — wer auf Design+ setzt, bekommt Hot-Refresh, Hover-Inspektion und Highlighting vollständig vorkonfiguriert, ohne eine einzige Zeile Konfiguration.

---

## Perfekt kombiniert: Contao Design+ Theme

Diese Extension wurde im Alltag mit dem **[Contao Design+ Theme](https://themes.contao.org/de/index/contao-design-plus)** entwickelt und ist dort vollständig integriert. Design+ liefert alle nötigen Marker direkt in den Templates mit — Hot-Refresh, Hover-Inspektion und Highlighting funktionieren damit auf Anhieb, ohne jegliche Konfiguration.

Wer professionelle Contao-Projekte baut, bekommt mit Design+ und Live Preview einen Workflow mit einem nativen Gefühl — bei vollem Zugriff auf das gewohnte Contao Backend.

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

Öffne eine beliebige Seite, einen Artikel oder ein Inhaltselement im Contao Backend. Oben rechts im Backend-Header erscheint der **„Live Preview"**-Button. Klicken — fertig.

Die Vorschau aktualisiert sich automatisch wenn du:
- zu einer anderen Seite, einem anderen Artikel oder Inhaltselement navigierst
- einen Datensatz speicherst (direkter Hot-Refresh)
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

- **Inhaltselemente ohne CSS-ID in mehrspaltigen Layouts**: Elemente können falsch zugeordnet werden, wenn Spalten im HTML in anderer Reihenfolge erscheinen als in der DB. Workaround: CSS-ID am betroffenen Element setzen.
- **Verschachtelte Inhaltselemente** (z. B. Akkordeon-Inhalte): die Positionszählung kann versetzt sein. Mit CSS-ID ist das Matching zuverlässig.
- **`noMarkup`-Artikel**: Hot-Refresh und Highlighting benötigen den Standard-`id="article-{N}"`-Wrapper.
- **Seiten-Datensätze**: das Speichern einer Seite löst keinen Hot-Refresh aus. Ein manuelles Neuladen der Sidebar ist nötig.

---

## Lizenz

LGPL-3.0-or-later — siehe [LICENSE](LICENSE).

Entwickelt von [Think Digital Agency](https://think-digital.agency).