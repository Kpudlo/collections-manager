/**
 * Streaming-style collection rail and post carousels (Embla).
 */
import EmblaCarousel from 'embla-carousel';

const reduceMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

const RAIL_OPTIONS = {
	align: 'start',
	containScroll: 'trimSnaps',
	dragFree: ! reduceMotion,
	duration: reduceMotion ? 0 : 28,
	skipSnaps: true,
	watchSlides: false,
};

const CAROUSEL_OPTIONS = {
	align: 'start',
	containScroll: 'trimSnaps',
	duration: reduceMotion ? 0 : 32,
	skipSnaps: true,
	watchSlides: false,
};

function initBrowser( root ) {
	const tabs = Array.from( root.querySelectorAll( '.cm-browser__tab' ) );
	const stage = root.querySelector( '.cm-browser__stage' );
	const panels = Array.from( root.querySelectorAll( '.cm-browser__panel' ) );
	if ( ! tabs.length || ! stage ) {
		return;
	}

	function bindSlider( viewport, prev, next, options, keyboardRoot ) {
		if ( ! viewport ) {
			return null;
		}

		const embla = EmblaCarousel( viewport, options );
		const keysRoot = keyboardRoot || viewport;
		const syncNav = () => {
			const canPrev = embla.canScrollPrev();
			const canNext = embla.canScrollNext();
			if ( prev ) {
				prev.hidden = ! canPrev;
				prev.disabled = ! canPrev;
			}
			if ( next ) {
				next.hidden = ! canNext;
				next.disabled = ! canNext;
			}
		};

		embla.on( 'select', syncNav );
		embla.on( 'reInit', syncNav );
		syncNav();

		if ( prev ) {
			prev.addEventListener( 'click', () => embla.scrollPrev() );
		}
		if ( next ) {
			next.addEventListener( 'click', () => embla.scrollNext() );
		}

		keysRoot.addEventListener( 'keydown', ( event ) => {
			if ( event.altKey || event.ctrlKey || event.metaKey ) {
				return;
			}
			if ( event.key === 'ArrowLeft' ) {
				event.preventDefault();
				embla.scrollPrev();
			} else if ( event.key === 'ArrowRight' ) {
				event.preventDefault();
				embla.scrollNext();
			}
		} );

		viewport.addEventListener( 'focusin', ( event ) => {
			const slides = embla.slideNodes();
			const index = slides.findIndex( ( slide ) => slide.contains( event.target ) );
			if ( index >= 0 && ! embla.slidesInView().includes( index ) ) {
				embla.scrollTo( index );
			}
		} );

		return embla;
	}

	const rail = root.querySelector( '.cm-browser__rail' );
	const railViewport = root.querySelector( '.cm-browser__rail-viewport' );
	const railEmbla =
		rail && railViewport
			? bindSlider(
					railViewport,
					rail.querySelector( ':scope > .cm-browser__nav--prev' ),
					rail.querySelector( ':scope > .cm-browser__nav--next' ),
					RAIL_OPTIONS,
					rail
			  )
			: null;

	const carousels = new Map();

	root.querySelectorAll( '.cm-browser__carousel' ).forEach( ( carousel ) => {
		const embla = bindSlider(
			carousel.querySelector( '.cm-browser__viewport' ),
			carousel.querySelector( '.cm-browser__nav--prev' ),
			carousel.querySelector( '.cm-browser__nav--next' ),
			CAROUSEL_OPTIONS,
			carousel
		);
		const panel = carousel.closest( '.cm-browser__panel' );
		if ( embla && panel ) {
			carousels.set( panel.dataset.collectionId, embla );
		}
	} );

	function setPanelOpen( panel, open ) {
		panel.hidden = ! open;
		if ( open ) {
			panel.removeAttribute( 'inert' );
		} else {
			panel.setAttribute( 'inert', '' );
		}
	}

	function syncRail() {
		if ( ! railEmbla ) {
			return;
		}
		const snap = railEmbla.selectedScrollSnap();
		railEmbla.reInit();
		railEmbla.scrollTo( snap, true );
	}

	function afterRailSettle( callback ) {
		if ( reduceMotion ) {
			callback();
			return;
		}
		window.setTimeout( callback, 560 );
	}

	function activatePanel( id ) {
		panels.forEach( ( panel ) => {
			setPanelOpen( panel, panel.dataset.collectionId === id );
		} );
		const embla = carousels.get( id );
		if ( ! embla ) {
			return;
		}
		embla.reInit();
		embla.scrollTo( 0, true );
	}

	let swapTimer = 0;

	function switchCollection( id ) {
		const inner = stage.querySelector( '.cm-browser__stage-inner' );
		const next = panels.find( ( panel ) => panel.dataset.collectionId === id );

		tabs.forEach( ( tab ) => {
			tab.setAttribute(
				'aria-expanded',
				tab.dataset.collectionId === id ? 'true' : 'false'
			);
		} );

		window.clearTimeout( swapTimer );

		if ( reduceMotion || ! inner || ! next ) {
			activatePanel( id );
			return;
		}

		const fromHeight = inner.getBoundingClientRect().height;
		inner.style.height = fromHeight + 'px';
		inner.classList.add( 'is-swapping' );

		swapTimer = window.setTimeout( () => {
			activatePanel( id );
			window.requestAnimationFrame( () => {
				const toHeight = next.getBoundingClientRect().height;
				inner.classList.remove( 'is-swapping' );
				inner.style.height = Math.max( toHeight, 0 ) + 'px';

				const release = ( event ) => {
					if ( event && event.propertyName && event.propertyName !== 'height' ) {
						return;
					}
					inner.style.height = '';
					inner.removeEventListener( 'transitionend', release );
				};
				inner.addEventListener( 'transitionend', release );
				window.setTimeout( () => release(), 420 );
			} );
		}, 180 );
	}

	function closeAll( restoreFocus ) {
		const openTab = tabs.find( ( tab ) => tab.getAttribute( 'aria-expanded' ) === 'true' );
		window.clearTimeout( swapTimer );
		const inner = stage.querySelector( '.cm-browser__stage-inner' );
		if ( inner ) {
			inner.classList.remove( 'is-swapping' );
			inner.style.height = '';
		}
		root.classList.remove( 'is-open' );
		stage.classList.remove( 'is-open' );
		tabs.forEach( ( tab ) => tab.setAttribute( 'aria-expanded', 'false' ) );
		panels.forEach( ( panel ) => setPanelOpen( panel, false ) );
		afterRailSettle( syncRail );
		if ( restoreFocus && openTab ) {
			openTab.focus();
		}
	}

	function openCollection( id, fromTab ) {
		const alreadyOpen = tabs.some(
			( tab ) =>
				tab.getAttribute( 'aria-expanded' ) === 'true' &&
				tab.dataset.collectionId === id
		);
		if ( alreadyOpen ) {
			closeAll( false );
			return;
		}

		const wasOpen = root.classList.contains( 'is-open' );
		if ( wasOpen ) {
			switchCollection( id );
			return;
		}

		root.classList.add( 'is-open' );
		stage.classList.add( 'is-open' );
		tabs.forEach( ( tab ) => {
			tab.setAttribute(
				'aria-expanded',
				tab.dataset.collectionId === id ? 'true' : 'false'
			);
		} );
		panels.forEach( ( panel ) => {
			setPanelOpen( panel, panel.dataset.collectionId === id );
		} );

		if ( ! fromTab ) {
			const panel = panels.find( ( item ) => item.dataset.collectionId === id );
			if ( panel ) {
				panel.focus();
			}
		}

		afterRailSettle( syncRail );

		const embla = carousels.get( id );
		if ( ! embla ) {
			return;
		}

		const settle = () => {
			embla.reInit();
			embla.scrollTo( 0, true );
		};

		if ( reduceMotion ) {
			settle();
			return;
		}

		window.setTimeout( settle, 620 );
		const onStageEnd = ( event ) => {
			if ( event.target !== stage || event.propertyName !== 'grid-template-rows' ) {
				return;
			}
			stage.removeEventListener( 'transitionend', onStageEnd );
			settle();
		};
		stage.addEventListener( 'transitionend', onStageEnd );
	}

	tabs.forEach( ( tab ) => {
		tab.addEventListener( 'click', () => {
			openCollection( tab.dataset.collectionId, tab );
		} );
	} );

	root.querySelectorAll( '.cm-browser__close' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => closeAll( true ) );
	} );

	root.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Escape' && root.classList.contains( 'is-open' ) ) {
			event.preventDefault();
			closeAll( true );
		}
	} );
}

document.querySelectorAll( '.cm-browser' ).forEach( initBrowser );
