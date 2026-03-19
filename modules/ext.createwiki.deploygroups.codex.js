( function () {
	$( () => {
		const $searchInput = $( '[data-mw-createwiki-deploygroups-search]' );
		if ( !$searchInput.length ) {
			return;
		}

		const $items = $( '[data-mw-createwiki-deploygroups-item]' );
		const $emptyState = $( '[data-mw-createwiki-deploygroups-empty-state]' );

		const updateSearch = () => {
			const query = String( $searchInput.val() || '' ).toLowerCase().trim();
			let visibleCount = 0;

			$items.each( ( _, item ) => {
				const $item = $( item );
				const searchText = String(
					$item.attr( 'data-mw-createwiki-deploygroups-search-text' ) || ''
				);
				const isVisible = query === '' || searchText.includes( query );
				$item.toggleClass( 'ext-createwiki-deploygroups-wiki-item--hidden', !isVisible );
				if ( isVisible ) {
					visibleCount++;
				}
			} );

			$emptyState.toggleClass( 'ext-createwiki-deploygroups-empty-state--hidden', visibleCount !== 0 );
		};

		$searchInput.on( 'input', updateSearch );
		updateSearch();
	} );
}() );
