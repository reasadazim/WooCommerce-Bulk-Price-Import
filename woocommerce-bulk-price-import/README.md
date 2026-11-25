# WooCommerce Bulk Price Import

Import regular prices for existing WooCommerce product variations from a CSV. The plugin matches each row to a parent product SKU, then matches each price column to a size. You can review the variation IDs and prices before applying the changes.

## Install

Requires WordPress 6.2 or later, PHP 7.4 or later, and an active WooCommerce installation.

1. In WordPress, go to **Plugins → Add New → Upload Plugin**.
2. Upload `woocommerce-bulk-price-import.zip` and activate **WooCommerce Bulk Price Import**. If replacing the older plugin installed as `sku-size-price-importer`, deactivate that copy first.
3. Open **WooCommerce → Bulk Price Import** with an account that can manage WooCommerce.

## Prepare the CSV

Start with [example-prices.csv](example-prices.csv). Replace the fictional SKUs and prices with your own. Save the file as **UTF-8, comma-separated CSV**. Each upload can contain up to 10,000 data records and must be 5 MB or smaller.

```csv
SKU,S,M,L,XL
DEMO-001,25,27,29,31
DEMO-002,30,,34,36
```

| Field | What to enter |
| --- | --- |
| First column | One row per parent variable product SKU. The first header can be `SKU` or another label. |
| Remaining headers | One column for each size. Use the size names or slugs from your selected attribute. Columns can be in any order. |
| Price cells | Nonnegative regular prices in the store currency, using a decimal point if needed. For example, enter `25.50` without a currency symbol or thousands separator. |
| Blank price | Leave a cell empty to keep the current regular price. In the sample, `DEMO-002` keeps its M price. Enter `0` only when you intend to set a zero price. |

If a SKU appears more than once in the CSV, the importer skips every row for that SKU. Combine the intended prices into one row before uploading it again.

## Preview and import

1. Set **Size attribute slug** to the attribute used by the variations. A global Size attribute commonly uses `pa_size`. A custom Size attribute commonly uses `size`.
2. Review the matching options. Both are selected by default for a new preview.
   - **Update all matching variations for each size** applies the CSV price to every eligible match. Clear it to require exactly one eligible variation per size.
   - **Skip variations without their own image** requires an image assigned directly to the variation. A variation that only inherits the parent product image is skipped.
3. Choose the CSV and click **Upload and preview**. Check the variation IDs, current and proposed prices, and **Validation and import messages**. Upload a fresh preview after changing the file or matching options.
4. Click **Import first 10 prices** and use **Continue import** until complete. Each request processes up to 10 variation prices and stops after the current item once it reaches the 10-second target. The preview expires after one hour of inactivity.
5. Check the **Result** column and validation messages, then verify the updated prices on the product page.

The importer updates regular prices on existing variations. Active sale prices can still determine the storefront price. If a proposed regular price is at or below the existing sale price, the importer skips that change to preserve the sale. Missing sizes, variations set to **Any size**, and changes that would require rounding beyond the store's price precision are also skipped.

## Resume an interrupted import

Click **Refresh status** to see saved progress, then use **Continue import** if changes are still pending. To replace the preview, wait for any active request to finish and click **Reset and start again**. Reset clears the preview. Prices already saved remain in place. If a variation reports a save or synchronization error, verify its current price in WooCommerce before retrying.
