/**
 * Alpine.js components for the SCD Standings frontend. Registered inside an
 * `alpine:init` listener (see Frontend\Assets for why load order matters),
 * so this file must finish executing before the bundled Alpine itself runs.
 * Every component talks only to the public, read-mostly scd/v1 REST API -
 * see Infrastructure\Rest\RestApi for the routes consumed here.
 */
( function () {
	var restUrl = ( window.scdConfig && window.scdConfig.restUrl ) || '/wp-json/scd/v1';

	function api( path ) {
		return fetch( restUrl + path, { headers: { Accept: 'application/json' } } ).then( function ( res ) {
			if ( ! res.ok ) {
				throw new Error( 'Request failed: ' + res.status );
			}
			return res.json();
		} );
	}

	document.addEventListener( 'alpine:init', function () {
		Alpine.data( 'scdStandings', function ( tournamentSlug, groupSlug ) {
			return {
				rows: [],
				loading: true,
				error: false,
				load: function () {
					var query = groupSlug ? '?group=' + encodeURIComponent( groupSlug ) : '';
					api( '/tournaments/' + tournamentSlug + '/standings' + query )
						.then(
							function ( groups ) {
								this.rows = ( groups[ 0 ] && groups[ 0 ].standings ) || [];
								this.loading = false;
							}.bind( this ),
						)
						.catch(
							function () {
								this.error = true;
								this.loading = false;
							}.bind( this ),
						);
				},
			};
		} );

		Alpine.data( 'scdBracket', function ( tournamentSlug ) {
			return {
				slots: [],
				loading: true,
				error: false,
				load: function () {
					api( '/tournaments/' + tournamentSlug + '/bracket' )
						.then(
							function ( slots ) {
								this.slots = slots;
								this.loading = false;
							}.bind( this ),
						)
						.catch(
							function () {
								this.error = true;
								this.loading = false;
							}.bind( this ),
						);
				},
				sideLabel: function ( side ) {
					if ( side.resolved ) {
						var match = side.possible.filter( function ( t ) {
							return t.team_id === side.resolved;
						} )[ 0 ];
						return match ? match.name : '#' + side.resolved;
					}
					if ( 1 === side.possible.length ) {
						return side.possible[ 0 ].name;
					}
					return side.possible.length + ' possible';
				},
			};
		} );

		Alpine.data( 'scdOpponents', function ( tournamentSlug, teamSlug, initialRound ) {
			return {
				round: initialRound,
				opponents: [],
				loading: false,
				error: false,
				load: function () {
					if ( ! this.round ) {
						return;
					}
					this.loading = true;
					this.error = false;
					api( '/tournaments/' + tournamentSlug + '/teams/' + teamSlug + '/opponents?round=' + encodeURIComponent( this.round ) )
						.then(
							function ( opponents ) {
								this.opponents = opponents;
								this.loading = false;
							}.bind( this ),
						)
						.catch(
							function () {
								this.error = true;
								this.loading = false;
							}.bind( this ),
						);
				},
			};
		} );

		Alpine.data( 'scdScenarios', function ( matchId ) {
			return {
				scenarios: [],
				loading: true,
				error: false,
				load: function () {
					api( '/matches/' + matchId + '/scenarios' )
						.then(
							function ( scenarios ) {
								this.scenarios = scenarios;
								this.loading = false;
							}.bind( this ),
						)
						.catch(
							function () {
								this.error = true;
								this.loading = false;
							}.bind( this ),
						);
				},
			};
		} );

		Alpine.data( 'scdSimulator', function ( groupId, matches ) {
			return {
				groupId: groupId,
				inputs: matches.map( function ( m ) {
					return { match_id: m.id, home_name: m.home_name, away_name: m.away_name, home_score: '', away_score: '' };
				} ),
				rows: [],
				submitted: false,
				loading: false,
				error: false,
				run: function () {
					this.loading = true;
					this.error = false;

					var results = this.inputs
						.filter( function ( i ) {
							return '' !== i.home_score && '' !== i.away_score;
						} )
						.map( function ( i ) {
							return { match_id: i.match_id, home_score: parseInt( i.home_score, 10 ), away_score: parseInt( i.away_score, 10 ) };
						} );

					fetch( restUrl + '/simulate', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
						body: JSON.stringify( { group_id: this.groupId, results: results } ),
					} )
						.then(
							function ( res ) {
								if ( ! res.ok ) {
									throw new Error( 'Request failed: ' + res.status );
								}
								return res.json();
							},
						)
						.then(
							function ( rows ) {
								this.rows = rows;
								this.submitted = true;
								this.loading = false;
							}.bind( this ),
						)
						.catch(
							function () {
								this.error = true;
								this.loading = false;
							}.bind( this ),
						);
				},
				reset: function () {
					this.submitted = false;
					this.rows = [];
					this.inputs.forEach( function ( i ) {
						i.home_score = '';
						i.away_score = '';
					} );
				},
			};
		} );
	} );
} )();
