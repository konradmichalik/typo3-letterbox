# JSON API

Back to [documentation overview](README.md).

A token-protected, read-mostly API for end-to-end tests, for example to fetch the password reset link of a mail. It needs [external access](external-access.md) to be on, which includes `LETTERBOX_ACCESS_TOKEN` (at least 32 characters). The tester link is derived from the same token, see [external access](external-access.md#tester-inbox). Every client with the token sees and can delete every caught mail of the system.

Every endpoint sits below `<site URL>_letterbox/api/v1/`. On an install in a sub-directory the prefix follows the TYPO3 path, for example `https://example.org/sub/_letterbox/api/v1/`. The API sets no CORS headers and answers every refusal with `404 {"error":"not_found"}`, whether the cause is a wrong token or an inactive guard.

## Authentication

Send the token in a header, either one:

```bash
curl -H "Authorization: Bearer $LETTERBOX_ACCESS_TOKEN" "https://example.org/_letterbox/api/v1/messages/latest?to=anna@example.org"
curl -H "X-Letterbox-Token: $LETTERBOX_ACCESS_TOKEN" "https://example.org/_letterbox/api/v1/messages?limit=5"
```

A token in the query string is never accepted, even a correct one, because query strings end up in logs. Letterbox does not limit failed attempts, so use a long random token.

Apache with PHP-FPM or CGI drops the `Authorization` header unless you add `CGIPassAuth On` to the vhost or `.htaccess`. `X-Letterbox-Token` avoids that, and is also the choice behind basic auth, which uses `Authorization` itself.

## Endpoints

| Request | Answer |
|---------|--------|
| `GET messages` | `{"total": 1, "items": [summary]}`, newest first |
| `GET messages/latest?to=` | Summary of the newest matching mail, `404` when there is none |
| `GET messages/{id}` | Detail |
| `GET messages/{id}/source` | The raw message as `application/octet-stream` |
| `GET messages/{id}/attachments/{index}` | The attachment as `application/octet-stream` |
| `DELETE messages/{id}` | `204`, or `404` when the mail does not exist |
| `DELETE messages` | `204`, the mbox file is cleared |

When the mbox file cannot be changed, for example because it is not writable, both `DELETE` requests answer `500 {"error":"write_failed"}`. TYPO3 logs the exception class at error level, never the message, because it names the file path.

Query parameters of `GET messages`:

| Parameter | Meaning |
|-----------|---------|
| `to` | Exact address (case insensitive) among To, Cc and envelope recipients |
| `subject` | Case insensitive part of the subject |
| `limit` | 1 to 100, default 20 |
| `offset` | 0 to 1000000, default 0 |

`messages/latest` takes `to` only. An invalid value (a non-number, a value out of range, an array) answers `404`. Ids are 32 lowercase hex characters, paths take no trailing slash.

## Response shape

A summary:

```json
{
  "id": "0123456789abcdef0123456789abcdef",
  "messageId": "reset-1@example.org",
  "date": "2026-10-27T10:00:00+01:00",
  "subject": "Reset your password",
  "from": {"address": "noreply@example.org", "name": "Example Shop"},
  "to": [{"address": "anna@example.org", "name": "Anna Example"}],
  "cc": [],
  "attachmentCount": 1,
  "links": [
    {"url": "https://www.example.org/login?tx_felogin_login%5Bhash%5D=abc", "isPasswordReset": true},
    {"url": "https://www.example.org/imprint", "isPasswordReset": false}
  ]
}
```

The detail adds `text`, `html`, `headers` (an object of header name to list of values) and `attachments` (`index`, `filename`, `mimeType`, `size`, `contentId`). `date` and `from` can be `null`. `to` and `cc` list every recipient, Bcc is not part of the shape. Invalid UTF-8 in a mail is substituted, so the JSON is always valid.

`GET messages` and `messages/latest` take every summary field from the mail index. Only `links` needs the text and HTML part, which are read one mail at a time, so a page of mails with large attachments costs the memory of one mail. Attachment content is decoded only for the attachment download.

## Example with Playwright

The goal: one suite that runs against DDEV Mailpit locally and against Letterbox on stage. The helper hides which one is behind it, the test does not know. Because every refusal is a `404`, a wrong token looks like a mail that never arrives: the helper then ends in its timeout.

### Helper: `e2e/mailbox.ts`

Set `LETTERBOX_URL` and `LETTERBOX_ACCESS_TOKEN` on stage, `MAILPIT_URL` in DDEV (for example `https://example.ddev.site:8026`). The Mailpit calls follow its API: `GET /api/v1/search?query=to:...` and `GET /api/v1/message/{ID}`, see the [endpoint reference](https://github.com/axllent/mailpit/blob/develop/_autodocs/endpoints.md).

```ts
export type Link = { url: string; isPasswordReset: boolean };
export type Message = { id: string; subject: string; links: Link[] };

type Read = (to: string) => Promise<Message | null>;

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

async function poll<T>(read: () => Promise<T | null>, timeoutMs = 30_000): Promise<T> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const value = await read();
    if (value !== null) return value;
    await sleep(1_000);
  }
  throw new Error('No matching mail arrived in time');
}

function mailbox(latestMessage: Read) {
  return {
    latestMessage,
    // afterId skips the mail that was already there before the test triggered a new one
    resetLink: (to: string, afterId?: string) =>
      poll(async () => {
        const message = await latestMessage(to);
        if (message === null || message.id === afterId) return null;
        return message.links.find((link) => link.isPasswordReset)?.url ?? null;
      }),
  };
}

function letterbox(baseUrl: string, token: string) {
  return mailbox(async (to) => {
    const response = await fetch(`${baseUrl}/_letterbox/api/v1/messages/latest?to=${encodeURIComponent(to)}`, {
      headers: { 'X-Letterbox-Token': token },
    });
    return response.ok ? ((await response.json()) as Message) : null;
  });
}

// Mailpit has no link list, so the links come from the text part with the same rule as Letterbox.
function isPasswordReset(url: string): boolean {
  const { pathname, searchParams } = new URL(url);
  return (
    pathname.toLowerCase().replace(/\/+$/, '').endsWith('/login/password-reset/validate') ||
    searchParams.has('tx_felogin_login[hash]') ||
    searchParams.get('tx_felogin_login[action]') === 'showChangePassword'
  );
}

function mailpit(baseUrl: string) {
  return mailbox(async (to) => {
    const search = await fetch(`${baseUrl}/api/v1/search?query=${encodeURIComponent(`to:${to}`)}&limit=1`);
    const hit = ((await search.json()) as { messages: { ID: string }[] }).messages[0];
    if (hit === undefined) return null;

    const detail = (await (await fetch(`${baseUrl}/api/v1/message/${hit.ID}`)).json()) as {
      ID: string;
      Subject: string;
      Text: string;
    };
    const urls = detail.Text.match(/https?:\/\/[^\s<>"')\]]+/g) ?? [];
    return {
      id: detail.ID,
      subject: detail.Subject,
      links: urls.map((url) => ({ url, isPasswordReset: isPasswordReset(url) })),
    };
  });
}

export const mails = process.env.MAILPIT_URL
  ? mailpit(process.env.MAILPIT_URL)
  : letterbox(process.env.LETTERBOX_URL!, process.env.LETTERBOX_ACCESS_TOKEN!);
```

### Test: `e2e/password-reset.spec.ts`

The page `/forgot-password` carries the felogin "forgot password" plugin and `anna@example.org` is a frontend user. The ids are those of the core felogin templates, adjust them to your own templates.

```ts
import { expect, test } from '@playwright/test';
import { mails } from './mailbox';

test('a frontend user sets a new password through the reset mail', async ({ page }) => {
  test.setTimeout(60_000);
  const to = 'anna@example.org';
  const before = await mails.latestMessage(to);

  await page.goto('/forgot-password');
  await page.locator('#tx-felogin-input-data').fill(to);
  await page.locator('form input[type="submit"]').click();

  const resetLink = await mails.resetLink(to, before?.id);

  await page.goto(resetLink);
  await page.locator('#tx-felogin-input-newpassword-1').fill('N3w-Passw0rd-for-tests');
  await page.locator('#tx-felogin-input-newpassword-2').fill('N3w-Passw0rd-for-tests');
  await page.locator('form input[type="submit"]').click();

  await expect(page.locator('#tx-felogin-input-newpassword-1')).toHaveCount(0);
});
```
