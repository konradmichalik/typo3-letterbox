# Delivery modes

Back to [documentation overview](README.md).

`transport_letterbox_mode` decides what happens to each recipient of a mail.

| Mode | Caught in the mbox file | Delivered through `transport_letterbox_delivery` |
|------|-------------------------|---------------------------------------------------|
| `all` | Every recipient | Nobody |
| `listed` | Recipients matching `transport_letterbox_catch` | All other recipients |
| `off` | Nobody | Every recipient |

After a [takeover](configuration.md#switch), delivery defaults to the original transport.

## Delivery after a takeover

When the [switch](configuration.md#switch) took over the transport and `transport_letterbox_delivery` is not set, mails that are not caught are delivered through the original `MAIL/transport` and `MAIL/dsn`, as without Letterbox. A configured `transport_letterbox_delivery` wins and is built with the original DSN as well. In a `Production` context the default mode `listed` with an empty catch list delivers every mail this way.

## Default per context

Without `transport_letterbox_mode`, the mode is `listed` in every `Production` context, root or sub (`Production/Staging`, `Production/Live`), and `all` in every other context such as `Development` or `Testing`. A live system may well run in a sub context, so it never catches by default.

A stage system that runs as `Production/Staging` and should catch everything sets `transport_letterbox_mode` to `all` explicitly. Reports then shows a warning for mode `all` in a Production context, which is expected there.

## Mode `listed`

* Patterns are full addresses (`qa@example.com`) or domain wide (`*@letterbox.invalid`). Matching is case insensitive.
* An empty catch list catches nothing, so every mail is delivered.
* The decision is made per recipient from the envelope. A mail to a caught and a real address is stored and delivered, and the delivered copy only goes to the real address. The message headers are not rewritten, so To and Cc still show the caught address.
* `*@letterbox.invalid` uses the reserved `.invalid` top level domain, which never resolves. Validators that check DNS records reject such addresses, so a form with DNS validation may refuse them. Use a real domain you control in that case.

## Stored recipients

Every stored mail carries an `X-Letterbox-Envelope-To` header with the caught envelope recipients. It is needed because Bcc recipients are not part of the message headers. The header is only in the mbox file and never reaches a delivered copy.

## Mode `off`

Mails are delivered as without Letterbox. `transport_mbox_file` is still required, because the transport validates it on startup.

## Personal data

Caught mails can contain personal data of testers and test users. The mbox file keeps their full content, including recipients. [Retention](operations.md#retention) removes old mails (by default beyond 500 mails or after 30 days), the file itself stays. Keep the file outside the web root, restrict access to it and clear it regularly in the module.
