# WooCommerce Bulk Price Import

A WordPress plugin for updating WooCommerce variation regular prices from a CSV. It matches prices by parent product SKU and size, previews the proposed changes, and saves approved changes in small batches.

- [Installation and import guide](woocommerce-bulk-price-import/README.md)
- [Example CSV](woocommerce-bulk-price-import/example-prices.csv)
- [Installable plugin ZIP](woocommerce-bulk-price-import.zip)

## Requirements

- WordPress 6.2 or later
- PHP 7.4 or later
- WooCommerce activated

## Project structure

| Path | Purpose |
| --- | --- |
| [woocommerce-bulk-price-import.php](woocommerce-bulk-price-import/woocommerce-bulk-price-import.php) | Plugin entry point, admin screen, preview and import workflow |
| [class-sspi-csv.php](woocommerce-bulk-price-import/includes/class-sspi-csv.php) | CSV parsing and validation |
| [Plugin README](woocommerce-bulk-price-import/README.md) | Installation, CSV format, import settings, and recovery |
| [example-prices.csv](woocommerce-bulk-price-import/example-prices.csv) | Sample prices and fictional product SKUs |

## Build the plugin ZIP

Archive the `woocommerce-bulk-price-import/` directory as `woocommerce-bulk-price-import.zip`. The archive should contain the plugin directory at its root. From the repository root in PowerShell, run:

```powershell
Compress-Archive -LiteralPath .\woocommerce-bulk-price-import -DestinationPath .\woocommerce-bulk-price-import.zip -Force
```

In WordPress, upload the ZIP through **Plugins → Add New → Upload Plugin**. After activation, open **WooCommerce → Bulk Price Import**.

## Author and license

[Reasad Azim](https://reasadazim.com) · GPL-2.0-or-later
