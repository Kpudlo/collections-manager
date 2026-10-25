<?php
/**
 * Remote WordPress REST and RSS/Atom collection items.
 *
 * @package CollectionsManager
 */

namespace CollectionsManager\Feed;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches cards from another WordPress site or an XML feed.
 */
class Client {

	const TRANSIENT_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Allowlisted http(s) URL, or empty.
	 *
	 * @param string $raw Raw URL.
	 * @return string
	 */
	public static function sanitize_url( $raw ) {
		$url = esc_url_raw( trim( (string) $raw ), array( 'http', 'https' ) );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Cards for stored feed settings.
	 *
	 * @param array $settings Sanitized feed meta.
	 * @return array<int, array<string, mixed>>
	 */
	public static function items_for_collection( $settings ) {
		$result = self::fetch( is_array( $settings ) ? $settings : array() );
		return is_wp_error( $result ) ? array() : $result;
	}

	/**
	 * Fetch items for a feed collection.
	 *
	 * @param array $settings Feed settings.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function fetch( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$url      = self::sanitize_url( (string) ( $settings['url'] ?? '' ) );
		if ( '' === $url ) {
			return new \WP_Error( 'cm_feed_url', __( 'Enter a WordPress site or feed URL.', 'collections-manager' ) );
		}

		$kind      = self::sanitize_kind( $settings['kind'] ?? 'auto' );
		$rest_base = self::sanitize_rest_base( $settings['rest_base'] ?? 'posts' );
		$cat_id    = absint( $settings['category_id'] ?? 0 );
		$cat_slug  = sanitize_title( (string) ( $settings['category_slug'] ?? '' ) );
		$limit = \collections_manager_source_fetch_limit( $settings, 12 );

		$cache_key = 'cm_feed_' . md5( wp_json_encode( compact( 'url', 'kind', 'rest_base', 'cat_id', 'cat_slug', 'limit' ) ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( 'auto' === $kind ) {
			$kind = self::guess_kind( $url );
		}

		if ( 'rss' === $kind ) {
			$items = self::rss_items( $url, $limit );
		} else {
			$origin = self::origin( $url );
			if ( '' === $origin ) {
				return new \WP_Error( 'cm_feed_url', __( 'That URL is not a valid site address.', 'collections-manager' ) );
			}
			$items = self::rest_items( $origin, $rest_base, $cat_id, $cat_slug, $limit );
			if ( is_wp_error( $items ) ) {
				$feed_url = $origin . '/feed/';
				$rss      = self::rss_items( $feed_url, $limit );
				if ( ! is_wp_error( $rss ) && ! empty( $rss ) ) {
					$items = $rss;
				}
			}
		}

		if ( is_wp_error( $items ) ) {
			return $items;
		}

		set_transient( $cache_key, $items, self::TRANSIENT_TTL );
		return $items;
	}

	/**
	 * Inspect a URL: WordPress REST vs RSS/Atom.
	 *
	 * @param string $raw URL.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function discover( $raw ) {
		$url = self::sanitize_url( $raw );
		if ( '' === $url ) {
			return new \WP_Error( 'cm_feed_url', __( 'Enter a WordPress site or feed URL.', 'collections-manager' ) );
		}

		if ( self::looks_like_feed( $url ) ) {
			return array(
				'kind'       => 'rss',
				'url'        => $url,
				'types'      => array(),
				'categories' => array(),
			);
		}

		$origin = self::origin( $url );
		$index  = self::json_get( $origin . '/wp-json/' );
		if ( ! is_wp_error( $index ) && is_array( $index ) && isset( $index['namespaces'] ) && in_array( 'wp/v2', (array) $index['namespaces'], true ) ) {
			return array(
				'kind'       => 'rest',
				'url'        => $origin . '/',
				'name'       => isset( $index['name'] ) ? sanitize_text_field( (string) $index['name'] ) : $origin,
				'types'      => self::rest_types( $origin ),
				'categories' => self::rest_terms( $origin, 'categories' ),
			);
		}

		return array(
			'kind'       => 'rss',
			'url'        => $url,
			'types'      => array(),
			'categories' => array(),
		);
	}

	/**
	 * @param mixed $kind Raw kind.
	 * @return string
	 */
	private static function sanitize_kind( $kind ) {
		$kind = sanitize_key( (string) $kind );
		return in_array( $kind, array( 'auto', 'rest', 'rss' ), true ) ? $kind : 'auto';
	}

	/**
	 * @param mixed $base REST route base.
	 * @return string
	 */
	private static function sanitize_rest_base( $base ) {
		$base = sanitize_key( (string) $base );
		return $base ? $base : 'posts';
	}

	/**
	 * @param string $url URL.
	 * @return string rest|rss
	 */
	private static function guess_kind( $url ) {
		if ( self::looks_like_feed( $url ) ) {
			return 'rss';
		}
		if ( false !== strpos( $url, '/wp-json/' ) ) {
			return 'rest';
		}
		return 'rest';
	}

	/**
	 * @param string $url URL.
	 * @return bool
	 */
	private static function looks_like_feed( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return (bool) preg_match( '/(\/feed\/?|\/rss\/?|\/atom\/?|\.xml|\.rss|\.atom)(\?|$)/i', $path );
	}

	/**
	 * @param string $url URL.
	 * @return string
	 */
	private static function origin( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $parts['scheme'] . '://' . $parts['host'] . $port;
	}

	/**
	 * @param string $origin    Site origin.
	 * @param string $rest_base Posts rest base.
	 * @param int    $cat_id    Category term ID.
	 * @param string $cat_slug  Category slug.
	 * @param int    $limit     Max items.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function rest_items( $origin, $rest_base, $cat_id, $cat_slug, $limit ) {
		if ( $cat_id < 1 && $cat_slug ) {
			$terms = self::json_get( $origin . '/wp-json/wp/v2/categories?slug=' . rawurlencode( $cat_slug ) );
			if ( ! is_wp_error( $terms ) && isset( $terms[0]['id'] ) ) {
				$cat_id = absint( $terms[0]['id'] );
			}
		}

		$query = array(
			'per_page' => $limit,
			'_embed'   => '1',
			'status'   => 'publish',
		);
		if ( $cat_id > 0 ) {
			$query['categories'] = $cat_id;
		}

		$endpoint = $origin . '/wp-json/wp/v2/' . $rest_base . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$data     = self::json_get( $endpoint );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'cm_feed_rest', __( 'That WordPress site did not return posts.', 'collections-manager' ) );
		}

		$items = array();
		foreach ( $data as $post ) {
			if ( ! is_array( $post ) ) {
				continue;
			}
			$link = isset( $post['link'] ) ? self::sanitize_url( (string) $post['link'] ) : '';
			if ( ! $link ) {
				continue;
			}
			$title = '';
			if ( isset( $post['title']['rendered'] ) ) {
				$title = html_entity_decode( wp_strip_all_tags( (string) $post['title']['rendered'] ), ENT_QUOTES, 'UTF-8' );
			} elseif ( isset( $post['title'] ) && is_string( $post['title'] ) ) {
				$title = wp_strip_all_tags( $post['title'] );
			}
			$image = '';
			if ( isset( $post['_embedded']['wp:featuredmedia'][0]['source_url'] ) ) {
				$image = self::sanitize_url( (string) $post['_embedded']['wp:featuredmedia'][0]['source_url'] );
			}
			$published = isset( $post['date'] ) ? (string) $post['date'] : '';
			$id        = isset( $post['id'] ) ? (string) $post['id'] : md5( $link );
			$items[]   = self::shape_item( 'wp-' . $id, $title, $link, $image, $published );
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * @param string $origin Site origin.
	 * @return array<int, array{slug:string,name:string}>
	 */
	private static function rest_types( $origin ) {
		$data = self::json_get( $origin . '/wp-json/wp/v2/types' );
		if ( is_wp_error( $data ) || ! is_array( $data ) ) {
			return array(
				array(
					'slug' => 'posts',
					'name' => __( 'Posts', 'collections-manager' ),
				),
			);
		}

		$out = array();
		foreach ( $data as $slug => $type ) {
			if ( ! is_array( $type ) ) {
				continue;
			}
			$rest_base = isset( $type['rest_base'] ) ? sanitize_key( (string) $type['rest_base'] ) : sanitize_key( (string) $slug );
			if ( ! $rest_base || preg_match( '/^(attachments|media|blocks|templates|template-parts|navigation|menu-items|global-styles|font-families|feedback|jetpack|gblocks|gp_|jp_|activity-log)/', $rest_base ) ) {
				continue;
			}
			if ( isset( $type['viewable'] ) && ! $type['viewable'] ) {
				continue;
			}
			$name  = isset( $type['name'] ) ? sanitize_text_field( (string) $type['name'] ) : $rest_base;
			$out[] = array(
				'slug' => $rest_base,
				'name' => $name ? $name : $rest_base,
			);
		}

		return $out ? $out : array(
			array(
				'slug' => 'posts',
				'name' => __( 'Posts', 'collections-manager' ),
			),
		);
	}

	/**
	 * @param string $origin Site origin.
	 * @param string $tax    REST taxonomy base.
	 * @return array<int, array{id:int,name:string,slug:string}>
	 */
	private static function rest_terms( $origin, $tax ) {
		$tax  = sanitize_key( $tax );
		$data = self::json_get( $origin . '/wp-json/wp/v2/' . $tax . '?per_page=100&hide_empty=true' );
		if ( is_wp_error( $data ) || ! is_array( $data ) ) {
			return array();
		}

		$out = array();
		foreach ( $data as $term ) {
			if ( ! is_array( $term ) || empty( $term['id'] ) ) {
				continue;
			}
			$out[] = array(
				'id'   => absint( $term['id'] ),
				'name' => isset( $term['name'] ) ? sanitize_text_field( (string) $term['name'] ) : '',
				'slug' => isset( $term['slug'] ) ? sanitize_title( (string) $term['slug'] ) : '',
			);
		}

		return $out;
	}

	/**
	 * @param string $url   Feed URL.
	 * @param int    $limit Max items.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function rss_items( $url, $limit ) {
		$response = self::request( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body || ! function_exists( 'simplexml_load_string' ) ) {
			return new \WP_Error( 'cm_feed_rss', __( 'Could not read that XML feed.', 'collections-manager' ) );
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( false === $xml ) {
			return new \WP_Error( 'cm_feed_rss', __( 'That URL is not a valid RSS or Atom feed.', 'collections-manager' ) );
		}

		$items = array();
		if ( isset( $xml->channel->item ) ) {
			foreach ( $xml->channel->item as $item ) {
				$shaped = self::shape_rss_item( $item );
				if ( $shaped ) {
					$items[] = $shaped;
				}
				if ( count( $items ) >= $limit ) {
					break;
				}
			}
		} else {
			$xml->registerXPathNamespace( 'atom', 'http://www.w3.org/2005/Atom' );
			$entries = $xml->xpath( '//atom:entry' );
			if ( $entries ) {
				foreach ( $entries as $entry ) {
					$shaped = self::shape_atom_item( $entry );
					if ( $shaped ) {
						$items[] = $shaped;
					}
					if ( count( $items ) >= $limit ) {
						break;
					}
				}
			}
		}

		return $items;
	}

	/**
	 * @param \SimpleXMLElement $item RSS item.
	 * @return array<string, mixed>|null
	 */
	private static function shape_rss_item( $item ) {
		$title = sanitize_text_field( html_entity_decode( wp_strip_all_tags( (string) $item->title ), ENT_QUOTES, 'UTF-8' ) );
		$link  = self::sanitize_url( (string) $item->link );
		if ( ! $link && isset( $item->guid ) ) {
			$link = self::sanitize_url( (string) $item->guid );
		}
		if ( ! $link ) {
			return null;
		}

		$image = '';
		$media = $item->children( 'http://search.yahoo.com/mrss/' );
		if ( isset( $media->thumbnail ) ) {
			$attrs = $media->thumbnail->attributes();
			if ( isset( $attrs['url'] ) ) {
				$image = self::sanitize_url( (string) $attrs['url'] );
			}
		}
		if ( ! $image ) {
			$itunes = $item->children( 'http://www.itunes.com/dtds/podcast-1.0.dtd' );
			if ( isset( $itunes->image ) ) {
				$attrs = $itunes->image->attributes();
				if ( isset( $attrs['href'] ) ) {
					$image = self::sanitize_url( (string) $attrs['href'] );
				}
			}
		}
		if ( ! $image && isset( $item->enclosure ) ) {
			$attrs = $item->enclosure->attributes();
			$type  = isset( $attrs['type'] ) ? (string) $attrs['type'] : '';
			if ( 0 === strpos( $type, 'image/' ) && isset( $attrs['url'] ) ) {
				$image = self::sanitize_url( (string) $attrs['url'] );
			}
		}
		if ( ! $image ) {
			$content = '';
			if ( isset( $item->children( 'http://purl.org/rss/1.0/modules/content/' )->encoded ) ) {
				$content = (string) $item->children( 'http://purl.org/rss/1.0/modules/content/' )->encoded;
			} elseif ( isset( $item->description ) ) {
				$content = (string) $item->description;
			}
			if ( $content && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches ) ) {
				$image = self::sanitize_url( $matches[1] );
			}
		}

		$published = isset( $item->pubDate ) ? (string) $item->pubDate : '';
		return self::shape_item( md5( $link ), $title, $link, $image, $published );
	}

