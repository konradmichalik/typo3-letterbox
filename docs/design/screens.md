# Screen designs

Back to [documentation overview](../README.md).

The reference designs for every Letterbox screen live on a design canvas: https://claude.ai/artifact/2PrmpeU4WsAhDv2Fn8opeH. The canvas is private to its owner until it is shared from its Share menu.

The canvas draws the TYPO3 shell (top bar, module menu) for context only. The module renders its body and doc header; the shell comes from core. Values in the mockups are examples.

The rules behind these screens are in [rules.md](rules.md). When a board changes on the canvas, export it and add or replace its preview in `screens/`.

## Backend module

| Board | Screen | What it settles |
| --- | --- | --- |
| Inbox (light) | Inbox with list and detail | Heading and meta line, list rows, quick filter, pagination, mail header, icon actions, tabs, preview frame and width toggles, list hide button |
| Inbox, list collapsed | Inbox with hidden list | The 52px rail and its show button |
| Inbox (dark) | Same as light in dark mode | Core dark values apply without own tokens |
| Empty inbox (light, dark) | Inbox without mails | Illustration, heading, sentence, Reload and the debug hint in place of both columns |
| Inbox, resizable list | Alternative to the hide button | Splitter with mouse, keyboard and double-click; under review |
| External access | Sub page of the module | Status card, tester links table, create link form, API card with example |

## Outside the backend and system surfaces

| Board | Screen | What it settles |
| --- | --- | --- |
| Tester inbox | Page for testers without a backend account | Header, scope note, list, reset link callout, view switch, 44px targets |
| Toolbar item, dashboard widget, status | Toolbar dropdown, "Recent mails" widget, reports entry | Wording, admin-only content, warning severity |
