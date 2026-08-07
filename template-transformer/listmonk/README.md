Listmonk template converted from `../brevo-big.html`.

Files:

- `template.html`: Listmonk campaign template HTML.
- `assets/images/`: Newsletter image assets downloaded from the Brevo export.
- `assets/social/`: Active social icon assets.
- `assets/fonts/`: Local CSS and font files for Bangers 400 and Roboto regular/bold/italic/bold-italic.

Listmonk requirements included in `template.html`:

- `{{ template "content" . }}` appears exactly once.
- `{{ MessageURL }}` is included for the hosted message link.
- `{{ UnsubscribeURL }}` is included for unsubscribe/preferences.
- `{{ TrackView }}` is included once for open tracking.
- `<title>` uses `{{ .Campaign.Subject }}`.

Asset note: the template references the public Listmonk upload URLs under `https://newsletter.wirmachen.wien/uploads/assets/`.
