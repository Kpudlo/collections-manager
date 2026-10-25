( function ( $ ) {
	var config = window.collectionsManagerDashboard || {};
	var frame;
	var searchTimer;

	function selectedIds() {
		return $( '#cm-selected-posts li' )
			.map( function () {
				return String( $( this ).data( 'id' ) );
			} )
			.get();
	}

	function addPost( post ) {
		if ( selectedIds().indexOf( String( post.id ) ) !== -1 ) {
			return;
		}

		var $item = $(
			'<li data-id="' +
				post.id +
				'">' +
				'<input type="hidden" name="collection[post_ids][]" value="' +
				post.id +
				'" />' +
				'<span class="cm-selected-posts__title"></span>' +
				'<button type="button" class="button-link cm-move-up">Up</button>' +
				'<button type="button" class="button-link cm-move-down">Down</button>' +
				'<button type="button" class="button-link cm-remove-post">Remove</button>' +
				'</li>'
		);
		$item.find( '.cm-selected-posts__title' ).text( post.title );
		$( '#cm-selected-posts' ).append( $item );
	}

	function currentSource() {
		return $( 'input[name="collection[source]"]:checked' ).val() || 'manual';
	}

	function syncSource() {
		var source = currentSource();
		$( '#cm-panel-manual' ).prop( 'hidden', source !== 'manual' );
		$( '#cm-panel-query' ).prop( 'hidden', source !== 'query' );
		$( '#cm-panel-youtube' ).prop( 'hidden', source !== 'youtube' );
		$( '#cm-panel-feed' ).prop( 'hidden', source !== 'feed' );
		syncYoutubeMode();
	}

	function youtubeMode() {
		return $( 'input[name="collection[youtube][mode]"]:checked' ).val() || 'latest';
	}

	function youtubeChannelIds() {
		return $( '#cm-youtube-channels li' )
			.map( function () {
				return String( $( this ).data( 'id' ) );
			} )
			.get();
	}

	function syncYoutubeMode() {
		var mode = youtubeMode();
		var hasChannel = youtubeChannelIds().length > 0;
		var unlocked =
			hasChannel || $.trim( $( '#cm-youtube-playlist-id' ).val() ) !== '';
		$( '#cm-youtube-need-channel' ).prop( 'hidden', unlocked );
		$( '#cm-youtube-after-channel' ).prop( 'hidden', ! unlocked );
		$( '#cm-youtube-latest' ).prop( 'hidden', mode !== 'latest' );
		$( '#cm-youtube-playlist' ).prop( 'hidden', mode !== 'playlist' );
		$( '#cm-youtube-videos' ).prop( 'hidden', mode !== 'videos' );
		syncLimitFields();
		if ( hasChannel && mode === 'playlist' ) {
			loadChannelPlaylists();
		}
	}

	function syncLimitFields() {
		var queryUnlimited = $( '#cm-query-unlimited' ).is( ':checked' );
		$( '#cm-query-limit-wrap' ).prop( 'hidden', queryUnlimited );

		var feedUnlimited = $( '#cm-feed-unlimited' ).is( ':checked' );
		$( '#cm-feed-limit-wrap' ).prop( 'hidden', feedUnlimited );
		$( '#cm-feed-unlimited-help' ).prop( 'hidden', ! feedUnlimited );

		var mode = youtubeMode();
		var ytUnlimited = $( '#cm-youtube-unlimited' ).is( ':checked' );
		$( '#cm-youtube-unlimited' )
			.closest( 'p' )
			.prop( 'hidden', mode === 'videos' );
		$( '#cm-youtube-limit-wrap' ).prop(
			'hidden',
			mode === 'videos' || ytUnlimited
		);
		$( '#cm-youtube-unlimited-help' ).prop(
			'hidden',
			mode === 'videos' || ! ytUnlimited
		);
	}

	function selectedVideoIds() {
		return $( '#cm-youtube-selected li' )
			.map( function () {
				return String( $( this ).data( 'id' ) );
			} )
			.get();
	}

	function channelOption( channel ) {
		return $( '<option/>' ).val( channel.id ).text( channel.title );
	}

	function fillChannelSelect( $pick ) {
		var current = $pick.val();
		$pick.empty();
		$( '#cm-youtube-channels li' ).each( function () {
			$pick.append(
				channelOption( {
					id: String( $( this ).data( 'id' ) ),
					title: $( this ).find( '.cm-selected-posts__title' ).text(),
				} )
			);
		} );
		if ( current && $pick.find( 'option[value="' + current + '"]' ).length ) {
			$pick.val( current );
		}
	}

	function refreshChannelPick() {
		fillChannelSelect( $( '#cm-youtube-channel-pick' ) );
		fillChannelSelect( $( '#cm-youtube-playlist-channel' ) );
		syncYoutubeMode();
	}

	function loadChannelPlaylists() {
		var channelId = $( '#cm-youtube-playlist-channel' ).val();
		var $status = $( '#cm-youtube-playlist-list-status' );
		var $pick = $( '#cm-youtube-playlist-pick' );
		var current = $.trim( $( '#cm-youtube-playlist-id' ).val() );
		if ( ! channelId ) {
			return;
		}
		if ( ! config.hasApiKey ) {
			$status.text(
				'Paste a playlist URL, or add an API key to list playlists.'
			);
			return;
		}
		$status.text( 'Loading playlists…' );
		$.getJSON( config.ajaxUrl, {
			action: 'collections_manager_youtube_channel_playlists',
			_ajax_nonce: config.nonce,
			channel_id: channelId,
		} ).done( function ( response ) {
			$pick.find( 'option:not(:first)' ).remove();
			if ( ! response || ! response.success ) {
				$status.text(
					( response && response.data && response.data.message ) ||
						'Could not list playlists.'
				);
				return;
			}
			var playlists = ( response.data && response.data.playlists ) || [];
			playlists.forEach( function ( playlist ) {
				var label = playlist.title;
				if ( playlist.count ) {
					label += ' (' + playlist.count + ')';
				}
				$pick.append( $( '<option/>' ).val( playlist.id ).text( label ) );
			} );
			if ( current && $pick.find( 'option[value="' + current + '"]' ).length ) {
				$pick.val( current );
			}
			if ( ! playlists.length ) {
				$status.text(
					( config.i18n && config.i18n.playlistsEmpty ) ||
						'No public playlists. Paste a URL instead.'
				);
				return;
			}
			$status.text( '' );
		} );
	}

	function addChannel( channel ) {
		if (
			$( '#cm-youtube-channels li[data-id="' + channel.id + '"]' ).length
		) {
			return;
		}
		var $item = $(
			'<li data-id="' +
				channel.id +
				'">' +
				'<input type="hidden" name="collection[youtube][channels][id][]" />' +
				'<input type="hidden" name="collection[youtube][channels][title][]" />' +
				'<span class="cm-selected-posts__title"></span>' +
				'<button type="button" class="button-link cm-remove-channel">Remove</button>' +
				'</li>'
		);
		$item.find( 'input' ).eq( 0 ).val( channel.id );
		$item.find( 'input' ).eq( 1 ).val( channel.title );
		$item.find( '.cm-selected-posts__title' ).text( channel.title );
		$( '#cm-youtube-channels' ).append( $item );
		refreshChannelPick();
	}

	function addVideo( video ) {
		if ( selectedVideoIds().indexOf( String( video.id ) ) !== -1 ) {
			return;
		}
		var $item = $(
			'<li data-id="' +
				video.id +
				'">' +
				'<input type="hidden" name="collection[youtube][video_ids][]" />' +
				'<input type="hidden" name="collection[youtube][video_titles][]" />' +
				'<span class="cm-selected-posts__title"></span>' +
				'<button type="button" class="button-link cm-move-up">Up</button>' +
				'<button type="button" class="button-link cm-move-down">Down</button>' +
				'<button type="button" class="button-link cm-remove-video">Remove</button>' +
				'</li>'
		);
		$item.find( 'input' ).eq( 0 ).val( video.id );
		$item.find( 'input' ).eq( 1 ).val( video.title );
		$item.find( '.cm-selected-posts__title' ).text( video.title );
		$( '#cm-youtube-selected' ).append( $item );
	}

	var youtubePageToken = '';

	function loadChannelVideos( append ) {
		var channelId = $( '#cm-youtube-channel-pick' ).val();
		var $status = $( '#cm-youtube-video-results' );
		if ( ! channelId ) {
			$status
				.empty()
				.append( $( '<li/>' ).text( ( config.i18n && config.i18n.addChannel ) || 'Add a channel first.' ) )
				.prop( 'hidden', false );
			return;
		}

		$.getJSON( config.ajaxUrl, {
			action: 'collections_manager_youtube_channel_videos',
			_ajax_nonce: config.nonce,
			channel_id: channelId,
			s: $.trim( $( '#cm-youtube-video-search' ).val() ),
			page_token: append ? youtubePageToken : '',
		} )
			.done( function ( response ) {
				if ( ! append ) {
					$status.empty();
				}
				if ( ! response || ! response.success ) {
					$status
						.empty()
						.append(
							$( '<li/>' ).text(
								( response && response.data && response.data.message ) ||
									'Could not load videos.'
							)
						)
						.prop( 'hidden', false );
					return;
				}
				var videos = response.data.videos || [];
				youtubePageToken = response.data.next || '';
				if ( ! videos.length && ! append ) {
					$status
						.append(
							$( '<li/>' ).text(
								( config.i18n && config.i18n.noVideos ) || 'No videos found.'
							)
						)
						.prop( 'hidden', false );
					$( '#cm-youtube-more' ).prop( 'hidden', true );
					return;
				}
				videos.forEach( function ( video ) {
					var $row = $(
						'<li><span></span><button type="button" class="button">Add</button></li>'
					);
					$row.find( 'span' ).text( video.title );
					$row.find( 'button' ).on( 'click', function () {
						addVideo( video );
					} );
					$status.append( $row );
				} );
				$status.prop( 'hidden', false );
				$( '#cm-youtube-more' ).prop( 'hidden', ! youtubePageToken );
			} )
			.fail( function () {
				$status
					.empty()
					.append( $( '<li/>' ).text( 'Could not load videos.' ) )
					.prop( 'hidden', false );
			} );
	}

	function taxonomiesFor( postType ) {
		var data = config.postTypes || {};
		return ( data[ postType ] && data[ postType ].taxonomies ) || {};
	}

	function fillTaxonomySelect() {
		var postType = $( '#cm-query-post-type' ).val();
		var taxes = taxonomiesFor( postType );
		var current = $( '#cm-query-taxonomy' ).val();
		var $tax = $( '#cm-query-taxonomy' ).empty();
		var names = Object.keys( taxes );

		if ( ! names.length ) {
			$tax.append( $( '<option/>' ).val( '' ).text( 'None available' ) );
			renderTerms( [] );
			return;
		}

		names.forEach( function ( name ) {
			$tax.append( $( '<option/>' ).val( name ).text( taxes[ name ] ) );
		} );

		if ( current && taxes[ current ] ) {
			$tax.val( current );
		}

		loadTerms();
	}

	function renderTerms( terms ) {
		var $list = $( '#cm-query-terms' ).empty();
		if ( ! terms.length ) {
			$list.append(
				$( '<p class="description"/>' ).text( 'No terms yet for this taxonomy.' )
			);
			return;
		}

		terms.forEach( function ( term ) {
			var $label = $( '<label/>' );
			$label.append(
				$( '<input type="checkbox" name="collection[query][terms][]" />' ).val(
					term.id
				)
			);
			$label.append( document.createTextNode( ' ' + term.name + ' ' ) );
			$label.append(
				$( '<span class="cm-term-count"/>' ).text( '(' + term.count + ')' )
			);
			$list.append( $label );
		} );
	}

	function loadTerms() {
		var taxonomy = $( '#cm-query-taxonomy' ).val();
		var postType = $( '#cm-query-post-type' ).val();
		if ( ! taxonomy ) {
			renderTerms( [] );
			return;
		}

		$.getJSON( config.ajaxUrl, {
			action: 'collections_manager_query_terms',
			_ajax_nonce: config.nonce,
			post_type: postType,
			taxonomy: taxonomy,
		} ).done( function ( response ) {
			renderTerms(
				response && response.success && Array.isArray( response.data )
					? response.data
					: []
			);
		} );
	}

	$( '.cm-select-image' ).on( 'click', function ( event ) {
		event.preventDefault();
		if ( ! frame ) {
			frame = wp.media( {
				title: 'Select cover image',
				button: { text: 'Use this image' },
				library: { type: 'image' },
				multiple: false,
			} );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				var url =
					att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
				$( '#cm-image-id' ).val( att.id );
				$( '.cm-image-preview' ).html(
					'<img src="' +
						url +
						'" alt="" style="max-width:240px;height:auto;display:block;" />'
				);
				$( '.cm-remove-image' ).prop( 'hidden', false );
			} );
		}
		frame.open();
	} );

	$( '.cm-remove-image' ).on( 'click', function ( event ) {
		event.preventDefault();
		$( '#cm-image-id' ).val( '0' );
		$( '.cm-image-preview' ).empty();
		$( this ).prop( 'hidden', true );
	} );

	$( 'input[name="collection[source]"]' ).on( 'change', syncSource );
	$( 'input[name="collection[youtube][mode]"]' ).on( 'change', syncYoutubeMode );
	$( '#cm-query-unlimited, #cm-youtube-unlimited, #cm-feed-unlimited' ).on(
		'change',
		syncLimitFields
	);
	syncLimitFields();

	$( '#cm-youtube-add-channel' ).on( 'click', function () {
		var value = $.trim( $( '#cm-youtube-channel-input' ).val() );
		if ( ! value ) {
			return;
		}
		$.getJSON( config.ajaxUrl, {
			action: 'collections_manager_youtube_resolve_channel',
			_ajax_nonce: config.nonce,
			channel: value,
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				window.alert(
					( response && response.data && response.data.message ) ||
						'Could not add that channel.'
				);
				return;
			}
			addChannel( response.data );
			$( '#cm-youtube-channel-input' ).val( '' );
		} );
	} );

	$( '#cm-youtube-channels' ).on( 'click', '.cm-remove-channel', function () {
		$( this ).closest( 'li' ).remove();
		refreshChannelPick();
	} );

	$( '#cm-youtube-load-videos' ).on( 'click', function () {
		loadChannelVideos( false );
	} );

	$( '#cm-youtube-more' ).on( 'click', function () {
		loadChannelVideos( true );
	} );

	$( '#cm-youtube-selected' ).on( 'click', '.cm-remove-video', function () {
		$( this ).closest( 'li' ).remove();
	} );

	$( '#cm-youtube-selected' ).on( 'click', '.cm-move-up', function () {
		var $item = $( this ).closest( 'li' );
		$item.prev( 'li' ).before( $item );
	} );

	$( '#cm-youtube-selected' ).on( 'click', '.cm-move-down', function () {
		var $item = $( this ).closest( 'li' );
		$item.next( 'li' ).after( $item );
	} );

	$( '#cm-youtube-playlist-pick' ).on( 'change', function () {
		var value = $( this ).val();
		if ( value ) {
			$( '#cm-youtube-playlist-id' ).val( value );
		}
	} );

	$( '#cm-youtube-playlist-channel' ).on( 'change', function () {
		if ( youtubeMode() === 'playlist' ) {
			loadChannelPlaylists();
		}
	} );

	$( '#cm-youtube-preview-latest' ).on( 'click', function () {
		var $status = $( '#cm-youtube-latest-status' );
		$status.text( 'Checking…' );
		$.getJSON( config.ajaxUrl, {
			action: 'collections_manager_youtube_latest_preview',
			_ajax_nonce: config.nonce,
			channels: youtubeChannelIds().join( ',' ),
			limit: $( '#cm-youtube-limit' ).val(),
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				$status.text(
					( response && response.data && response.data.message ) ||
						'Could not load latest videos.'
				);
				return;
			}
			var count = response.data.count || 0;
			if ( ! count ) {
				$status.text(
					( config.i18n && config.i18n.previewEmpty ) || 'No videos found.'
				);
				return;
			}
			var template =
				( config.i18n && config.i18n.previewOk ) || 'Found %d videos.';
			$status.text( template.replace( '%d', String( count ) ) );
		} );
	} );

	$( '#cm-youtube-preview' ).on( 'click', function () {
		var $status = $( '#cm-youtube-preview-status' );
		$status.text( 'Checking…' );
		$.getJSON( config.ajaxUrl, {
			action: 'collections_manager_youtube_playlist_preview',
			_ajax_nonce: config.nonce,
			playlist: $( '#cm-youtube-playlist-id' ).val(),
			limit: $( '#cm-youtube-limit' ).val(),
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				$status.text(
					( response && response.data && response.data.message ) ||
						'Could not load that playlist.'
				);
				return;
			}
			var count = response.data.count || 0;
			if ( ! count ) {
				$status.text(
					( config.i18n && config.i18n.previewEmpty ) || 'No videos found.'
				);
				return;
			}
			var template =
				( config.i18n && config.i18n.previewOk ) || 'Found %d videos.';
			$status.text( template.replace( '%d', String( count ) ) );
		} );
	} );

	function fillFeedTypes( types, selected ) {
		var $sel = $( '#cm-feed-rest-base' ).empty();
		if ( ! types || ! types.length ) {
			$sel.append( $( '<option/>' ).val( 'posts' ).text( 'Posts' ) );
			return;
		}
		types.forEach( function ( type ) {
			$sel.append( $( '<option/>' ).val( type.slug ).text( type.name ) );
		} );
		if ( selected && $sel.find( 'option[value="' + selected + '"]' ).length ) {
			$sel.val( selected );
		}
	}

	function fillFeedCategories( cats, selected ) {
		var $sel = $( '#cm-feed-category' ).empty();
		$sel.append( $( '<option/>' ).val( '0' ).text( 'All categories' ) );
		( cats || [] ).forEach( function ( cat ) {
			$sel.append(
				$( '<option/>' )
					.val( cat.id )
					.attr( 'data-slug', cat.slug )
					.text( cat.name )
			);
		} );
		if ( selected && $sel.find( 'option[value="' + selected + '"]' ).length ) {
			$sel.val( String( selected ) );
		}
		syncFeedCategorySlug();
	}

	function syncFeedCategorySlug() {
		var $opt = $( '#cm-feed-category option:selected' );
		$( '#cm-feed-category-slug' ).val( $opt.attr( 'data-slug' ) || '' );
	}

	$( '#cm-feed-category' ).on( 'change', syncFeedCategorySlug );

	$( '#cm-feed-discover' ).on( 'click', function () {
		var $status = $( '#cm-feed-discover-status' );
		$status.text( 'Detecting…' );
		$.getJSON( config.ajaxUrl, {
			action: 'collections_manager_feed_discover',
			_ajax_nonce: config.nonce,
			url: $( '#cm-feed-url' ).val(),
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				$status.text(
					( response && response.data && response.data.message ) ||
						'Could not detect that URL.'
				);
				return;
			}
			var data = response.data;
			$( '#cm-feed-kind' ).val( data.kind || 'auto' );
			if ( data.url ) {
				$( '#cm-feed-url' ).val( data.url );
			}
			$( '#cm-feed-rest-fields' ).prop( 'hidden', data.kind === 'rss' );
			if ( data.kind === 'rest' ) {
				fillFeedTypes( data.types, $( '#cm-feed-rest-base' ).val() );
				fillFeedCategories( data.categories, $( '#cm-feed-category' ).val() );
				$status.text(
					( config.i18n && config.i18n.feedRest ) ||
						'WordPress site detected.'
				);
			} else {
				$status.text(
					( config.i18n && config.i18n.feedRss ) || 'XML feed detected.'
				);
			}
		} );
	} );

	$( '#cm-feed-preview' ).on( 'click', function () {
		var $status = $( '#cm-feed-preview-status' );
		$status.text( 'Checking…' );
		$.getJSON( config.ajaxUrl, {
			action: 'collections_manager_feed_preview',
			_ajax_nonce: config.nonce,
			url: $( '#cm-feed-url' ).val(),
			kind: $( '#cm-feed-kind' ).val(),
			rest_base: $( '#cm-feed-rest-base' ).val(),
			category_id: $( '#cm-feed-category' ).val(),
			category_slug: $( '#cm-feed-category-slug' ).val(),
			limit: $( '#cm-feed-limit' ).val(),
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				$status.text(
					( response && response.data && response.data.message ) ||
						'Could not load that feed.'
				);
				return;
			}
			var count = response.data.count || 0;
			if ( ! count ) {
				$status.text(
					( config.i18n && config.i18n.feedEmpty ) || 'No items found.'
				);
				return;
			}
			var template = ( config.i18n && config.i18n.feedOk ) || 'Found %d items.';
			$status.text( template.replace( '%d', String( count ) ) );
		} );
	} );

	$( '#cm-query-post-type' ).on( 'change', fillTaxonomySelect );
	$( '#cm-query-taxonomy' ).on( 'change', loadTerms );

	$( '#cm-post-search' ).on( 'input', function () {
		var query = $.trim( $( this ).val() );
		window.clearTimeout( searchTimer );
		if ( query.length < 2 ) {
			$( '#cm-search-results' ).empty().prop( 'hidden', true );
			return;
		}

		searchTimer = window.setTimeout( function () {
			$.getJSON( config.ajaxUrl, {
				action: 'collections_manager_search_posts',
				_ajax_nonce: config.nonce,
				s: query,
				post_type: $( '#cm-manual-post-type' ).val() || 'post',
			} ).done( function ( response ) {
				var $list = $( '#cm-search-results' ).empty();
				if ( ! response || ! response.success || ! response.data.length ) {
					$list.prop( 'hidden', true );
					return;
				}
				response.data.forEach( function ( post ) {
					var $row = $(
						'<li><span></span><button type="button" class="button">Add</button></li>'
					);
					$row.find( 'span' ).text( post.title );
					$row.find( 'button' ).on( 'click', function () {
						addPost( post );
					} );
					$list.append( $row );
				} );
				$list.prop( 'hidden', false );
			} );
		}, 250 );
	} );

	$( '#cm-selected-posts' ).on( 'click', '.cm-remove-post', function () {
		$( this ).closest( 'li' ).remove();
	} );

	$( '#cm-selected-posts' ).on( 'click', '.cm-move-up', function () {
		var $item = $( this ).closest( 'li' );
		$item.prev( 'li' ).before( $item );
	} );

	$( '#cm-selected-posts' ).on( 'click', '.cm-move-down', function () {
		var $item = $( this ).closest( 'li' );
		$item.next( 'li' ).after( $item );
	} );
} )( jQuery );
