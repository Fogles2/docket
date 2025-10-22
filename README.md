```markdown
# Court Docket Analyzer (multi-range)

This project scrapes and categorizes Clayton County court dockets. New: multiple pages for different docket ranges:

- 24 hour (docket_24.php)
- 48 hour (docket_48.php)
- 5 day (docket_5day.php)
- 15 day (docket_15day.php)
- 30 day (docket_30day.php)

Each page:
- Fetches the specific docket range (via ?days= parameter behind the scenes).
- Saves raw HTML to last_docket_<days>.html
- Enriches parsed records (crime_category, age_range) and stores them in SQLite with a source_days field.
- Shows session summary and DB statistics filtered by that source_days.

Quick start:
1. Upload files to your PHP webserver.
2. Ensure PHP extensions: sqlite3 and curl (preferred). If cURL isn't available, allow_url_fopen is required.
3. Ensure the web server user can write to the project directory.
4. Visit index.php then open a docket page and click "Update Now".

If parsing returns 0 records, open the generated last_docket_<days>.html and paste the relevant snippet so parsing rules can be refined.
```