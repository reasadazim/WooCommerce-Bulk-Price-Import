<?php
/**
 * Plugin Name: WooCommerce Bulk Price Import
 * Description: Update regular prices for WooCommerce variations in bulk from a CSV. Match by parent SKU and size, then preview changes before importing.
 * Version: 1.0.5
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Reasad Azim
 * Author URI: https://reasadazim.com
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/includes/class-sspi-csv.php';

final class SSPI_Importer {
    const KEY = 'sspi_price_plan_v103_';
    const LOCK = 'sspi_store_import_lock';
    const BATCH_SIZE = 10;
    const BATCH_SECONDS = 10;

    private static function fingerprint( $variation ) {
        $from = $variation->get_date_on_sale_from( 'edit' );
        $to = $variation->get_date_on_sale_to( 'edit' );
        return hash( 'sha256', serialize( array( $variation->get_attributes(), $from ? $from->getTimestamp() : null, $to ? $to->getTimestamp() : null, $variation->get_status( 'edit' ), $variation->get_image_id( 'edit' ) ) ) );
    }

    private static function valid_price( $price ) {
        // Reject precision loss rather than silently round a client's price.
        $parts = explode( '.', $price );
        return strlen( ltrim( $parts[0], '0' ) ) <= 12 && strlen( rtrim( $parts[1] ?? '', '0' ) ) <= wc_get_price_decimals();
    }

    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
    }

    public static function menu() {
        add_submenu_page( 'woocommerce', 'WooCommerce Bulk Price Import', 'Bulk Price Import', 'manage_woocommerce', 'sspi-import', array( __CLASS__, 'page' ) );
    }

    private static function key() {
        return self::KEY . get_current_user_id();
    }

    private static function acquire_lock() {
        $lock = array( 'token' => wp_generate_password( 32, false ), 'updated' => time() );
        if ( ! add_option( self::LOCK, $lock, '', false ) ) {
            $existing = get_option( self::LOCK );
            // Also recognize the timestamp-only locks used by earlier releases.
            $updated = is_array( $existing ) ? $existing['updated'] : (int) $existing;
            if ( $updated >= time() - HOUR_IN_SECONDS ) {
                throw new RuntimeException( 'Another preview or import is running. Use Refresh status to check progress, then retry. Interrupted locks expire after one hour.' );
            }
            delete_option( self::LOCK );
            if ( ! add_option( self::LOCK, $lock, '', false ) ) {
                throw new RuntimeException( 'Another preview or import started. Refresh status and retry.' );
            }
        }
        // PHP timeouts and fatal errors bypass finally, but normally run shutdown handlers.
        register_shutdown_function( static function () use ( $lock ) {
            self::release_lock( $lock['token'] );
        } );
        return $lock['token'];
    }

    private static function release_lock( $token ) {
        $lock = get_option( self::LOCK );
        if ( is_array( $lock ) && hash_equals( $lock['token'], $token ) ) {
            delete_option( self::LOCK );
        }
    }

    private static function heartbeat( $token ) {
        $lock = get_option( self::LOCK );
        if ( ! is_array( $lock ) || ! hash_equals( $lock['token'], $token ) ) {
            throw new RuntimeException( 'The import lock changed. Refresh status before continuing.' );
        }
        $lock['updated'] = time();
        update_option( self::LOCK, $lock, false );
    }

    private static function normalize( $value ) {
        return strtolower( trim( rawurldecode( (string) $value ) ) );
    }

    private static function attributes() {
        $options = array( 'size' => 'Custom product attribute: Size' );
        foreach ( wc_get_attribute_taxonomies() as $attribute ) {
            $options[ wc_attribute_taxonomy_name( $attribute->attribute_name ) ] = $attribute->attribute_label . ' (global)';
        }
        return $options;
    }

    /** Match explicit variation values only; an Any-size variation is never inferred. */
    private static function matches( $variation, $attribute, $size ) {
        $attributes = $variation->get_attributes();
        if ( ! isset( $attributes[ $attribute ] ) || '' === $attributes[ $attribute ] ) {
            return false;
        }
        $value = $attributes[ $attribute ];
        if ( self::normalize( $value ) === self::normalize( $size ) ) {
            return true;
        }
        if ( taxonomy_exists( $attribute ) ) {
            $term = get_term_by( 'slug', $value, $attribute );
            return $term && self::normalize( $term->name ) === self::normalize( $size );
        }
        return false;
    }

    private static function preview( $parsed, $attribute, $all_matches = false, $skip_no_image = false ) {
        $plan = array( 'items' => array(), 'messages' => $parsed['messages'], 'offset' => 0, 'updated' => 0, 'skipped' => 0, 'attribute' => $attribute, 'created' => time(), 'currency' => get_woocommerce_currency(), 'decimals' => wc_get_price_decimals(), 'all_matches' => $all_matches, 'skip_no_image' => $skip_no_image );
        $targets = array();
        foreach ( $parsed['rows'] as $row ) {
            $id = wc_get_product_id_by_sku( $row['sku'] );
            $product = $id ? wc_get_product( $id ) : false;
            if ( ! $product || ! $product->is_type( 'variable' ) ) {
                $plan['messages'][] = "Line {$row['line']}: SKU {$row['sku']} is not an existing variable parent product; skipped.";
                continue;
            }
            $children = array();
            foreach ( array_unique( $product->get_children() ) as $child_id ) {
                $child = wc_get_product( $child_id );
                if ( $child && $child->is_type( 'variation' ) ) {
                    $children[] = $child;
                }
            }
            foreach ( $row['prices'] as $size => $price ) {
                if ( ! self::valid_price( $price ) ) {
                    $plan['messages'][] = "{$row['sku']} / {$size}: price exceeds store decimal precision or 12 integer digits; skipped.";
                    continue;
                }
                $found = array();
                foreach ( $children as $child ) {
                    if ( self::matches( $child, $attribute, $size ) ) {
                        // edit context excludes WooCommerce's inherited parent image.
                        if ( $skip_no_image && ! $child->get_image_id( 'edit' ) ) {
                            $plan['messages'][] = "{$row['sku']} / {$size}: variation #" . $child->get_id() . ' skipped because it has no own variation image.';
                            continue;
                        }
                        $found[] = $child;
                    }
                }
                if ( ! $found || ( ! $all_matches && count( $found ) !== 1 ) ) {
                    $plan['messages'][] = "Line {$row['line']}: {$row['sku']} / {$size}: " . count( $found ) . ' eligible matching variations; skipped. Single-match mode requires exactly one eligible variation.';
                    foreach ( $found as $candidate ) {
                        $details = array();
                        foreach ( $candidate->get_attributes() as $name => $value ) {
                            $display = '' === $value ? '(Any)' : $value;
                            if ( '' !== $value && taxonomy_exists( $name ) ) {
                                $term = get_term_by( 'slug', $value, $name );
                                if ( $term ) {
                                    $display .= ' (term name: ' . $term->name . ')';
                                }
                            }
                            $details[] = $name . '=' . $display;
                        }
                        $plan['messages'][] = "{$row['sku']} / {$size}: matched variation #" . $candidate->get_id() . ' on parent #' . $id . '; status=' . $candidate->get_status( 'edit' ) . '; attributes: ' . implode( ', ', $details ) . '; regular price=' . $candidate->get_regular_price( 'edit' ) . '.';
                    }
                    continue;
                }
                foreach ( $found as $child ) {
                    $child_id = $child->get_id();
                    if ( isset( $targets[ $child_id ] ) ) {
                        $plan['messages'][] = "Variation {$child_id} was targeted more than once; all its changes were skipped.";
                        $plan['items'][ $targets[ $child_id ] ]['blocked'] = true;
                        continue;
                    }
                    $price = wc_format_decimal( $price, wc_get_price_decimals() );
                    if ( '' !== $child->get_sale_price( 'edit' ) && (float) $price <= (float) $child->get_sale_price( 'edit' ) ) {
                        $plan['messages'][] = "{$row['sku']} / {$size} / variation #{$child_id}: new regular price must exceed the existing sale price; skipped to preserve the sale price.";
                        continue;
                    }
                    if ( $child->get_regular_price( 'edit' ) === $price ) {
                        continue;
                    }
                    $targets[ $child_id ] = count( $plan['items'] );
                    $plan['items'][] = array( 'id' => $child_id, 'parent' => $id, 'sku' => $product->get_sku( 'edit' ), 'size' => $size, 'old' => $child->get_regular_price( 'edit' ), 'new' => $price, 'sale' => $child->get_sale_price( 'edit' ), 'fingerprint' => self::fingerprint( $child ), 'status' => 'Pending' );
                }
            }
        }
        $plan['items'] = array_values( array_filter( $plan['items'], static function ( $item ) { return empty( $item['blocked'] ); } ) );
        return $plan;
    }

    private static function batch( &$plan, $lock_token ) {
        if ( $plan['currency'] !== get_woocommerce_currency() || $plan['decimals'] !== wc_get_price_decimals() ) {
            throw new RuntimeException( 'Store currency or decimal settings changed. Upload and preview again.' );
        }
        $started = microtime( true );
        $first = $plan['offset'];
        $stop = min( $first + self::BATCH_SIZE, count( $plan['items'] ) );
        for ( ; $plan['offset'] < $stop; $plan['offset']++ ) {
            // Finish the current item, then yield before starting another slow save.
            if ( $plan['offset'] > $first && microtime( true ) - $started >= self::BATCH_SECONDS ) {
                break;
            }
            self::heartbeat( $lock_token );
            $item = $plan['items'][ $plan['offset'] ];
            $saved = false;
            try {
                $product = wc_get_product( $item['parent'] );
                $variation = wc_get_product( $item['id'] );
                if ( ! $product || ! $product->is_type( 'variable' ) || $product->get_sku( 'edit' ) !== $item['sku'] || ! $variation || ! $variation->is_type( 'variation' ) || $variation->get_parent_id() !== $item['parent'] || ! self::matches( $variation, $plan['attribute'], $item['size'] ) || $variation->get_regular_price( 'edit' ) !== $item['old'] || $variation->get_sale_price( 'edit' ) !== $item['sale'] || self::fingerprint( $variation ) !== $item['fingerprint'] ) {
                    throw new RuntimeException( 'Product changed since preview. Preview again to update it.' );
                }
                if ( $plan['skip_no_image'] && ! $variation->get_image_id( 'edit' ) ) {
                    throw new RuntimeException( 'Variation has no own image; skipped.' );
                }
                $matches = array();
                foreach ( array_unique( $product->get_children() ) as $child_id ) {
                    $child = wc_get_product( $child_id );
                    if ( $child && $child->is_type( 'variation' ) && self::matches( $child, $plan['attribute'], $item['size'] ) && ( ! $plan['skip_no_image'] || $child->get_image_id( 'edit' ) ) ) {
                        $matches[] = $child->get_id();
                    }
                }
                if ( ! in_array( $item['id'], $matches, true ) || ( ! $plan['all_matches'] && array( $item['id'] ) !== $matches ) ) {
                    throw new RuntimeException( 'Eligible size matches changed. Upload and preview again.' );
                }
                // WooCommerce CRUD maintains effective price, sale scheduling and lookup data.
                $variation->set_regular_price( $item['new'] );
                if ( ! $variation->save() ) {
                    throw new RuntimeException( 'WooCommerce did not confirm the save. Check the product before retrying.' );
                }
                $saved = true;
                $plan['updated']++;
                $plan['items'][ $plan['offset'] ]['status'] = 'Updated';
                WC_Product_Variable::sync( $item['parent'] );
                wc_delete_product_transients( $item['parent'] );
            } catch ( Throwable $error ) {
                if ( ! $saved ) {
                    $plan['skipped']++;
                }
                $plan['items'][ $plan['offset'] ]['status'] = $saved ? 'Saved; synchronization failed. Check product.' : 'Not completed; check message and product.';
                $plan['messages'][] = "{$item['sku']} / {$item['size']} / variation #{$item['id']}: " . $error->getMessage();
            }
            // Persist after each item so a retry cannot replay an already completed batch.
            $checkpoint = $plan;
            $checkpoint['started'] = true;
            $checkpoint['offset']++;
            set_transient( self::key(), $checkpoint, HOUR_IN_SECONDS );
        }
    }

    public static function page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'You do not have permission to import prices.' );
        }
        echo '<div class="wrap"><h1>WooCommerce Bulk Price Import</h1>';
        if ( ! function_exists( 'wc_get_product' ) ) {
            echo '<p>Activate WooCommerce to use this importer.</p></div>';
            return;
        }
        $error = '';
        $notice = '';
        $page_url = admin_url( 'admin.php?page=sspi-import' );
        $plan = get_transient( self::key() );
        $attributes = self::attributes();
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
            check_admin_referer( 'sspi_import' );
            $action = isset( $_POST['sspi_action'] ) ? sanitize_key( $_POST['sspi_action'] ) : '';
            $lock_token = false;
            try {
                if ( ! in_array( $action, array( 'preview', 'apply', 'reset' ), true ) ) {
                    throw new RuntimeException( 'Unknown import action. Refresh status and retry.' );
                }
                $lock_token = self::acquire_lock();
                // Another request may have finished after this page first loaded its plan.
                $plan = get_transient( self::key() );
                if ( 'preview' === $action ) {
                    delete_transient( self::key() );
                    $plan = false;
                    $attribute = isset( $_POST['size_attribute'] ) ? sanitize_key( wp_unslash( $_POST['size_attribute'] ) ) : '';
                    if ( ! $attribute || strlen( $attribute ) > 100 ) {
                        throw new RuntimeException( 'Enter the size attribute slug, for example pa_size or size.' );
                    }
                    if ( empty( $_FILES['price_csv'] ) || UPLOAD_ERR_OK !== $_FILES['price_csv']['error'] || ! is_uploaded_file( $_FILES['price_csv']['tmp_name'] ) ) {
                        throw new RuntimeException( 'Select a valid CSV upload.' );
                    }
                    if ( strtolower( pathinfo( $_FILES['price_csv']['name'], PATHINFO_EXTENSION ) ) !== 'csv' || $_FILES['price_csv']['size'] > 5 * 1024 * 1024 ) {
                        throw new RuntimeException( 'Upload a .csv file of at most 5 MB.' );
                    }
                    $parsed = SSPI_CSV::parse( $_FILES['price_csv']['tmp_name'] );
                    $plan = self::preview( $parsed, $attribute, isset( $_POST['all_matches'] ) && '1' === $_POST['all_matches'], isset( $_POST['skip_no_image'] ) && '1' === $_POST['skip_no_image'] );
                    $plan['token'] = wp_generate_password( 32, false );
                    set_transient( self::key(), $plan, HOUR_IN_SECONDS );
                } else {
                    if ( $plan && ( empty( $_POST['plan_token'] ) || ! hash_equals( $plan['token'], (string) wp_unslash( $_POST['plan_token'] ) ) ) ) {
                        throw new RuntimeException( 'Preview expired or was replaced. Upload the CSV again.' );
                    }
                    if ( 'reset' === $action ) {
                        delete_transient( self::key() );
                        $plan = false;
                        $notice = 'Import reset. Upload the CSV again to create a fresh preview. Prices already saved are kept; unchanged prices will not be imported again.';
                    } else {
                        if ( ! $plan ) {
                            throw new RuntimeException( 'Preview expired or was replaced. Upload the CSV again.' );
                        }
                        if ( ! isset( $_POST['plan_offset'] ) || (string) $plan['offset'] !== (string) wp_unslash( $_POST['plan_offset'] ) ) {
                            throw new RuntimeException( 'This batch was already submitted. Review the current progress below.' );
                        }
                        self::batch( $plan, $lock_token );
                        $plan['started'] = true;
                        set_transient( self::key(), $plan, HOUR_IN_SECONDS );
                    }
                }
            } catch ( Throwable $exception ) {
                $error = $exception->getMessage();
            } finally {
                if ( $lock_token ) {
                    self::release_lock( $lock_token );
                }
            }
            // Show the latest checkpoint even when a request could not proceed.
            $plan = get_transient( self::key() );
        }
        if ( $error ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
        }
        if ( $notice ) {
            echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
        }
        echo '<p>Import regular prices into existing variations. Column 1 is the parent product SKU (any header, including Latin). Remaining headers are size values, such as S, M, L, XL, in any order.</p>';
        echo '<p>Blank prices and duplicate SKU rows in the CSV are skipped. Choose whether to update all size matches or require one match, and whether to skip variations without their own image. Existing sale prices remain in place. Prices use your store currency and tax-entry settings.</p>';
        echo '<form method="post" action="' . esc_url( $page_url ) . '">';
        wp_nonce_field( 'sspi_import' );
        echo '<input type="hidden" name="sspi_action" value="reset"><input type="hidden" name="plan_token" value="' . esc_attr( $plan ? $plan['token'] : '' ) . '">';
        echo '<p><a class="button button-secondary" href="' . esc_url( $page_url ) . '">Refresh status</a> ';
        submit_button( 'Reset and start again', 'secondary', 'submit', false );
        echo '</p><p class="description">Refresh status loads the latest saved progress without submitting a batch. Reset clears your preview so you can upload the CSV again. Prices already saved are kept. A running request must finish before resetting.</p></form>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( $page_url ) . '">';
        wp_nonce_field( 'sspi_import' );
        echo '<input type="hidden" name="sspi_action" value="preview"><p><label>Size attribute slug <input name="size_attribute" list="sspi-attributes" value="' . esc_attr( $plan ? $plan['attribute'] : ( isset( $attributes['pa_size'] ) ? 'pa_size' : 'size' ) ) . '" required></label><datalist id="sspi-attributes">';
        foreach ( $attributes as $slug => $label ) {
            echo '<option value="' . esc_attr( $slug ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '</datalist> <span class="description">Global Size is usually pa_size; a custom Size attribute is size.</span></p>';
        echo '<p><label><input type="checkbox" name="all_matches" value="1"' . ( ! $plan || $plan['all_matches'] ? ' checked' : '' ) . '> Update all matching variations for each size</label></p>';
        echo '<p><label><input type="checkbox" name="skip_no_image" value="1"' . ( ! $plan || $plan['skip_no_image'] ? ' checked' : '' ) . '> Skip variations without their own image</label><br><span class="description">A parent product image does not count. Duplicate CSV SKU rows are always skipped. No variations are deleted.</span></p>';
        echo '<p><input type="file" name="price_csv" accept=".csv,text/csv" required></p>';
        submit_button( 'Upload and preview' );
        echo '</form>';
        if ( $plan ) {
            $count = count( $plan['items'] );
            echo '<hr><h2>Import preview</h2><p>' . esc_html( "$count price changes. Processed: {$plan['offset']}. Updated: {$plan['updated']}. Skipped during import: {$plan['skipped']}." ) . '</p>';
            echo '<p>' . esc_html( 'Preview settings: ' . ( $plan['all_matches'] ? 'update all matching variations' : 'require one matching variation' ) . '; ' . ( $plan['skip_no_image'] ? 'skip variations without their own image' : 'include variations without an image' ) . '. Change settings by uploading a new preview.' ) . '</p>';
            if ( $plan['offset'] < $count ) {
                echo '<form method="post">';
                wp_nonce_field( 'sspi_import' );
                echo '<input type="hidden" name="sspi_action" value="apply"><input type="hidden" name="plan_offset" value="' . esc_attr( $plan['offset'] ) . '"><input type="hidden" name="plan_token" value="' . esc_attr( $plan['token'] ) . '">';
                submit_button( empty( $plan['started'] ) ? 'Import first ' . self::BATCH_SIZE . ' prices' : 'Continue import (next ' . self::BATCH_SIZE . ')' );
                echo '</form><p>Each click imports up to ' . self::BATCH_SIZE . ' prices and stops after the current item if the batch reaches ' . self::BATCH_SECONDS . ' seconds. Repeat until complete. If interrupted, use Refresh status to load saved progress, then continue or reset. Preview expires after one hour of inactivity.</p>';
            } elseif ( ! empty( $plan['started'] ) ) {
                echo '<div class="notice notice-success inline"><p>Import finished. Review any skipped entries below.</p></div>';
            }
            echo '<details><summary>Validation and import messages (' . count( $plan['messages'] ) . ')</summary><ul>';
            foreach ( $plan['messages'] as $message ) {
                echo '<li>' . esc_html( $message ) . '</li>';
            }
            echo '</ul></details><table class="widefat striped"><thead><tr><th>SKU</th><th>Size</th><th>Variation ID</th><th>Previous regular price</th><th>New regular price</th><th>Existing sale price</th><th>Result</th></tr></thead><tbody>';
            foreach ( $plan['items'] as $item ) {
                echo '<tr>';
                foreach ( array( 'sku', 'size', 'id', 'old', 'new', 'sale', 'status' ) as $field ) {
                    echo '<td>' . esc_html( $item[ $field ] ) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}
add_action( 'plugins_loaded', array( 'SSPI_Importer', 'boot' ) );
