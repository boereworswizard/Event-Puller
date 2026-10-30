# Festival Sync

WordPress plugin that imports events from the Goabase API into the `festival` post type (ACF fields).

Install: copy the `festival-sync` folder to `wp-content/plugins/`, activate, then go to **Festivals > Festival Sync**.

- Choose event types (Festival, Open Air, Club, ...), countries, status for new posts and a schedule.
- **Preview (dry run)** lists what would be created / matched without saving.
- Matching: stored source ID, then normalised title + start date within 3 days. The same title in another year is treated as a new edition.
- Existing festivals you created are only filled where fields are empty. Posts created by the plugin are refreshed on each sync (optional).
- No links back to the source are stored or shown; source URLs are stripped from imported text. The source ID is kept in hidden meta `_gfs_source_id` for matching only.
- Filters: `gfs_api_query_args` to add query parameters to the API request.
