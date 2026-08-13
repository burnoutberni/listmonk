# Wir machen Wien Listmonk Setup

Configuration, templates, assets, and helper scripts for the Wir machen Wien Listmonk setup.

## Contents

- `listmonk/`: Deployable Listmonk customizations, templates, public assets, and visual template source.
- `scripts/`: Helper scripts for one-off maintenance and migration tasks.
- `wordpress/listmonk-signup/`: Site-specific WordPress shortcode plugin for Listmonk newsletter signups.
- `legacy/brevo/`: Original Brevo exports kept only as historical source material.

## Deployable Listmonk Files

The `listmonk/` folder mirrors server destinations:

- `listmonk/uploads/*` -> `/srv/wirmachenwien/listmonk/uploads/`
- `listmonk/static/*` -> `/srv/wirmachenwien/listmonk/static/`

The public asset URLs are served under:

```text
https://newsletter.wirmachen.wien/uploads/assets/
```

Listmonk appearance CSS is DB-backed, so `listmonk/static/public/custom.css` is the canonical source file, but its contents must be pasted into Listmonk's public appearance settings to affect `/public/custom.css`.

## Contact Import Script

The contact converter expects a semicolon-delimited Brevo export with this header:

```csv
EMAIL;VORNAME;NACHNAME;ANREDE;JOURFIXE
```

Run it from `scripts/`:

```bash
python3 convert_contacts.py contacts/data.csv
```

Generated contact CSVs are written to `scripts/contacts/`. That folder is ignored by git because it contains private subscriber data.

## Contributors

- Bernhard Hayden

## License

This project is licensed under the GNU Affero General Public License v3.0 or later. See `LICENSE`.
