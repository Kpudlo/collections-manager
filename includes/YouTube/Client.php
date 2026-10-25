<?php
/**
 * YouTube playlist and channel video lookups.
 *
 * @package CollectionsManager
 */

namespace CollectionsManager\YouTube;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves playlists and channel videos via Data API v3, with Atom RSS fallback.
 */
class Client {

	const API_BASE = 'https://www.googleapis.com/youtube/v3/';
	const TRANSIENT_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * API key from wp-config or the Collections settings option.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'COLLECTIONS_MANAGER_YOUTUBE_API_KEY' ) && COLLECTIONS_MANAGER_YOUTUBE_API_KEY ) {
			return (string) COLLECTIONS_MANAGER_YOUTUBE_API_KEY;
		}

		return (string) get_option( 'collections_manager_youtube_api_key', '' );
	}

	/**
	 * Whether a key is available for Data API calls.
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		return '' !== self::api_key();
	}

	/**
	 * Extract a playlist ID from a pasted ID or URL.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	public static function parse_playlist_id( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		if ( preg_match( '/[?&]list=([A-Za-z0-9_-]+)/', $raw, $matches ) ) {
			return $matches[1];
		}

		if ( preg_match( '/^(PL|UU|FL|OL|RD)[A-Za-z0-9_-]+$/', $raw ) ) {
			return $raw;
		}

		return '';
	}

	/**
	 * Normalize a video ID.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	public static function parse_video_id( $raw ) {
		$raw = trim( (string) $raw );
		if ( preg_match( '/(?:v=|youtu\.be\/|embed\/)([A-Za-z0-9_-]{11})/', $raw, $matches ) ) {
			return $matches[1];
		}
		if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $raw ) ) {
			return $raw;
		}

		return '';
	}

	/**
	 * Shape stored YouTube settings into collection cards.
	 *
	 * @param array $settings Sanitized youtube meta.
	 * @return array<int, array<string, mixed>>
	 */
	public static function items_for_collection( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$mode     = isset( $settings['mode'] ) ? (string) $settings['mode'] : 'latest';
		if ( ! in_array( $mode, array( 'latest', 'playlist', 'videos' ), true ) ) {
			$mode = 'latest';
		}

		if ( 'videos' === $mode ) {
			return self::videos_by_ids( isset( $settings['videos'] ) ? $settings['videos'] : array() );
		}

		$limit = \collections_manager_source_fetch_limit( $settings, 24 );

		if ( 'latest' === $mode ) {
			$result = self::latest_from_channels( isset( $settings['channels'] ) ? $settings['channels'] : array(), $limit );
			return is_wp_error( $result ) ? array() : $result;
		}

		$playlist_id = self::parse_playlist_id( (string) ( $settings['playlist'] ?? '' ) );
		if ( '' === $playlist_id ) {
			return array();
		}

		$result = self::playlist_videos( $playlist_id, $limit );
		return is_wp_error( $result ) ? array() : $result;
	}

	/**
	 * Newest public uploads from one or more channels, mixed by date.
	 *
	 * @param array $channels Channel rows or IDs.
	 * @param int   $limit    Max items.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function latest_from_channels( $channels, $limit = 24 ) {
		$limit = max( 1, min( 50, (int) $limit ) );
		$ids   = array();
		foreach ( (array) $channels as $row ) {
			$channel_id = is_array( $row ) ? (string) ( $row['id'] ?? '' ) : (string) $row;
			$channel_id = sanitize_text_field( $channel_id );
			if ( preg_match( '/^UC[A-Za-z0-9_-]{22}$/', $channel_id ) ) {
				$ids[ $channel_id ] = $channel_id;
			}
		}

		if ( ! $ids ) {
			return new \WP_Error( 'cm_youtube_channel', __( 'Add a YouTube channel to load latest videos.', 'collections-manager' ) );
		}

		$merged = array();
		$error  = null;
		foreach ( $ids as $channel_id ) {
			$items = self::channel_latest( $channel_id, $limit );
			if ( is_wp_error( $items ) ) {
				$error = $items;
				continue;
			}
			foreach ( $items as $item ) {
				$id = isset( $item['id'] ) ? (string) $item['id'] : '';
				if ( $id ) {
					$merged[ $id ] = $item;
				}
			}
		}

		if ( ! $merged ) {
			return $error ? $error : array();
		}

		uasort(
			$merged,
			static function ( $a, $b ) {
				$left  = isset( $a['timestamp'] ) ? (int) $a['timestamp'] : 0;
				$right = isset( $b['timestamp'] ) ? (int) $b['timestamp'] : 0;
				return $right <=> $left;
			}
		);

		return array_slice( array_values( $merged ), 0, $limit );
	}

	/**
	 * Latest uploads for a single channel.
	 *
	 * @param string $channel_id Channel ID.
	 * @param int    $limit      Max items.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function channel_latest( $channel_id, $limit = 24 ) {
		$channel_id = sanitize_text_field( $channel_id );
		if ( ! preg_match( '/^UC[A-Za-z0-9_-]{22}$/', $channel_id ) ) {
			return new \WP_Error( 'cm_youtube_channel', __( 'Invalid channel ID.', 'collections-manager' ) );
		}

		$limit     = max( 1, min( 50, (int) $limit ) );
		$cache_key = 'cm_yt_ch_' . md5( $channel_id . '|' . $limit );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rss = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode( $channel_id );
		if ( self::has_api_key() ) {
			$uploads = self::uploads_playlist_id( $channel_id );
			$items   = $uploads ? self::playlist_videos( $uploads, $limit ) : self::rss_videos( $rss, $limit );
			if ( is_wp_error( $items ) ) {
				$items = self::rss_videos( $rss, $limit );
			}
		} else {
			$items = self::rss_videos( $rss, min( 15, $limit ) );
		}

		if ( is_wp_error( $items ) ) {
			return $items;
		}

		set_transient( $cache_key, $items, self::TRANSIENT_TTL );
		return $items;
	}

	/**
	 * Uploads playlist ID for a channel (UU + id without UC).
	 *
	 * @param string $channel_id Channel ID.
	 * @return string
	 */
	public static function uploads_playlist_id( $channel_id ) {
		$channel_id = sanitize_text_field( $channel_id );
		if ( ! preg_match( '/^UC[A-Za-z0-9_-]{22}$/', $channel_id ) ) {
			return '';
		}

		if ( self::has_api_key() ) {
			$data = self::api_get(
				'channels',
				array(
					'part' => 'contentDetails',
					'id'   => $channel_id,
				)
			);
			if ( ! is_wp_error( $data ) ) {
				$uploads = $data['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '';
				if ( $uploads ) {
					return (string) $uploads;
				}
			}
		}

		return 'UU' . substr( $channel_id, 2 );
	}

	/**
	 * Public playlists created on a channel.
	 *
	 * @param string $channel_id Channel ID.
	 * @return array<int, array{id:string,title:string,count:int}>|\WP_Error
	 */
	public static function channel_playlists( $channel_id ) {
		$channel_id = sanitize_text_field( $channel_id );
		if ( ! preg_match( '/^UC[A-Za-z0-9_-]{22}$/', $channel_id ) ) {
			return new \WP_Error( 'cm_youtube_channel', __( 'Invalid channel ID.', 'collections-manager' ) );
		}

		if ( ! self::has_api_key() ) {
			return new \WP_Error(
				'cm_youtube_key',
				__( 'Add a YouTube Data API key under Settings to list playlists for a channel, or paste a playlist URL.', 'collections-manager' )
			);
		}

		$cache_key = 'cm_yt_pls_' . md5( $channel_id );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$playlists = array();
		$token     = '';
		do {
			$query = array(
				'part'       => 'snippet,contentDetails',
				'channelId'  => $channel_id,
				'maxResults' => 50,
			);
			if ( $token ) {
				$query['pageToken'] = $token;
			}

			$data = self::api_get( 'playlists', $query );
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
				$id = sanitize_text_field( (string) ( $item['id'] ?? '' ) );
				if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $id ) ) {
					continue;
				}
				$title = isset( $item['snippet']['title'] ) ? sanitize_text_field( (string) $item['snippet']['title'] ) : $id;
				$count = isset( $item['contentDetails']['itemCount'] ) ? absint( $item['contentDetails']['itemCount'] ) : 0;
				$playlists[] = array(
					'id'    => $id,
					'title' => $title ? $title : $id,
					'count' => $count,
				);
			}

			$token = isset( $data['nextPageToken'] ) ? (string) $data['nextPageToken'] : '';
		} while ( $token && count( $playlists ) < 200 );

		set_transient( $cache_key, $playlists, self::TRANSIENT_TTL );
		return $playlists;
	}

	/**
	 * Videos in a playlist, newest-feed or API order.
	 *
	 * @param string $playlist_id Playlist ID.
	 * @param int    $limit       Max items.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function playlist_videos( $playlist_id, $limit = 24 ) {
		$playlist_id = self::parse_playlist_id( $playlist_id );
		if ( '' === $playlist_id ) {
			return new \WP_Error( 'cm_youtube_playlist', __( 'Enter a valid YouTube playlist ID or URL.', 'collections-manager' ) );
		}

		$limit = max( 1, min( 50, (int) $limit ) );
		$cache_key = 'cm_yt_pl_' . md5( $playlist_id . '|' . $limit );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( self::has_api_key() ) {
			$items = self::api_playlist_items( $playlist_id, $limit );
		} else {
			$items = self::rss_videos( 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . rawurlencode( $playlist_id ), $limit );
		}

		if ( is_wp_error( $items ) ) {
			return $items;
		}

		set_transient( $cache_key, $items, self::TRANSIENT_TTL );
		return $items;
	}

	/**
	 * Resolve a channel URL, @handle, or UC id.
	 *
	 * @param string $raw Raw input.
	 * @return array{id:string,title:string}|\WP_Error
	 */
	public static function resolve_channel( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return new \WP_Error( 'cm_youtube_channel', __( 'Enter a YouTube channel URL, @handle, or channel ID.', 'collections-manager' ) );
		}

		$channel_id = '';
		$handle     = '';
		$username   = '';

		if ( preg_match( '/UC[A-Za-z0-9_-]{22}/', $raw, $matches ) ) {
			$channel_id = $matches[0];
		} elseif ( preg_match( '#youtube\.com/@([A-Za-z0-9._-]+)#', $raw, $matches ) ) {
			$handle = $matches[1];
		} elseif ( preg_match( '/^@([A-Za-z0-9._-]+)$/', $raw, $matches ) ) {
			$handle = $matches[1];
		} elseif ( preg_match( '#youtube\.com/(?:c|user)/([^/?#]+)#', $raw, $matches ) ) {
			$username = rawurldecode( $matches[1] );
		}

		if ( $channel_id && ! self::has_api_key() ) {
			return array(
				'id'    => $channel_id,
				'title' => $channel_id,
			);
		}

		if ( ! self::has_api_key() ) {
			return new \WP_Error(
				'cm_youtube_key',
				__( 'Add a YouTube Data API key under Collections → Settings to look up channels by @handle or custom URL.', 'collections-manager' )
			);
		}

		$query = array( 'part' => 'snippet,contentDetails' );
		if ( $channel_id ) {
			$query['id'] = $channel_id;
		} elseif ( $handle ) {
			$query['forHandle'] = $handle;
		} elseif ( $username ) {
			$query['forUsername'] = $username;
		} else {
			return new \WP_Error( 'cm_youtube_channel', __( 'Could not read a channel from that value.', 'collections-manager' ) );
		}

		$data = self::api_get( 'channels', $query );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$item = $data['items'][0] ?? null;
		if ( ! is_array( $item ) || empty( $item['id'] ) ) {
			return new \WP_Error( 'cm_youtube_channel', __( 'YouTube did not return that channel.', 'collections-manager' ) );
		}

		$title = isset( $item['snippet']['title'] ) ? sanitize_text_field( (string) $item['snippet']['title'] ) : (string) $item['id'];

		return array(
			'id'    => (string) $item['id'],
			'title' => $title,
		);
	}

	/**
	 * Recent videos from a channel.
	 *
	 * @param string $channel_id Channel ID.
	 * @param string $search     Optional title filter.
	 * @param string $page_token API page token.
	 * @return array{videos:array,next:string}|\WP_Error
	 */
	public static function channel_videos( $channel_id, $search = '', $page_token = '' ) {
		$channel_id = sanitize_text_field( $channel_id );
		if ( ! preg_match( '/^UC[A-Za-z0-9_-]{22}$/', $channel_id ) ) {
			return new \WP_Error( 'cm_youtube_channel', __( 'Invalid channel ID.', 'collections-manager' ) );
		}

		$search     = sanitize_text_field( $search );
		$page_token = sanitize_text_field( $page_token );

		if ( self::has_api_key() ) {
			return self::api_channel_videos( $channel_id, $search, $page_token );
		}

		$items = self::rss_videos( 'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode( $channel_id ), 15 );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$items  = array_values(
				array_filter(
					$items,
					static function ( $item ) use ( $needle ) {
						return false !== strpos( strtolower( (string) $item['title'] ), $needle );
					}
				)
			);
		}

		return array(
			'videos' => $items,
			'next'   => '',
		);
	}

	/**
	 * Hydrate stored video ids (and optional cached titles).
	 *
	 * @param array $videos List of ids or {id,title} rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function videos_by_ids( $videos ) {
		$ids    = array();
		$titles = array();
		foreach ( (array) $videos as $row ) {
			if ( is_array( $row ) ) {
				$id = self::parse_video_id( (string) ( $row['id'] ?? '' ) );
				if ( $id ) {
					$ids[]        = $id;
					$titles[ $id ] = sanitize_text_field( (string) ( $row['title'] ?? '' ) );
				}
			} else {
				$id = self::parse_video_id( (string) $row );
				if ( $id ) {
					$ids[] = $id;
				}
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$fetched = array();
		if ( self::has_api_key() ) {
			$chunks = array_chunk( $ids, 50 );
			foreach ( $chunks as $chunk ) {
				$data = self::api_get(
					'videos',
					array(
						'part' => 'snippet',
						'id'   => implode( ',', $chunk ),
					)
				);
				if ( is_wp_error( $data ) || empty( $data['items'] ) ) {
					continue;
				}
				foreach ( $data['items'] as $item ) {
					$video = self::shape_api_video( $item );
					if ( $video ) {
						$fetched[ $video['id'] ] = $video;
					}
				}
			}
		}

		$out = array();
		foreach ( $ids as $id ) {
			if ( isset( $fetched[ $id ] ) ) {
				$out[] = $fetched[ $id ];
				continue;
			}
			$title = isset( $titles[ $id ] ) && '' !== $titles[ $id ] ? $titles[ $id ] : $id;
			$out[] = self::shape_video( $id, $title, '', '' );
		}

		return $out;
	}

	/**
	 * @param string $playlist_id Playlist ID.
	 * @param int    $limit       Max items.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function api_playlist_items( $playlist_id, $limit ) {
		$items = array();
		$token = '';

		do {
			$query = array(
				'part'       => 'snippet,contentDetails',
				'playlistId' => $playlist_id,
				'maxResults' => min( 50, $limit - count( $items ) ),
			);
			if ( $token ) {
				$query['pageToken'] = $token;
			}

			$data = self::api_get( 'playlistItems', $query );
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
				$video_id = isset( $item['contentDetails']['videoId'] ) ? self::parse_video_id( (string) $item['contentDetails']['videoId'] ) : '';
				$title    = isset( $item['snippet']['title'] ) ? (string) $item['snippet']['title'] : '';
				if ( ! $video_id || in_array( $title, array( 'Private video', 'Deleted video' ), true ) ) {
					continue;
				}
				$thumb = '';
				if ( ! empty( $item['snippet']['thumbnails']['medium']['url'] ) ) {
					$thumb = (string) $item['snippet']['thumbnails']['medium']['url'];
				} elseif ( ! empty( $item['snippet']['thumbnails']['high']['url'] ) ) {
					$thumb = (string) $item['snippet']['thumbnails']['high']['url'];
				}
				$published = isset( $item['snippet']['publishedAt'] ) ? (string) $item['snippet']['publishedAt'] : '';
				$items[]   = self::shape_video( $video_id, $title, $thumb, $published );
				if ( count( $items ) >= $limit ) {
					break 2;
				}
			}

			$token = isset( $data['nextPageToken'] ) ? (string) $data['nextPageToken'] : '';
		} while ( $token && count( $items ) < $limit );

		return $items;
	}

	/**
	 * @param string $channel_id Channel ID.
	 * @param string $search     Search query.
	 * @param string $page_token Page token.
	 * @return array{videos:array,next:string}|\WP_Error
	 */
	private static function api_channel_videos( $channel_id, $search, $page_token ) {
		if ( '' !== $search ) {
			$query = array(
				'part'       => 'snippet',
				'channelId'  => $channel_id,
				'type'       => 'video',
				'order'      => 'date',
				'maxResults' => 12,
				'q'          => $search,
			);
			if ( $page_token ) {
				$query['pageToken'] = $page_token;
			}
			$data = self::api_get( 'search', $query );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$videos = array();
			foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
				$video_id = isset( $item['id']['videoId'] ) ? self::parse_video_id( (string) $item['id']['videoId'] ) : '';
				if ( ! $video_id ) {
					continue;
				}
				$title = isset( $item['snippet']['title'] ) ? (string) $item['snippet']['title'] : '';
				$thumb = isset( $item['snippet']['thumbnails']['medium']['url'] ) ? (string) $item['snippet']['thumbnails']['medium']['url'] : '';
				$published = isset( $item['snippet']['publishedAt'] ) ? (string) $item['snippet']['publishedAt'] : '';
				$videos[] = self::shape_video( $video_id, $title, $thumb, $published );
			}

			return array(
				'videos' => $videos,
				'next'   => isset( $data['nextPageToken'] ) ? (string) $data['nextPageToken'] : '',
			);
		}

		$channel = self::api_get(
			'channels',
			array(
				'part' => 'contentDetails',
				'id'   => $channel_id,
			)
		);
		if ( is_wp_error( $channel ) ) {
			return $channel;
		}

		$uploads = $channel['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '';
		if ( ! $uploads ) {
			return new \WP_Error( 'cm_youtube_channel', __( 'That channel has no public uploads playlist.', 'collections-manager' ) );
		}

		$query = array(
			'part'       => 'snippet,contentDetails',
			'playlistId' => $uploads,
			'maxResults' => 12,
		);
		if ( $page_token ) {
			$query['pageToken'] = $page_token;
		}

		$data = self::api_get( 'playlistItems', $query );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$videos = array();
		foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
			$video_id = isset( $item['contentDetails']['videoId'] ) ? self::parse_video_id( (string) $item['contentDetails']['videoId'] ) : '';
			$title    = isset( $item['snippet']['title'] ) ? (string) $item['snippet']['title'] : '';
			if ( ! $video_id || in_array( $title, array( 'Private video', 'Deleted video' ), true ) ) {
				continue;
			}
			$thumb = isset( $item['snippet']['thumbnails']['medium']['url'] ) ? (string) $item['snippet']['thumbnails']['medium']['url'] : '';
			$published = isset( $item['snippet']['publishedAt'] ) ? (string) $item['snippet']['publishedAt'] : '';
			$videos[] = self::shape_video( $video_id, $title, $thumb, $published );
		}

		return array(
			'videos' => $videos,
			'next'   => isset( $data['nextPageToken'] ) ? (string) $data['nextPageToken'] : '',
		);
	}

	/**
	 * @param string               $endpoint API endpoint.
	 * @param array<string,string> $query    Query args without key.
	 * @return array|\WP_Error
	 */
	private static function api_get( $endpoint, $query ) {
		$query['key'] = self::api_key();
		$url          = self::API_BASE . $endpoint . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$response     = wp_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'limit_response_size' => 1024 * 1024,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			$message = '';
			if ( is_array( $body ) && ! empty( $body['error']['message'] ) ) {
				$message = sanitize_text_field( (string) $body['error']['message'] );
			}
			return new \WP_Error(
				'cm_youtube_api',
				$message ? $message : __( 'YouTube could not complete that request.', 'collections-manager' )
			);
		}

		return $body;
	}

	/**
	 * @param string $url   Atom feed URL.
	 * @param int    $limit Max entries.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function rss_videos( $url, $limit ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'limit_response_size' => 512 * 1024,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || ! function_exists( 'simplexml_load_string' ) ) {
			return new \WP_Error( 'cm_youtube_rss', __( 'Could not load that YouTube feed. Check the ID, or add a Data API key under Settings.', 'collections-manager' ) );
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( wp_remote_retrieve_body( $response ), 'SimpleXMLElement', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( false === $xml ) {
			return new \WP_Error( 'cm_youtube_rss', __( 'YouTube returned an unreadable feed.', 'collections-manager' ) );
		}

		$xml->registerXPathNamespace( 'atom', 'http://www.w3.org/2005/Atom' );
		$xml->registerXPathNamespace( 'yt', 'http://www.youtube.com/xml/schemas/2015' );
		$entries = $xml->xpath( '/atom:feed/atom:entry' );
		if ( empty( $entries ) ) {
			return array();
		}

		$items = array();
		foreach ( $entries as $entry ) {
			$yt    = $entry->children( 'http://www.youtube.com/xml/schemas/2015' );
			$id    = self::parse_video_id( (string) $yt->videoId );
			$title = sanitize_text_field( (string) $entry->children( 'http://www.w3.org/2005/Atom' )->title );
			$published = (string) $entry->children( 'http://www.w3.org/2005/Atom' )->published;
			if ( ! $id ) {
				continue;
			}
			$items[] = self::shape_video( $id, $title, '', $published );
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * @param array $item YouTube videos.list item.
	 * @return array<string, mixed>|null
	 */
	private static function shape_api_video( $item ) {
		$id = isset( $item['id'] ) ? self::parse_video_id( (string) $item['id'] ) : '';
		if ( ! $id ) {
			return null;
		}
		$title = isset( $item['snippet']['title'] ) ? (string) $item['snippet']['title'] : '';
		$thumb = isset( $item['snippet']['thumbnails']['medium']['url'] ) ? (string) $item['snippet']['thumbnails']['medium']['url'] : '';
		$published = isset( $item['snippet']['publishedAt'] ) ? (string) $item['snippet']['publishedAt'] : '';

		return self::shape_video( $id, $title, $thumb, $published );
	}

	/**
	 * @param string $id        Video ID.
	 * @param string $title     Title.
	 * @param string $image     Thumbnail URL.
	 * @param string $published ISO or atom date.
	 * @return array<string, mixed>
	 */
	private static function shape_video( $id, $title, $image, $published ) {
		$timestamp = $published ? strtotime( $published ) : false;
		$image     = $image ? esc_url_raw( $image ) : ( 'https://i.ytimg.com/vi/' . $id . '/mqdefault.jpg' );

		return array(
			'id'        => $id,
			'title'     => sanitize_text_field( $title ? $title : $id ),
			'url'       => 'https://www.youtube.com/watch?v=' . $id,
			'image'     => $image,
			'date'      => $timestamp ? date_i18n( get_option( 'date_format' ), $timestamp ) : '',
			'timestamp' => $timestamp ? (int) $timestamp : 0,
			'external'  => true,
		);
	}
}
