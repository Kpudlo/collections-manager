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
};

const CAROUSEL_OPTIONS = {
	align: 'start',
	containScroll: 'trimSnaps',
	duration: reduceMotion ? 0 : 32,
	skipSnaps: true,
};

function initBrowser( root ) {
	const tabs = Array.from( root.querySelectorAll( '.cm-browser__tab' ) );
	const stage = root.querySelector( '.cm-browser__stage' );
	const panels = Array.from( root.querySelectorAll( '.cm-browser__panel' ) );
	if ( ! tabs.length || ! stage ) {
		return;
	}

	function bindSlider( viewport, prev, next, options ) {
		if ( ! viewport ) {
			return null;
		}

		const embla = EmblaCarousel( viewport, options );
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

		return embla;
	}

	const rail = root.querySelector( '.cm-browser__rail' );
	const railViewport = root.querySelector( '.cm-browser__rail-viewport' );
	if ( rail && railViewport ) {
		bindSlider(
			railViewport,
			rail.querySelector( ':scope > .cm-browser__nav--prev' ),
			rail.querySelector( ':scope > .cm-browser__nav--next' ),
			RAIL_OPTIONS
		);
	}

	const carousels = new Map();

	root.querySelectorAll( '.cm-browser__carousel' ).forEach( ( carousel ) => {
		const embla = bindSlider(
			carousel.querySelector( '.cm-browser__viewport' ),
			carousel.querySelector( '.cm-browser__nav--prev' ),
			carousel.querySelector( '.cm-browser__nav--next' ),
			CAROUSEL_OPTIONS
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

	function closeAll( restoreFocus ) {
		const openTab = tabs.find( ( tab ) => tab.getAttribute( 'aria-expanded' ) === 'true' );
		root.classList.remove( 'is-open' );
		stage.classList.remove( 'is-open' );
		tabs.forEach( ( tab ) => tab.setAttribute( 'aria-expanded', 'false' ) );
		panels.forEach( ( panel ) => setPanelOpen( panel, false ) );
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

		const panel = panels.find( ( item ) => item.dataset.collectionId === id );
		if ( panel ) {
			panel.focus( { preventScroll: true } );
		}

		const embla = carousels.get( id );
		if ( ! embla ) {
			return;
		}

		const settle = () => {
			embla.reInit();
			embla.scrollTo( 0, true );
		};

		requestAnimationFrame( settle );
		if ( ! reduceMotion ) {
			window.setTimeout( settle, 520 );
			stage.addEventListener( 'transitionend', settle, { once: true } );
		}
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
