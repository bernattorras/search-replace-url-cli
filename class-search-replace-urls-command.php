<?php
if ( ! class_exists( 'WP_CLI' ) ) {
    return;
}

/**
 * Custom Search Command Class
 */
class Search_Replace_URLS {

    /**
     * Search for 'moneymaven.io' instances in the database and extract matching URLs.
     * If --reduce-urls is provided, the first 3 segments of each URL will be saved to urls_found_reduced.csv.
     * If --find-similar-urls is provided, find similar post names in the wp_posts table for each "reduced segment" in urls_found.csv and update the CSV.
	 *
     * ## OPTIONS
     *
     * [--search-string=<string>]
     * : Specify the search string to use. Defaults to 'moneymaven.io'.
     *
     * [--reduce-urls]
     * : If provided, remove the last 2 segments from each string in urls_found.csv and save to urls_found_reduced.csv.
     *
	 * [--find-similar-urls]
	 * : If provided, search for similar URLs in the wp_posts table for each URL in urls_found_reduced.csv and update the CSV.
	 * 
	 * [--replace-urls]
	 * : If provided, replace the Original URLs in urls_found_similar.csv with the permalinks from the wp_posts table.
	 * 
     * ## EXAMPLES
     *
	 * wp search-replace-urls search --search-string=THE_WANTED_URL
     * wp search-replace-urls search --reduce-urls
     * wp search-replace-urls search --find-similar-urls
	 * wp search-replace-urls search --replace-urls
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function search( $args, $assoc_args ) {
        global $wpdb;

        $original_file_name = isset( $assoc_args['file'] ) ? $assoc_args['file'] : 'urls_found.csv';
        $reduced_file_name  = 'urls_found_reduced.csv';
		$search_string      = isset( $assoc_args['search-string'] ) ? $assoc_args['search-string'] : 'moneymaven.io';

        if ( isset( $assoc_args['reduce-urls'] ) ) {
            $csv_file_path = $original_file_name;

            if ( ! file_exists( $csv_file_path ) ) {
                WP_CLI::error( "CSV file {$csv_file_path} not found." );
                return;
            }

            $csv_content = file_get_contents( $csv_file_path );
            $lines = explode( PHP_EOL, $csv_content );

            $reduced_urls = array();
			$reduced_urls[] = 'Original URL' . ',' . 'Original Name' . ',' . 'Reduced Name';

            foreach ( $lines as $line ) {
				//WP_CLI::log( "Line {$line}." );
                //$fields = explode( ',', $line );
                //$original_url = trim( $fields[0] );
				$original_url = $line;
			
				$parts_to_remove = array(
					'https://moneymaven.io/mishtalk/economics/',
					'https://moneymaven.io/mishtalk/politics/',
					'https://moneymaven.io/mishtalk/pages/',
					'https://moneymaven.io/mishtalk/',
				);
				
				// Take the $original_url and remove the $parts_to_remove from it.
				$original_name = str_replace( $parts_to_remove, '', $original_url );

                // Remove the last 2 segments from the URL.
                $reduced_name = $this->reduce_url_segments( $original_name );

                if ( ! empty( $reduced_name ) ) {
                    $reduced_urls[] = $line . ',' . $original_name . ',' . $reduced_name;
                }
            }

            // Save the reduced URLs to the new CSV file.
            $reduced_csv_data = implode( PHP_EOL, $reduced_urls );
            file_put_contents( $reduced_file_name, $reduced_csv_data );
            WP_CLI::success( "Segments reduced and saved to {$reduced_file_name}." );

		} elseif ( isset( $assoc_args['find-similar-urls'] ) ) {
			$this->find_similar_urls();

		} elseif ( isset( $assoc_args['replace-urls'] ) ) {
			$this->replace_urls();

        } else {
            $this->extract_matching_urls( $search_string );
        }
    }

    /**
     * Remove the last 2 segments from the URL.
     *
     * @param string $original_url
     *
     * @return string
     */
    private function reduce_url_segments( $original_url ) {
        $url_parts = explode( '-', $original_url );;

        if ( is_array( $url_parts ) && count( $url_parts ) > 2 ) {
			$reduced_url = implode( '-', array_slice( $url_parts, 0, 3 ) );
			//WP_CLI::log( "REDUCED TO {$reduced_url}." );
            return $reduced_url;
        }

        return '';
    }

	/**
	 * Search each string from the urls_found_reduced.csv file in the wp_posts table (post_name) and update the CSV.
	 */
	private function find_similar_urls() {
		global $wpdb;

		$csv_file_path         = 'urls_found_reduced.csv';
		$similar_csv_file_path = 'urls_found_similar.csv';

		if ( ! file_exists( $csv_file_path ) ) {
			WP_CLI::error( "CSV file {$csv_file_path} not found." );
			return;
		}

		$csv_content = file_get_contents( $csv_file_path );
		$csv_new_content = 'Original URL,Original Name,Reduced Name, Existing Post Name,Post ID,Permalink' . PHP_EOL;
		$lines = explode( PHP_EOL, $csv_content );

		foreach ( $lines as $line ) {
			// Skip the first line.
			if ( strpos( $line, 'Original URL' ) !== false ) {
				continue;
			}
			$fields = explode( ',', $line );
			$original_url = trim( $fields[0] );
			$original_name = trim( $fields[1] );
			$reduced_name = trim( $fields[2] );

			WP_CLI::log( "Processing URL: {$original_url}." );
			// Search for the entry in the wp_posts table.
			$post_data = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT ID, post_name FROM {$wpdb->posts} WHERE post_name LIKE %s",
					'%' . $wpdb->esc_like( $reduced_name ) . '%'
				)
			);

