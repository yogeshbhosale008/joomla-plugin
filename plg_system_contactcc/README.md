# Contact CC for Joomla

Adds a **CC Email Addresses** field to each contact in Joomla's Contacts component, and sends a copy of every contact form message to those addresses. Works natively on Joomla 4 and 5.

## Features

- **CC field right below Email** on the Contact edit screen.
- **Site-wide default CC list**, added to every contact.
- **Replies go to the visitor:** replying to the copy answers the person who filled in the form.
- **Custom fields included:** checkbox, list and radio answers show their option text.
- **Address check on save:** invalid addresses are rejected, with a message naming them.
- **Safe for visitors:** if the copy fails to send, the problem is logged and the visitor's submission still goes through.
- **No compatibility plugin needed:** runs natively on Joomla 4.0+ and 5.x.

## Requirements

- Joomla 4.0 or later, including Joomla 5.x
- PHP 8.1 or later
- Working mail settings in **System → Global Configuration → Server → Mail**

## Installation

1. Download `plg_system_contactcc.zip` from the [Releases page](https://github.com/yogeshbhosale008/plg_system_contactcc/releases).
2. In Joomla, go to **System → Install → Extensions** and upload the zip.
3. Go to **System → Manage → Plugins**, search for `Contact CC` and enable **System - Contact CC**.

## Setup

### For one contact

1. Go to **Components → Contacts** and edit a contact.
2. Fill in **CC Email Addresses**, directly below **Email**. Separate addresses with commas, for example `sales@example.com, manager@example.com`. Semicolons, spaces and new lines work too.
3. Save. Invalid addresses are rejected with a message naming them.

### For every contact (optional)

Open the plugin in **System → Manage → Plugins**:

| Option | What it does |
|---|---|
| **Default CC Email Addresses** | Added to the CC list of every contact, on top of each contact's own addresses. |
| **Include Custom Fields** | **Yes** (default): adds the submitted custom fields to the copy. |

## What the copy looks like

Joomla's normal email to the contact is unchanged. The copy is a separate email:

- **To:** the contact's CC addresses plus the default list, with duplicates removed. The contact's own email address is skipped, because it already gets Joomla's normal email.
- **From:** your site's mail settings.
- **Reply-To:** the visitor.
- **Subject:** `[CC] ` followed by the visitor's subject.

```text
This is a copy of a message sent through the contact form on Example Site (https://example.com/).

Contact: Sales Team
Date: Friday, 27 November 2026 13:45

Name: Jane Doe
Email: jane@customer.com
Subject: Pricing question

Message:
Hello,
I need 3 terminals.

Additional fields:
Phone Number: +966 500 000 000
Interests: Point of Sale, Inventory

--
Replying to this email will reply to Jane Doe <jane@customer.com>.
```

The copy is sent even when the contact's **Custom Reply** option is turned on.

### Custom fields

The copy includes fields made for the Contact **Mail** context (**Components → Contacts → Fields**, context *Mail*) that the visitor filled in. Empty fields are left out.

## Troubleshooting

**No copy arrives**
- Check that **System - Contact CC** is enabled.
- Check that Joomla can send mail: **Global Configuration → Server → Send Test Mail**.
- Look in `administrator/logs/plg_system_contactcc.php`. Failed sends and skipped addresses are logged there and never shown to visitors.

**The CC field doesn't appear on the Contact edit screen**
- Check that the plugin is enabled.
- An admin template override of the contact edit screen (`administrator/templates/<template>/html/com_contact/contact/edit.php`) that doesn't render the `email_to` field will hide it too.

**Why is it a system plugin and not a contact plugin?**
Joomla only loads content and system plugins on the Contact edit screen, so a contact plugin could never add the field there.

## Updates

New versions show up in Joomla under **System → Update → Extensions**.

## Support

Report problems or ask questions on [GitHub Issues](https://github.com/yogeshbhosale008/plg_system_contactcc/issues).

## License

GNU General Public License version 2 or later. See [LICENSE.txt](LICENSE.txt).