	/**
	 * @param \SimpleXMLElement $entry Atom entry.
	 * @return array<string, mixed>|null
	 */
	private static function shape_atom_item( $entry ) {
		$title = sanitize_text_field( html_entity_decode( wp_strip_all_tags( (string) $entry->children( 'http://www.w3.org/2005/Atom' )->title ), ENT_QUOTES, 'UTF-8' ) );
		$link  = '';
		foreach ( $entry->children( 'http://www.w3.org/2005/Atom' )->link as $node ) {
			$attrs = $node->attributes();
			$rel   = isset( $attrs['rel'] ) ? (string) $attrs['rel'] : 'alternate';
			$href  = isset( $attrs['href'] ) ? self::sanitize_url( (string) $attrs['href'] ) : '';
			if ( $href && in_array( $rel, array( 'alternate', '' ), true ) ) {
				$link = $href;
				break;
			}
		}
		if ( ! $link ) {
			return null;
		}
		$published = (string) $entry->children( 'http://www.w3.org/2005/Atom' )->published;
		if ( ! $published ) {
			$published = (string) $entry->children( 'http://www.w3.org/2005/Atom' )->updated;
		}
		$image = '';
		$media = $entry->children( 'http://search.yahoo.com/mrss/' );
		if ( isset( $media->thumbnail ) ) {
			$attrs = $media->thumbnail->attributes();
			if ( isset( $attrs['url'] ) ) {
				$image = self::sanitize_url( (string) $attrs['url'] );
			}
		}

		return self::shape_item( md5( $link ), $title, $link, $image, $published );
	}

