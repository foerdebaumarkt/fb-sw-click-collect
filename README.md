# Foerde Click & Collect

Shopware 6 plugin that adds click & collect capabilities for Foerdebaumarkt.

## Requirements

- Shopware 6.7
- PHP 8.2 or newer

## Installation

1. Copy the plugin into the `custom/plugins` directory of your Shopware project.
2. Run `bin/console plugin:refresh`.
3. Install and activate the plugin via `bin/console plugin:install --activate FoerdeClickCollect`.

After activation you will find the configuration under **Settings → System → Plugins → Foerde Click & Collect** where you can adjust the pickup window values.

### Reminders (daily)

This plugin sends “ready for pickup” reminders once per day at a configurable time.

- Configuration key: `FoerdeClickCollect.config.reminderRunTime` (HH:MM), default `06:00`.
- Timezone: uses your shop timezone `core.basicInformation.timezone` (Admin → Settings → Shop → Timezone). If unset/invalid, defaults to `Europe/Berlin`.
- Scheduled task: `foerde_click_collect.send_reminders` runs daily and is aligned to the configured HH:MM after each run, and once on plugin activation/update.
- Manual run: `bin/console fb:click-collect:send-reminders`
- Templates: reminders use DB mail templates of type `fb_click_collect.reminder` (required). The command fails if the template or a translation is missing.

Tip: if you change the run time or timezone, the next execution will be realigned automatically after the next run. You can force immediate alignment by running the command once.

## Development

Run `composer install` inside this repository to set up dependencies used for static analysis and testing once they are introduced.
