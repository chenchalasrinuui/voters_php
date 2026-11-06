<div align="center">
<img width="1200" height="475" alt="GHBanner" src="https://ai.google.dev/static/site-assets/images/share-ais-513315318.png" />
</div>

# Run and deploy your AI Studio app

This contains everything you need to run your app locally.

View your app in AI Studio: https://ai.studio/apps/955f49ed-a128-4bf7-ab28-853df747125c

## Run Locally

**Prerequisites:**  Node.js


1. Install dependencies:
   `npm install`
2. Set the `GEMINI_API_KEY` in [.env.local](.env.local) to your Gemini API key
3. Run the app:
   `npm run dev`

## Database update

Before using the out-of-station voter status, apply
[`migrations/20261103_add_voter_out_of_station.sql`](./migrations/20261103_add_voter_out_of_station.sql)
to the database that the PHP API connects to. It adds `out_of_station` and
`out_of_station_phone` columns to `voters`.

## PDF voter photo extraction

Apache PHP upload limits are configured in `.htaccess` for this XAMPP setup:
PDFs up to 128 MB are accepted, with `post_max_size` set slightly higher for
multipart request overhead. If Apache does not permit PHP settings in
`.htaccess`, set the same values in the active `php.ini` and restart Apache.

The PHP server uses Poppler's `pdfimages` command when available to extract
embedded photos from uploaded electoral-roll PDFs. Install Poppler and ensure
`pdfimages` is available on the PHP server's `PATH`. The PHP fallback extracts
embedded JPEG streams and converts Flate-compressed raw pixels to lossless PNG
files. If there are not enough real photos for the voters in the PDF, the
upload fails instead of saving generated/default portraits.
The voter records parsed by the voter ID application are sent with the upload;
the PHP endpoint does not substitute a bundled sample voter dataset.

Apply
[`migrations/20261103_add_voter_out_of_station_location.sql`](./migrations/20261103_add_voter_out_of_station_location.sql)
as well to store the location for out-of-station voters.

Apply
[`migrations/20261104_add_voter_return_expectation.sql`](./migrations/20261104_add_voter_return_expectation.sql)
to store whether an out-of-station voter is expected to return. Existing
out-of-station records remain unclassified until updated.

Apply
[`migrations/20261104_add_admin_user_ownership_and_sessions.sql`](./migrations/20261104_add_admin_user_ownership_and_sessions.sql)
to enable authenticated super-admin sessions and record which super-admin
created each user. Only that super-admin can delete the user's village and
voter records. Sign in again after applying the migration to receive a server
session. Existing users are left unassigned by this migration; set their
`created_by_admin_id` to the verified creator's `admin.id` before enabling
their village deletion.

Apply
[`migrations/20261106_add_voter_family_grouping.sql`](./migrations/20261106_add_voter_family_grouping.sql)
to add the family-head and family-head EPIC fields used to group voters.
Mark the head first, then enter that voter's EPIC on each family member's
details modal. A family head cannot be unmarked until its members are reassigned.