	/**
	 * @param string $id        Item id.
	 * @param string $title     Title.
	 * @param string $url       Permalink.
	 * @param string $image     Image URL.
	 * @param string $published Date string.
	 * @return array<string, mixed>
	 */
	private static function shape_item( $id, $title, $url, $image, $published ) {
		$timestamp = $published ? strtotime( $published ) : false;

		return array(
			'id'       => $id,
			'title'    => sanitize_text_field( $title ? $title : $url ),
			'url'      => $url,
			'image'    => $image,
			'date'     => $timestamp ? date_i18n( get_option( 'date_format' ), $timestamp ) : '',
			'external' => true,
		);
	}

	/**
	 * @param string $url JSON URL.
	 * @return array|\WP_Error
	 */
	private static function json_get( $url ) {
		$response = self::request( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error( 'cm_feed_http', __( 'That WordPress site did not respond.', 'collections-manager' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'cm_feed_json', __( 'That WordPress site did not return JSON.', 'collections-manager' ) );
		}

		return $body;
	}

	/**
	 * @param string $url Remote URL.
	 * @return array|\WP_Error
	 */
	private static function request( $url ) {
		$url = self::sanitize_url( $url );
		if ( '' === $url ) {
			return new \WP_Error( 'cm_feed_url', __( 'Enter a WordPress site or feed URL.', 'collections-manager' ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'limit_response_size' => 1024 * 1024,
				'headers'             => array(
					'Accept' => 'application/json, application/rss+xml, application/atom+xml, application/xml, text/xml, */*',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'cm_feed_http', __( 'Could not load that URL.', 'collections-manager' ) );
		}

		return $response;
	}
}
