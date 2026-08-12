Listmonk template converted from `../brevo-big.html`.

Files:

- `template.html`: Listmonk campaign template HTML.
- `visual-template.json`: Listmonk visual editor source JSON for a visual campaign template.
- `uploads/assets/images/`: Newsletter image assets downloaded from the Brevo export.
- `uploads/assets/social/`: Active social icon assets.
- `uploads/assets/fonts/`: Local CSS and font files for Bangers 400 and Roboto regular/bold/italic/bold-italic.
- `static/email-templates/base.html`: Branded system email base template.
- `static/public/custom.css`: Branded stylesheet for Listmonk public pages/forms.

Server placement:

- Copy `uploads/*` to `/srv/wirmachenwien/listmonk/uploads/`.
- Copy `static/*` to `/srv/wirmachenwien/listmonk/static/`.

Listmonk requirements included in `template.html`:

- `{{ template "content" . }}` appears exactly once.
- `{{ MessageURL }}` is included for the hosted message link.
- `{{ UnsubscribeURL }}` is included for unsubscribe/preferences.
- `{{ TrackView }}` is included once for open tracking.
- `<title>` uses `{{ .Campaign.Subject }}`.

Asset note: the template references the public Listmonk upload URLs under `https://newsletter.wirmachen.wien/uploads/assets/`.

Font note: email clients do not handle web fonts consistently. `template.html` inlines `@font-face` declarations with absolute font URLs for clients that support them, while all text styles include safe fallback stacks. Headlines use `Bangers, Impact, Arial Black, Arial, sans-serif`; body copy uses `Roboto, Arial, Helvetica, sans-serif`. Outlook/Gmail may use the fallback fonts instead of the hosted fonts.
