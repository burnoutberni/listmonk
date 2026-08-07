# Brevo to listmonk

Utilities and migration artifacts for moving newsletter data and templates from Brevo to listmonk.

## Contents

- `contact-transformer/`: Converts a Brevo contact CSV export into listmonk import CSV files.
- `template-transformer/brevo-big.html`: Original Brevo newsletter HTML export used as the source template.
- `template-transformer/listmonk/`: Converted listmonk campaign template plus the image, social icon, and font assets it needs.

## Contacts

The contact transformer expects a semicolon-delimited Brevo export with this header:

```csv
EMAIL;VORNAME;NACHNAME;ANREDE;JOURFIXE
```

Run it from `contact-transformer/`:

```bash
python3 convert_contacts.py contacts/data.csv
```

Generated contact CSVs are written to `contact-transformer/contacts/`. That folder is ignored by git because it contains private subscriber data.

## Template

The listmonk template is in `template-transformer/listmonk/template.html`.

It includes listmonk template placeholders for campaign content, hosted-message links, unsubscribe/preferences links, and open tracking. Asset URLs point to the public listmonk upload path at `https://newsletter.wirmachen.wien/uploads/assets/`.

The committed assets are the source copy for that upload folder. If an asset changes, update `template-transformer/listmonk/assets/` and upload the matching files to the server.
