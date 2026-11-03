# Installation & Configuration

Set the following environment variables before starting the application:

- `APP_ENV` sets the runtime environment (`development` or `production`).
- Database connection variables:
  - `DB_HOST`
  - `DB_PORT`
  - `DB_NAME`
  - `DB_USER`
  - `DB_PASSWORD`
  - `DB_CHARSET`

# Colis Search & Grouping

The menu entry **Rechercher Colis** opens `/colis/search` where parcels can be searched using the quick search field or advanced filters. Parent parcels are shown with a **G** badge and their children appear below them. Selecting a parent highlights its children.
Multiple search terms may be entered in the free search field, separated by spaces.