			if ( ! empty( $post_data ) ) {
				// Update the CSV with the original URL, post_name, and ID separated by a comma.
				$permalink = get_permalink( $post_data->ID );
				$updated_line = $original_url . ',' . $original_name . ',' . $reduced_name . ',' . $post_data->post_name . ',' . $post_data->ID  . ',' . $permalink . PHP_EOL;
			} elseif ( empty( $post_data ) ) {
				WP_CLI::warning( "No match found for {$original_url}." );
				$updated_line = $original_url . ',' . $original_name . ',' . $reduced_name . ',' . 'NO MATCH FOUND' . ',' . 'NO MATCH FOUND' . ',' . 'NO MATCH FOUND' . PHP_EOL;
			}
			$csv_new_content .= $updated_line;
		}

		// Save the updated CSV file.
		file_put_contents( $similar_csv_file_path, $csv_new_content );
		WP_CLI::success( "Similar URLs updated in {$similar_csv_file_path}." );

	}

    /**
     * Extract matching URLs containing the search string from the database and save to CSV.
     *
     * @param string $file_name
     */
    private function extract_matching_urls( $search_string ) {
        global $wpdb;
		$file_name = 'urls_found.csv';

        // Perform a search in the wp_posts table. You may need to adjust the table names based on your database structure.
        $results = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_content FROM {$wpdb->posts} WHERE post_content LIKE %s",
                '%' . $wpdb->esc_like( $search_string ) . '%'
            )
        );

        if ( empty( $results ) ) {
            WP_CLI::error( 'No matches found.' );
            return;
        }

        // Extract URLs containing the search_string.
        $matching_urls = array();
        foreach ( $results as $content ) {
            $dom = new DOMDocument;
            @$dom->loadHTML( $content );
            $anchors = $dom->getElementsByTagName( 'a' );

            foreach ( $anchors as $anchor ) {
                $url = $anchor->getAttribute( 'href' );
                if ( strpos( $url, $search_string ) !== false ) {
					// Replace "%2520" with "-" in the URL.
					//$url = str_replace( '%2520', '-', $url );
					$url = str_replace( ',', '-', $url );
                    $matching_urls[] = htmlspecialchars( $url );
                }
            }
        }

        $matching_urls = array_unique( $matching_urls );

        if ( empty( $matching_urls ) ) {
            WP_CLI::error( 'No matching URLs found.' );
            return;
        }

        // Save the original URLs to the CSV file.
        $csv_data = implode( PHP_EOL, $matching_urls );
        file_put_contents( $file_name, $csv_data );
        WP_CLI::success( "Original URLs saved to {$file_name}." );
    }

	/**
	 * Replace the Original URLs in urls_found_similar.csv with the permalinks from the wp_posts table.
	 */
	private function replace_urls(){
		global $wpdb;

		$csv_file_path         = 'urls_found_similar.csv';
		$csv_updated_file_path = 'urls_found_similar_updated.csv';

		if ( ! file_exists( $csv_file_path ) ) {
			WP_CLI::error( "CSV file {$csv_file_path} not found." );
			return;
		}

		$csv_content     = file_get_contents( $csv_file_path );
		$lines           = explode( PHP_EOL, $csv_content );
		$updated_lines[] = 'Original URL,Original Name,Reduced Name, Existing Post Name,Post ID,Permalink,Updated Entries' . PHP_EOL;

		foreach ( $lines as $line ) {
			$fields = explode( ',', $line );
			$original_url = trim( $fields[0] );
			$permalink = trim( $fields[5] );

			// Skip the first line and the entries that have no match.
			if ( strpos( $line, 'Original URL' ) !== false || strpos( $line, 'NO MATCH FOUND' ) !== false ) {
				continue;
			}

			WP_CLI::log( "Replacing URL: {$original_url} with {$permalink}" );

			// Replace any instances of the $original_url in the wp_posts table with the $permalink.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, %s, %s )",
					$original_url,
					$permalink
				)
			);

			// Add the number of updated entries to the CSV line
			$updated_entries = $wpdb->rows_affected;
			$updated_lines[] = $line . ',' . $updated_entries . PHP_EOL;
			WP_CLI::colorize( "%G{$updated_entries} entries updated.%n" );
		}

		
		// Save the updated CSV file.
		file_put_contents( $csv_updated_file_path, $updated_lines );
		WP_CLI::success( "URLs replaced. Results updated in {$csv_updated_file_path}." );
	}
}

WP_CLI::add_command( 'search-replace-urls', 'Search_Replace_URLS' );
?>
