# Noptin & TranslatePress Bridge

WordPress plugin, který propojuje **Noptin** a **TranslatePress**.

Ukládá jazyk odběratele při registraci / přihlášení k odběru a automaticky překládá odchozí e-maily (newslettery) do jazyka příjemce.

## Požadavky

- WordPress 5.8+
- [Noptin](https://wordpress.org/plugins/newsletter-optin-box/)
- [TranslatePress](https://wordpress.org/plugins/translatepress-multilingual/)

## Instalace

1. Stáhni soubor `noptin-translatepress-bridge.php`
2. Nahraj ho do složky `/wp-content/plugins/noptin-translatepress-bridge/`
3. Aktivuj plugin v administraci WordPressu
4. Jdi do **Nastavení → Noptin & TranslatePress** a uprav požadované volby

## Hlavní funkce

- **Ukládání jazyka odběratele** – při registraci i při opětovném odeslání formuláře
- **Překlad odchozích e-mailů** – subject + tělo podle jazyka příjemce
- **Sdílená cache** – pro hromadné rozesílky (jeden překlad pro všechny odběratele stejného jazyka)
- **Přepis interních odkazů** do správné jazykové verze
- **Synchronizace s WP uživateli** – když si uživatel změní jazyk na webu
- **Debug režim** – vynucený překlad do zvoleného jazyka + diagnostika posledního e-mailu

## Nastavení

Plugin přidává stránku **Nastavení → Noptin & TranslatePress**.

### Obecné
- Zapnutí / vypnutí integrace
- Překládat pouze e-maily z Noptinu (doporučeno)
- Automatický přepis odkazů

### Debug: Vynucený překlad
- Umožňuje otestovat překlad bez ohledu na jazyk příjemce
- Select s jazyky se načítá automaticky z TranslatePress

### Synchronizace jazyka
- Aktualizace jazyka při opětovném odeslání formuláře
- Synchronizace s jazykem přihlášeného WP uživatele

### Cache
- Povolení cache překladů
- Sdílená cache pro newslettery
- Doba platnosti cache
- Tlačítko pro okamžité vymazání cache

## Důležité poznámky

- Překlad funguje pouze pro řetězce, které už TranslatePress zná (nebo pokud máš zapnutý Automatic Translation).
- Priorita filtru `wp_mail` je nastavena na `1000001`, aby překlad proběhl **po** interním zpracování Noptinu.
- Při větších úpravách textů newsletterů doporučujeme vymazat cache překladů.

## Verze

**1.2.3**
- Opravena priorita filtru `wp_mail` (konflikt s Noptinem)
- Select jazyků z TranslatePress místo ručního zadávání
- Robustnější volání překladu
- Vylepšený debug výpis

## Licence

GPL-2.0-or-later

---

Vytvořeno jako custom integrace pro propojení Noptin + TranslatePress.
