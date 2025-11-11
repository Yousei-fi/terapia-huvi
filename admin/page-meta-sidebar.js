( function( wp, config ) {
	if ( ! wp || ! config ) {
		return;
	}

	const { plugins, editPost, components, data, element } = wp;

	if ( ! plugins || ! editPost || ! components || ! data || ! element ) {
		return;
	}

	const { registerPlugin } = plugins;
	const { PluginDocumentSettingPanel } = editPost;
	const { TextControl, TextareaControl } = components;
	const { useSelect, useDispatch } = data;
	const { createElement } = element;

	if ( ! registerPlugin || ! PluginDocumentSettingPanel ) {
		return;
	}

	const MetaPanel = () => {
		const slug = useSelect( ( select ) => {
			const post = select( 'core/editor' ).getCurrentPost();
			return post ? post.slug : null;
		}, [] );

		const meta = useSelect(
			( select ) => select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {}
		);

		const { editPost } = useDispatch( 'core/editor' );

		if ( ! slug || ! config[ slug ] ) {
			return null;
		}

		const definition = config[ slug ];

		const onChange = ( key ) => ( value ) => {
			editPost( { meta: { ...meta, [ key ]: value } } );
		};

		const fields = definition.fields.map( ( field ) => {
			const fieldProps = {
				key: field.key,
				label: field.label,
				help: field.help || undefined,
				value: meta[ field.key ] ?? '',
				onChange: onChange( field.key ),
			};

			if ( field.type === 'textarea' ) {
				if ( field.rows ) {
					fieldProps.rows = field.rows;
				}

				return createElement( TextareaControl, fieldProps );
			}

			return createElement( TextControl, fieldProps );
		} );

		const children = [];

		if ( definition.description ) {
			children.push(
				createElement(
					'p',
					{ className: 'hpp-page-meta-panel__description', key: 'hpp-description' },
					definition.description
				)
			);
		}

		children.push( ...fields );

		return createElement(
			PluginDocumentSettingPanel,
			{
				name: 'hpp-page-meta-panel',
				title: definition.title,
				className: 'hpp-page-meta-panel',
			},
			children
		);
	};

	registerPlugin( 'hpp-page-meta-sidebar', { render: MetaPanel } );
} )( window.wp || undefined, window.hppPageMetaConfig || {} );


