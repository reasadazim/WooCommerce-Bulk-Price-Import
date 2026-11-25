<?php
/** Strict CSV parsing independent of WooCommerce, for validation and tests. */
defined( 'ABSPATH' ) || exit;

final class SSPI_CSV {
    private static function validate( $path ) {
        $text = file_get_contents( $path );
        if ( false === $text || strlen( $text ) > 5 * 1024 * 1024 || false !== strpos( $text, "\0" ) || 1 !== preg_match( '//u', $text ) ) {
            throw new RuntimeException( 'CSV must be valid UTF-8 text without NUL bytes, at most 5 MB.' );
        }
        $text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
        // fgetcsv tolerates broken quoting; reject it before it can shift prices.
        $state = 'start';
        $length = strlen( $text );
        for ( $i = 0; $i < $length; $i++ ) {
            $char = $text[ $i ];
            if ( 'quoted' === $state ) {
                if ( '"' === $char ) {
                    $state = 'closed';
                }
                continue;
            }
            if ( 'closed' === $state && '"' === $char ) {
                $state = 'quoted';
                continue;
            }
            if ( ',' === $char || "\n" === $char || "\r" === $char ) {
                if ( "\r" === $char ) {
                    if ( $i + 1 >= $length || "\n" !== $text[ $i + 1 ] ) {
                        throw new RuntimeException( 'Use Windows (CRLF) or Unix (LF) CSV line endings.' );
                    }
                    $i++;
                }
                $state = 'start';
            } elseif ( '"' === $char && 'start' === $state ) {
                $state = 'quoted';
            } elseif ( '"' === $char || 'closed' === $state ) {
                throw new RuntimeException( 'Malformed CSV quoting. Export again as UTF-8 comma-separated CSV.' );
            } else {
                $state = 'unquoted';
            }
        }
        if ( 'quoted' === $state ) {
            throw new RuntimeException( 'CSV contains an unclosed quoted field.' );
        }
    }

    public static function parse( $path ) {
        self::validate( $path );
        $handle = fopen( $path, 'rb' );
        if ( ! $handle ) {
            throw new RuntimeException( 'Cannot read CSV.' );
        }
        try {
            $header = fgetcsv( $handle, 0, ',', '"', '' );
            if ( ! $header || count( $header ) < 2 ) {
                throw new RuntimeException( 'CSV must have a SKU column followed by size columns. Save as UTF-8 comma-separated CSV.' );
            }
            $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
            $sizes = array();
            foreach ( array_slice( $header, 1 ) as $value ) {
                $size = strtoupper( trim( (string) $value ) );
                if ( '' === $size || in_array( $size, $sizes, true ) ) {
                    throw new RuntimeException( 'Size column headers must be nonempty and unique.' );
                }
                $sizes[] = $size;
            }
            $rows = array();
            $messages = array();
            $seen = array();
            $line = 1;
            while ( false !== ( $cells = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
                $line++;
                if ( $line > 10001 ) {
                    throw new RuntimeException( 'Limit is 10,000 CSV records per upload.' );
                }
                if ( array( null ) === $cells || ! array_filter( $cells, static function ( $cell ) { return '' !== trim( (string) $cell ); } ) ) {
                    continue;
                }
                $sku = trim( (string) $cells[0] );
                if ( '' === $sku ) {
                    $messages[] = "Line $line: empty SKU; skipped.";
                    continue;
                }
                $key = strtolower( $sku );
                $seen[ $key ] = ( $seen[ $key ] ?? 0 ) + 1;
                if ( count( $cells ) !== count( $header ) ) {
                    $messages[] = "Line $line: $sku has an incorrect column count; skipped.";
                    continue;
                }
                $prices = array();
                foreach ( $sizes as $index => $size ) {
                    $value = trim( (string) $cells[ $index + 1 ] );
                    if ( '' === $value ) {
                        continue;
                    }
                    if ( ! preg_match( '/^\d+(?:\.\d+)?$/D', $value ) || ! is_finite( (float) $value ) ) {
                        $messages[] = "Line $line: $sku / $size has an invalid price; cell skipped. Use nonnegative numbers with a decimal point, without currency symbols or thousands separators.";
                        continue;
                    }
                    $prices[ $size ] = $value;
                }
                $rows[] = array( 'sku' => $sku, 'line' => $line, 'prices' => $prices );
            }
            $duplicates = array_keys( array_filter( $seen, static function ( $count ) { return $count > 1; } ) );
            foreach ( $duplicates as $sku ) {
                $messages[] = "Duplicate SKU $sku: all rows for this SKU skipped. Resolve duplicates and upload again.";
            }
            $rows = array_values( array_filter( $rows, static function ( $row ) use ( $seen ) { return 1 === $seen[ strtolower( $row['sku'] ) ]; } ) );
            return array( 'rows' => $rows, 'messages' => $messages, 'sizes' => $sizes, 'duplicate_skus' => $duplicates );
        } finally {
            fclose( $handle );
        }
    }
}
