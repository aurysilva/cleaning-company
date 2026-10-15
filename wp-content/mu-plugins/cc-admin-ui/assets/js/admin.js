// Adds a soft shadow under the top bar once the page scrolls.
( function () {
	var body = document.body;
	var queued = false;

	function update() {
		body.classList.toggle( 'cc-ui-scrolled', window.scrollY > 4 );
		queued = false;
	}

	window.addEventListener( 'scroll', function () {
		if ( ! queued ) {
			queued = true;
			window.requestAnimationFrame( update );
		}
	}, { passive: true } );

	update();
} )();
