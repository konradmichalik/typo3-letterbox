# Design rules

Back to [documentation overview](../README.md).

These rules are binding for every change to Letterbox's backend module, the tester inbox, the toolbar item, the dashboard widget and the reports entry. When a change needs to break one, change the rule here first, in the same pull request, and say why.

The screens these rules produce are listed in [screens.md](screens.md).

Phase 2 is implemented: inbox, detail view, empty state and the "No mail selected" state. The other screens are the target design for later phases.

## Principles

1. **Native TYPO3, not branded.** The backend module looks like a core module. Letterbox adds no colours, fonts or focus styles of its own.
2. **The test comes first.** A tester wants to know who got which mail and to open its link. Subject, recipient and links lead; technical detail is one click away.
3. **Mail content is hostile.** Mail HTML and attachments can carry attacker input. They are never rendered in the backend document itself.
4. **Loud when it matters, quiet otherwise.** Catching mails is announced where it can surprise someone (toolbar, reports, a Production context). Everything else stays calm.
5. **Accessible by default.** WCAG 2.2 AA and BITV are the floor, in light and dark.

## Colour

The backend module has no colour tokens of its own. Everything comes from core classes and core custom properties, so both themes and dark mode apply automatically.

Reference values measured in TYPO3 14.3 (theme "fresh") and 13.4, for orientation only, never to hardcode:

| Use | Core source | v14 light | v14 dark | v13 light |
| --- | --- | --- | --- | --- |
| Text | `--typo3-text-color-base` | `#18181b` | `#e4e4e7` | `#1a1a1a` |
| Muted text | `.text-variant` | about `#68686a` | about `#a09fa6` | `#666666` |
| Primary | `.btn-primary`, `--typo3-text-color-primary` | `#412aa2` | `#9785e0` (text) | `#1f5aad` |
| Danger | `.btn-danger`, `.text-danger` | `#b72f36` | `#d65c63` (text) | `#bc3429` |
| Warning callout | `.callout-warning` | `#faeed1` on `#2e2205` | `#2e2205` on `#faeed1` | `#fff2cc` on `#332600` |

- No literal colour values in templates or CSS of the backend module.
- Status is never shown by colour alone: a callout has a title, a badge has a word, an icon has a label.
- The tester inbox runs outside the backend and ships a small stylesheet that copies the v14 light values above as custom properties. It is the only place with own colour values.

## Typography

- The backend module inherits core typography (Verdana 12px body in v13 and v14, core heading fonts). No `font-family` or base `font-size` in Letterbox CSS.
- One `h1` per module page ("Letterbox", "External access"), `h2` for the mail subject and for cards. No skipped levels.
- Mail content is shown in its own fonts inside the preview frame; the frame does not restyle it.

## Layout

| Element | Value |
| --- | --- |
| Module body padding | core (24px) |
| Inbox | two columns: mail list and mail detail, 16px gap |
| Mail list width | 440px, collapsible to a 52px rail |
| List row | subject line plus one meta line, 14px by 18px padding |
| Buttons | core sizes (36px default, 29px `btn-sm` in the doc header) |
| Quick filter | segmented control, full list width, 26px segments |
| Preview frame | full detail width, min height 480px, white mail canvas on a neutral frame |

- Group with core cards and whitespace. A hairline only between rows inside a card.
- `Resources/Public/Css/inbox.css` holds only the two-column layout, the rail, the preview frame, the empty state layout and one exception: the doc header "Clear all" button. Core's `InputButton` always adds `btn-default`, so a `.btn.letterbox-clear-all` rule maps core's danger tokens back. Everything else is core markup.

## Navigation

- The module lives under System › Letterbox, admin only. Its heading is "Letterbox", the line below reads `Mail inbox · <file path> · <N> mails · <size>`.
- Doc header: core breadcrumb row, core button bar with reload and "Clear all". Sub pages (External access) use the core breadcrumb, never an own back bar.
- Every mail has its own URL. The list is a list of links, not buttons.
- The mail list hides from a button in its own header ("Mails 38"); hidden, it leaves a rail with a show button. The state is stored per backend user. A resizable splitter is the alternative under review.

## Mail list

- Each row: subject first and bold, time on the right, then `<sender name> to <first recipient>` in muted text.
- An unread mail has a dot plus visually hidden "Unread". A mail with attachments has a paperclip icon plus visually hidden "has attachments".
- No categories or badges. A mbox file has no reliable source for them.
- The selected row uses the core active list style and `aria-current="true"`; other rows carry no `aria-current`.
- Quick filters: All, Unread, Attachments. Counts as a small number inside the segment, not in brackets.
- Pagination is core pagination below the list, with the range text in a polite live region.

## Mail detail

- Header: subject as `h2`, then a definition list (`dl`) with the rows From, To, Cc and Date. Labels sit in a narrow muted column (48px), values in the second, 4px between rows. An address shows the display name in semibold and the address in muted angle brackets, or only the address in normal weight when there is no name. Cc only when present. The Date row carries the full date and time and ends with "All headers", which jumps to the Headers tab; there is no second, collapsible header block.
- Actions are icon-only buttons with a tooltip and an accessible name that includes the subject: download `.eml`, delete.
- Tabs: HTML, Text, Headers, Source, Links, Attachments (all six ship in Phase 2). Core tab markup with the full ARIA tabs pattern.
- Preview width: icon-only toggles for desktop, tablet and mobile width, with tooltip and accessible name.

