# Contact Transformer

Converts a semicolon-delimited Brevo export into two listmonk import CSV files.

Expected input header:

```csv
EMAIL;VORNAME;NACHNAME;ANREDE;JOURFIXE
```

Run:

```bash
python3 convert_contacts.py contacts/data.csv
```

Default outputs are written to `contacts/`:

- `contacts_jourfixe.csv` for contacts where `JOURFIXE` is true
- `contacts_other.csv` for all remaining contacts

The `contacts/` folder is ignored by git and should be used for private input and output files.
