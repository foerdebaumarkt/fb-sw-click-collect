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

## Development

Run `composer install` inside this repository to set up dependencies used for static analysis and testing once they are introduced.