## Mail preview

- Rendered only in `<iframe sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc>` with a title naming the mail. Never `allow-scripts`, `allow-same-origin`, `allow-forms` or `allow-top-navigation`.
- The document starts with a fixed shell: CSP `default-src 'none'; img-src data:; style-src 'unsafe-inline'` and `referrer no-referrer`. Mail metas, `base`, scripts, frames, links and foreign-content styles are removed.
- Remote images stay blocked. "Load remote images" works per message and says that it tells the sender the viewer's IP address and that the mail was opened.
- Mails above 2 MiB or that cannot be parsed get no preview; a callout points to Text and Source.

## States

| State | Treatment |
| --- | --- |
| Not configured | Core info callout: what to set (`MAIL/transport`, `transport_mbox_file`) and where |
| No mails | Large centred illustration (the unDraw "empty mailbox", 240px wide), `h2` "No mails caught yet", one sentence on how mails arrive, a Reload button, and the test mail command as a muted hint when debug mode is on. Replaces both columns |
| No mail selected | Detail column shows the 150px illustration, `h2` "No mail selected" and "Pick a mail from the list to read it here." |
| No HTML preview | Core callout, Text tab selected |
| Catching in a Production context (mode `all`) | Warning in toolbar and reports |
| Mode `listed` | Info: "Catching N addresses" |
| External access on | Shown in the External access status card and in reports |

## Toolbar, widget, reports

- Toolbar item: core toolbar button with an envelope icon and a count badge, accessible name "Mail catcher active, N mails caught". Admins only, can be switched off with `showToolbarItem`.
- Dropdown: one sentence on what happens to mails, the latest three subjects, a link to the module.
- Dashboard widget "Recent mails": core widget markup, admin only, subject, recipient and time.
- Reports: core status provider with core severities; no own markup.

## Tester inbox

- A page outside the backend for people without a backend account. Header with page title "Test inbox", system host and a "mails are not delivered" note.
- Landmarks: `header`, `main`, `h1` for the page, `h2` for the list and for the subject.
- A detected reset link is offered at the top of the mail ("Open reset link").
- Touch targets at least 44px. Works down to 320px wide without horizontal scrolling.
- It states whose mails the visitor sees and until when the link is valid.

## Illustration

- One illustration only, the unDraw "empty mailbox" by Katerina Limpitsouni, kept in `Resources/Private/Partials/EmptyIllustration.html`. Inline SVG, recoloured with CSS classes. It appears twice through the same partial with a `width` argument: 240px for the empty inbox and 150px above "No mail selected" in the detail column. It is under the [unDraw license](https://undraw.co/license), not the GPL (see Credits in the README).
- Colours come from `currentColor` and core custom properties: text colour for the outline parts, module background for the light parts, primary text colour for the flag, border colour at 55% opacity for bushes and the circle. No hard-coded hex values. It follows light and dark mode; in forced-colors mode outlines use `CanvasText`, fills `Canvas` and the flag `Highlight`.
- It is decorative: `aria-hidden="true"` and `focusable="false"`. The heading and sentence next to it carry the message.
- Scales down with `max-width: 100%`; it never pushes the heading below the fold at 1280 by 720.

## Motion

- No own animation. Core transitions only. Anything added later goes inside `prefers-reduced-motion: no-preference`.

## Accessibility

- WCAG 2.2 AA and BITV for every surface, in light and dark.
- Semantic HTML: links navigate, buttons act and carry an explicit `type`, forms post with a real submit button. Exception: doc header buttons rendered by core's button bar, whose markup Letterbox cannot set (v13 `InputButton`); in v14 an explicit `type="submit"` would make the confirm dialog submit twice.
- Every icon-only control has an accessible name and a tooltip. Decorative icons are `aria-hidden`.
- Tabs follow the ARIA tabs pattern with arrow keys, Home and End.
- Every input has a label. A visually hidden label is allowed only for the search field, whose magnifier icon is the visible label.
- Focus is always visible and comes from core.
- Check with axe and a keyboard-only pass before a screen is done.

## Copy

- English in the code and the XLIFF source; translations through XLIFF.
- Sentence case for labels and buttons.
- Names: Letterbox, Mail inbox, External access, Tester link, Catch list, Delivery mode.
- Separate facts with ` · `.

## Pull request checklist

- [ ] Only core classes and custom properties in the backend module, no colour literals
- [ ] Mail content only inside the sandboxed preview, nothing rendered raw
- [ ] One `h1`, no skipped heading levels, labelled icon-only controls, explicit button types
- [ ] Light and dark checked on TYPO3 13 and 14, screenshot in the PR
- [ ] axe and a keyboard-only pass without findings
- [ ] A rule that changed is updated in this file
