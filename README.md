# nwr-lead-stats

WordPress plugin, ktorý počíta odoslané formuláre (leady) a cez REST vracia **iba denné počty**, nie obsah formulárov. Slúži pre denné reporty (napr. n8n), ktoré potrebujú skutočný počet leadov z webu namiesto odhadu z GA4 alebo Google Ads.

## Zdroje
| Plugin | Živé počítanie (hook) | História pred inštaláciou |
|---|---|---|
| Bricksforge Pro Forms | `bricksforge/pro_forms/before_submit` (po validácii, honeypote a Turnstile) | `{prefix}bricksforge_submissions`, iba ak má formulár akciu „Save submission“ |
| WPForms Lite/Pro | `wpforms_process_complete` | Pro: `{prefix}wpforms_entries`; Lite nemá |
| Forminator | `forminator_custom_form_submit_before_set_fields`, iba `status = active` | `{prefix}frmt_form_entry` |
| Elementor Pro Forms | `elementor_pro/forms/new_record` | `{prefix}e_submissions` |
| Contact Form 7 | `wpcf7_submit`, stav `mail_sent` alebo `mail_failed` | nemá |
| Amelia (rezervácie) | bez hooku, vždy z `{prefix}amelia_customer_bookings` | celá, odkedy Amelia vypĺňa `created` (GMT) |

Živé záznamy idú do `{prefix}nwr_leads`. Tabuľky formulárových pluginov sa berú iba pre obdobie pred `nwr_leads_since` (prvá inštalácia), takže sa nič nezráta dvakrát. Odoslania od prihlásených editorov a adminov sa označia ako test a nerátajú sa (`include_tests=1` ich zahrnie). História z natívnych tabuliek testy rozlíšiť nevie.

Amelia: rátajú sa iba rezervácie z webu (`info` vyplnené JSONom zákazníka z rezervačného formulára). Rezervácie, ktoré zadá personál v administrácii, majú `info` NULL a nerátajú sa. Zrušené a zamietnuté sa rátajú (zákazník rezerváciu odoslal), zmazané zmiznú. Kľúč `amelia:<ID služby>`, názov = názov služby. Testy sa pri Amelii rozlíšiť nedajú.

Nepodporuje platobné formuláre (Bricksforge Stripe/Mollie sa zráta už pri odoslaní, nie po zaplatení).

## API
`GET /wp-json/nwr-leads/v1/counts?since=YYYY-MM-DD&until=YYYY-MM-DD&forms=bricksforge:abc123,wpforms:12&include_tests=0`

- Autorizácia: hlavička `X-NWR-Leads-Key: <kľúč>` (Nástroje → Nowera Lead Stats) alebo admin (Application Password).
- Predvolené obdobie: posledných 14 dní, max. 3700 dní. `group=day|week|month` zoskupí výsledok po dňoch, ISO týždňoch (`2026-W41`) alebo mesiacoch (`2026-10`).
- Odpoveď: `total`, `days` (súčet za deň), `forms[]` s `key`, `title`, `total`, `days`, `complete_since` (odkedy sú počty úplné), `live_since`.

## Napojenie na report (n8n)
- Jeden HTTP uzol na web, autorizácia cez Header Auth credential s `X-NWR-Leads-Key` (kľúč nepatrí do JSONu workflowu).
- Filtrovanie formulárov radšej v reporte: zoznam kľúčov `source:form_id`, ktoré sa rátajú (alebo ktoré sa vynechajú).
- `complete_since` hovorí, odkedy sú počty úplné; porovnania so staršími obdobiami treba označiť ako neúplné.
- Pri chybe endpointu použiť pôvodný zdroj (GA4 / Ads) a upozorniť.

## Inštalácia
ZIP z `plugin/nwr-lead-stats` (`cd plugin && zip -r ../dist/nwr-lead-stats-X.Y.Z.zip nwr-lead-stats`) → Pluginy → Pridať nový → Nahrať. Kľúč a prehľad posledných 30 dní: Nástroje → Nowera Lead Stats.

## Testy
WordPress Playground:

```bash
npx @wp-playground/cli@latest server --port=9430 --php=8.1 --login --blueprint=tests/blueprint.json --mount=plugin/nwr-lead-stats:/wordpress/wp-content/plugins/nwr-lead-stats --mount=tests:/wordpress/nwr-tests
```

- `/nwr-tests/seed.php`: simulované odoslania zo všetkých pluginov, história, spam, drafty, testy, únik Bricksforge requestu.
- `/nwr-tests/amelia.php`: Amelia, hranica dňa v GMT, rezervácie personálu, filter, `complete_since` (vypíše `N passed, 0 failed`).

## Licencia
GPL-2.0-or-later, plné znenie v [LICENSE](LICENSE).
